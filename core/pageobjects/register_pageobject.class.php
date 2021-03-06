<?php
/*	Project:	EQdkp-Plus
 *	Package:	EQdkp-plus
 *	Link:		http://eqdkp-plus.eu
 *
 *	Copyright (C) 2006-2016 EQdkp-Plus Developer Team
 *
 *	This program is free software: you can redistribute it and/or modify
 *	it under the terms of the GNU Affero General Public License as published
 *	by the Free Software Foundation, either version 3 of the License, or
 *	(at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU Affero General Public License for more details.
 *
 *	You should have received a copy of the GNU Affero General Public License
 *	along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// the member & email check functionality. POST == security. Not use in-get!!!
if(registry::register('input')->get('ajax', 0) == '1'){
	if(isset($_POST['username'])){
		if(registry::register('input')->exists('olduser') && registry::register('input')->get('olduser') === $_POST['username']){
			echo 'true';
		}else{
			echo registry::register('plus_datahandler')->get('user', 'check_username', array(registry::register('input')->get('username')));
		}
	}
	if(isset($_POST['user_email'])){
		if(registry::register('input')->exists('oldmail') && urldecode(registry::register('input')->get('oldmail')) === $_POST['user_email']){
			echo 'true';
		}else{
			echo registry::register('plus_datahandler')->get('user', 'check_email', array(registry::register('input')->get('user_email')));
		}
	}
	if(isset($_POST['oldpassword'])){
		echo registry::register('plus_datahandler')->get('user', 'check_password', array(registry::register('input')->get('oldpassword')));
	}
	exit;
}

class register_pageobject extends pageobject {
	public static $shortcuts = array('email'=>'MyMailer','crypt' => 'encrypt');

	public $server_url	= '';
	public $data		= array();
	private $userProfileData = array();

	public function __construct() {
		$handler = array(
			'submit'			=> array('process' => 'submit',  'csrf' => true),
			'register'			=> array('process' => 'display_form'),
			'guildrules'		=> array('process' => 'display_guildrules'),
			'deny'				=> array('process' => 'process_deny'),
			'confirmed'			=> array('process' => 'process_confirmed'),
			'activate'			=> array('process' => 'process_activate'),
			'resend_activation'	=> array('process' => 'process_resend_activation'),
			'resendactivation'	=> array('process' => 'display_resend_activation_mail'),
		);
		parent::__construct(false, $handler);
		if ($this->user->data['rules'] == 1){
			// If they're trying access this page while logged in, redirect to settings.php
			if( $this->user->is_signedin() && !$this->in->exists('key')) {
				redirect($this->controller_path_plain.'Settings/'. $this->SID);
			}
			if((int)$this->config->get('enable_registration') == 0){
				redirect($this->controller_path_plain.$this->SID);
			}
			if((int)$this->config->get('cmsbridge_active') == 1 && strlen($this->config->get('cmsbridge_reg_url'))) {
				redirect($this->config->get('cmsbridge_reg_url'),false,true);
			}
		}

		// Build the server URL
		// ---------------------------------------------------------
		$this->server_url  = $this->env->link.$this->controller_path_plain.'Register/';
		$this->process();
	}

	// ---------------------------------------------------------
	// Process Submit
	// ---------------------------------------------------------
	public function submit() {
		if((int)$this->config->get('cmsbridge_active') == 1 && strlen($this->config->get('cmsbridge_reg_url'))) {
			redirect($this->config->get('cmsbridge_reg_url'),false,true);
		}
		
		//Static input vars
		$this->data = array(
			'username'			=> $this->in->get('username'),
			'user_email'		=> $this->in->get('user_email'),
			'user_email2'		=> $this->in->get('user_email2'),
			'user_lang'			=> $this->in->get('user_lang', $this->config->get('default_lang')),
			'user_timezone'		=> $this->in->get('user_timezone', $this->config->get('timezone')),
			'user_password1'	=> $this->in->get('new_user_password1'),
			'user_password2'	=> $this->in->get('new_user_password2'),
		);

		//Check Honeypot
		if (strlen($this->in->get($this->user->csrfGetToken("honeypot")))){
			$this->core->message($this->user->lang('lib_captcha_wrong'), $this->user->lang('error'), 'red');
			$this->display();
			return;
		}
		
		//Check User Profilefields
		$arrUserProfileFields = $this->pdh->get('user_profilefields', 'registration_fields');
		$form = false;
		if (count($arrUserProfileFields)){
			$form = register('form', array('register'));
			$form->validate = true;
			$form->add_fields($arrUserProfileFields);
			$arrFieldValues = $form->return_values();
			$this->userProfileData = $arrFieldValues;
		}
		
		//Check CAPTCHA
		if ($this->config->get('enable_captcha') == 1 && $this->config->get('lib_recaptcha_pkey') && strlen($this->config->get('lib_recaptcha_pkey'))){
			require($this->root_path.'libraries/recaptcha/recaptcha.class.php');
			$captcha = new recaptcha;
			$response = $captcha->check_answer ($this->config->get('lib_recaptcha_pkey'), $this->env->ip, $this->in->get('g-recaptcha-response'));
			if (!$response->is_valid) {
				$this->core->message($this->user->lang('lib_captcha_wrong'), $this->user->lang('error'), 'red');
				$this->display_form();
				return;
			}
		}
		
		//Check Password
		if ($this->in->get('new_user_password1') !== $this->in->get('new_user_password2')){
			$this->core->message($this->user->lang('password_not_match'), $this->user->lang('error'), 'red');
			$this->display_form();
			return;
		}
		if (strlen($this->in->get('new_user_password1')) > 64) {
			$this->core->message($this->user->lang('password_too_long'), $this->user->lang('error'), 'red');
			$this->display_form();
			return;	
		}
		
		//Check Email
		if ($this->pdh->get('user', 'check_email', array($this->in->get('user_email'))) == 'false'){
			$this->core->message(str_replace("{0}", $this->in->get('user_email'), $this->user->lang('fv_email_alreadyuse')), $this->user->lang('error'), 'red');
			$this->display_form();
			return;
		} elseif (!preg_match("/^([a-zA-Z0-9])+([\.a-zA-Z0-9_\-\+])*@([a-zA-Z0-9_-])+(\.[a-zA-Z0-9_-]+)+/",$this->in->get('user_email'))){
			$this->core->message($this->user->lang('fv_invalid_email'), $this->user->lang('error'), 'red');
			$this->display_form();
			return;
		}

		//Check Username
		if ($this->pdh->get('user', 'check_username', array($this->in->get('username'))) == 'false'){
			$this->core->message(str_replace("{0}", $this->in->get('username'), $this->user->lang('fv_username_alreadyuse')), $this->user->lang('error'), 'red');
			$this->display_form();
			return;
		}
		
		//Check User Profilefields - Part 2
		if (is_object($form) && $form->error){
			$this->display_form();
			return;
		}

		// If the config requires account activation, generate a random key for validation
		if ( ((int)$this->config->get('account_activation') == 1) || ((int)$this->config->get('account_activation') == 2) ) {
			$user_key = random_string(true, 32);
			$intEmailConfirmed = -1;

			if ($this->user->is_signedin()) {
				$this->user->destroy();
			}
		} else {
			$user_key = '';
			$intEmailConfirmed = '1';
		}

		//Insert the user into the DB
		$user_id = $this->pdh->put('user', 'register_user', array($this->data, 1, $user_key, true, $this->in->get('lmethod'), $this->userProfileData, $intEmailConfirmed));
		if(!$user_id) message_die('Error while saving the user.', $this->user->lang('error'));
		
		//Add auth-account
		if ($this->in->exists('auth_account')){
			$auth_account = $this->crypt->decrypt($this->in->get('auth_account'));
			if ($this->pdh->get('user', 'check_auth_account', array($auth_account, $this->in->get('lmethod')))){
				$this->pdh->put('user', 'add_authaccount', array($user_id, $auth_account, $this->in->get('lmethod')));
			}
		}

		//Give permissions if there is no default group
		$default_group = $this->pdh->get('user_groups', 'standard_group', array());
		if (!$default_group) {
			$sql = 'SELECT auth_id, auth_default
					FROM __auth_options
					ORDER BY auth_id';
			$result = $this->db->query($sql);
			if ($result){
				while ( $row = $result->fetchAssoc() ) {
					$arrSet = array(
						'user_id' 		=> $user_id,
						'auth_id' 		=> $row['auth_id'],
						'auth_setting'	=> $row['auth_default'],
					);
					$this->db->prepare("INSERT INTO __auth_users :p")->set($arrSet)->execute();
				}
			}
		}
			
		$title = '';
		
		if ($this->config->get('account_activation') == 1) {
			$success_message	= sprintf($this->user->lang('register_activation_self'), $this->in->get('user_email'));
			$email_template		= 'register_activation_self';
			$email_subject		= $this->user->lang('email_subject_activation_self');
			$title				= $this->user->lang('email_subject_activation_self');
		} elseif ($this->config->get('account_activation') == 2) {
			$success_message	= sprintf($this->user->lang('register_activation_admin'), $this->in->get('user_email'));
			$email_template		= 'register_activation_admin';
			$email_subject		= $this->user->lang('email_subject_activation_admin');
			$title				= $this->user->lang('email_subject_activation_admin');
		} else {
			$success_message = sprintf($this->user->lang('register_activation_none'), '<a href="'.$this->controller_path.'Login/'.$this->SID.'">', '</a>', $this->in->get('user_email'));
			$email_template		= 'register_activation_none';
			$email_subject		= $this->user->lang('email_subject_activation_none');
			$title				= $this->user->lang('success');
		}

		// Email a notice
		$this->email->Set_Language($this->in->get('user_lang'));
		$bodyvars = array(
			'USERNAME'		=> stripslashes($this->in->get('username')),
			'PASSWORD'		=> stripslashes($this->in->get('user_password1')),
			'U_ACTIVATE' 	=> $this->server_url . 'Activate/?key=' . $user_key,
			'GUILDTAG'		=> $this->config->get('guildtag'),
		);
		if(!$this->email->SendMailFromAdmin($this->in->get('user_email'), $email_subject, $email_template.'.html', $bodyvars)){
			$success_message = $this->user->lang('email_subject_send_error');
			
		}

		// Now email the admin if we need to
		if ( $this->config->get('account_activation') == 2 ) {
			$this->email->Set_Language($this->config->get('default_lang'));
			$bodyvars = array(
				'USERNAME'		=> $this->in->get('username'),
				'U_ACTIVATE' 	=> $this->server_url . 'Activate/?key=' . $user_key,
			);
			if(!$this->email->SendMailFromAdmin(register('encrypt')->decrypt($this->config->get('admin_email')), $this->user->lang('email_subject_activation_admin_act'), 'register_activation_admin_activate.html', $bodyvars)){
				$success_message	= $this->user->lang('email_subject_send_error');
				$title = '';
			}
		}
		
		//Notify Admins
		$this->ntfy->add('eqdkp_user_new_registered', $user_id, $this->in->get('username'), $this->root_path.'admin/manage_users.php'.$this->SID.'&u='.$user_id, false, "", false, array("a_users_man"));
		
		message_die($success_message, $title);
	}

	public function display_resend_activation_mail(){
		$this->tpl->add_js('document.lost_password.username.focus();', 'docready');
		$this->tpl->assign_vars(array(
			'BUTTON_NAME'			=> 'resend_activation',
			'S_RESEND_ACTIVATION'	=> true,
		));

		$this->core->set_vars(array(
			'page_title'		=> $this->user->lang('get_new_activation_mail'),
			'template_file'		=> 'lost_password.html',
			'display'			=> true,
		));

	}

	// ---------------------------------------------------------
	// Process Resend Validation E-Mail
	// ---------------------------------------------------------
	public function process_resend_activation() {
		if((int)$this->config->get('cmsbridge_active') == 1 && strlen($this->config->get('cmsbridge_reg_url'))) {
			redirect($this->config->get('cmsbridge_reg_url'),false,true);
		}

		$username   = ( $this->in->exists('username') )   ? trim(strip_tags($this->in->get('username'))) : '';

		// Look up record based on the username and e-mail		
		$objQuery = $this->db->prepare("SELECT user_id, username, user_email, user_active, user_lang, user_email_confirmed
				FROM __users
				WHERE LOWER(user_email) = ?
				OR LOWER(username)=?")->limit(1)->execute(utf8_strtolower($username), clean_username($username));
		if ($objQuery){
			if ($objQuery->numRows){
				$row = $objQuery->fetchAssoc();
				
				// Account's inactive, can't give them their password
				if ( (int)$row['user_active'] || $this->config->get('account_activation') != 1 || ((int)$row['user_email_confirmed'] != -1)) {
					message_die($this->user->lang('error_already_activated'));
				}

				$username = $row['username'];

				// Create a new activation key
				$user_key = $this->pdh->put('user','create_new_activationkey',array($row['user_id']));

				// Email them their new password
				$bodyvars = array(
					'USERNAME'		=> $row['username'],
					'DATETIME'		=> $this->time->user_date($this->time->time, true),
					'U_ACTIVATE' 	=> $this->server_url . 'Activate/?key=' . $user_key,
				);

				if(!$this->email->SendMailFromAdmin($row['user_email'], $this->user->lang('email_subject_activation_self'), 'register_activation_self.html', $bodyvars)) {
					message_die($this->user->lang('error_email_send'), $this->user->lang('get_new_activation_mail'));
				}
			}
			
		}
		
		message_die($this->user->lang('password_resend_success'), $this->user->lang('get_new_activation_mail'));
	}


	// ---------------------------------------------------------
	// Process Activate
	// ---------------------------------------------------------
	public function process_activate() {
		$objQuery = $this->db->prepare("SELECT user_id, username, user_active, user_email_confirmed, user_email, user_lang, user_email_confirmkey, user_email_confirmed, user_temp_email
				FROM __users
				WHERE user_email_confirmkey=?")->execute($this->in->get('key'));	
		if($objQuery){
			if($objQuery->numRows){
				$row = $objQuery->fetchAssoc();
				
				$intConfirmType = intval($row['user_email_confirmed']);
				
				// If they're already active, just bump them back
				if (intval($row['user_active']) == 0 || ((intval($row['user_email_confirmed']) == 1) && ($row['user_email_confirmkey'] == '')) ) {
					message_die($this->user->lang('error_already_activated'), $this->user->lang('error'), 'error');
				} else {
					//Activate User; Email is sent in activation method
					$blnResult = $this->pdh->put('user', 'confirm_email', array($row['user_id'], 1));
					
					if ($blnResult) {
						
						//Registration
						if($intConfirmType == -1){
							// E-mail the user if this was activated by the admin
							if ( $this->config->get('account_activation') == 2 ) {
								$success_message = $this->user->lang('account_activated_admin');
							} else {
								$success_message = sprintf($this->user->lang('account_activated_user'), '<a href="'.$this->controller_path.'Login/' . $this->SID . '">', '</a>');
								$this->tpl->add_meta('<meta http-equiv="refresh" content="3;'.$this->controller_path.'Login/' . $this->SID . '">');
							}
						//Admin requestes email confirmation
						} elseif($intConfirmType == 0){
							$success_message = $this->user->lang('email_confirmed');
							$this->tpl->add_meta('<meta http-equiv="refresh" content="3;'.$this->controller_path. $this->SID . '">');
						//Account was locked by too much logins
						} elseif($intConfirmType == -2){
							$success_message = sprintf($this->user->lang('account_activated_user'), '<a href="'.$this->controller_path.'Login/' . $this->SID . '">', '</a>');
							$this->tpl->add_meta('<meta http-equiv="refresh" content="3;'.$this->controller_path.'Login/' . $this->SID . '">');
						//User changed his Email on his own
						} elseif($intConfirmType == 2){
							$this->pdh->put('user', 'confirm_email', array($row['user_id'], 1, $row['user_temp_email']));
							$success_message = $this->user->lang('email_confirmed');
							$this->tpl->add_meta('<meta http-equiv="refresh" content="3;'.$this->controller_path. $this->SID . '">');
						}
						
						
			
					} else {
						message_die($this->user->lang('email_subject_send_error'), $this->user->lang('success'), 'error');
					}
					
					message_die($success_message, $this->user->lang('success'), 'ok');
				}
				
			} else {
				message_die($this->user->lang('error_invalid_key'), $this->user->lang('error'), 'error');
			}
		} else {
			message_die('Could not obtain user information', '', 'error');
		}
	}

	// ---------------------------------------------------------
	// Process helper methods
	// ---------------------------------------------------------

	public function display() {
		$intGuildrulesArticleID = $this->pdh->get('articles', 'resolve_alias', array('guildrules'));
		$blnGuildrules = ($intGuildrulesArticleID && $this->pdh->get('articles', 'published', array($intGuildrulesArticleID)));
		
		if ($blnGuildrules){
			$this->display_guildrules();
		} else {
			$this->display_form();
		}
	}

	public function display_guildrules() {
		$button = ($this->user->is_signedin()) ? 'confirmed' : 'register';
		$intGuildrulesArticleID = $this->pdh->get('articles', 'resolve_alias', array('guildrules'));
		$arrArticle = $this->pdh->get('articles', 'data', array($intGuildrulesArticleID));
		$strText = xhtml_entity_decode($arrArticle['text']);

		$this->tpl->assign_vars(array(
			'SUBMIT_BUTTON'	=> $button,
			'HEADER'		=> $this->user->lang('guildrules'),
			'TEXT'			=> $strText,
			'S_LICENCE'		=> true,
		));

		$this->core->set_vars(array(
			'page_title'		=> $this->user->lang('register_title'),
			'template_file'		=> 'register.html',
			'display'			=> true)
		);
	}

	public function process_deny() {
		if ($this->user->is_signedin()){
			redirect($this->controller_path_plain.'Login/Logout/'.$this->SID.'&link_hash='.$this->user->csrfGetToken("login_pageobjectlogout"));
		} else {
			redirect();
		}
	}

	public function process_confirmed() {
		if ($this->user->is_signedin()){
			$this->db->prepare("UPDATE __users SET rules = 1 WHERE user_id=?")->execute($this->user->id);
		}
		redirect();
	}

	// ---------------------------------------------------------
	// Display form
	// ---------------------------------------------------------
	public function display_form() {
		if((int)$this->config->get('cmsbridge_active') == 1 && strlen($this->config->get('cmsbridge_reg_url'))) {
			redirect($this->config->get('cmsbridge_reg_url'),false,true);
		}
		
		//Pre fill the form
		$strMethod = $this->in->get('lmethod');
		if ($strMethod != ""){
			$pre_register_data = $this->user->handle_login_functions('pre_register', $strMethod);
			if ($pre_register_data) $this->data = $pre_register_data;
		} else {
			// If it's not in POST, we get it from config defaults
			$this->data = array(
					'username'			=> $this->in->get('username'),
					'user_email'		=> $this->in->get('user_email'),
					'user_email2'		=> $this->in->get('user_email2'),
					'user_lang'			=> $this->in->get('user_lang', $this->config->get('default_lang')),
					'user_timezone'		=> $this->in->get('user_timezone', $this->config->get('timezone')),
					'user_password1'	=> $this->in->get('new_user_password1'),
					'user_password2'	=> $this->in->get('new_user_password2'),
			);
		}

		//Captcha
		if ($this->config->get('enable_captcha') == 1){
			require($this->root_path.'libraries/recaptcha/recaptcha.class.php');
			$captcha = new recaptcha;
			$this->tpl->assign_vars(array(
				'CAPTCHA'				=> $captcha->get_html($this->config->get('lib_recaptcha_okey')),
				'S_DISPLAY_CATPCHA'		=> true,
			));
		}

		$language_array = array();
		if($dir = @opendir($this->root_path . 'language/')){
			while($file = @readdir($dir)){
				if((!is_file($this->root_path . 'language/' . $file)) && (!is_link($this->root_path . 'language/' . $file)) && valid_folder($file)){
					$language_array[$file] = ucfirst($file);
				}
			}
		}
		
		//User Profilefields
		$arrUserProfileFields = $this->pdh->get('user_profilefields', 'registration_fields');
		$form = false;
		if (count($arrUserProfileFields)){
			$form = register('form', array('register'));
			$form->validate = true;
			$form->add_fields($arrUserProfileFields);
			$form->output($this->userProfileData);
		}
		
		$this->tpl->add_js("
			$('[data-equalto]').bind('input', function() {
    var to_confirm = $(this);
    var to_equal = $('#' + to_confirm.data('equalto'));
		
    if(to_confirm.val() != to_equal.val()){
		var fieldtype = $(this).attr('type');
		if(fieldtype == 'email'){
			 this.setCustomValidity(\"".$this->jquery->sanitize(registry::fetch('user')->lang('fv_email_not_match'))."\");
		}else if(fieldtype == 'password'){
			 this.setCustomValidity(\"".$this->jquery->sanitize(registry::fetch('user')->lang('fv_required_password_repeat'))."\");
		} else {
			 this.setCustomValidity(\"".$this->jquery->sanitize(registry::fetch('user')->lang('fv_fields_not_match'))."\");
		};
    } else {
        this.setCustomValidity('');
	}
});");
		

		$this->tpl->assign_vars(array(
			'S_CURRENT_PASSWORD'			=> false,
			'S_NEW_PASSWORD'				=> false,
			'S_SETTING_ADMIN'				=> false,
			'S_MU_TABLE'					=> false,
			'S_PROFILEFIELDS'				=> count($arrUserProfileFields) ? true : false,

			'VALID_EMAIL_INFO'				=> ($this->config->get('account_activation') == 1) ? '<br />'.$this->user->lang('valid_email_note') : '',
			'AUTH_REGISTER_BUTTON'			=> ($arrRegisterButtons = $this->user->handle_login_functions('register_button')) ? implode(' ', $arrRegisterButtons) : '',

			'REGISTER'						=> true,

			'DD_LANGUAGE'					=> new hdropdown('user_lang', array('options' => $language_array, 'value' => $this->data['user_lang'])),
			'DD_TIMEZONES'					=> new hdropdown('user_timezone', array('options' => $this->time->timezones, 'value' => $this->data['user_timezone'])),
			'HIDDEN_FIELDS'					=> (isset($this->data['auth_account'])) ? new hhidden('lmethod', array('value' => $this->in->get('lmethod'))).new hhidden('auth_account', array('value' => $this->crypt->encrypt($this->data['auth_account']))) : '',

			'USERNAME'						=> $this->data['username'],
			'USER_EMAIL'					=> $this->data['user_email'],
			'USER_EMAIL2'					=> $this->data['user_email2'],
		));

		$this->core->set_vars(array(
			'page_title'		=> $this->user->lang('register_title'),
			'template_file'		=> 'register.html',
			'display'			=> true)
		);
	}
}
?>