<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Sync extends CI_Controller {

	function __construct()
	{
		parent::__construct();
		
		// Load the Library
		$this->load->library(array('user', 'user_manager'));
				
	}

	function index()
	{
		$this->load->helper('url');
		

		// Construct the Jurisdiction Model
		//$this->load->model('democracymap_model', 'democracymap');
		//$jurisdiction = $this->democracymap->jurisdictions[0];




		$scraper_rate = $this->config->item('scraper_rate');
		$scheduled_scrapers = $this->scheduled_scrapers($scraper_rate);

		// foreach scheduled scrapers as scraper
		foreach ($scheduled_scrapers as $scraper) {
			$this->sync_data($scraper);
		}

	}
	
	function scheduled_scrapers($scraper_rate = null) {
		
		
		$due_date = gmdate("Y-m-d H:i:s", strtotime("-$scraper_rate hours"));

		// get overdue scrapers
		
		$this->db->select('id, scraperwiki_name, last_sync');		
		$this->db->where('last_sync < ', $due_date);
		
		$query = $this->db->get('sync_scheduler');
		
		if ($query->num_rows() > 0) {
			return $query->result_array();
		} else {
			return false;
		}
	
	}


	function sync_data($scraper) {
		
		$this->load->helper('api');
		
		// get scraper metadata, tables, number of rows, last run date		
		$metadata = 'https://api.scraperwiki.com/api/1.0/scraper/getinfo?format=jsondict&name=' . $scraper['scraperwiki_name'] . '&version=-1';		
		$metadata = curl_to_json($metadata);
		
		if(!empty($metadata)) {

			$last_run = $metadata[0]['last_run'] . 'Z';

			// make sure it's run since we last pulled it
			if (date(strtotime($scraper['last_sync'])) < date(strtotime($last_run))) {
			
				
				if(!empty($metadata[0]['datasummary']['tables'])) {

					$tables = $metadata[0]['datasummary']['tables'];	

					// if(!empty($tables['jurisdictions'])) 
					
					if(!empty($tables['officials'])) {
						
						// Construct API call to scraper datastore with pagination
						
						// total
						$total = $tables['officials']['count'];
						$count = 1;
						$pagesize = 1000;
						
						while (($count * $pagesize) <= $total) {
							$offset = $count * $pagesize;							
							$url = 'https://api.scraperwiki.com/api/1.0/datastore/sqlite?format=jsondict&name=dmap2_city_representatives_-_california&query=select%20*%20from%20%60officials%60%20limit%20' . $offset . '%2C%20' . $pagesize;
							
							$officials = curl_to_json($url);
							
							if(!empty($officials)) {

								$ocdid = array();
								$skip = array();	

								foreach($officials as $official) {
									
									// probably unneeded, but just to make array key names more accessible/consistent
									$gov_name = urlify($official['government_name']);
									
									// if this gov's ocdid lookup has already failed and been logged, skip it
									if(!empty($skip[$gov_name])) {
										continue;
									}										
									
									// if we don't have an ocdid yet, get one
									if(empty($ocdid[$gov_name])) {	
																			
										// Get the OCDID for this jurisdiction
										$ocdid[$gov_name] = $this->determine_ocdid($official);										
										
										// if the lookup failed, skip it
										if ($ocdid[$gov_name] === false) {
											$skip[$gov_name] = true;
											continue;
										}

									}
									
									// ensure we actually have the ocdid for this gov
									if(!empty($ocdid[$gov_name])) {
										
										// check for current data from db to merge
										
										// add the ocdid to the object for the official
										$official['meta_ocd_id'] = $ocdid[$gov_name];
										
										// remove temporary fields
										unset($official['government_name']);
										unset($official['government_level']);
																				
										// get existing entry
										$this->db->select('*');		
										$this->db->where('meta_ocd_id', $official['meta_ocd_id']);			
										$this->db->where('name_full', $official['name_full']);
										$this->db->where('title', $official['title']);		

										$query = $this->db->get('scraped_officials');


										if ($query->num_rows() > 0) {
										   $official_existing = $query->row(); 		
										
											// Existing entry found, run a comparison
																				
											// remove any existing fields not used in new copy as to only compare changes 
											foreach ($official_existing as $field => $value) {												
												if(empty($official[$field])) {
													unset($official[$field]);													
												}												
											}
											
										
											// check to see if there are any differences between existing and new data
											$diff = array_diff_assoc($official, $official_existing);
																						
											// if there are no differences, then skip
											if(empty($diff)) {
												
												continue;
												
											// if there are differences
											} else {
												
												// add conflicts from diff to conflicts field
												if(!empty($official_existing['conflicting_data'])) {
													
													$conflicts = json_decode($official_existing['conflicting_data']);
													
													// check to see if any of these conflicts already exist													
													foreach ($diff as $diff_field => $diff_value) {
														
														
														$duplicate = array();
														
														foreach ($conflicts as $conflict) {

															// see if this same key/value from the new data was already logged
															if($diff_field == $conflict['field'] && $official[$diff_field] == $conflict['value'] ) {
																$duplicate['new'] = true;
															}
															
															// see if this same key/value from the old data was already logged															
															if($diff_field == $conflict['field'] && $official_existing[$diff_field] == $conflict['value'] ) {
																$duplicate['old'] = true;
															}															
															
																											
														}														
														
														// if the new value isn't already listed, add it
														if(empty($duplicate['new'])) {
															$conflicts[] = array(
																				"field" => $diff_field,
																				"value" => $official[$diff_field], 
																				"source" => $official['source'],
																				"timestamp" => gmdate("Y-m-d H:i:s")
																				);																		
																				
														} 
														
														// if the old value isn't already listed, add it														
														if(empty($duplicate['old'])) {
															$conflicts[] = array(
																				"field" => $diff_field,
																				"value" => $official_existing[$diff_field], 
																				"source" => NULL,
																				"timestamp" => gmdate("Y-m-d H:i:s")
																				);															
														}														
														
														
													}
													

													
													
												// otherwise create a fresh entry for conflicts	
												} else {
													
													$conflicts = array();
													
													foreach($diff as $diff_field => $value) {
														
														$conflicts[] = array(
																			"field" => $diff_field,
																			"value" => $official[$diff_field], 
																			"source" => $official['source'],
																			"timestamp" => gmdate("Y-m-d H:i:s")
																			);
																			
														$conflicts[] = array(
																			"field" => $diff_field,
																			"value" => $official_existing[$diff_field], 
																			"timestamp" => gmdate("Y-m-d H:i:s")
																			);														

													}
													
												}												
												
												
												// overwrite old values with new
												foreach($diff as $diff_field => $value) {
													$official_existing[$diff_field] = $value;
												}
												
												$official_existing['conflicting_data'] = json_encode($conflicts);
												
												// add new source or update timestamp on existing entry
												
												// update db
											
											}
											

										
										} else {
											
											// add as brand new entry in the database
											$this->db->insert('scraped_officials', $official);
											
										}									
										
										

										
										
									} 
									
									
									
								}
								
							}
									
							$count++;
						}
						
					}
								

				} else {
					// scraper data not available. eg scraperwiki not responding to this api call

					// log this error	
				}					
				
						

			}			
			
	
		} else {
			// scraper not found
			
			// log this error
			
			return false;
		}


	
		
		
	}
	
	
	function determine_ocdid($gov) {
		
		// make sure this works for officials and jurisdictions using slighty different field names (todo: possibly fix naming)
		if((empty($gov['level']) && !empty($gov['government_level'])) && (empty($gov['name']) && !empty($gov['government_name']))) {			
			$gov['level'] = $gov['government_level'];
			$gov['name'] = $gov['government_name'];						
		}
		
		if (!empty($gov['level']) && !empty($gov['name']) && !empty($gov['address_region'])) {

			// lookup ocdid		

			$this->db->select('ocd_id');		
			$this->db->where('name', $gov['name']);			
			$this->db->where('level', $gov['level']);
			$this->db->where('address_region', $gov['address_region']);		

			$query = $this->db->get('scraped_jurisdictions');

			// if successful return ocdid
			if ($query->num_rows() > 0) {
			   $row = $query->row(); 		
			   return $row->ocd_id;
			// if lookup fails, log it
			} else {

				$description = "Unable to lookup OCD ID for {$gov['level']} {$gov['name']} {$gov['address_region']}";

				// log this
				$data = array (
								'source' => $gov['source'],
								'type' => 'jurisdiction', 
								'description' => $description,
								'timestamp' => gmdate("Y-m-d H:i:s")								
								);

				$this->db->insert('sync_log', $data);			


				return false;
			}			
			
			
			
		} else {
			
			// lookup failed
			
			// log this 'unable to lookup ocdid, not enough info. Supplied info was: level: $level, name: $name, address_region: $address_region'
			
			return false;
		}
		
		

	
		
		
	}
	
	
	
	
}