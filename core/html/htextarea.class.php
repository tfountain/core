<?php
 /*
 * Project:		EQdkp-Plus
 * License:		Creative Commons - Attribution-Noncommercial-Share Alike 3.0 Unported
 * Link:		http://creativecommons.org/licenses/by-nc-sa/3.0/
 * -----------------------------------------------------------------------
 * Began:		2013
 * Date:		$Date: 2013-04-24 10:23:19 +0200 (Mi, 24 Apr 2013) $
 * -----------------------------------------------------------------------
 * @author		$Author: godmod $
 * @copyright	2006-2013 EQdkp-Plus Developer Team
 * @link		http://eqdkp-plus.com
 * @package		eqdkp-plus
 * @version		$Rev: 13337 $
 * 
 * $Id: super_registry.class.php 13337 2013-04-24 08:23:19Z godmod $
 */

if ( !defined('EQDKP_INC') ){
	header('HTTP/1.0 404 Not Found');exit;
}

include_once(registry::get_const('root_path').'core/html/html.aclass.php');

class htextarea extends html {
	public static $shortcuts = array('in');

	protected static $type = 'textarea';
	
	public $name = '';
	public $rows = 5;
	public $cols = 10;
	public $disabled = false;
	public $codeinput = false;
	public $inp_encrypt = false;
	
	public function __construct($name, $options=array()) {
		$this->name = $name;
		foreach($options as $key => $option) {
			$this->$key = $option;
		}
	}
	
	public function __toString() {
		$out = '<textarea name="'.$this->name.'" rows="'.$this->rows.'" cols="'.$this->cols.'" ';
		if(empty($this->id)) $this->id = $this->cleanid($this->name);
		$out .= 'id="'.$this->id.'" ';
		if(!empty($this->class)) $out .= 'class="'.$this->class.'" ';
		if($this->disabled) $out .= 'disabled="disabled" ';
		if(!empty($this->js)) $out.= $this->js.' ';
		if($this->inp_encrypt) $this->value = $this->encrypt->decrypt($this->value);
		return $out.'>'.$this->value.'</textarea>';
	}
	
	public function inpval() {
		$value = $this->in->get($this->name, '', ($this->codeinput) ? 'raw' : ''));
		if($this->inp_encrypt) $value = $this->encrypt->encrypt($value);
		return $value;
	}
}
?>