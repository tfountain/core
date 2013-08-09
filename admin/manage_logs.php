<?php
 /*
 * Project:		EQdkp-Plus
 * License:		Creative Commons - Attribution-Noncommercial-Share Alike 3.0 Unported
 * Link:		http://creativecommons.org/licenses/by-nc-sa/3.0/
 * -----------------------------------------------------------------------
 * Began:		2006
 * Date:		$Date$
 * -----------------------------------------------------------------------
 * @author		$Author$
 * @copyright	2006-2011 EQdkp-Plus Developer Team
 * @link		http://eqdkp-plus.com
 * @package		eqdkp-plus
 * @version		$Rev$
 *
 * $Id$
 */

define('EQDKP_INC', true);
define('IN_ADMIN', true);
$eqdkp_root_path = '../';
include_once($eqdkp_root_path . 'common.php');

class Manage_Logs extends page_generic {
	public static function __shortcuts() {
		$shortcuts = array('user', 'tpl', 'in', 'pdh', 'jquery', 'core', 'config', 'time', 'html', 'pdl', 'logs');
		return array_merge(parent::$shortcuts, $shortcuts);
	}

	public function __construct(){
		$this->user->check_auth('a_logs_view');

		$handler = array(
			'reset'			=> array('process' => 'reset_logs',			'check' => 'a_logs_del', 'csrf'=>true),
			'del_errors'	=> array('process' => 'delete_errors',		'check' => 'a_logs_del', 'csrf'=>true),
			'dellogdays'	=> array('process' => 'delete_log_days',	'check' => 'a_logs_del', 'csrf'=>true)
		);
		parent::__construct(false, $handler, array(), null, 'selected_ids[]', 'logid');
		if($this->url_id > 0) $this->view_log();
		$this->process();
	}
	
	public function delete(){
		echo "delete";
		if(count($this->in->getArray('selected_ids', 'int')) > 0) {
			$ret = $this->pdh->put('logs', 'delete_ids', array($this->in->getArray('selected_ids','int')));
			$this->pdh->process_hook_queue();
			$this->logs->add( 'action_logs_deleted', array('{L_NUMBER_OF_LOGS}' => count($this->in->getArray('selected_ids', 'int'))));
			$this->display();
		}	
	}

	public function reset_logs(){
		$ret = $this->pdh->put('logs', 'truncate_log', array());
		$this->pdh->process_hook_queue();
		$this->logs->add( 'action_logs_deleted', array('{L_NUMBER_OF_LOGS}' => $ret));
		$this->display();
	}

	public function delete_errors(){
		$this->pdl->delete_logfile('php_error');
		$this->pdl->delete_logfile('sql_error');
		$this->display();
	}

	public function delete_log_days(){
		$ret = $this->pdh->put('logs', 'clean_log', array($this->in->get('dellogdays')));
		$this->pdh->process_hook_queue();
		$this->logs->add( 'action_old_logs_deleted', array('{L_CLEAR_LAST_LOGS}' => $this->in->get('dellogdays').' {L_DAYS}', '{L_NUMBER_OF_LOGS}' => $ret));
		$this->display();
	}

	public function view_log() {
		$log_value = unserialize($this->pdh->get('logs', 'value', array($this->url_id)));
		
		$blnCompare = false;
		if(is_array($log_value)) {
			foreach ($log_value as $k => $v){
				if($k != 'header'){
					//Enable Compare view
					if (is_array($v)){
						$blnCompare = true;
						
						if ($v['flag'] == 1){
							require_once($this->root_path.'libraries/diff/diff.php');
							require_once($this->root_path.'libraries/diff/engine.php');
							require_once($this->root_path.'libraries/diff/renderer.php');
							$diff = new diff(xhtml_entity_decode($this->logs->lang_replace($v['old'])), xhtml_entity_decode($this->logs->lang_replace($v['new'])), true);
							$renderer = new diff_renderer_inline();
							
							$new = $content = $renderer->get_diff_content($diff);
						} else {
							$new = nl2br($this->logs->lang_replace($v['new']));
						}
						
						$this->tpl->assign_block_vars('log_compare_row', array(
								'KEY'			=> $this->logs->lang_replace(stripslashes($k)).':',
								'OLD'			=> nl2br($this->logs->lang_replace($v['old'])),
								'NEW'			=> $new,
								'FLAG'			=> $v['flag'],
						));
					} else {				
						$this->tpl->assign_block_vars('log_row', array(
							'KEY'			=> $this->logs->lang_replace(stripslashes($k)).':',
							'VALUE'			=> $this->logs->lang_replace(stripslashes($v)))
						);
					}
				}
			}
		}
		$plugin = $this->pdh->get('logs', 'plugin', array($this->url_id));
		$this->tpl->assign_vars(array(
			'LOG_PLUGIN'		=> ($plugin != 'core') ? (($this->user->lang($plugin)) ? $this->user->lang($plugin) : ucfirst($plugin)) : '',
			'LOG_DATE'			=> $this->pdh->geth('logs', 'date', array($this->url_id, true)),
			'LOG_USERNAME'		=> $this->pdh->geth('logs', 'user', array($this->url_id)),
			'LOG_IP_ADDRESS'	=> $this->pdh->geth('logs', 'ipaddress', array($this->url_id)),
			'LOG_SESSION_ID'	=> $this->pdh->geth('logs', 'sid', array($this->url_id)),
			'LOG_ACTION'		=> $this->pdh->geth('logs', 'tag', array($this->url_id)),
			'S_COMPARE_VIEW'	=> $blnCompare,
			'S_MORE_INFOS'		=> count($log_value),
		));
		$this->tpl->add_js('
			$("#back2view").click(function(){
				window.location="manage_logs.php'.$this->SID.'";
			});', 'docready');
		$this->tpl->css_file($this->root_path.'libraries/diff/diff.css');
		$this->core->set_vars(array(
			'page_title'		=> $this->user->lang('viewlogs_title'),
			'template_file'		=> 'admin/manage_logs_view.html',
			'display'			=> true)
		);
	}

	public function display(){
	
		$plugin_list['']	= '';
		if (is_array($this->pdh->get('logs', 'plugins'))){
			foreach($this->pdh->get('logs', 'plugins') as $pluginname){
				if (in_array($pluginname, $this->logs->plugins)){
					$name = ($this->user->lang($pluginname)) ? $this->user->lang($pluginname) : ucfirst($pluginname);
					$plugin_list[$pluginname] = $name;
				}
			}
		}
		
		$user_list[-1]	= '';
		$arrUsers = $this->pdh->get('logs', 'grouped_users', array());
		foreach($arrUsers as $user_id){
			$user_list[$user_id] = $this->pdh->get('user', 'name', array($user_id));
		}
		
		$type_list['']	= '';
		$arrTags = $this->pdh->get('logs', 'grouped_tags', array());
		foreach($arrTags as $tag){
			$type_list[$tag] = $this->user->lang($tag, true, false);
		}
		
		$result_list = array(
			'-1' => '',
			'0' => $this->user->lang('error'),
			'1' => $this->user->lang('success'),
		);
	
		
		//Prepare Filter
		$blnFilter = false;
		$strFilterSuffix = "";
		if ($this->in->exists('filter')){
			//Change Filter options here
			$plugin = ($this->in->get('filter_plugin') != "") ? $this->in->get('filter_plugin') : false;
			$result = ($this->in->exists('filter_result') && $this->in->get('filter_result', 0) >= 0) ? $this->in->get('filter_result', 0) : false;
			$ip		= ($this->in->get('filter_ip') != "") ? $this->in->get('filter_ip') : false;
			$sid	= ($this->in->get('filter_sid') != "") ? $this->in->get('filter_sid') : false;
			$tag	= ($this->in->get('filter_type') != "") ? $this->in->get('filter_type') : false;
			$user_id= ($this->in->exists('filter_user') && $this->in->get('filter_user', 0) >= 0) ? $this->in->get('filter_user', 0) : false;
			$value	= ($this->in->get('filter_value') != "") ? $this->in->get('filter_value') : false;
			$date_from = ($this->in->get('filter_date_from') != "") ? $this->time->fromformat($this->in->get('filter_date_from','1.1.1970').' 00:00', 1) : false;
			$date_to = ($this->in->get('filter_date_to') != "") ? $this->time->fromformat($this->in->get('filter_date_to','1.1.1970').' 00:00', 1) : false;
			if (!$date_from) {$date_from = ($this->in->get('f_date_from', 0)) ? $this->in->get('f_date_from', 0) : false;}
			if (!$date_to) {$date_to = ($this->in->get('f_date_to', 0)) ? $this->in->get('f_date_to', 0) : false;}

			//Do we have filters?
			if ($plugin !== false || $result !== false || $ip !== false || $sid !== false || $tag !== false || $user_id !== false || $value !== false || $date_from !== false || $date_to !== false){
				$blnFilter = true;
				//Get filtered ID list
				$view_list = $this->pdh->get('logs', 'filtered_id_list', array($plugin, $result, $ip, $sid, $tag, $user_id, $value, $date_from, $date_to));
				
				//Build GET-Params for Sorting and Pagination
				$strFilterSuffix .= "&amp;filter=1";
				if ($plugin !== false) $strFilterSuffix .= "&amp;filter_plugin=".$plugin;
				if ($result !== false) $strFilterSuffix .= "&amp;filter_result=".$result;
				if ($ip !== false) $strFilterSuffix .= "&amp;filter_ip=".$ip;
				if ($sid !== false) $strFilterSuffix .= "&amp;filter_sid=".$sid;
				if ($tag !== false) $strFilterSuffix .= "&amp;filter_type=".$tag;
				if ($user_id !== false) $strFilterSuffix .= "&amp;filter_user=".$user_id;
				if ($value !== false) $strFilterSuffix .= "&amp;filter_value=".$value;
				if ($date_from !== false) $strFilterSuffix .= "&amp;f_date_from=".$date_from;
				if ($date_to !== false) $strFilterSuffix .= "&amp;f_date_to=".$date_to;
				

				$_date_from = ($date_from !== false) ? $this->time->user_date($date_from , false, false, false, function_exists('date_create_from_format')) : '';
				$_date_to	= ($date_to !== false) ? $this->time->user_date($date_to , false, false, false, function_exists('date_create_from_format')) : '';
				//Template Vars
				$this->tpl->assign_vars(array(
					'FILTER_PLUGINS' => $this->html->DropDown('filter_plugin', $plugin_list, (($plugin !== false) ? $plugin : '')),
					'FILTER_USER'	 => $this->html->DropDown('filter_user', $user_list, (($user_id !== false) ? $user_id : '')),
					'FILTER_TYPE'	 => $this->html->DropDown('filter_type', $type_list, (($tag !== false) ? $tag : '')),
					'FILTER_RESULT'  => $this->html->DropDown('filter_result', $result_list, (($result !== false) ? $result : -1)),
					'FILTER_IP'		=> $ip,
					'FILTER_SID'	=> $sid,
					'FILTER_VALUE'	=> $value,
					'FILTER_DATE_FROM'		=> $this->jquery->Calendar('filter_date_from', $_date_from),
					'FILTER_DATE_TO'		=> $this->jquery->Calendar('filter_date_to', $_date_to),
				));
			}
			
		}
		
		if (!$blnFilter){
			//Common Filter Output
			$this->tpl->assign_vars(array(
				'FILTER_PLUGINS' => $this->html->DropDown('filter_plugin', $plugin_list, ''),
				'FILTER_USER'	 => $this->html->DropDown('filter_user', $user_list, ''),
				'FILTER_TYPE'	 => $this->html->DropDown('filter_type', $type_list, ''),
				'FILTER_RESULT'  => $this->html->DropDown('filter_result', $result_list,-1),
				'FILTER_DATE_FROM'		=> $this->jquery->Calendar('filter_date_from', ''),
				'FILTER_DATE_TO'		=> $this->jquery->Calendar('filter_date_to', ''),
			));
			$view_list			= $this->pdh->get('logs', 'id_list', array());
		}
	
	

		$start = $this->in->get('start', 0);
		$this->jquery->Dialog('delete_all_warning', '', array('url'=>'manage_logs.php'.$this->SID.'&reset=true&link_hash='.$this->CSRFGetToken('reset'), 'message'=>$this->user->lang('confirm_delete_logs')), 'confirm');
		$this->confirm_delete($this->user->lang('confirm_delete_partial_logs'));
		$this->jquery->Tab_header('log_tabs', true);
		$error_type_array = array(
			'warning'	=> 'WARNING',
			'fatal'		=> 'FATAL ERROR',
			'parse'		=> 'PARSING ERROR',
			'compile'	=> 'COMPILE ERROR',
			'error'		=> 'ERROR',
			'exception' => 'EXCEPTION',
		);
		$time_array	= $error_array = $type_array = array();
		//PHP-Errors
		$php_errors	= $this->pdl->get_file_log('php_error', 50, $start);
		if(isset($php_errors['entries'])){
			foreach($php_errors['entries'] as $key=> $value) {
				if($this->in->get('error') != '' && 'php' != $this->in->get('error')) break;
				if($this->in->get('type') != '' && (strpos($php_errors['entries'][$key+1], $error_type_array[$this->in->get('type')]) !== 0)) continue;
				if(preg_match('/([0-9][0-9]\.[01][0-9]\.[0-9]{4}\s[0-9]{2}\:[0-9]{2}\:[0-9]{2}\s)/', $value)){
					$error_array[]	= $php_errors['entries'][$key+1];
					$type_array[]	= 'php';
					$time_array[]	= strtotime($value);
				}
			}
		}
		//MySQL
		$sql_errors = $this->pdl->get_file_log('sql_error', 50, $start);
		if (isset($sql_errors['entries'])){
			foreach ($sql_errors['entries'] as $key=>$value){
				if ($this->in->get('error') != '' && 'db' != $this->in->get('error')) break;
				if ($this->in->get('type') != '' && strpos($sql_errors['entries'][$key+1], $error_type_array[$this->in->get('type')]) === false) continue;
				if (preg_match('/([0-9][0-9]\.[01][0-9]\.[0-9]{4}\s[0-9]{2}\:[0-9]{2}\:[0-9]{2}\s)/', $value)){
					$error_array[]	= $sql_errors['entries'][$key+1];
					$type_array[]	= 'db';
					$time_array[]	= strtotime($value);
				}
			}
		}
		array_multisort($time_array, (($this->in->get('o', '0.0') == '0.0') ? SORT_DESC : SORT_ASC), SORT_NUMERIC, $error_array, SORT_DESC, SORT_NUMERIC, $type_array);
		$total_errors	= ($this->in->get('type') == '') ? $sql_errors['entrycount'] + $php_errors['entrycount'] : (($this->in->get('type') == 'php') ? $php_errors['entrycount'] : $sql_errors['entrycount']);
		$max_page = ($php_errors['entrycount'] > $sql_errors['entrycount'] && ($this->in->get('type') == '' || $this->in->get('type') == 'php')) ? $php_errors['entrycount'] : $sql_errors['entrycount'];
		foreach ($time_array as $key => $value){
			$this->tpl->assign_block_vars('error_row', array(
				'DATE'			=> $this->time->user_date($value, true),
				'MESSAGE'		=> $error_array[$key],
				'TYPE'			=> $type_array[$key],
			));
		}
		$error_list = array(
			''		=> '',
			'php'	=> 'PHP',
			'db'	=> 'DB'
		);
		$type_list = array(
			''			=> '',
			'warning'	=> 'Warning',
			'error'		=> 'Error',
			'fatal'		=> 'Fatal Error',
			'parse'		=> 'Parse Error',
			'compile'	=> 'Compile Error',
		);

		$actionlog_count	= count($view_list);
		$hptt_psettings		= $this->pdh->get_page_settings('admin_manage_logs', 'hptt_managelogs_actions');
		$hptt				= $this->get_hptt($hptt_psettings, $view_list, $view_list, array('%link_url%' => 'manage_logs.php', '%link_url_suffix%' => '', md5($strFilterSuffix)));
		$footer_text		= sprintf($this->user->lang('viewlogs_footcount'), $actionlog_count, 100);
		$page_suffix		= '&amp;start='.$this->in->get('start', 0).$strFilterSuffix;
		$sort_suffix		= $this->SID.'&amp;sort='.$this->in->get('sort').$strFilterSuffix;
		$logs_list = $hptt->get_html_table($this->in->get('sort',''), $page_suffix, $this->in->get('start', 0), 100, $footer_text);
		
		$this->jquery->Collapse('#toggleFilter', true);
		$this->tpl->assign_vars(array(
			'LOGS_LIST'				=> $logs_list,
			'LOGS_PAGINATION'		=> generate_pagination('manage_logs.php'.$sort_suffix.$strFilterSuffix, $actionlog_count, 100, $this->in->get('start', 0)),
			'HPTT_LOGS_COUNT'		=> $hptt->get_column_count(),
			'ERROR_FILTER_SELECT'	=> $this->html->DropDown('error_dd', $error_list, $this->in->get('error'), '', 'onchange="window.location=\'manage_logs.php'.$this->SID.'&error=\'+document.post2.error_dd.value"'),
			'ERROR_TYPE_SELECT'		=> $this->html->DropDown('error_type_dd', $type_list, $this->in->get('type'), '', 'onchange="window.location=\'manage_logs.php'.$this->SID.'&type=\'+document.post2.error_type_dd.value"'),
			'EL_FOOTCOUNT'			=> sprintf($this->user->lang('viewlogs_footcount'), $total_errors, 50),
			'EL_PAGINATION'			=> generate_pagination('manage_logs.php'.$this->SID.'&amp;error='.sanitize($this->in->get('error')).'&amp;type='.sanitize($this->in->get('type')), $max_page, 50, $start),
		));
		$this->core->set_vars(array(
			'page_title'		=> $this->user->lang('viewlogs_title'),
			'template_file'		=> 'admin/manage_logs.html',
			'display'			=> true)
		);
	}
}
registry::register('Manage_Logs');
?>