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

if ( !defined('EQDKP_INC') ){
	die('Do not access this file directly.');
}

if ( !class_exists( "pdh_r_points" ) ) {
	class pdh_r_points extends pdh_r_generic{
		public static $shortcuts = array('apa' => 'auto_point_adjustments');
		
		public $default_lang = 'english';

		private $cache;
		public $points;
		// initialise array to store multipools which are decayed
		private $decayed = array();
		private $hardcap = array();
		private $arrCalculatedSingle = array();
		private $arrCalculatedMulti = array();
		private $arrSnapshotTime = array();
		
		public $hooks = array(
			'adjustment_update',
			'event_update',
			'item_update',
			'member_update',
			'raid_update',
			'multidkp_update',
			'itempool_update',
		);

		public $presets = array(
			'earned' => array('earned', array('%member_id%', '%dkp_id%', 0, '%with_twink%'), array('%dkp_id%')),
			'spent' => array('spent', array('%member_id%', '%dkp_id%', 0, 0, '%with_twink%'), array('%dkp_id%')),
			'adjustment' => array('adjustment', array('%member_id%', '%dkp_id%', 0, '%with_twink%'), array('%dkp_id%')),
			'current' => array('current', array('%member_id%', '%dkp_id%', 0, 0, '%with_twink%'), array('%dkp_id%', false, true)),
			'all_current' => array('current', array('%member_id%', '%ALL_IDS%', 0, 0, '%with_twink%'), array('%ALL_IDS%', true, true)),
		);

		public $detail_twink = array(
			'earned' => 'summed_up',
			'spent' => 'summed_up',
			'adjustment' => 'summed_up',
			'current' => 'summed_up',
		);

		public function reset($affected_ids=array(), $strHook='', $arrAdditionalData=array()){
			$arrTotalAffected = array();
			
			//For Hooks with additional data
			foreach($arrAdditionalData as $arrData){
				if(isset($arrData['action'])){
					$strAction = $arrData['action'];
					if($strHook == 'member_update' && $strAction == 'update_points') return true;
					
					if((isset($arrData['apa']) && $arrData['apa']) || ($strAction == 'add' && ($strHook == 'itempool_update' || $strHook == 'multidkp_update' || $strHook == 'member_update'))){	
						//Nothing to do with APA or member_cache
					} else {
						$arrAffectedMembers = (isset($arrData['members']) && is_array($arrData['members'])) ? $arrData['members'] : $this->pdh->get('member', 'id_list');
						$intAffectedTime = (isset($arrData['time'])) ? $arrData['time'] : 0;
						
						$arrTotalAffected = array_merge($arrTotalAffected, $arrAffectedMembers);
						$this->get_delete_from_snapshot($intAffectedTime, $arrAffectedMembers);
							
						//Reset the APA Cache for the affected members
						$apaAffectedIDs = array();
						foreach($this->pdh->get('multidkp', 'id_list') as $mdkpid){
							foreach($arrAffectedMembers as $memberid){
								$apaAffectedIDs[] = $mdkpid.'_'.$memberid;
							}
						}
						$this->apa->enqueue_update('current', $apaAffectedIDs);
					}
				
				}			
			}
			if((isset($arrData['apa']) && $arrData['apa']) || ($strAction == 'add' && ($strHook == 'itempool_update' || $strHook == 'multidkp_update' || ($strHook == 'member_update'  && !is_array($affected_ids))))){
				//Nothing to do with APA or member_cache
			} elseif($strHook == 'member_update' && is_array($affected_ids) && count($affected_ids)) {
				$apaAffectedIDs = array();
				$arrTotalAffected = array_merge($arrTotalAffected, $affected_ids);
				foreach($this->pdh->get('multidkp', 'id_list') as $mdkpid){
					foreach($affected_ids  as $memberid){
						$apaAffectedIDs[] = $mdkpid.'_'.$memberid;
					}
				}
				$this->apa->enqueue_update('current', $apaAffectedIDs);
			} elseif(count($arrAdditionalData) == 0) {
				//Reset the APA Cache for all members
				$apaAffectedIDs = array();
				foreach($this->pdh->get('multidkp', 'id_list') as $mdkpid){
					foreach($this->pdh->get('member', 'id_list')  as $memberid){
						$apaAffectedIDs[] = $mdkpid.'_'.$memberid;
					}
				}
				$this->apa->enqueue_update('current', $apaAffectedIDs);
				$arrTotalAffected = false;
			}
						
			$this->pdc->del('pdh_points_snapshot_mapping');
			$this->pdc->del('pdh_points_table');
			
			//Reset the member point cache for affected members only
			if(is_array($arrTotalAffected)) $arrTotalAffected = array_unique($arrTotalAffected);
			$this->pdh->put('member', 'reset_points', array($arrTotalAffected));
			
			$this->arrCalculatedMulti = array();
			$this->arrCalculatedSingle = array();
			$this->points = NULL;
		}

		public function init() {
			$this->arrSnapshotTime = $this->pdc->get('pdh_points_snapshot_mapping');
			if($this->arrSnapshotTime === NULL){
				$this->snapshot_mapping();
			}
			
			//cached data not outdated?
			$this->points = $this->pdc->get('pdh_points_table');

			if($this->points !== NULL){
				return true;
			}
			$this->points = array();
			$mdkpids = $this->pdh->maget('multidkp', array('event_ids', 'itempool_ids'), 0, array($this->pdh->get('multidkp', 'id_list')));
			$raid2event = array();
			foreach($mdkpids as $dkp_id => $evip) {
				if((!is_array($evip['event_ids']) || count($evip['event_ids']) < 1) && (!is_array($evip['itempool_ids']) || count($evip['itempool_ids']) < 1)) continue;
				//earned
				if(is_array($evip['event_ids'])) {
					foreach($evip['event_ids'] as $event_id) {
						$raid_ids = $this->pdh->get('raid', 'raidids4eventid', array($event_id));
						foreach($raid_ids as $raid_id) {
							$raid2event[$raid_id] = $event_id;
							$attendees = $this->pdh->get('raid', 'raid_attendees', array($raid_id));
							if( !is_array($attendees) ) continue;
							$value = $this->pdh->get('raid', 'value', array($raid_id, $dkp_id));
							foreach($attendees as $attendee){
								if(!isset($this->points[$attendee][$dkp_id]['single']['earned'][$event_id]))
									$this->points[$attendee][$dkp_id]['single']['earned'][$event_id] = 0;
								$this->points[$attendee][$dkp_id]['single']['earned'][$event_id] += $value;
							}
						}
					}
				}

				//spent
				if(is_array($evip['itempool_ids'])) {
					foreach($evip['itempool_ids'] as $itempool_id) {
						$item_ids = $this->pdh->get('item', 'item_ids_of_itempool', array($itempool_id));
						if(is_array($item_ids)) {
							foreach($item_ids as $item_id){
								$member_id = $this->pdh->get('item', 'buyer', array($item_id));
								$value = $this->pdh->get('item', 'value', array($item_id, $dkp_id));
								$raid_id = $this->pdh->get('item', 'raid_id', array($item_id));
								if(!isset($this->points[$member_id])) $this->points[$member_id] = array();
								if(!isset($this->points[$member_id][$dkp_id])) $this->points[$member_id][$dkp_id] = array('single' => array('earned' => array(), 'spent' => array()));
								$eventID = (isset($raid2event[$raid_id])) ? $raid2event[$raid_id] : 0;
								if(!isset($this->points[$member_id][$dkp_id]['single']['spent'][$eventID])) $this->points[$member_id][$dkp_id]['single']['spent'][$eventID] = array();
								
								if(!isset($this->points[$member_id][$dkp_id]['single']['spent'][$eventID][$itempool_id]))
									$this->points[$member_id][$dkp_id]['single']['spent'][$eventID][$itempool_id] = 0;
								$this->points[$member_id][$dkp_id]['single']['spent'][$eventID][$itempool_id] += $value;
							}
						}
					}
				}

				//adjustment
				if(is_array($evip['event_ids'])) {
					foreach($evip['event_ids'] as $event_id) {
						$adjustment_ids = $this->pdh->get('adjustment', 'adjsofeventid', array($event_id));
						foreach($adjustment_ids as $adjustment_id) {
							$member_id = $this->pdh->get('adjustment', 'member', array($adjustment_id));
							$value = $this->pdh->get('adjustment', 'value', array($adjustment_id, $dkp_id));
							if(!isset($this->points[$member_id][$dkp_id]['single']['adjustment'][$event_id]))
								$this->points[$member_id][$dkp_id]['single']['adjustment'][$event_id] = 0;
							$this->points[$member_id][$dkp_id]['single']['adjustment'][$event_id] += $value;
						}
					}
				}
			}
			$this->pdc->put('pdh_points_table', $this->points, null);
		}
		
		public function get_earned($member_id, $multidkp_id, $event_id=0, $with_twink=true){
			if($with_twink){
				if(!isset($this->arrCalculatedMulti[$member_id.'_'.$multidkp_id])){
					$this->calculate_multi_points($member_id, $multidkp_id);
					$this->arrCalculatedMulti[$member_id.'_'.$multidkp_id] = 1;
				}
				$with_twink = 'multi';
			} else {
				if(!isset($this->arrCalculatedSingle[$member_id.'_'.$multidkp_id])){
					$this->calculate_single_points($member_id, $multidkp_id);
					$this->arrCalculatedSingle[$member_id.'_'.$multidkp_id] = 1;
				}
				$with_twink = 'single';
			}
			
			if(!isset($this->points[$member_id][$multidkp_id][$with_twink]['earned'][$event_id])) return 0;
			return $this->points[$member_id][$multidkp_id][$with_twink]['earned'][$event_id];
		}

		public function get_html_earned($member_id, $multidkp_id, $event_id=0, $with_twink=true){
			return '<span class="positive">'.runden($this->get_earned($member_id, $multidkp_id, $event_id, $with_twink)).'</span>';
		}

		public function get_spent($member_id, $multidkp_id, $event_id=0, $itempool_id=0, $with_twink=true){
			if($with_twink){
				if(!isset($this->arrCalculatedMulti[$member_id.'_'.$multidkp_id]) ){
					$this->calculate_multi_points($member_id, $multidkp_id);
					$this->arrCalculatedMulti[$member_id.'_'.$multidkp_id] = 1;
				}
				$with_twink = 'multi';
			} else {
				if(!isset($this->arrCalculatedSingle[$member_id.'_'.$multidkp_id])){
					$this->calculate_single_points($member_id, $multidkp_id);
					$this->arrCalculatedSingle[$member_id.'_'.$multidkp_id] = 1;
				}
				$with_twink = 'single';
			}
			
			if(!isset($this->points[$member_id][$multidkp_id][$with_twink]['spent'][$event_id][$itempool_id])) return 0;
			return $this->points[$member_id][$multidkp_id][$with_twink]['spent'][$event_id][$itempool_id];
		}

		public function get_html_spent($member_id, $multidkp_id, $event_id=0, $itempool_id=0, $with_twink=true){
			return '<span class="negative">'.runden($this->get_spent($member_id, $multidkp_id, $event_id, $itempool_id, $with_twink)).'</span>';
		}

		public function get_adjustment($member_id, $multidkp_id, $event_id=0, $with_twink=true){
			if($with_twink){
				if(!isset($this->arrCalculatedMulti[$member_id.'_'.$multidkp_id])){
					$this->calculate_multi_points($member_id, $multidkp_id);
					$this->arrCalculatedMulti[$member_id.'_'.$multidkp_id] = 1;
				}
				$with_twink = 'multi';
			} else {
				if(!isset($this->arrCalculatedSingle[$member_id.'_'.$multidkp_id])){
					$this->calculate_single_points($member_id, $multidkp_id);
					$this->arrCalculatedSingle[$member_id.'_'.$multidkp_id] = 1;
				}
				$with_twink = 'single';
			}
			
			if(!isset($this->points[$member_id][$multidkp_id][$with_twink]['adjustment'][$event_id])) return 0;
			return $this->points[$member_id][$multidkp_id][$with_twink]['adjustment'][$event_id];
		}

		public function get_html_adjustment($member_id, $multidkp_id, $event_id=0, $with_twink=true){
			return '<span class="'.color_item($this->get_adjustment($member_id, $multidkp_id, $event_id, $with_twink)).'">'.runden($this->get_adjustment($member_id, $multidkp_id, $event_id, $with_twink)).'</span>';
		}
		
		
		/**
		 * Default without APA decay!
		 */
		public function get_current_history($member_id, $multidkp_id, $from=0, $to=PHP_INT_MAX, $event_id=0, $itempool_id=0, $with_twink=true, $blnWithAPA=false){
			if(!isset($this->decayed[$multidkp_id])) $this->decayed[$multidkp_id] = $this->apa->is_decay('current', $multidkp_id);

			if($blnWithAPA && $this->decayed[$multidkp_id]){
				echo "decayed";
				$data =  array(
						'id'			=> $multidkp_id.'_'.$member_id,
						'member_id'		=> $member_id,
						'dkp_id'		=> $multidkp_id,
						'event_id'		=> $event_id,
						'itempool_id'	=> $itempool_id,
						'with_twink'	=> ($with_twink) ? true : false,
						'date'			=> $to,
				);
				
				$toPoints = $this->apa->get_value('current', $multidkp_id, $to, $data);
				
				if($from > 0) {
					$fromPoints = $this->apa->get_value('current', $multidkp_id, $from, $data);
					return $toPoints - $fromPoints;
				} else {
					return $toPoints;
				}
				
			} else {			
				$arrPoints = $this->pdh->get('points_history', 'points', array($member_id, $multidkp_id, $from, $to, $event_id, $itempool_id, $with_twink));
				
				$earned = (float)$arrPoints['earned'][$event_id];
				$spent = (float)$arrPoints['spent'][$event_id][$itempool_id];
				$adj = (float)$arrPoints['adjustment'][$event_id];
				return (float)($earned - $spent + $adj);
			}
		}

		/**
		 * Default with APA decay!
		 */
		public function get_current($member_id, $multidkp_id, $event_id=0, $itempool_id=0, $with_twink=true, $with_apa=true){
			if(!isset($this->decayed[$multidkp_id])) $this->decayed[$multidkp_id] = $this->apa->is_decay('current', $multidkp_id);
			if(!isset($this->hardcap[$multidkp_id])) $this->hardcap[$multidkp_id] = $this->apa->is_hardcap('current_hardcap', $multidkp_id);

			if($with_apa && $this->decayed[$multidkp_id]) {
				$data =  array(
					'id'			=> $multidkp_id.'_'.$member_id,
					'member_id'		=> $member_id,
					'dkp_id'		=> $multidkp_id,
					'event_id'		=> $event_id,
					'itempool_id'	=> $itempool_id,
					'with_twink'	=> ($with_twink) ? true : false,
					'date'			=> $this->time->time,
				);
				$value = $this->apa->get_value('current', $multidkp_id, $this->time->time, $data);
			} else {			
				$value = ($this->get_earned($member_id, $multidkp_id, $event_id, $with_twink) - $this->get_spent($member_id, $multidkp_id, $event_id, $itempool_id, $with_twink) + $this->get_adjustment($member_id, $multidkp_id, $event_id, $with_twink));
			}
			
			if($with_apa && $this->hardcap[$multidkp_id]){
				$data =  array(
						'id'			=> $multidkp_id.'_'.$member_id,
						'val'			=> $value,
				);
				$value = $this->apa->get_value('current_cap', $multidkp_id, $this->time->time, $data);
			}
			return $value;
		}

		public function get_html_current($member_id, $multidkp_id,  $event_id=0, $itempool_id=0, $with_twink=true){
			$with_twink = (int)$with_twink;
			$current = $this->get_current($member_id, $multidkp_id, $event_id, $itempool_id, $with_twink);
			return '<span class="'.color_item($current).'">'.runden($current).'</span>';
		}

		public function get_html_caption_current($mdkpid, $showname, $showtooltip, $tt_options = array()){
			if($showname){
				$text = $this->pdh->get('multidkp', 'name', array($mdkpid));
			}else{
				$text = $this->pdh->get_lang('points', 'current');
			}

			if($showtooltip){
				$tooltip = $this->user->lang('events').": <br />";
				$events = $this->pdh->get('multidkp', 'event_ids', array($mdkpid));
				if(is_array($events)) foreach($events as $event_id) $tooltip .= $this->pdh->get('event', 'name', array($event_id))."<br />";
				$text = new htooltip('tt_event'.(int)$mdkpid, array_merge(array('content' => $tooltip, 'label' => $text), $tt_options));
			}
			return $text;
		}

		public function calculate_single_points($memberid, $multidkpid = 1){
			//already cached?
			$cacheEntry = $this->pdh->get('member', 'points', array($memberid, $multidkpid));
			if($cacheEntry !== false){
				if(!isset($this->points[$memberid][$multidkpid])) $this->points[$memberid][$multidkpid] = array();
				if(!isset($this->points[$memberid][$multidkpid]['single'])) $this->points[$memberid][$multidkpid]['single'] = array();
				$this->points[$memberid][$multidkpid]['single']['earned'][0] = $cacheEntry[0];
				$this->points[$memberid][$multidkpid]['single']['spent'][0][0] = $cacheEntry[1];
				$this->points[$memberid][$multidkpid]['single']['adjustment'][0] = $cacheEntry[2];

				return $this->points[$memberid][$multidkpid]['single'];
			}
			
			if(isset($this->points[$memberid][$multidkpid]['single']['earned'][0])){
				return $this->points[$memberid][$multidkpid]['single'];
			}

			//init
			$this->points[$memberid][$multidkpid]['single']['earned'][0] = 0;
			$this->points[$memberid][$multidkpid]['single']['spent'][0][0] = 0;
			$this->points[$memberid][$multidkpid]['single']['adjustment'][0] = 0;

			//calculate
			if(is_array($this->points[$memberid][$multidkpid]['single']['earned'])){
				foreach($this->points[$memberid][$multidkpid]['single']['earned'] as $event_id => $earned){
					$this->points[$memberid][$multidkpid]['single']['earned'][0] += $earned;
				}
			}

			if(is_array($this->points[$memberid][$multidkpid]['single']['spent'])){
				foreach($this->points[$memberid][$multidkpid]['single']['spent'] as $event_id => $itempools) {
					foreach($itempools as $itempool_id => $spent){
						$this->points[$memberid][$multidkpid]['single']['spent'][0][0] += $spent;
						if(!isset($this->points[$memberid][$multidkpid]['single']['spent'][$event_id][0])) $this->points[$memberid][$multidkpid]['single']['spent'][$event_id][0] = 0;
						$this->points[$memberid][$multidkpid]['single']['spent'][$event_id][0] += $spent;
						if(!isset($this->points[$memberid][$multidkpid]['single']['spent'][0][$itempool_id])) $this->points[$memberid][$multidkpid]['single']['spent'][0][$itempool_id] = 0;
						$this->points[$memberid][$multidkpid]['single']['spent'][0][$itempool_id] += $spent;
					}
				}
			}

			if(is_array($this->points[$memberid][$multidkpid]['single']['adjustment'])){
				foreach($this->points[$memberid][$multidkpid]['single']['adjustment'] as $event_id => $adjustment){
					$this->points[$memberid][$multidkpid]['single']['adjustment'][0] += $adjustment;
				}
			}

			$arrToSave = array($this->points[$memberid][$multidkpid]['single']['earned'][0],
					$this->points[$memberid][$multidkpid]['single']['spent'][0][0],
					$this->points[$memberid][$multidkpid]['single']['adjustment'][0]);
			
			$this->pdh->put('member', 'points', array($memberid, $multidkpid, $arrToSave));
			
			//Think about a snapshot
			if(isset($this->arrSnapshotTime[$memberid][$multidkpid])){
				$intTime = $this->arrSnapshotTime[$memberid][$multidkpid];
				if($intTime < (time()-POINTS_SNAPSHOT_TIME)) $this->get_add_snapshot($memberid, $multidkpid);
			} else {
				$this->get_add_snapshot($memberid, $multidkpid);
			}
			
			$this->pdh->process_hook_queue();
			
			return $this->points[$memberid][$multidkpid]['single'];
		}


		public function calculate_multi_points($memberid, $multidkpid = 1){
			//already cached?
			if(isset($this->points[$memberid][$multidkpid]['multi'])){
				return $this->points[$memberid][$multidkpid]['multi'];
			}
			
			if(!isset($this->arrCalculatedSingle[$memberid.'_'.$multidkpid])){
				$this->calculate_single_points($memberid, $multidkpid);
				$this->arrCalculatedSingle[$memberid.'_'.$multidkpid] = 1;
			}

			//twink stuff
			if($this->pdh->get('member', 'is_main', array($memberid))){
				$twinks = $this->pdh->get('member', 'other_members', $memberid);

				//main points
				$points = $this->calculate_single_points($memberid, $multidkpid);
				$this->points[$memberid][$multidkpid]['multi']['earned'][0] = $points['earned'][0];
				$this->points[$memberid][$multidkpid]['multi']['spent'][0] = $points['spent'][0];
				$this->points[$memberid][$multidkpid]['multi']['adjustment'][0] = $points['adjustment'][0];

				//Accumulate points from twinks
				if(!empty($twinks) && is_array($twinks)){
					foreach($twinks as $twinkid){
						$twinkpoints = $this->calculate_single_points($twinkid, $multidkpid);
						$this->points[$memberid][$multidkpid]['multi']['earned'][0] += $twinkpoints['earned'][0];
						$this->points[$memberid][$multidkpid]['multi']['adjustment'][0] += $twinkpoints['adjustment'][0];
						//calculate points of member+twinks per event / itempool
						foreach(array('earned', 'adjustment') as $type) {
							if(isset($this->points[$memberid][$multidkpid][$type]) && is_array($this->points[$memberid][$multidkpid][$type])) {
								foreach($this->points[$memberid][$multidkpid][$type] as $id => $point) {
									if(!isset($this->points[$memberid][$multidkpid]['multi'][$type][$id])) $this->points[$memberid][$multidkpid]['multi'][$type][$id] = 0;
									$this->points[$memberid][$multidkpid]['multi'][$type][$id] += $this->points[$twinkid][$multidkpid]['single'][$type][$id];
								}
							}
						}
						foreach($twinkpoints['spent'] as $event_id => $vals) {
							foreach($vals as $ip_id => $val) {
								if(!isset($this->points[$memberid][$multidkpid]['multi']['spent'][$event_id][$ip_id])) $this->points[$memberid][$multidkpid]['multi']['spent'][$event_id][$ip_id] = 0;
								$this->points[$memberid][$multidkpid]['multi']['spent'][$event_id][$ip_id] += $val;
							}
						}
					}
				} else {
					$this->points[$memberid][$multidkpid]['multi'] = $this->points[$memberid][$multidkpid]['single'];
				}
				return $this->points[$memberid][$multidkpid]['multi'];
			} else {
				$main_id = $this->pdh->get('member', 'mainid', array($memberid));
				if($main_id) $this->points[$memberid][$multidkpid]['multi'] = $this->calculate_multi_points($main_id, $multidkpid);
				return $this->points[$memberid][$multidkpid]['multi'];
			}
		}
		
		public function get_add_snapshot($intMemberID, $intMdkpID){
			$objQuery = $this->db->prepare("INSERT INTO __member_points :p")->set(array(
				'time' 		=> time(),
				'member_id' => $intMemberID,
				'mdkp_id'	=> $intMdkpID,
				'current'	=> $this->get_current($intMemberID, $intMdkpID, 0, 0, false, false),
				'earned'	=> $this->get_earned($intMemberID, $intMdkpID, 0, false),
				'spent'		=> $this->get_spent($intMemberID, $intMdkpID, 0, false),
				'adjustments' => $this->get_adjustment($intMemberID, $intMdkpID, 0, false),
				'misc'		=> $this->points[$intMemberID][$intMdkpID]['single'],
				'type'		=> 'snapshot',
			))->execute();
			$this->snapshot_mapping();
		}
		
		public function get_latest_snapshot($intMemberID, $intMdkpID, $intTime=false){
			if($intTime){
				$objQuery = $this->db->prepare("SELECT * FROM __member_points WHERE member_id=? AND mdkp_id=? AND time < ? ORDER by time DESC LIMIT 1;")->execute($intMemberID, $intMdkpID, $intTime);
			} else {
				$objQuery = $this->db->prepare("SELECT * FROM __member_points WHERE member_id=? AND mdkp_id=? ORDER BY time DESC LIMIT 1")->execute($intMemberID, $intMdkpID);
			}
			
			if($objQuery){
				$arrSnapshop = $objQuery->fetchAssoc();
				return $arrSnapshop;
			}
			
			return false;
		}
		
		private function snapshot_mapping(){
			$this->arrSnapshotTime = array();
			$objQuery = $this->db->prepare("SELECT MAX(time) as lastsnap, member_id, mdkp_id FROM __member_points GROUP BY member_id, mdkp_id DESC")->execute();
			if($objQuery){
				while($row = $objQuery->fetchAssoc()){
					if(!isset($this->arrSnapshotTime[(int)$row['member_id']])) $this->arrSnapshotTime[(int)$row['member_id']] = array();
					$this->arrSnapshotTime[(int)$row['member_id']][(int)$row['mdkp_id']] = $row['lastsnap'];
				}
			}
			$this->pdc->put('pdh_points_snapshot_mapping', $this->arrSnapshotTime);
			return $this->arrSnapshotTime;
		}
		
		public function get_delete_from_snapshot($intTime, $arrMembers=false){
			if($arrMembers && is_array($arrMembers)){
				$objQuery = $this->db->prepare("DELETE FROM __member_points WHERE time > ? AND member_id :in")->in($arrMembers)->execute($intTime);
			} else {
				$objQuery = $this->db->prepare("DELETE FROM __member_points WHERE time > ?")->execute($intTime);
			}
			
			
			//Recalculate Mapping
			$this->snapshot_mapping();
			return ($objQuery) ? true : false;
		}
		
	}//end class
}//end if
?>