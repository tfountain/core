<?php
/*
* Project:		EQdkp-Plus
* License:		Creative Commons - Attribution-Noncommercial-Share Alike 3.0 Unported
* Link:			http://creativecommons.org/licenses/by-nc-sa/3.0/
* -----------------------------------------------------------------------
* Began:		2009
* Date:			$Date$
* -----------------------------------------------------------------------
* @author		$Author$
* @copyright	2006-2011 EQdkp-Plus Developer Team
* @link			http://eqdkp-plus.com
* @package		eqdkpplus
* @version		$Rev$
*
* $Id$
*/

if(!defined('EQDKP_INC')) {
	die('Do not access this file directly.');
}

if(!class_exists('pdh_w_raid_groups')) {
	class pdh_w_raid_groups extends pdh_w_generic{
		public static function __shortcuts() {
		$shortcuts = array('pdh', 'db'	);
		return array_merge(parent::$shortcuts, $shortcuts);
	}

		public function __construct() {
			parent::__construct();
		}

		public function add_grp($id, $name, $desc='', $standard=0, $sortid=0,$deletable=1) {
			
			$arrSet = array(
				'groups_raid_id' 		=> $id,
				'groups_raid_name'		=> $name,
				'groups_raid_desc'		=> $desc,
				'groups_raid_deletable' => $deletable,
				'groups_raid_default'	=> $standard,
				'groups_raid_sortid'	=> $sortid,
			);
			
			$objQuery = $this->db->prepare("INSERT INTO __groups_raid :p")->set($arrSet)->execute();
			
			if(!$objQuery) {
				return false;
			}
			$this->pdh->enqueue_hook('raid_groups_update');
			return true;
		}

		public function update_grp($id, $name='', $desc='', $standard=0, $sortid=0) {
			$old = array();
			$old['name']		= $this->pdh->get('raid_groups', 'name', array($id));
			$old['desc']		= $this->pdh->get('raid_groups', 'desc', array($id));
			$old['standard']	= (int)$this->pdh->get('raid_groups', 'standard', array($id));
			$old['sortid']		= (int)$this->pdh->get('raid_groups', 'sortid', array($id));
			$changes = false;
			
			foreach($old as $varname => $value) {
				if(${$varname} === '') {
					${$varname} = $value;
				} else {
					if(${$varname} != $value) {
						$changes = true;
					}
				}
			}

			if ($changes) {
				$arrSet = array(
					'groups_raid_name'		=> $name,
					'groups_raid_desc'		=> $desc,
					'groups_raid_default'	=> $standard,
					'groups_raid_sortid'	=> $sortid,
				);
				
				$objQuery = $this->db->prepare("UPDATE __groups_raid :p WHERE groups_raid_id=?")->set($arrSet)->execute($id);
				
				if(!$objQuery) {
					return false;
				}
			}
			$this->pdh->enqueue_hook('raid_groups_update');
			return true;
		}

		public function delete_grp($id) {
			if ($id == $this->pdh->get('raid_groups', 'standard_group', array())) {
				return false;
			} else {
				$old['name'] = $this->pdh->get('raid_groups', 'name', array($id));
				
				$objQuery = $this->db->prepare("DELETE FROM __groups_raid WHERE (groups_raid_id = ? AND groups_raid_deletable != '0' AND groups_raid_default != '1');")->execute($id);

				if($objQuery) {
					$this->pdh->put('raid_groups_users', 'delete_all_user_from_group', $id);
					$this->pdh->enqueue_hook('raid_groups_update');
					$this->log_insert('action_raidgroups_deleted', array(), $id, $old['name']);
					return true;
				}
			}
		}
	}
}
?>