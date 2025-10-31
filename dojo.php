<?php

/*
echo phpinfo();
die();
*/


//error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));

include_once("Traffic_Data/config.inc.php");
include_once("Traffic_Data/td.inc.php");
include_once("Traffic_Data/ac.inc.php");
include_once(SMARTY_LIB . '/Smarty.class.php');
include_once("Traffic_Data/controller/controller.class.php");
include_once("Traffic_Data/controller/dojo.class.php");
include_once("Traffic_Data/DataFileLoader.class.php");
include_once('QuickBooks/accounting.inc.v3.php');


require 'vendor/autoload.php';

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Core\ServiceContext;
use QuickBooksOnline\API\PlatformService\PlatformService;
use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Bill;
use QuickBooksOnline\API\Facades\Line;
use QuickBooksOnline\API\Data\IPPInvoice;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

use mikehaertl\wkhtmlto\Pdf;

use Aws\Rekognition\RekognitionClient;

$smarty = new Smarty();
$smarty->template_dir = array(SMARTY_HOME . '/templates','Traffic_Data/view');
$smarty->compile_dir = SMARTY_HOME . '/templates_c';
$smarty->cache_dir = SMARTY_HOME . '/smarty/cache';
$smarty->config_dir = SMARTY_HOME . '/configs';

session_start();

// server should keep session data for AT LEAST 1 hour
ini_set('session.gc_maxlifetime', 7200);

// each client should remember their session id for EXACTLY 1 hour
session_set_cookie_params(7200);


function respond($resultant)
{
	header('Content-Type: application/json');
	echo json_encode($resultant);
	exit(0);
}

function checkAuthKey()
{
	if($_REQUEST['key']!='9f8f6b17-468a-48aa-b62e-012458736e86'){
		$resultant['err'] = 1;
		$resultant['message'] = "Access denied!";
		respond($resultant);
	}
}

switch($_GET['act']){

	  case "touch":

		exit(0);
		break;

    case "update_required_by_unix":


				$count['required_by_unix'] = strtotime($_POST['required_by_unix']);
				$count['count_guid'] = $_POST['count_guid'];

				if(updateGenericDB($count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err)){
						$resultant['err'] = 0;
				} else {
					$resultant['err'] = 1;
					$resultant['message'] = "$msg: $err";
				}

				echo json_encode($resultant);
				exit(0);

		break;

		case "save_google_key":

		$key['uid'] = $_POST['uid'];
		$key['rotate_days'] = $_POST['rotate_days'];

		if(updateGenericDB($key, TABLE_GOOGLE_API_KEYS, 'uid', $msg, $err)){
			$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = "$err: $msg";
		}

		echo json_encode($resultant);
		exit(0);

		break;

		case "rotate_google_key":

		$key['uid'] = $_POST['uid'];

		//We need to delete the key in Google API, and re-create it with the same Display Name.
		include_once('Traffic_Data/google.inc.php');
		$projectId = 'spectrum-1536287828481';
    $manager = new GoogleApiKeyManager($projectId);

		$result = $manager->getKeyDetails($key['uid']);

		if(isset($result['error'])){
			$resultant['err'] = 1;
			$resultant['message'] = $result['error'];
			echo json_encode($resultant);
			exit(0);
		} else {

			//Key exists on Google Cloud.  Let's now delete and re-create it.
			$d = $manager->deleteKey($key['uid']);

			if(isset($d['error'])){

					$resultant['err'] = 1;
					$resultant['message'] = $d['error'];
					echo json_encode($resultant);
					exit(0);

			} else {

					//Key deleted successfully.  Let's create a new key.
					$new_key = $manager->createKey($result['displayName'], $result['restrictions']);
					$resultant['err'] = 0;
					$resultant['uid'] = $new_key['key_id'];
					$resultant['key_string'] = $new_key['api_key'];
					echo json_encode($resultant);
					exit(0);

			}

		}

		$resultant['err'] = 0;
		echo json_encode($resultant);
		exit(0);


		break;


		case "add_customer_requests":

				$counts = json_decode($_POST['counts']);

				$_SESSION['CUSTOMER_REQUEST_ITEMS'] = [];

				foreach($counts as $count){
					$_SESSION['CUSTOMER_REQUEST_ITEMS'][] = $count;
				}

				jsonTrace($_SESSION['CUSTOMER_REQUEST_ITEMS'], 1);
				$resultant['err'] = 0;
				/*
				if(updateGenericDB($count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err)){
						$resultant['err'] = 0;
				} else {
					$resultant['err'] = 1;
					$resultant['message'] = "$msg: $err";
				}
				*/

				echo json_encode($resultant);
				exit(0);

		break;


		case "customer_request":


				$user_guid = $_POST['user_guid'];
				$request_id = $_POST['request_id'];
				$customer_request_note = $_POST['customer_request_note'];
				$count_guid = $_POST['count_guid'];
				$employee_guid = $_SESSION['user_guid'];

				//debugTrace(-1);
				//debugTrace("$user_guid, $request_id, $customer_request_note, $count_guid, $employee_guid");

				if(sendCustomerRequestDB($user_guid, $request_id, $customer_request_note, $count_guid, $employee_guid, $err)){
					$resultant['err'] = 0;
				} else {
					$resultant['message'] = $err;
					$resultant['err'] = 1;
				}

				/*
				if(updateGenericDB($count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err)){
						$resultant['err'] = 0;
				} else {
					$resultant['err'] = 1;
					$resultant['message'] = "$msg: $err";
				}
				*/

				echo json_encode($resultant);
				exit(0);

		break;


		case "apply_pricing":

		$study_guid = $_POST['study_guid'];

		if($total = getTotalPriceDB($study_guid, $err)){
				$resultant['total'] = $total;
				$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);
		break;

		case "apply_po_to_study":

		$study_guid = $_POST['study_guid'];

		if($study = getStudyDB($study_guid)){

			//jsonTrace($study, 1);

			if($po = getPurchaseOrderDB($study['po_guid'], $err)){

				//jsonTrace($po);

				//build a lookup array for the po items:
				foreach($po['po_items'] as $key=>$po_item){
					  $po_array[$po_item['service_type_id']][] = array('unit' => $po_item['unit'], 'rate' => $po_item['rate'], 'number' => $po_item['item_no'], 'period_hrs' => $po_item['period_hrs']);
				}

				$tmc_counts = listCounts2DB($study_guid, $err);

				foreach($tmc_counts as $key=>$count){

					  if(count($po_array[$count['details']['service_type_id']]) == 1){
								//Exact po_item match
								//debugTrace("Exact PO match");
								$po_unit = $po_array[$count['details']['service_type_id']][0]['unit'];
								$po_number = $po_array[$count['details']['service_type_id']][0]['number'];
								$po_rate = $po_array[$count['details']['service_type_id']][0]['rate'];
								$period_hrs = $po_array[$count['details']['service_type_id']][0]['period_hrs'];
								if(!is_null($period_hrs)){
									 $quantity = (int)$count['details']['hours'] / $period_hrs;
								} else {
										$quantity = 1;
								}

								$price = $quantity * (float)$po_rate;
								$u_count = array('count_guid' => $count['details']['count_guid'], 'po_number' => $po_number, 'po_rate' => $po_rate, 'po_unit' => $po_unit, 'po_quantity' => $quantity, 'price' => $price);
								//jsonTrace($u_count);
								//debugTrace("{$count['details']['req_id']} - $po_unit, $po_number, $po_rate, count hours: {$count['details']['hours']} period hours: $period_hrs, $quantity, $price");
								//jsonTrace($u_count);
								updateGenericDB($u_count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err);

						} elseif (count($po_array[$count['details']['service_type_id']]) > 1){

								foreach($po_array[$count['details']['service_type_id']] as $j=>$po_item){

										if((int)$po_item['period_hrs'] == (int)$count['details']['hours']){
												$po_unit = $po_item['unit'];
												$po_number = $po_item['number'];
												$po_rate = $po_item['rate'];
												$period_hrs = $po_item['period_hrs'];
												$quantity = (int)$count['details']['hours'] / (int)$po_item['period_hrs'];
												$price = $quantity * (float)$po_rate;
												$u_count = array('count_guid' => $count['details']['count_guid'], 'po_number' => $po_number, 'po_rate' => $po_rate, 'po_unit' => $po_unit, 'po_quantity' => $quantity, 'price' => $price);
												//debugTrace("{$count['details']['req_id']} - $po_unit, $po_number, $po_rate, count hours: {$count['details']['hours']} period hours(po): {$po_item['period_hrs']}  $period_hrs, $quantity, $price");
												updateGenericDB($u_count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err);
												//break;
										}
								}

						} else {
							debugTrace("No match found!");
							//break;
						}

				}



			} else {
				$resultant['err'] = 1;
				$resultant['message'] = $err;
			}


		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}

		$resultant['err'] =1;
		$resultant['message'] = 'OK!';

		echo json_encode($resultant);

		exit(0);
		break;

		case "update_price":

		$price = $_POST['price'];
		$count_guid = $_POST['count_guid'];

		if($count = getCountDB($count_guid, $err)){

				$c['count_guid'] = $count_guid;
				$c['price'] = $price;

				if(updateGenericDB($c, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err)){
					$resultant['err'] = 0;
					$resultant['message'] = "Price updated";
				} else {
					$resultant['err'] = 1;
					$resultant['message'] = "$msg ($err)";
				}
		}

		$resultant['message'] = "Price updated";

		echo json_encode($resultant);
		exit(0);
		break;

		case "update_study_po":

		$po_guid = $_POST['po_guid'];
		$study_guid = $_POST['study_guid'];

		$study['study_guid'] = $study_guid;
		$study['po_guid'] = $po_guid;

		if(updateGenericDB($study, TABLE_STUDIES, 'study_guid', $msg, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Study PO updated";
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = "$msg ($err)";
		}

		$resultant['message'] = "Price updated";

		echo json_encode($resultant);
		exit(0);
		break;

		case "update_approach_name":

		$id = $_POST['id'];
		$name = $_POST['name'];

		if($r = updateApproachNameDB($id, $name, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Approach name updated";
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);
		break;

		case "update_leg_name":

		$leg['leg_id'] = $_POST['id'];
		$leg['name'] = $_POST['name'];

		if($r = updateLegDB($leg, $msg, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Approach name updated";
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $msg;
		}

		echo json_encode($resultant);
		exit(0);
		break;

		case "update_map_bounds":

	  $user_guid = $_SESSION['user_guid'];
	  $latitude = $_POST['latitude'];
		$longitude = $_POST['longitude'];
		$zoom = $_POST['zoom'];
		$err="";

		if(updateMapBoundsDB($user_guid, $latitude, $longitude, $zoom, $err)){
		    $resultant['err'] = 0;
		    $resultant['message'] = "Location bounds updated!";
		    echo json_encode($resultant);
		} else {
		    $resultant['err'] = 1;
		    $resultant['message'] = $err;
		    echo json_encode($resultant);
		}
		exit(0);
		break;

		case "move_counts":

		$study_guid = $_POST['study_guid'];
		$count_strn = $_POST['count_strn'];
		$study_id = $_POST['study_id'];

		$counts_array = explode(":", $count_strn);

		$origin_study = getStudyDB($study_guid, $err);


		if($origin_study['status'] == 'COMPLETED'){
			$resultant['err'] = 1;
			$resultant['message'] = "Study is already complete, cannot move / delete / add counts";
			echo json_encode($resultant);
			exit(0);
		}

		if($study = getStudyByIdDB($study_id, $err)){

			$new_study_guid = $study['study_guid'];

			foreach($counts_array as $key=>$value){
				$count['count_guid'] = $value;
				$count['study_guid'] = $new_study_guid;
				updateGenericDB($count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err);
			}
			//jsonTrace($study);
			$resultant['err'] = 0;
			echo json_encode($resultant);
		}  else {
			$resultant['err'] = 1;
			echo json_encode($resultant);
		}

		exit(0);

		break;

		case "merge_counts":

		$study_guid = $_POST['study_guid'];
		$count_strn = $_POST['count_strn'];
		$study_id = $_POST['study_id'];
		$counts_array = explode(":", $count_strn);
		$new_study_guid = $study['study_guid'];


		$init_location_id = false;
		$location_id_current = null;
		$resultant['err'] = 0;
		$resultant['message'] = '';

		$guid_array = [];
		$check_array = [];
		$counts = [];

		foreach($counts_array as $key=>$count_guid){

			if(strlen($count_guid) == 32){

			  $count = getCountDB($count_guid, $err);

				foreach($count['approaches'] as $approach) {
					  	$counts[$key]['approaches'][$approach['DirectionFromIntersection']] = $approach['ID'];
							$counts[$key]['approaches'][$approach['ID']] = $approach['DirectionFromIntersection'];
				}

				foreach($count['files'] as $file) {
					  	$counts[$key]['files'][] = $file['file_guid'];
				}

				foreach($count['movements'] as $d=>$movement) {
							$Entrance = $counts[$key]['approaches'][$movement['EntranceApproach']];
							$Exit = $counts[$key]['approaches'][$movement['ExitApproach']];
							$counts[$key]['movements']["$Entrance:$Exit"]['MovementID'] = $movement['ID'];
				}

				$counts[$key]['count_guid'] = $count['details']['count_guid'];

				foreach($count['approaches'] as $approach){
					$check_array[$key] .= $approach['DirectionFromIntersection'];
				}

				$guid_array[] = $count_guid;

				$schedules[] = $count['schedule'];

				//Check to make sure the data is at the same location.
				$location_id = $count['details']['location_id'];

				if(!$init_location_id){

					$location_id_current = $location_id;
					$init_location_id = true;

				} else {

					if($location_id_current != $count['details']['location_id']){
							$resultant['err'] = 1;
							$resultant['message'] = "Location IDs do not match!";
							echo json_encode($resultant);
							exit(0);

					} else {
						  $location_id_current = $location_id;
					}

				}

			}
		}

		$link = dbConnect();

		//Create the SQL statements based on the new counts array.
		for($i = 1; $i < sizeof($counts); $i++){
			//Let's make these counts invisilbe.
			$c = ['count_guid' => $counts[$i]['count_guid'], 'visible' => 0];
			updateGenericDB($c, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err);
			foreach($counts[$i]['movements'] as $key=>$movement){
					$sql = "UPDATE td_data_rows SET `count_guid` = '{$counts[0]['count_guid']}', `MovementID` = '" . $counts[0]['movements'][$key]['MovementID'] . "' WHERE `MovementID` = '${movement['MovementID']}'";
					mysqli_query($link, $sql);
			}
		}

		//Create the SQL statements based on the new counts array.
		for($i = 1; $i < sizeof($counts); $i++){
			foreach($counts[$i]['files'] as $k=>$file_guid){
					//deleteFileDB($file_guid, $err);
			}
		}

		for($i = 1; $i < sizeof($counts); $i++){
			foreach($counts[$i] as $key=>$count){
					$sql = "UPDATE td_manifest SET `count_guid` = '" . $counts[0]['count_guid']  . "' WHERE `count_guid` = '" . $counts[$i]['count_guid'] . "'";
					mysqli_query($link, $sql);
			}
		}

		/*
		if(arrayValuesSame($checksum_array)){
			debugTrace("Same!");
		} else {
			debugTrace("Not the same!");
		}*/

		$resultant['err'] = 0;
		$resultant['message'] = '';
		echo json_encode($resultant);
		exit(0);

		break;


		case "update_tracking_status":

		$count['count_guid'] = $_POST['count_guid'];
		$count['status'] = (int)$_POST['status'];

		$tracking_status = getTrackingStatusDB();
		jsonTrace($tracking_status);

		if(updateGenericDB($count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $err)){

			$history = ['user_guid' => $_SESSION['user_guid'], 'created_unix' => time(), 'action_id' => 3, 'note' => "Updating status of count with guid: {$count['count_guid']} to status {$count['status']}", 'object_guid' => $count['count_guid'], 'object_type' => 'count'];
			addGenericDB($history, TABLE_DEPLOYMENT_HISTORY, 'id', $msg, $errno);

			$resultant['err'] = 0;
			$resultant['message'] = "Tracking update";
			echo json_encode($resultant);
			exit(0);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $msg;
			echo json_encode($resultant);
			exit(0);
		}

		break;

		case "get_vendor_employees":

				$vendor_guid = $_POST['vendor_guid'];

				if($users = getVendorEmployeesDB($vendor_guid, $err)){
				    $resultant['err'] = 0;
				    $resultant['users'] = $users;
				    echo json_encode($resultant);
				} else {
				    $resultant['err'] = 1;
				    $resultant['message'] = $err;
				    echo json_encode($resultant);
				}
				exit(0);

		break;

		case "publish_counts":

				$users_array = json_decode($_POST['user_guids']);
				$counts_array = json_decode($_POST['count_guids']);

				if(publishCountsDB($counts_array, $users_array, $err)){
				    $resultant['err'] = 0;
				    $resultant['users'] = $users;
				    echo json_encode($resultant);
				} else {
				    $resultant['err'] = 1;
				    $resultant['message'] = $err;
				    echo json_encode($resultant);
				}

				exit(0);


		break;


	case "change_pane_state":


		$pane = $_POST['pane'];
		$state = $_POST['state'];
		$err = 0;

		if($user = getEmployeeDB($_SESSION['user_guid'], $error)){

			if($state == 'open'){
				switch($pane){

					case "north":

						$user['ui_panel_state'] = ($user['ui_panel_state']  ^ OPT_UI_TOP);
						break;

					case "east":

						$user['ui_panel_state'] = ($user['ui_panel_state'] ^ OPT_UI_RIGHT);
						break;

					case "west":

						$user['ui_panel_state'] = ($user['ui_panel_state']  ^ OPT_UI_LEFT);
						break;


				}

			} else {

				switch($pane){

					case "north":

						$user['ui_panel_state'] = ($user['ui_panel_state']  & ~ OPT_UI_TOP);
						break;

					case "east":

						$user['ui_panel_state'] = ($user['ui_panel_state']  & ~ OPT_UI_RIGHT);
						break;

					case "west":

						$user['ui_panel_state'] = ($user['ui_panel_state']  & ~  OPT_UI_LEFT);
						break;

				}

			}

			if (!updateEmployeeDB($user, $error)){
				$err = 1;
			}

		} else {
			$err = 1;
		}

		$resultant['err'] = $err;
		$resultant['err'] = $error;

		header('Content-Type: application/json');
		echo json_encode($resultant);
		exit(0);
		break;


	case "upload_data":
		$err = null;
		checkAuthKey();
		$filename = $_REQUEST['filename'];
		$data = $_REQUEST['data'];

		try{
			$loader = new DataFileLoader($filename);
			$data = explode("\n",$data);
			$rowCount = $loader->load($data);
		}catch (Exception $e){
			$resultant['err'] = 1;
			$resultant['message'] = "Upload failed: " .$e->getMessage();
			respond($resultant);
		}

		$resultant['err'] = 0;
		$resultant['message'] = "$rowCount rows loaded!";
		respond($resultant);
		break;

	case "load_action_plans":

		$start = (double)$_POST['start'];
		$end = (double)$_POST['end'];


		if($action_plans = getActionPlansDB($start, $end, $err)){
			$resultant['action_plans'] = $action_plans;
			$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;

			if(is_null($err)){
				$resultant['message'] = "No action plans found";
			} else {
				$resultant['message'] = $err;
			}

		}

		echo json_encode($resultant);
		exit(0);

		break;

	case "move_action":

		$action_guid = $_POST['action_guid'];
		$target = $_POST['target'];
		$target_guid = $_POST['target_guid'];
		$resultant['err'] = 0;

		if($action = getActionDB($action_guid, $err)){

			//unset owner / creator
			unset($action['owner']);
			unset($action['creator']);

			if($target == 'study'){
				$action['plan_guid'] = null;
			} else if ($target == 'plan'){
				$action['plan_guid'] = $target_guid;
			} else {
				$resultant['err'] = 1;
				$resultant['message'] = 'Invalid type';
			}

			if(!updateActionDB($action, $err)){
				$resultant['err'] = 1;
				$resultant['message'] = $err;
			}

		} else {

			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);

		break;

	case "update_actions_opts":

		$option = $_POST['option'];
		$value = $_POST['value'];
		$condition = $_POST['condition'];
		$resultant['err'] = 0;

		switch($option){

			case 'tech_guid':


				if(isset($_SESSION['ACTION_PLAN_SETTINGS']['techs'])){
					if($condition == 'false'){
						unset($_SESSION['ACTION_PLAN_SETTINGS']['techs'][$value]);
					} else {
						$tech = getEmployeeDB($value, $err);
						unset($tech['password']);
						unset($tech['qb_vendor_id']);
						$_SESSION['ACTION_PLAN_SETTINGS']['techs'][$value] = $tech;
					}
				}

				break;

			case 'display_deployments':


				if(isset($_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment'])){
					if($value == 'false'){
						$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment'] = 0;
					} else {
						$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment'] = 1;
					}
				}

				break;

			case 'display_pickups':


				if(isset($_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'])){
					if($value == 'false'){
						$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'] = 0;
					} else {
						$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'] = 1;
					}
				}

				break;

			case 'display_unassigned':

				if(isset($_SESSION['ACTION_PLAN_SETTINGS']['display_unassigned'])){
					if($value == 'false'){
						$_SESSION['ACTION_PLAN_SETTINGS']['display_unassigned'] = 0;
					} else {
						$_SESSION['ACTION_PLAN_SETTINGS']['display_unassigned'] = 1;
					}
				}

				break;

			case 'display_completed':

				if(isset($_SESSION['ACTION_PLAN_SETTINGS']['display_completed'])){
					if($value == 'false'){
						$_SESSION['ACTION_PLAN_SETTINGS']['display_completed'] = 0;
					} else {
						$_SESSION['ACTION_PLAN_SETTINGS']['display_completed'] = 1;
					}
				}

				break;


		}

		echo json_encode($resultant);
		exit(0);

		break;


	case "update_actions_order":

		$actions = $_POST['actions'];
		$resultant['err'] = 0;

		foreach($actions as $key=>$val){

			if($action = getActionDB($val, $err)){

				$action['ordinal'] = (int)$action['owner']['ordinal'] * 10000 + $key;

				unset($action['owner']);
				unset($action['creator']);

				if(!updateActionDB($action, $err)){
					$resultant['err'] = 1;
					$resultant['message'] = $err;
				}

			}

		}

		echo json_encode($resultant);
		exit(0);

		break;

	case "update_action_plan_notes":


		$resultant['err'] = 0;

		$action_plan_note = $_POST['action_plan_note'];
		$plan_guid = $_POST['plan_guid'];

		if(strlen($action_plan_note)>64000){

			$resultant['err'] = 1;
			$resultant['message'] = "Note exceeds 64kb!";

		} else {

			if($plan = getActionPlanDB($plan_guid, $err)){
				$plan['action_plan_note'] = $action_plan_note;

				if(!saveActionPlanDB($plan, $err)){
					$resultant['err'] = 1;
					$resultant['message'] = $err;
				}

			} else {

				$resultant['err'] = 1;
				$resultant['message'] = "Action plan";

			}

		}

		echo json_encode($resultant);
		exit(0);

		break;


	case "load_studies":

		$err = null;

		//Get the Sunday of this week.

		$techs = array();

		$employees = getEmployeesDB(1, $err);
		foreach($employees as $key=>$employee){
			if(USER_ROLE_TECH & $employee['user_role']){
				unset($employee['password']);
				unset($employee['qb_vendor_id']);
				$employee['view_deployments'] = true;
				$techs[$employee['user_guid']] = $employee;
			}
		}


		//Initialize filter settings
		if(!isset($_SESSION['ACTION_PLAN_SETTINGS'])){
			$_SESSION['ACTION_PLAN_SETTINGS']['techs'] = $techs;
		}

		$tech_suffix = '';

		foreach($_SESSION['ACTION_PLAN_SETTINGS']['techs'] as $key=>$val){
			$tech_suffix .=" OR td_actions.owner_guid = '$key'";
		}

		$tech_suffix .= " OR td_actions.owner_guid = NULL";
		$tech_suffix = (substr($tech_suffix, 4));

		if(!isset($_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment'])){
			$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment'] = 1;
		}

		if(!isset($_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'])){
			$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'] = 1;
		}

		$action_suffix = '';

		if($_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'] && $_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment']){

			$action_suffix = '';

		} else if(!$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['pickup'] && !$_SESSION['ACTION_PLAN_SETTINGS']['display_actions']['deployment']){

			$action_suffix = 'td_actions.action_name!=deployment AND td_actions.action_name!=pickup';

		} else {

			foreach($_SESSION['ACTION_PLAN_SETTINGS']['display_actions'] as $key=>$val){
				$action_suffix .= $_SESSION['ACTION_PLAN_SETTINGS']['display_actions'][$key] ? " OR td_actions.action_name = '$key'" : " OR td_actions.action_name != '$key'";
			}
			$action_suffix = (substr($action_suffix, 4));
		}

		$sunday = strtotime('this sunday', $_SESSION['EQUIPMENT_MAP_RANGE']['start']);

		$saturday = $sunday + 60*60*24*7;

		$actions = Array();

		if($link=dbConnect()){

			  $sql = "SELECT td_intersection_counts.ordinal, td_equipment.type AS equipment_type, td_intersection_counts.count_guid AS count_guid, td_equipment.name AS equipment_name, td_studies.status, td_intersection_counts.ordinal, td_studies.study_id, td_studies.name, MIN(td_recording_schedules.start_time) AS start_time, MAX(td_recording_schedules.end_time) AS end_time, manifest_guid, serial_no, td_studies.study_guid, td_studies.name,
						td_manifest.longitude, td_manifest.latitude, td_manifest.tripod, td_manifest.extension, td_manifest.carbon_fiber
						FROM td_recording_schedules
						LEFT JOIN td_intersection_counts ON td_intersection_counts.count_guid = td_recording_schedules.count_guid
						LEFT JOIN td_studies ON td_studies.study_guid = td_intersection_counts.study_guid
						LEFT JOIN td_manifest ON td_manifest.count_guid = td_intersection_counts.count_guid
						LEFT JOIN td_equipment ON td_equipment.equipment_guid = td_manifest.equipment_guid
						WHERE start_time >=$sunday AND end_time <= $saturday AND manifest_guid IS NOT NULL AND td_studies.status != 'CANCELLED' GROUP BY manifest_guid ORDER BY td_studies.study_id, td_intersection_counts.ordinal ASC";

			  if($result=mysqli_query( $link, $sql)){

					$manifests = array();

					while($record = mysqli_fetch_assoc($result)){

					   $record['actions'] = array('deployment' =>null, 'pickup'=>null);

					   $manifests[$record['manifest_guid']]=$record;

					}


					$sql_actions = "SELECT headshot_filename, study_id, td_studies.status, td_studies.study_guid, MIN(start_time) as start_time, MAX(end_time) AS end_time, action_guid, td_actions.ordinal, creator_guid, owner_guid, complete, priority, object_guid, object_name, action_name, plan_guid, td_employees.user_guid, username, first_name, last_name
															FROM td_actions
															LEFT JOIN td_employees ON td_employees.user_guid = td_actions.owner_guid
															LEFT JOIN td_manifest ON td_manifest.manifest_guid = td_actions.object_guid
															LEFT JOIN td_recording_schedules ON td_recording_schedules.count_guid = td_manifest.count_guid
															LEFT JOIN td_intersection_counts ON td_intersection_counts.count_guid = td_recording_schedules.count_guid
															LEFT JOIN td_studies ON td_studies.study_guid = td_intersection_counts.study_guid
															WHERE start_time >= $sunday AND end_time <= $saturday AND object_name = 'manifest' AND td_studies.status != 'CANCELLED' AND ($tech_suffix)  " . ($action_suffix!= '' ? " AND ($action_suffix) " : "") . "
															GROUP BY action_guid ORDER BY plan_guid, td_actions.ordinal ASC";

					if($result_2 = mysqli_query($link, $sql_actions)){

						if(mysqli_num_rows($result_2) > 0){

							while($action = mysqli_fetch_assoc($result_2)){
								$actions[] = $action;
								$manifests[$action['object_guid']]['actions'][$action['action_name']] = $action;

							}

						}
					}

					$resultant['actions'] = $actions;



					//add the action plans for the week to this study.
					if($action_plans = getActionPlansDB($sunday, $saturday, $err)){
						$resultant['action_plans'] = $action_plans;
					} else {
						$resultant['action_plans'] = NULL;
					}

					$resultant['err'] = 0;
					$resultant['equipment'] = $manifests;
					echo json_encode($resultant);
					exit(0);

			  } else {

					$resultant['err'] = 1;
					$resultant['message'] = mysqli_error($link);
					echo json_encode($resultant);
					exit(0);
			  }

		} else {

			$resultant['err'] = 1;
			$resultant['message'] = "Database connection error";
			echo json_encode($resultant);
		    exit(0);
		}

		break;

	case "assign_manifest_action":

		$manifest_guid = $_POST['manifest_guid'];
		$employee_guid = $_POST['employee_guid'];
		$method = $_POST['method'];
		$resultant['err'] = 0;

		if($employee = getEmployeeDB($employee_guid, $err)){
			if($manifest = getManifestDB($manifest_guid, $err)){

				if(!$action = updateManifestActionDB($manifest_guid, $method, $employee_guid, $err)){
					$resultant['err'] = 1;
					$resultant['message'] = "1028 $err";
				} else {
					$resultant['action'] = $action;
				}

			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "1036 $err";
			}
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = "1040 $err";
		}

		header('Content-Type: application/json');
		echo json_encode($resultant);
		exit(0);
		break;

	break;

	case "update_manifest_option":


		$manifest_guid = $_POST['manifest_guid'];
		$option = $_POST['option'];
		$value = $_POST['value'];

		$resultant['err'] = 0;

		if($m = getManifestDB($manifest_guid, $err)){

			if($option=='picked_up' || $option=='deployed'){

				$action_guid = ($option == 'picked_up' ? $m['pickup_action_guid']: $m['deployment_action_guid']);

				//Update the action associated to this manifest
				if($action_guid == null){

				} else {

					if($action = getActionDB($action_guid, $err)){

						unset($action['owner']);
						unset($action['creator']);

						if($_SESSION['user_guid']!= $action['owner_guid']){

							$resultant['err'] = 1;
							$resultant['message'] = "You are not the owner of this task!";

						} else {

							$action['complete'] = (int)$value;
							$action['last_modified'] = time();

							if(!updateActionDB($action, $err)){
								$resultant['err'] = 1;
								$resultant['message'] = $err;
							}

						}

					} else {

						$resultant['err'] = 1;
						$resultant['message'] = $msg;

					}

				}

			} else {

				$manifest['manifest_guid'] = $manifest_guid;
				$manifest[$option] = $value;

				if(!updateGenericDB($manifest, TABLE_MANIFESTS, 'manifest_guid', $msg, $err)){
					$resultant['err'] = 1;
					$resultant['message'] = $msg;
				}

			}

		} else {
			$resultant['err'] = 1;
			$resultant['message'] = "$err";

		}

		header('Content-Type: application/json');
		echo json_encode($resultant);
		exit(0);
		break;

	case "update_equipment_days":


		$val = (int)$_POST['val'];
		$state = $_POST['state'];

		if($state == 'true'){
			$_SESSION['EQUIPMENT_MAP_RANGE']['equipment_view_days'] += $val;
		} else {
			$_SESSION['EQUIPMENT_MAP_RANGE']['equipment_view_days'] -= $val;
		}

		$resultant['err'] = 0;

		header('Content-Type: application/json');
		echo json_encode($resultant);
		exit(0);
		break;

	case "update_action_plan_days":

		$val = (int)$_POST['val'];
		$state = $_POST['state'];

		if(isset($_SESSION['EQUIPMENT_MAP_RANGE']['action_plan_view_days'])){
			unset($_SESSION['EQUIPMENT_MAP_RANGE']['action_plan_view_days']);
		}

		if($state == 'true'){
			$_SESSION['EQUIPMENT_MAP_RANGE']['action_plan_view_days'] += $val;
		} else {
			$_SESSION['EQUIPMENT_MAP_RANGE']['action_plan_view_days'] -= $val;
		}

		$resultant['err'] = 0;

		header('Content-Type: application/json');
		echo json_encode($resultant);
		exit(0);
		break;

	case "get_study":
		$err = null;
		checkAuthKey();

		$study_id = substr($_REQUEST['study_id'],0,8);
		$resultant = getStudyByIdDB($study_id, $err);

		//check if the study exists
		if(!$resultant || !$resultant["study_guid"]){
			$resultant['err'] = 1;
			$resultant['message'] = "There was an error retrieving study '$study_id'!\n\n$err";
			respond($resultant);
		}

		$resultant['counts'] = getCountsDB($resultant["study_guid"], $err);
		//an empty counts array is not considered an error, only caring for SQL errors.
		if($err){
			$resultant['err'] = 1;
			$resultant['message'] = "There was an error retrieving study '$study_id'!\n\n$err";
			respond($resultant);
		}

		$resultant['err'] = 0;
		respond($resultant);
		break;

	case "get_equipment_schedule":
		$err = null;
		$serial_no = $_POST['serial_no'];
		$tolerance = 3600*3;//3 hours tolerance
		$start_time = strtotime($_POST['start_time']);//timestamp on the video filename

		$schedule = getEquipmentScheduleDB($serial_no, $start_time, $tolerance,$err);
		if($err)
			trigger_error("ERROR:\n$err");

		if($schedule){
			$resultant['err'] = 0;
			$resultant['skip'] = $schedule['start_time']-$start_time;
			$resultant['duration'] = $schedule['end_time']-$schedule['start_time'];
		}else{
			$resultant['err'] = 1;
			$resultant['message'] = 'Schedule not found: '.$err;
		}
		header('Content-Type: application/json');
		echo json_encode($resultant);
		exit(0);
		break;
}

if(!isset($_SESSION['user_guid']) && $_GET['act']!='authenticate_passkey'){
	$resultant['err'] = 1;
	$resultant['message'] = 'Not logged in.';
    echo json_encode($resultant);
    exit(0);
}

//Check Which Action To Perform:
if(isset($_GET['act'])){
    $act=trim($_GET['act']);
} else {
    $act=null;
}

$err=null;

switch($act){

	case "update_class_setting":
	   echo dojo::update_class_setting();
	break;

	case "update_class_parent":
		echo dojo::update_class_parent();
	break;

	case "scan_videos":

	$study_id = trim($_POST['study_id']);
	$study_guid = trim($_POST['study_guid']);

	$s3 = new S3Client([
		'region' => 'us-east-1',
		'version' => 'latest',
		'credentials' =>array(
			'key'    => AWS_ACCESS_KEY_ID,
			'secret' => AWS_SECRET_ACCESS_KEY
		)
	]);

	// Use the plain API (returns ONLY up to 1000 of your objects).
	try {
	    $objects = $s3->listObjects([
	        'Bucket' => "spectrum_videos",
					'Prefix' => $study_id
	    ]);

	} catch (S3Exception $e) {
			$message = $e->getMessage() . PHP_EOL;
	}

	$records = count($objects['Contents']);

	$matches = 0;

	$message = "Scan results: for $study_id\n\n";

  //Build Array:

	if($link = dbConnect()){

		$sql = "
		SELECT td_intersection_counts.count_guid, td_manifest.equipment_guid, td_manifest.manifest_guid, td_equipment.serial_no, td_videos.filename, start_time, end_time FROM td_manifest
		LEFT JOIN td_equipment ON td_equipment.equipment_guid = td_manifest.equipment_guid
		LEFT JOIN td_videos ON td_videos.manifest_guid = td_manifest.manifest_guid
		LEFT JOIN td_intersection_counts ON td_intersection_counts.count_guid = td_manifest.count_guid
		LEFT JOIN td_recording_schedules ON td_recording_schedules.count_guid = td_intersection_counts.count_guid
		LEFT JOIN td_studies ON td_studies.study_guid = td_intersection_counts.study_guid
		WHERE td_studies.study_guid = '$study_guid'";

		if($rs = mysqli_query($link, $sql)){

				$manifests = array();

				while($record = mysqli_fetch_assoc($rs)){
						 $manifests[]=$record;
				}

				//jsonTrace($manifests, 1);
				//jsonTrace($result, 1);

				if(count($manifests) > 0){

					foreach ($objects['Contents']  as $object) {

							$filename = $object['Key'];

							//Extract video info.
							$ext = array();

	            if(!preg_match('/\.[^\.]+$/i', $filename, $ext)){
	                  $err = "Invalid file extension";
	                  return FALSE;
	            }

	            $pre_filename=str_replace($ext[0], "", $filename);

	            //Explode filename string
	            $filename_array = explode("_", $pre_filename);
	            $study_id = trim($filename_array[0]);
	            $type = trim($filename_array[1]);
	            $serial = trim($filename_array[2]);
	            $date = trim($filename_array[3]);
	            $start_time = trim($filename_array[4]);
	            $start_date_unix = strtotime("$date $start_time");

							$item = array_search($filename, array_column($manifests, 'filename'));

							if(!$item === false){

									$message .= "($item) Already found a match for found a video match already for $value\n";

							} else {

								foreach($manifests as $j=>$manifest) {

										if($manifest['serial_no'] == $serial && ($start_date_unix >= ($manifest['start_time'] - 5400) && $start_date_unix < $manifest['end_time'])){
											 $videoLink = array(
											 "video_guid" => createUniqueKey(),
											 "manifest_guid" => $manifest['manifest_guid'],
											 "location" => "ST",
											 "equipment_guid" => $manifest['equipment_guid'],
											 "serial_no" => $manifest["serial_no"],
										   "filename" => (string)$filename);

											 if(addGenericDB($videoLink, TABLE_VIDEOS, 'video_id', $msg, $err)){
												  $message .= "Found a match for $filename\n";
												  $matches++;
											 } else {
												  $message .= "Could not find a match for $filename ($err)\n";
											 }

										}

								}
					    }
						}

					} else {

						$message = "No manifests to scan";
					}


		} else {
				$message = mysqli_error($link);
		}


	}

	$resultant['message'] = $message;
	$resultant['matches'] = $matches;
	echo json_encode($resultant);

	break;

	case "update_diagnostic_warning":

	$warning = array();
	$warning['warning_guid'] = trim($_POST['warning_guid']);
  $warning['state'] = 2;


	if(updateGenericDB($warning, TABLE_DIAGNOSTIC_WARNINGS, 'warning_guid', $err, $msg)){

		$resultant['err'] = 0;
		echo json_encode($resultant);
		exit(0);
	} else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
		exit(0);
	}

	break;

	case "restore_diagnostic_warnings":

	$count_guid = trim($_POST['count_guid']);
	$report_guid = trim($_POST['report_guid']);

	if(restoreDiagnosticWarningsDB($report_guid, $count_guid, $err)){
		$resultant['err'] = 0;
		echo json_encode($resultant);
		exit(0);
	} else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
		exit(0);
	}

	break;

	case "delete_diagnostic_report":

	$report_guid = trim($_POST['report_guid']);

	if(deleteDiagnosticReportDB($report_guid, $err)){
		$resultant['err'] = 0;
		echo json_encode($resultant);
		exit(0);
	} else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
		exit(0);
	}

	break;

	case "delete_diagnostic_warning":

	$warning_guid = trim($_POST['warning_guid']);

	if(deleteDiagnosticWarningDB($warning_guid, $err)){
		$resultant['err'] = 0;
		echo json_encode($resultant);
		exit(0);
	} else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
		exit(0);
	}

	break;


	case "run_diagnostics":

		$study_guid = trim($_POST['study_guid']);

		if(runDiagnosticsDB($study_guid, $err)){
			$resultant['err'] = 0;
			echo json_encode($resultant);
			exit(0);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
			exit(0);
		}

	break;

	case "scan_xml":

	    $study_guid = trim($_POST['study_guid']);

			$study = getStudyDB($count['details']['study_guid'], $err);
			$spt_study_id = $study['study_id'];

			if(strstr($spt_study_id, 'PEL')){
			    $api_id = API_PUBLIC_USER_ID;
			    $api_key = API_PUBLIC_KEY;
			} elseif (strstr($spt_study_id, 'PTS')){
					$api_id = API_PTSL_ID;
					$api_key = API_PTSL_KEY;
			} else {
			    $api_id = API_USER_ID;
			    $api_key = API_KEY;
			}

	    if($counts = getCountsDB($study_guid, $err)){

		//Got Counts
		$txt="Scanned " . count($counts) . " counts\n\r";
		$skipped = 0;

		foreach($counts as $key=>$count){

		    $study_id = (int)$count['mio_id'];

		    //Check to see if xml already exists for this count.
		    if(checkXMLDataDB($count['count_guid'], $err)){
			//$txt .=  "Skipping " . $count['major_street'] . " & " . $count['minor_street'] . "\n\r";
			$skipped++;
		    } else {

			if($study_id > 0){
			    //Mio ID available.  Scan XML
			    if($xml = scanXMLDataDB($api_id, $api_key, $study_id, 15, $err)){
				if(writeXMLDB($count['count_guid'], 15, $xml, 'MIO', $err2)){
				    $txt .=  "Successfully scanned " . $count['major_street'] . " & " . $count['minor_street'] . " XML file\n\r";
				} else {
				    $txt .=  "error writing xml: $err2\n\r";
				}
			    } else {
				$txt .=  "error writing xml: $err\n\r";
			    }
			}

		    }
		}

		$txt.="Skipped $skipped files\n\r";

		$resultant['err'] = 1;
		$resultant['message'] = "$txt";
		echo json_encode($resultant);

	    } else {

		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
	    }


	break;

	case "scan_weather":

	    $study_guid = trim($_POST['study_guid']);

	    if($counts = getCountsDB($study_guid, $err)){

				//Got Counts
				$txt="Scanned weather for " . count($counts) . " counts\n\r";
				$skipped = 0;

				foreach($counts as $key=>$count){

						$current_time = time();
						$sched_count = count($count['schedule']);

						if($sched_count){

							  if($count['schedule'][0]['start_time']<=$current_time){

									$current_weather = getCurrentWeatherDB('study_guid', $count['weather_study_guid'], $count['schedule'], $err);

									foreach($count['schedule'] as $key=>$val){

										  $start_time = $val['start_time'];
										  $end_time = $val['end_time'];

										  foreach($current_weather as $i=>$weather){

												$unix_time = $weather['unix_time'];

												if($unix_time >= $start_time && $unix_time <= $end_time){
													$count['schedule'][$key]["weather_desc"] = $weather['weather'] . " (" . $weather['temp_c'] . " &deg;C)";
													break;
												}
										  }

										  updateScheduleDB($count['schedule'][$key], $err);
									}
							  }
						} else {
							  $count['current_weather'] = null;
						}

				}

				$resultant['err'] = 0;
				$resultant['message'] = "$txt";
				echo json_encode($resultant);

	    } else {

				$resultant['err'] = 1;
				$resultant['message'] = $err;
				echo json_encode($resultant);
	    }


	break;

    case "update_weather":

		$schedule_id = trim($_POST['schedule_id']);
		$weather_desc = trim($_POST['weather_desc']);

		if($schedule = getScheduleDB($schedule_id, $err)){

				$schedule['weather_desc'] = str_replace('&Acirc;', '', htmlentities($weather_desc));

				if(updateScheduleDB($schedule, $err)){
				    $resultant['err'] = 0;
				    $resultant['message'] = "done!";
				    echo json_encode($resultant);
				} else {
					$resultant['err'] = 1;
				    $resultant['message'] = $err;
				    echo json_encode($resultant);
				}

		} else {
				$resultant['err'] = 1;
				$resultant['message'] = $err;
				echo json_encode($resultant);
		}

    break;


	case "scan_xml_count":

		$count_guid = trim($_POST['count_guid']);
		$count = getCountDB($count_guid,$err);
		$study_id = (int)$count['details']['mio_id'];
		$resultant['err'] = 1;

		$study = getStudyDB($count['details']['study_guid'], $err);
		$spt_study_id = $study['study_id'];

		if(strstr($spt_study_id, 'PEL')){
		    $api_id = API_PUBLIC_USER_ID;
		    $api_key = API_PUBLIC_KEY;
		} else if (strstr($spt_study_id, 'PTS')){
				$api_id = API_PTSL_ID;
				$api_key = API_PTSL_KEY;
		} else {
		    $api_id = API_USER_ID;
		    $api_key = API_KEY;
		}

		if(checkXMLDataDB($count_guid, $err) === FALSE){
			if($mioDuplicate = getStudyIDByMioID($study_id,$err)){
				if(count($mioDuplicate) > 2){
					$resultant['message'] = "Mio ID used in multiple studies: ".$mioDuplicate[0]['study_id'].", ".$mioDuplicate[1]['study_id']."\n\r";
					echo json_encode($resultant);
					die();
				}
			}

			if($study_id > 0){//Mio ID available.  Scan XML
				if($xml = scanXMLDataDB($api_id, $api_key, $study_id, 15, $err)){
					if(writeXMLDB($count['details']['count_guid'], 15, $xml, 'MIO', $err2)){
						$resultant['err'] = 0;
					} else {

						$resultant['message'] = "Error scanning XML: $err2\n\r";
					}
				} else {
					$resultant['message'] =  "Error writing XML: $err\n\r";
				}
			}else{
				$resultant['message'] =  "Error: Mio ID not set\n\r $err\n\r";
			}
		} else{
			$resultant['message'] =  "Error: XML already exists\n\r $err\n\r";
		}

		echo json_encode($resultant);
	break;

	case "delete_xml_count":

	    $count_guid = trim($_POST['count_guid']);
	    $source = $_POST['source'];
		$resultant['err'] = 1;
		$txt = '';
	    if($count = getCountDB($count_guid, $err)){
			if(deleteXMLDB($count['details']['count_guid'], $source, $err)){
				$resultant['err'] = 0;
				$txt .=  "Deleted $source Data for " . $count['details']['major_street'] . " & " . $count['details']['minor_street'] . "\n\r";
			} else {
				$resultant['err'] = 1;
			}
			$resultant['message'] = "$txt\n\r$err";
	    }else{
	    		$resultant['message'] = "Couldn't not retrieve count!\n\r$err";
	    }
		echo json_encode($resultant);
		exit(0);

	break;

	case "delete_xml":

	    $study_guid = trim($_POST['study_guid']);
	    if($counts = getCountsDB($study_guid, $err)){

		//Got Counts
		$txt="Scanned " . count($counts) . " counts\n\r";
		$skipped = 0;

		foreach($counts as $key=>$count){

		    //Check to see if xml already exists for this count.
		    if(deleteXMLDB($count['count_guid'], 'MIO', $err)){
					$txt .=  "Deleted XML for " . $count['major_street'] . " & " . $count['minor_street'] . "\n\r";
		    } else {
					debugTrace($err);
		    }
		}

		$resultant['err'] = 1;
		$resultant['message'] = "$txt";
		echo json_encode($resultant);

	    } else {

		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
	    }

	break;

	case "send_rating_email":

        $study_guid=$_POST['study_guid'];
        $rating_email = $_POST['rating_email'];

	if($study = getStudyDB($study_guid, $err)){

	    $err="";

	    $smarty->assign('study_guid', $study_guid);
	    $smarty->assign('rating_email', $rating_email);
	    $smarty->assign('name', $study['name']);
	    $smarty->assign('study_id', $study['study_id']);

	    $body=$smarty->fetch('request_feedback.tpl');

	    if(sendRatingEmailDB($study_guid, $rating_email, $body, $err)){
		$resultant['err'] = 0;
		$resultant['last_send'] = time();
		echo json_encode($resultant);
	    } else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
	    }

	} else {
		$resultant['err'] = 1;
		$resultant['message'] = "Invalid study id";
		echo json_encode($resultant);
	}

	break;

	case "send_confirmation_email":

        $study_guid=$_POST['study_guid'];
	$quote_guid=$_POST['quote_guid'];
        $confirmation_email = $_POST['confirmation_email'];

	if($study = getStudyDB($study_guid, $err)){

	    $counts = getCountsDB($study_guid, $err);
	    $study_opts = $counts[0]['study_opts'];

	    foreach($counts as $key=>$value){
		$study_opts = $value['study_opts'];
	    }

	    $smarty->assign("OPT_72_HR", OPT_72_HR);
	    $smarty->assign("OPT_48_HR", OPT_48_HR);
	    $smarty->assign("OPT_24_HR", OPT_24_HR);
	    $smarty->assign("OPT_STORAGE_STANDARD", OPT_STORAGE_STANDARD);
	    $smarty->assign("OPT_STORAGE_AUTORENEW", OPT_STORAGE_AUTORENEW);
	    $smarty->assign("OPT_CLASS_NONE", OPT_CLASS_NONE);
	    $smarty->assign("OPT_CLASS_CT", OPT_CLASS_CT);
	    $smarty->assign("OPT_CLASS_CMH", OPT_CLASS_CMH);
	    $smarty->assign("OPT_CLASS_BCT", OPT_CLASS_BCT);
	    $smarty->assign("OPT_CLASS_BO", OPT_CLASS_BO);
	    $smarty->assign("OPT_CLASS_BCMH", OPT_CLASS_BCMH);
	    $smarty->assign("OPT_SEPARATE_B", OPT_SEPARATE_B);
	    $smarty->assign("OPT_MERGE_CB", OPT_MERGE_CB);
	    $smarty->assign("OPT_SEPARATE_PB", OPT_SEPARATE_PB);
	    $smarty->assign("OPT_MERGE_PB", OPT_MERGE_PB);
	    $smarty->assign("OPT_COUNT_PEDS", OPT_COUNT_PEDS);
	    $smarty->assign("OPT_DIRECTION_PED", OPT_DIRECTION_PED);
	    $smarty->assign("OPT_SEPARATE_PED", OPT_SEPARATE_PED);

	    //Send Quote confirmation
	    $quote = getQuoteDB($quote_guid, $err);
	    $smarty->assign('quote', $quote);
	    $smarty->assign('study_opts', $study_opts);
	    $smarty->assign('name', $study['name']);
	    $smarty->assign('count_list', $counts);
	    $employee = getEmployeeDB($study['employee_guid'], $err);
	    $smarty->assign('employee', $employee);

	    $body=$smarty->fetch('confirmation_email.tpl');

	    if(reSendOrderConfirmationEmailDB($confirmation_email, $quote, $body, $err)){
		$resultant['err'] = 0;
		echo json_encode($resultant);
	    } else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
	    }


	} else {
		$resultant['err'] = 1;
		$resultant['message'] = "Invalid study id";
		echo json_encode($resultant);
	}

	break;


	case "send_quote_email":

        $study_guid=$_POST['study_guid'];
		$quote_guid=$_POST['quote_guid'];
        $quote_email = $_POST['quote_email'];

	$smarty->assign("OPT_72_HR", OPT_72_HR);
	$smarty->assign("OPT_48_HR", OPT_48_HR);
	$smarty->assign("OPT_24_HR", OPT_24_HR);
	$smarty->assign("OPT_STORAGE_STANDARD", OPT_STORAGE_STANDARD);
	$smarty->assign("OPT_STORAGE_AUTORENEW", OPT_STORAGE_AUTORENEW);
	$smarty->assign("OPT_CLASS_NONE", OPT_CLASS_NONE);
	$smarty->assign("OPT_CLASS_CT", OPT_CLASS_CT);
	$smarty->assign("OPT_CLASS_CMH", OPT_CLASS_CMH);
	$smarty->assign("OPT_CLASS_BCT", OPT_CLASS_BCT);
	$smarty->assign("OPT_CLASS_BO", OPT_CLASS_BO);
	$smarty->assign("OPT_CLASS_BCMH", OPT_CLASS_BCMH);
	$smarty->assign("OPT_SEPARATE_B", OPT_SEPARATE_B);
	$smarty->assign("OPT_MERGE_CB", OPT_MERGE_CB);
	$smarty->assign("OPT_SEPARATE_PB", OPT_SEPARATE_PB);
	$smarty->assign("OPT_MERGE_PB", OPT_MERGE_PB);
	$smarty->assign("OPT_COUNT_PEDS", OPT_COUNT_PEDS);
	$smarty->assign("OPT_DIRECTION_PED", OPT_DIRECTION_PED);
	$smarty->assign("OPT_SEPARATE_PED", OPT_SEPARATE_PED);

	if($quote = getQuoteByStudyDB($study_guid, $err)){

	    $counts = getCountsDB($study_guid, $err);
	    $study = getStudyDB($study_guid, $err);

			$customer = getCustomerDB($study['customer_guid'], $err);


	    foreach($counts as $key=>$value){
		$opts[] = $value['study_opts'];
	    }

	    $opts = array_unique($opts);

	    if(count($opts)==1){
		$study_opts = $opts[0];
	    } else {
		$study_opts = "err";
	    }

	    $smarty->assign('study_opts', $study_opts);
	    $smarty->assign('quote', $quote);
	    $smarty->assign('study', $study);
	    $smarty->assign('count_list', $counts);

	    if(strlen($study['employee_guid'])==32){
		$employee = getEmployeeDB($study['employee_guid'], $err);
		$smarty->assign('employee', $employee);
	    } else {
		$smarty->assign('employee', 0);
	    }

	    $total_hours = (float)$quote['total_hours'];
	    $credit_rate = (float)$quote['credit_rate'];
	    $intersection_count = (int)$quote['intersection_count'];

	    $smarty->assign('turnaround_24_price', $total_hours * 6 * $credit_rate);
	    $smarty->assign('turnaround_48_price', $total_hours * 3 * $credit_rate);
	    $smarty->assign('premium_classifications_price', $total_hours * 3 * $credit_rate);
	    $smarty->assign('basic_classifications_price', $total_hours * 1 * $credit_rate);
	    $smarty->assign('count_peds_price', $total_hours * 1 * $credit_rate);
	    $smarty->assign('ped_direction_price', $total_hours * 1 * $credit_rate);
	    $smarty->assign('separate_peds_price', $total_hours * 3 * $credit_rate);
	    $smarty->assign('separate_buses_price', $total_hours * 3 * $credit_rate);
	    $smarty->assign('separate_pedal_bikes_price', $total_hours * 3 * $credit_rate);
	    $smarty->assign('premium_storage_price', $intersection_count * 5);
	    $smarty->assign('quote_email', $quote_email);
			$smarty->assign('company_name', $customer['company_name']);

	    $body=$smarty->fetch('request_quote.tpl');

	    if(sendQuoteEmailDB($quote_guid, $quote_email, $body, $err)){
		$quote = getQuoteDB($quote_guid, $err);
		$quote['recipient'] = $quote_email;
		$quote['date_sent']=time();
		@updateQuoteDB($quote, $err);
		$resultant['err'] = 0;
		$resultant['last_send'] = time();
		echo json_encode($resultant);
	    } else {
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		echo json_encode($resultant);
	    }

	} else {

	    $resultant['err'] = 1;
	    $resultant['message'] = "Invalid study id";
	    echo json_encode($resultant);
	}

	break;

	case "add_request_image":

		//jsonTrace($_POST['request_guid'], 1);
		//jsonTrace($_FILES['image']);

		$resultant['err'] = 0;
		$request_guid = $_POST['request_guid'];
		$image_guid = createUniqueKey();

		include_once("HTTP/Upload.php");
		$upload = new HTTP_Upload("en");

		$file = $upload->getFiles("image");
		$tmp_name = $file->getProp("tmp_name");
		$ext = "." . $file->getProp("ext");
		$size = $file->getProp("size");
		$type = $file->getProp("type");
		$filename = $file->getProp("name");
		$date_upload=time();

		// Find the position of the last dot in the filename
		$last_dot_position = strrpos($filename, '.');

		// Use substr to extract everything before the last dot
		$fname = substr($filename, 0, $last_dot_position);
		$thumb_name = $fname . "_" . "thumb";


		if($file->isValid()){

			$imagePath = "./files/images/" . $image_guid;

			if(move_uploaded_file($tmp_name, $imagePath)){
					//Move to S3 storage.

					//read in the data from the new location

					$fh = fopen( $imagePath, 'rb' );
					$contents = fread($fh, filesize($imagePath));
					$mime_content = mime_content_type($fh);
					fclose( $fh );
					$key = "$image_guid/$filename";
					$key_thumb = "$image_guid/$thumb_name$ext";

					$imageInfo = getimagesize($imagePath);
					$mime_type = $imageInfo['mime'];

					list($originalWidth, $originalHeight, $imageType) = getimagesize($imagePath);

					//debugTrace("$originalWidth, $originalHeight, $imageType");
					$newWidth = round(200 * $originalWidth/$originalHeight);

					$newHeight = 200;//$originalHeight/4;

					$resizedImage = imagecreatetruecolor($newWidth, $newHeight);
					$newImagePath = "./files/images/$thumb_name$ext";

					switch ($imageType) {
					    case IMAGETYPE_JPEG:
					        $originalImage = imagecreatefromjpeg($imagePath);
					        break;

					    case IMAGETYPE_PNG:
									$originalImage = imagecreatefrompng($imagePath);
					        // Set PNG to retain transparency
					        imagealphablending($resizedImage, false);
					        imagesavealpha($resizedImage, true);
									break;

					    case IMAGETYPE_GIF:

									$originalImage = imagecreatefromgif($imagePath);
					        // Set GIF to retain transparency
					        $transparentIndex = imagecolortransparent($originalImage);
					        if ($transparentIndex >= 0) {
					            $transparentColor = imagecolorsforindex($originalImage, $transparentIndex);
					            $transparentIndex = imagecolorallocate($resizedImage, $transparentColor['red'], $transparentColor['green'], $transparentColor['blue']);
					            imagefill($resizedImage, 0, 0, $transparentIndex);
					            imagecolortransparent($resizedImage, $transparentIndex);
					        }

									break;

					    default:
								$resultant['err'] = 1;
								$resultant['message'] = "Unsupported image type";
								echo json_encode($resultant);
								exit(0);

					}


					imagecopyresampled($resizedImage, $originalImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

					switch ($imageType) {
					    case IMAGETYPE_JPEG:
					        imagejpeg($resizedImage, $newImagePath, 90);  // Quality is 90 for JPEG
					        break;
					    case IMAGETYPE_PNG:
					        imagepng($resizedImage, $newImagePath);  // PNG does not use a quality parameter
					        break;
					    case IMAGETYPE_GIF:
					        imagegif($resizedImage, $newImagePath);  // No quality parameter for GIF
					        break;
					}

					//Open file handle to new thumbnail.
					$fh = fopen( $newImagePath, 'rb' );
					$thumb_contents = fread($fh, filesize($newImagePath));
					fclose( $fh );

					imagedestroy($originalImage);
					imagedestroy($resizedImage);

					//Create a S3Client
					$s3 = new S3Client([
					  'region' => 'us-east-1',
					  'version' => 'latest',
					  'credentials' =>array(
					    'key'    => AWS_ACCESS_KEY_ID,
					    'secret' => AWS_SECRET_ACCESS_KEY
					  )
					]);

					try {


							$result = $s3->putObject([
								'Bucket' => 'spectrum-traffic-images',
								'Key'    => "$key",
								'Body'   => $contents,
								'ACL'    => 'public-read',
								'ContentType' => $mime_content,
								'StorageClass' => 'ONEZONE_IA'
							]);

							$result2 = $s3->putObject([
								'Bucket' => 'spectrum-traffic-images',
								'Key'    => "$key_thumb",
								'Body'   => $thumb_contents,
								'ACL'    => 'public-read',
								'ContentType' => $mime_content,
								'StorageClass' => 'ONEZONE_IA'
							]);

	            $location="S3";
	    				unlink($imagePath);
							unlink($newImagePath);
							//Add to the images table.

							$image = ['image_guid' => $image_guid, 'object_guid' => $request_guid, 'object_type' => 'customer_requests', 'created_unix' => time(), 'filename' => "$filename", 'full_width' => $originalWidth, 'full_height' => $originalHeight, 'thumb_width' => $newWidth, 'thumb_height' => $newHeight,'mime-type' => $mime_type, 'has_thumb' => 1];
							addGenericDB($image, TABLE_IMAGES, 'image_guid', $msg, $errno);

							$resultant['err'] = 0;
							$resultant['image'] = $image;

					} catch (S3Exception $e) {
						$location="Local";
						$resultant['err'] = 1;
						$resultant['message'] = "Could not move file to S3...location on server under files/images/$image_guid";
					}

			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "Could not move file!";
			}

		} else {

			$resultant['err'] = 0;
			$resultant['message'] = "Invalid file!";

		}


		echo json_encode($resultant);

		exit(0);

	break;

	case "add_feedback_request":

		jsonTrace($_POST['act'], 1);
		jsonTrace($_GET);
		jsonTrace($_FILES['image']);
		$resultant['err'] = 0;
		echo json_encode($resultant);
		exit(0);

	break;

	case "send_invoice_email":


		$invoice_email=$_POST['invoice_email'];
		$qb_invoice_id=$_POST['qb_invoice_id'];
		$study_guid=$_POST['study_guid'];


	    if(sendInvoiceEmailDB($qb_invoice_id, $study_guid, $invoice_email, $err)){

			$invoice = getInvoiceDB($qb_invoice_id, $err);
			$invoice['last_sent_date'] = time();
			@updateInvoiceDB($invoice, $err);

			$resultant['err'] = 0;
			$resultant['last_send'] = time();
			$resultant['message'] = "Invoice sent to " . $invoice_email;

			$resultant['last_sent_date_fmt'] = date('D, M j, Y @ h:i A', time());

			echo json_encode($resultant);

	    } else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
	    }

	break;

	case "link_invoice":

		$quote_guid = $_POST['quote_guid'];
		$qb_invoice_id = $_POST['qb_invoice_id'];

		if($qbInvoiceObj = getInvoiceQB($qb_invoice_id, $err)){
			if(linkInvoiceDB($qbInvoiceObj, $quote_guid, $err)){
				$resultant['err'] = 0;
				echo json_encode($resultant);
			} else {
				$resultant['err'] = 1;
				$resultant['message'] = $err;
				echo json_encode($resultant);
			}
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "assign_count_location":

        $location_id=$_POST['location_id'];
        $count_guid = $_POST['count_guid'];

	$err="";

	if($location_id = assignCountLocationDB($count_guid, $location_id, $err)){
	    $resultant['err'] = 0;
	    $resultant['location_id'] = $location_id;
	    $resultant['count_guid'] = $count_guid;
	    echo json_encode($resultant);
	} else {
	    $resultant['err'] = 1;
	    $resultant['message'] = $err;
	    echo json_encode($resultant);
	}

	break;

	case "delete_count_location":

        $location_id=$_POST['location_id'];

	$err="";

	if($location_id = deleteCountLocationDB($location_id, $err)){
	    $resultant['err'] = 0;
	    echo json_encode($resultant);
	} else {
	    $resultant['err'] = 1;
	    $resultant['message'] = $err;
	    echo json_encode($resultant);
	}

	break;


	case "delete_manifest":

		$manifest_guid=$_POST['manifest_guid'];

		$sql = "SELECT " . TABLE_STUDIES . ".link_locations, " . TABLE_STUDIES . ".study_guid, " . TABLE_STUDIES . ".link_locations, " . TABLE_MANIFESTS . ".count_guid, " . TABLE_INTERSECTION_COUNTS . ".location_id, equipment_guid FROM " . TABLE_STUDIES . "
		LEFT JOIN " . TABLE_INTERSECTION_COUNTS . " ON " . TABLE_STUDIES . ".study_guid=" . TABLE_INTERSECTION_COUNTS . " .study_guid
		LEFT JOIN " . TABLE_MANIFESTS . " ON " . TABLE_INTERSECTION_COUNTS . ".count_guid=" . TABLE_MANIFESTS . ".count_guid
		WHERE manifest_guid='$manifest_guid'";


		$error = FALSE;

		if($link = dbConnect()){
			if($result = mysqli_query($link, $sql)){
				$record = mysqli_fetch_assoc($result);
				$study_guid = $record['study_guid'];
				$link_locations = (int)$record['link_locations'];
				$count_guid = $record['count_guid'];
				$location_id = $record['location_id'];
				$equipment_guid = $record['equipment_guid'];
			} else {
				$error = TRUE;
				$err = mysqli_error($link);
			}
		} else {
			$error = TRUE;
			$err = "Can't connect to database";
		}

		if(strlen($study_guid)){

			$sql = "SELECT * FROM " . TABLE_MANIFESTS . "
			LEFT JOIN " . TABLE_INTERSECTION_COUNTS . " ON " . TABLE_INTERSECTION_COUNTS . ".count_guid = " . TABLE_MANIFESTS . ".count_guid
			WHERE study_guid='$study_guid' AND location_id='$location_id' AND equipment_guid='$equipment_guid'";

			if(!$link_locations){
				$sql .= " AND manifest_guid='$manifest_guid'";
			}

			if($result = mysqli_query($link, $sql)){
				while($record = mysqli_fetch_assoc($result)){
					if(strlen($record['manifest_guid'])==32){

						if(!deleteManifestDB($record['manifest_guid'], $err)){
							$error = TRUE;
						}

						$error = FALSE;
					} else {
						$error = TRUE;
					}
				}
			} else {
				$error = TRUE;
				$err = mysql_error($link);
			}
		} else {
			$error = TRUE;
			$err = "Invalid study guid!";
		}

		if(!$error){
			$resultant['err'] = 0;
			echo json_encode($resultant);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;


	case "update_count_bounds":

        $count_guid=$_POST['count_guid'];
        $latitude = $_POST['latitude'];
		$longitude = $_POST['longitude'];
		$zoom = $_POST['zoom'];

		$err="";

		if(updateCountBoundsDB($count_guid, $latitude, $longitude, $zoom, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Location bounds updated!";
			echo json_encode($resultant);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "update_employee_bounds":

    $user_guid=$_POST['user_guid'];
    $latitude = $_POST['latitude'];
		$longitude = $_POST['longitude'];
		$zoom = $_POST['zoom'];

		$err="";

		if(updateEmployeeBoundsDB($user_guid, $latitude, $longitude, $zoom, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Location bounds updated!";
			echo json_encode($resultant);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "add_location":

        $name=$_POST['name'];
        $latitude = $_POST['latitude'];
		$longitude = $_POST['longitude'];
		$err="";

		if($location_id = addLocationDB($name, $latitude, $longitude, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Location added!";
			$resultant['location_id'] = $location_id;
			echo json_encode($resultant);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "add_tender":

			$vendor_guid = $_POST['vendor_guid'];
			$user_guid = $_POST['user_guid'];
			$service_guid = $_POST['service_guid'];

			if(addTenderDB($service_guid, 1, $_SESSION['user_guid'], $vendor_guid, $user_guid, "5.00", $err)){
				$resultant['err'] = 0;
				$resultant['message'] = "Tender added!";
			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "";
			}

			echo json_encode($resultant);
			exit(0);

	break;

	case "upload_tender_data":

      $tender_guid = $_POST['tender_guid'];
      $resultant['err'] = 0;
      $resultant['errors'] = '';

      $tender = getTenderDB($tender_guid, $err);
			$employee_guid = $tender['vendor_employee_guid'];

      $recordingSessions = getDataRecordingSessionsDB($tender_guid, $employee_guid, $err);

      if(count($recordingSessions)){

        $link = dbConnect();

        addSpectrumDataApproaches2DB($tender['service_guid'], $err);
        addSpectrumDataMovements2DB($tender['service_guid'], $err);
        deleteClassificationSettingsDB($tender['service_guid'], $err);
        deleteDataRows($tender['service_guid'], $err);

				$approaches = getApproachesByDirectionDB($tender['service_guid'], $err);
				$movements = getMovementsDB($tender['service_guid'], $err);

        foreach($movements as $movement){
              $movements_array[$movement['EntranceApproach']][$movement['ExitApproach']]=[$movement['ID']];
              $movements_array[$movement['EntranceApproach']][$movement['ExitApproach']] = $movement['ID'];
        }

        foreach($recordingSessions as $key=>$session){

          $dataObj = json_decode($session['dataObj'], true);

          $sql = "";

          foreach($dataObj['stack'] as $data){

              $Time = date("Y-m-d H:i:s", $session['start_video_unixtime'] + $data[4]);
              $Classification = $data[3];
              $Count = 1;
              $EntranceApproach = $approaches[$data[0]]['ID'];

              if((int)trim($data[1]) == -1 or is_null($data[1])){
                $ExitApproach = -1;
              } else {
                $ExitApproach = $approaches[trim($data[1])]['ID'];
              }

							$MovementID = $movements_array[$EntranceApproach][$ExitApproach];

              if(strlen($MovementID) > 0){
              		//if($Movement = getMovementByEntranceExitDB($EntranceApproach, $ExitApproach, $tender['service_guid'], $err)){
	                $sql .= "INSERT INTO " . TABLE_DATA_ROWS . " (Classification, Time, MovementID, Count, LaneID, count_guid, source) VALUES ('$Classification', '$Time', '$MovementID', '$Count', '$MovementID', '{$tender['service_guid']}', 'SPT');\n";
              } else {
	                $_error = array();
	                $_error['context'] = "API Error!";
	                $_error['page'] = "json.php";
	                $_error['line'] = __LINE__;
	                $_error['errors'][]=  "Movement not found! $EntranceApproach, $ExitApproach, {$tender['service_guid']}";
	                sendErrorNotificationDB($smarty, $_error, $err);
             }
          }


          mysqli_multi_query($link, $sql);
          do {
              /* store the result set in PHP */
              $result = mysqli_store_result($link);
          } while (mysqli_next_result($link));

        }

        //Lock the tenders
        //jsonTrace($tender, 1);

      } else {
        $resultant['err'] = 1;
        $resultant['errors'] = 'No recording sessions to write!';
      }

      echo json_encode($resultant);
      exit(0);

  break;

	case "delete_tender":

			$tender_guid = $_POST['tender_guid'];



			if(deleteTenderDB($tender_guid, $err)){
				$resultant['err'] = 0;
				$resultant['message'] = "Tender added!";
			} else {
				$resultant['err'] = 1;
				$resultant['message'] = $err;
			}

			echo json_encode($resultant);
			exit(0);

	break;

	case "toggle_ods":

		if($_POST['action'] == "toggle_ods"){

			$count_array = explode(";", $_POST['c_string']);

			$err = "";
			$msg = "";

			if(sizeof($count_array)>0){

				foreach($count_array as $key=>$value){

					$count['count_guid'] = $value;
					$count['open_data_share'] = 1;
					if(strlen($value)==32){
						//@deleteSchedulesByCountGuidDB($value, $err);
						@updateGenericDB($count, TABLE_INTERSECTION_COUNTS, "count_guid", $msg, $err);
					}
				}

				$resultant['err'] = 0;
				$resultant['message'] = "Counts shared!";
				echo json_encode($resultant);

			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "Nothing to share";
				echo json_encode($resultant);
			}

		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;


	case "link_permit":

		if($_POST['action'] == "link_permit"){

			$permit_guid = $_POST['permit_guid'];

			$count_array = explode(";", $_POST['c_string']);

			$err = "";
			$msg = "";

			if(sizeof($count_array)>0){

				foreach($count_array as $key=>$value){

					$count['count_guid'] = $value;
					$count['permit_guid'] = $permit_guid;

					if(strlen($value)==32){
						//@deleteSchedulesByCountGuidDB($value, $err);
						@updateGenericDB($count, TABLE_INTERSECTION_COUNTS, "count_guid", $msg, $err);
					}
				}

				$resultant['err'] = 0;
				$resultant['message'] = "Permit linked!";
				echo json_encode($resultant);

			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "Nothing to link";
				echo json_encode($resultant);
			}

		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "unlink_permits":

		if($_POST['action'] == "unlink_permits"){

			$count_array = explode(";", $_POST['c_string']);

			$err = "";
			$msg = "";

			if(sizeof($count_array)>0){

				foreach($count_array as $key=>$value){

					$count['count_guid'] = $value;
					$count['permit_guid'] = null;

					if(strlen($value)==32){
						//@deleteSchedulesByCountGuidDB($value, $err);
						@updateGenericDB($count, TABLE_INTERSECTION_COUNTS, "count_guid", $msg, $err);
					}
				}

				$resultant['err'] = 0;
				$resultant['message'] = "Permit linked!";
				echo json_encode($resultant);

			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "Nothing to link";
				echo json_encode($resultant);
			}

		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;

		case "delete_permit":

		if($_POST['action'] == "delete_permit"){

				$permit_guid = $_POST['permit_guid'];

				$permit = getPermitDB($permit_guid, $err);

				if($counts = getCounts2DB(array('permit_guid'=>$permit_guid), $err)){

					$resultant['err'] = 1;
					$resultant['message'] = "There are counts associated with this permit!";
					echo json_encode($resultant);

				} else {

					if(deleteGenericDB($permit, TABLE_PERMITS, "permit_guid", $msg, $err)){

						require("Traffic_Data/s3.class.php");

						$s3=new S3();
						$s3->setBucketName(S3_PERMITS_BUCKET);
						$key = urlencode("{$permit['permit_guid']}/{$permit['filename']}");
						$s3->deleteObject($key, S3_PERMITS_BUCKET);

						$resultant['err'] = 0;
						$resultant['message'] = "Permit deleted!";
						echo json_encode($resultant);
					} else {
						$resultant['err'] = 1;
						$resultant['message'] = $err;
						echo json_encode($resultant);
					}
				}

		} else {
			$resultant['err'] = 1;
			$resultant['message'] = "unknown command";
			echo json_encode($resultant);
		}

	break;


	case "add_action_plan":

		$name = $_POST['action_plan_name'];
		$unix_time = $_POST['unix_time'];

		if($action_plan = addActionPlanDB($name, $unix_time, $err)){
			$resultant['action_plan'] = $action_plan;
			$resultant['err'] = 0;
		} else {
			$resultant['action_plan'] = null;
			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);

	break;

	case "delete_action_plan":

		$plan_guid = $_POST['plan_guid'];
		$resultant['err'] = 0;

		if(deleteActionPlanDB($plan_guid, $err)){
			$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}


		echo json_encode($resultant);
		exit(0);

	break;


	case "update_action_plan":

		$plan['plan_guid'] = $_POST['plan_guid'];
		$plan['visible'] = $_POST['visible'] == 0 ? 1 : 0;

		$resultant['err'] = 0;

		if($r = updateGenericDB($plan, TABLE_ACTION_PLANS, 'plan_guid', $msg, $errno)){
			$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}


		echo json_encode($resultant);
		exit(0);

	break;


	case "update_current_customer":

    $customer_guid = $_POST['customer_guid'];
		$_SESSION['customer_map']['customer_guid'] = $customer_guid;

	break;

	case "update_map_options":

		$option = $_POST['option'];
		$value = $_POST['value'];
		$_SESSION['customer_map']['options'][$option] = $value;

	break;

	case "update_map_dates":

		$option = $_POST['option'];
		$date_array = explode(" ", $_POST['value']);
		$date_str = "{$date_array[0]} {$date_array[1]} {$date_array[2]} {$date_array[3]}";
		$_SESSION['customer_map'][$option] = strtotime($date_str);

	break;

	case "get_customer_counts":

	//jsonTrace($_POST, 1);
	debugTrace('get customer counts');


	$user = getUserDB($_SESSION['user_guid']);
	$customer = getCustomerDB($user['customer_guid'], $err); 

	$err="";

	$location_id = isset($_POST['location_id']) ? $_POST['location_id'] : NULL;

    $minLat = isset($_POST['minLat']) ? $_POST['minLat'] : NULL;
	$minLng = isset($_POST['minLng']) ? $_POST['minLng'] : NULL;
	$maxLat = isset($_POST['maxLat']) ? $_POST['maxLat'] : NULL;
	$maxLng = isset($_POST['maxLng']) ? $_POST['maxLng'] : NULL;
	$resultant = array();

	$count_array = array();


	if(is_alphanumeric($_SESSION['customer_map']['customer_guid'], 32, 32)){
		$customer_guid = $_SESSION['customer_map']['customer_guid'];
	} else {
		$customer_guid = NULL;
	}

	$opts = "";
	if(!is_null($_SESSION['customer_map']['options'])){

			foreach($_SESSION['customer_map']['options'] as $key=>$option){
				if(!$option){
					$opts .= " AND " . TABLE_LOCATIONS . ".control_type != '$key'";
				}
			}

	}

	$max_end_date = isset($_SESSION['customer_map']['max_end_date']) ? $_SESSION['customer_map']['max_end_date'] : time();
	$min_start_date = isset($_SESSION['customer_map']['min_start_date']) ? $_SESSION['customer_map']['min_start_date'] : strtotime('September 1, 2012');

	if($counts = getCustomerMapCountsDB_v2($customer_guid, $minLat, $minLng, $maxLat, $maxLng, $opts, $min_start_date, $max_end_date, $err)){

			$resultant['err'] = 0;

			foreach($counts as $key=>$count) {

					//$count_array[$count['location_id']]['name'] = $count['name'];
					$count_array[$count['location_id']]['longitude'] = $count['longitude'];
					$count_array[$count['location_id']]['latitude'] = $count['latitude'];
					$count_array[$count['location_id']]['location_id'] = $count['location_id'];
			}
			
			$resultant['locations'] = $count_array;

	    echo json_encode($resultant);

	} else {
	    $resultant['err'] = 1;
	    $resultant['message'] = $err;
	    echo json_encode($resultant);
	}


	break;

	case "update_count_exchange":

		$count_guid = $_POST['count_guid'];
		$value = $_POST['value'];

		$count['count_guid'] = $count_guid;
		$count['open_data_exchange'] = ($value == 'true' ? 1 : 0);

		if($r = updateGenericDB($count, TABLE_INTERSECTION_COUNTS, 'count_guid', $msg, $errno)){
			$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $msg;
		}

		echo json_encode($resultant);

		break;

	case "get_location_counts":

	$user = getUserDB($_SESSION['user_guid']);

	jsonTrace($_POST, 1);

	$err="";

	$opts = "";
	if(!is_null($_SESSION['customer_map']['options'])){

			foreach($_SESSION['customer_map']['options'] as $key=>$option){
				if(!$option){
					$opts .= " AND " . TABLE_LOCATIONS . ".control_type != '$key'";
				}
			}
	}

	$location_id = isset($_POST['location_id']) ? $_POST['location_id'] : NULL;

  $minLat = isset($_POST['minLat']) ? $_POST['minLat'] : NULL;
	$minLng = isset($_POST['minLng']) ? $_POST['minLng'] : NULL;
	$maxLat = isset($_POST['maxLat']) ? $_POST['maxLat'] : NULL;
	$maxLng = isset($_POST['maxLng']) ? $_POST['maxLng'] : NULL;

	$max_end_date = isset($_SESSION['customer_map']['max_end_date']) ? $_SESSION['customer_map']['max_end_date'] : time();
	$min_start_date = isset($_SESSION['customer_map']['min_start_date']) ? $_SESSION['customer_map']['min_start_date'] : strtotime('September 1, 2012');

	$count_array = array();

	$location = getLocationDB($location_id, $err);




	if($counts = getLocationCountsDB($location_id, $minLat, $minLng, $maxLat, $maxLng, $opts, $min_start_date, $max_end_date, $err)){

			$resultant['err'] = 0;

			foreach($counts as $key=>$count){
					$count_array[$count['count_guid']]['publish_exchange_videos']= $count['publish_exchange_videos'];
					$count_array[$count['count_guid']]['open_data_exchange']= $count['open_data_exchange'];
					$count_array[$count['count_guid']]['control_type']= $count['control_type'];
					$count_array[$count['count_guid']]['cust_id']= $count['cust_id'];
					$count_array[$count['count_guid']]['study_id']= $count['study_id'];
					$count_array[$count['count_guid']]['owner_guid'] = $count['owner_guid'];
					$count_array[$count['count_guid']]['buyer_guid'] = $count['buyer_guid'];
					$count_array[$count['count_guid']]['count_guid'] = $count['count_guid'];
					$count_array[$count['count_guid']]['study_guid'] = $count['study_guid'];
					$count_array[$count['count_guid']]['ymd'] = $count['ymd'];
					$count_array[$count['count_guid']]['day'] = $count['day'];
					$count_array[$count['count_guid']]['schedules'][] = array('time_from'=>$count['time_from'], 'time_to'=>$count['time_to']);
					$count_array[$count['count_guid']]['day'] = $count['day'];
					$count_array[$count['count_guid']]['age_in_years'] = round((time() - $count['start_time']) / (365 * 24 * 3600), 1);

			}

			//jsonTrace($count_array, 1);
			$resultant['location']['counts'] = $count_array;
			$resultant['location']['name'] = $location['name'];
			$resultant['location']['cust_id'] = $location['cust_id'];
			$resultant['location']['control_type'] = $location['control_type'];
			$resultant['location']['active_closures'] = $location['active_closures'];
			//jsonTrace($resultant, 1);

	    echo json_encode($resultant);

	} else {


		//jsonTrace($counts, 1);

	    $resultant['err'] = 1;
	    $resultant['message'] = $err;
	    echo json_encode($resultant);
	}

	break;

	case "get_locations":

	$err="";

	$minLat = $_POST['minLat'];
	$minLng = $_POST['minLng'];
	$maxLat = $_POST['maxLat'];
	$maxLng = $_POST['maxLng'];

	if($locations = getLocations2DB($minLat, $minLng, $maxLat, $maxLng, $err)){


	    $resultant['err'] = 0;
	    $resultant['locations'] = $locations;
	    echo json_encode($resultant);
	} else {
	    $resultant['err'] = 1;
	    $resultant['message'] = $err;
	    echo json_encode($resultant);
	}

	break;

	case "get_manifests":
		dojo::getManifests();
	break;

	case "get_counts":
		dojo::getCounts();
	break;

	case "get_count":
		dojo::getCount();
	break;

	break;

	case "get_nodes":

        $model_id=$_POST['model_id'];

	$err="";

	if($nodes = getNodes($model_id, $err)){
	    $resultant['nodes'] = $nodes;
	    $resultant['err'] = 0;
	    echo json_encode($resultant);
	} else {
	    $resultant['err'] = 1;
	    $resultant['err_desc'] = 'FAILED';
	    echo json_encode($resultant);
	}

	break;

	case "update_location":

		$location_id=$_POST['location_id'];
		$name=$_POST['name'];
		$latitude=$_POST['latitude'];
		$longitude=$_POST['longitude'];
		$lock=$_POST['lock'];


		if(isset($_POST['lock'])){
			$lock = (int)$_POST['lock'];
		} else {
			$lock = 0;
		}

		$err="";

		if($location = getLocationDB($location_id, $err)){

			$new_latitude = round((float)$latitude, 6);
			$new_longitude = round((float)$longitude, 6);
			$old_latitude = round((float)$location['latitude'], 6);
			$old_longitude = round((float)$location['longitude'], 6);

			if(updateLocationDB($location_id, $name, $latitude, $longitude, $lock, $err)){
				$history = ['user_guid' => $_SESSION['user_guid'], 'created_unix' => time(), 'action_id' => 7, 'note' => "Update location id $location_id ($name)  lat: $old_latitude to $new_latitude, long: $old_longitude to $new_longitude", 'object_guid' => $location_id, 'object_type' => 'location'];
				addGenericDB($history, TABLE_DEPLOYMENT_HISTORY, 'id', $msg, $errno);
				$resultant['err'] = 0;
				echo json_encode($resultant);
			} else {
				$resultant['err'] = 1;
				$resultant['err_desc'] = $err;
				echo json_encode($resultant);
			}

		} else {
			$resultant['err'] = 1;
			$resultant['err_desc'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "add_location_closure":

		$closure['location_id'] = $_POST['location_id'];
		$closure['impact'] = $_POST['impact_type'];
		$closure['type'] = $_POST['closure_type'];
		$closure['active'] = ($_POST['active'] == "true" ? 1 : 0);
		$closure['description'] = addslashes($_POST['description']);
		$closure['unix_created'] = time();
		$closure['created_by'] = $_SESSION['user_guid'];
		$err=0;
		$msg="";

		//jsonTrace($closure);
		//$resultant['err'] = 0;

		if(!addGenericDB($closure, TABLE_CLOSURES, 'id', $msg, $err)){
			$resultant['err'] = 1;
			$resultant['err_desc'] = "$msg";
		} else {
			$resultant['err'] = 0;
		}

		echo json_encode($resultant);

	break;


	case "update_closure":

		$closure['id'] = $_POST['id'];
		$closure['active'] = ($_POST['active'] == "false" ? 0 : 1) ;
		$err=0;
		$msg="";



		if(!updateGenericDB($closure, TABLE_CLOSURES, 'id', $msg, $err)){
			$resultant['err'] = 1;
			$resultant['err_desc'] = "$msg";
		} else {
			$resultant['err'] = 0;
		}


		echo json_encode($resultant);

	break;


	case "update_location_details":

		$location['location_id'] = $_POST['location_id'];
		$location['name'] = $_POST['name'];
		$location['control_type'] = $_POST['control_type'];
		$location['jurisdiction'] = $_POST['jurisdiction'];
		$location['cust_id'] = $_POST['cust_id'];
		$err=0;
		$msg="";

		if($res = updateGenericDB($location, TABLE_LOCATIONS, 'location_id', $msg, $err)){
			$resultant['err'] = 0;
			$resultant['location'] = $res;
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $msg;
		}

		echo json_encode($resultant);

	break;


	case "lock_location":

        $location_id=$_POST['location_id'];
		if(isset($_POST['lock'])){
			$lock = (int)$_POST['lock'];
		} else {
			$lock = NULL;
		}

		if($location = getLocationDB($location_id, $err)){

			if(updateLocationDB($location_id, $location['name'], $location['latitude'], $location['longitude'], $lock, $err)){
				$resultant['err'] = 0;
				echo json_encode($resultant);
			} else {
				$resultant['err'] = 1;
				$resultant['err_desc'] = $err;
				echo json_encode($resultant);
			}

		} else {
			$resultant['err'] = 1;
			$resultant['err_desc'] = $err;
			echo json_encode($resultant);
		}

	break;

	case "link_locations":

        $study_guid=$_POST['study_guid'];
		$link_locations=(int)$_POST['link_locations'];
		$err = "";

		if(strlen($study_guid)==32){
			if($study = getStudyDB($study_guid, $err)){
				$study['link_locations'] = ($link_locations ? 0 : 1);
				if(updateStudyDB($study, $err)){
					$resultant['err'] = 0;
				} else {
					$resultant['message'] = $err;
					$resultant['err'] = 1;
				}
			} else {
				$resultant['message'] = $err;
				$resultant['err'] = 1;
			}
		} else {
			$resultant['message'] = "Invalid study guid";
			$resultant['err'] = 1;
		}

		echo json_encode($resultant);

	break;

	case "update_action_owner":

		$action_guid = $_POST['action_guid'];
		$owner_guid = $_POST['owner_guid'];
		$action_name = $_POST['action_name'];
		$object_guid = $_POST['object_guid'];
		$ordinal = $_POST['ordinal'];
		$priority = $_POST['priority'];

		if($owner_guid == null || $owner_guid == ''){

        //Reset both the maniest and remove the action guids.
      	if($action = getActionDB($action_guid, $err)){
            //update manifest.
            $manifest = getManifestDB($object_guid, $err);
						$m_new['manifest_guid'] = $manifest['manifest_guid'];
						$m_new[$action_name . '_action_guid'] = null;
            updateGenericDB($m_new, TABLE_MANIFESTS, 'manifest_guid', $msg, $err);
            deleteGenericDB($action, TABLE_ACTIONS, 'action_guid', $msg, $err);
            $resultant['err'] = 0;
            echo json_encode($resultant);
        		exit(0);
        } else {
          $resultant['err'] = 1;
					$resultant['message'] = $err;
          echo json_encode($resultant);
      		exit(0);
        }
    }


		if($employee = getEmployeeDB($owner_guid, $err2)){

			if($action = getActionDB($action_guid, $err)){

				unset($action['owner']);
				unset($action['creator']);

				$action['owner_guid'] = $owner_guid;

				if(updateActionDB($action, $err)){
					$resultant['err'] = 0;
				} else {
					$resultant['err'] = 1;
					$resultant['message'] = $err;
				}

			} else {
				//New action required.
				if(updateManifestActionDB($object_guid, $action_name, $owner_guid, $err)){

					$resultant['err'] = 0;

				} else {
					$resultant['message'] = $err;
					$resultant['err'] = 1;
				}
			}

		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err2;
		}

		echo json_encode($resultant);
		exit(0);

		break;

	case "add_equipment_manifest":

    $equipment_guid=$_POST['equipment_guid'];
    $count_guid = $_POST['count_guid'];
		$latitude = $_POST['latitude'];
		$longitude = $_POST['longitude'];
		$rotation = 0;
		$err="";

    $count = getCountDB($count_guid, $err);
    $counts = getCountsDB($count['details']['study_guid'], $err);
		$study = getStudyDB($count['details']['study_guid'], $err);
		$link_locations = (int)$study['link_locations'];

		if($link_locations){
			foreach($counts as $key=>$value){
				if($count['details']['location_id'] == $value['location_id'] && $value['count_guid'] != $count_guid){
					@addEquipmentManifestDB($equipment_guid, $value['count_guid'], $latitude, $longitude, $rotation, $err);
				}
			}
		}

		if($manifest = addEquipmentManifestDB($equipment_guid, $count_guid, $latitude, $longitude, $rotation, $err)){
			$resultant['err'] = 0;
			$resultant['message'] = "Equipment added to manifest!";
			$resultant['manifest_guid'] = $manifest['manifest_guid'];
			$resultant['serial_no'] = $manifest['serial_no'];
			$resultant['name'] = $manifest['name'];
			$resultant['latitude'] = $latitude;
			$resultant['longitude'] = $longitude;
			$resultant['rotation'] = 0;//new equipment
			echo json_encode($resultant);
		} else {
			$resultant['err'] = 1;
			$resultant['message'] = $err;
			echo json_encode($resultant);
		}

	break;
/*

*/

	case "add_camera":

		$count_guid = $_POST['count_guid'];
		$resultant['err'] = 0;

		if($count = getCountDB($count_guid, $err)){

			$manifests = Array();

			foreach($count['manifest'] as $key=>$manifest){
				$manifests[$key] =  $manifest['serial_no'];
			}

			$equipment_list = getEquipmentDB($err);

			$i = 1;

			while($i<=8){
				if(!array_search("DUMMY-$i", $manifests)){
					break;
				}
				$i++;
			}

			if($i<9){

				foreach($equipment_list as $key=>$equipment){

					if($equipment['serial_no'] == "DUMMY-$i"){

						$counts = getCountsDB($count['details']['study_guid'], $err);
						$study = getStudyDB($count['details']['study_guid'], $err);
						$link_locations = (int)$study['link_locations'];

						if($link_locations){
							foreach($counts as $key=>$value){
								if($count['details']['location_id'] == $value['location_id'] && $value['count_guid'] != $count_guid){
									@addEquipmentManifestDB($equipment['equipment_guid'], $value['count_guid'], $value['details']['loc_latitude'], $value['details']['loc_longitude'], 0, $err);
								}
							}
						}

						if($manifest = addEquipmentManifestDB($equipment['equipment_guid'], $count_guid, $count['details']['loc_latitude'], $count['details']['loc_longitude'], 0, $err)){
							$resultant['err'] = 0;
							$resultant['message'] = "Equipment added to manifest!";
							$resultant['manifest_guid'] = $manifest['manifest_guid'];
							$resultant['equipment_guid'] = $equipment['equipment_guid'];
							$resultant['serial_no'] = $manifest['serial_no'];
							$resultant['name'] = $manifest['name'];
							$resultant['latitude'] = $count['details']['loc_latitude'];
							$resultant['longitude'] = $count['details']['loc_longitude'];
							$resultant['rotation'] = 0;
							echo json_encode($resultant);
							exit(0);
						} else {
							$resultant['err'] = 1;
							$resultant['message'] = $err;
							echo json_encode($resultant);
							exit(0);
						}

						break;
					}

				}
			} else {
				$resultant['err'] = 1;
				$resultant['message'] = "Dummys 1-8 taken at this location!";
			}

			//$resultant['message'] = "use DUMMY-$i";


		} else {

			$resultant['err'] = 1;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);

		break;

	case "update_manifest_equipment":

		$manifest_guid = $_POST['manifest_guid'];
		$equipment_guid = $_POST['equipment_guid'];
		if($equipment = getEquipmentByGuidDB($equipment_guid, $err)){

			//camera found.
			if($manifest = getManifestDB($manifest_guid, $err)){

				$manifest_obj['equipment_guid'] = $equipment_guid;
				$count = getCountDB($manifest['count_guid'], $err);
				$study = getStudyDB($count['details']['study_guid'], $err);
				$link_locations = (int)$study['link_locations'];

				if($manifests = getManifestsByStudyDB($study['study_guid'], $err)){
					if($link_locations){
						foreach($manifests as $key=>$value){
							if($value['serial_no'] == $manifest['serial_no'] && $count['details']['ordinal'] == $value['ordinal']){
								$manifest_obj['manifest_guid'] = $value['manifest_guid'];
								@updateManifestDB($manifest_obj, $err);
							}
						}
					}
				}

				$manifest_obj['manifest_guid'] = $manifest_guid;

				if(updateManifestDB($manifest_obj, $err)){
					$resultant['err'] = 0;
					echo json_encode($resultant);
					exit(0);
				} else {
					$resultant['err'] = 1;
					$resultant['err_desc'] = $err;
					echo json_encode($resultant);
					exit(0);
				}
			}
		}

		$resultant['err'] = 1;
		$resultant['err_desc'] = $err;
		echo json_encode($resultant);
		exit(0);

		break;

	case "update_manifest_location":

        $manifest_guid=$_POST['manifest_guid'];
		$name=$_POST['name'];
		$latitude=$_POST['latitude'];
		$longitude=$_POST['longitude'];
        $rotation=$_POST['rotation'];


		$err="";

		if(updateManifestLocationDB($manifest_guid, $name, $latitude, $longitude, $rotation, $err)){
			$resultant['err'] = 0;
			$resultant['err_desc'] = $err;
			echo json_encode($resultant);
		} else {
			$resultant['err'] = 1;
			$resultant['err_desc'] = $err;
			echo json_encode($resultant);
		}

	break;

	//legs
	case "get_legs": dojo::getLegs();break;
	case "add_leg":	dojo::callFunctionDB('addLegDB');break;
	case "update_leg":dojo::callFunctionDB('updateLegDB');break;
	case "delete_leg":dojo::callFunctionDB('deleteLegDB');break;

	//flows
	case "get_flows": dojo::getFlows();break;
	case "get_flows_data": dojo::getFlowsData();break;
	case "add_flow":dojo::callFunctionDB('addFlowDB');break;
	case "update_flow":dojo::callFunctionDB('updateFlowDB');break;
	case "delete_flow":dojo::callFunctionDB('deleteFlowDB');break;

	case "create_default_template": dojo::callFunctionDB('createDefaultTemplate');break;
	case "delete_template": dojo::callFunctionDB('deleteTemplate');break;

	case "set_forced_peak" :
			//receive a string from the browser and convert to unix time before processing
			$unix_time = strtotime($_POST['start_time']);
			$_POST['start_time'] = $unix_time;
			dojo::callFunctionDB('deleteForcedPeakDB',false);//delete previous setting, if it exists
			dojo::callFunctionDB('addForcedPeakDB');//add new settings
	break;

	case "set_forced_trim" :
			//receive a string from the browser and convert to unix time before processing
			$unix_time = strtotime($_POST['start_time']);
			$_POST['start_time'] = $unix_time;
			dojo::callFunctionDB('addForcedTrimDB');//add new settings
	break;

	case "delete_forced_peak":
		dojo::callFunctionDB('deleteForcedPeakDB');
	break;
	case "delete_forced_trim":
		dojo::callFunctionDB('deleteForcedTrimDB');
	break;

	case "get_customer_users":
		$customer_guid=$_POST['customer_guid'];

		if($users = getUsersDB($customer_guid, $err)){
		    $resultant['err'] = 0;
		    $resultant['users'] = $users;
		    echo json_encode($resultant);
		} else {
		    $resultant['err'] = 1;
		    $resultant['message'] = $err;
		    echo json_encode($resultant);
		}

	break;

	case "get_customer_offices":

		$customer_guid=$_POST['customer_guid'];

		if($offices = getMailingLocationsDB($customer_guid, $err)){
		    $resultant['err'] = 0;
		    $resultant['offices'] = $offices;
		    echo json_encode($resultant);
		} else {
		    $resultant['err'] = 1;
		    $resultant['message'] = $err;
		    echo json_encode($resultant);
		}

	break;

	case "align_flows": dojo::callFunctionDB('alignFlows');

	break;

	case "create_count_reports":

			$resultant['err'] = 0;
	    $study_guid = trim($_POST['study_guid']);
	    $count_guid = trim($_POST['count_guid']);

	    if($count = getCountDB($count_guid, $err) AND $study = getStudyDB($study_guid,$err)){

				  $study_id = $study['study_id'];
					$resultant['err'] = 0;

					debugTrace((int)$count['details']['service_type_id']);

					switch((int)$count['details']['service_type_id']){

							case 1:

							$resultant = uploadReportToCloud($study_id, $count, 'pdf',$smarty,$err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								exit(0);
							}

							$resultant=uploadReportToCloud($study_id,$count, 'xlsx',$smarty,$err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								exit(0);
							}

							$resultant = uploadReportToCloud($study_id, $count, 'utdf',$smarty,$err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								exit(0);
							}

							if(count($count['approaches']<=4)){
								$resultant=uploadReportToCloud($study_id, $count, 'jcd', $smarty, $err);
								if($resultant['err'] == 1){
									echo json_encode($resultant);
									exit(0);
								}
							}

							break;

							case 8:
							case 6:
							case 16:

							$resultant=uploadReportToCloud($study_id,$count, 'xlsx',$smarty,$err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								exit(0);
							}

							break;


							default:

					}

					/*
					if((int)$count['details']['service_type_id'] == 14){

						debugTrace("how are we in here? " . (int)$count['details']['service_type_id']);

						$resultant = uploadReportToCloud($study_id, $count, 'pdf',$smarty,$err);
						if($resultant['err'] == 1){
							echo json_encode($resultant);
							break;
						}

					} elseif((int)$count['details']['service_type_id'] != 8){

							$resultant = uploadReportToCloud($study_id, $count, 'pdf',$smarty,$err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								break;
							}

							$resultant = uploadReportToCloud($study_id, $count, 'utdf',$smarty,$err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								break;
							}

							$resultant=uploadReportToCloud($study_id, $count, 'jcd', $smarty, $err);
							if($resultant['err'] == 1){
								echo json_encode($resultant);
								break;
							}

					}

					$resultant=uploadReportToCloud($study_id,$count, 'xlsx',$smarty,$err);

					if($resultant['err'] == 1){
						echo json_encode($resultant);
						break;
					}
					*/


	    } else {

				$resultant['err'] = 1;
				$resultant['message'] = 'Error loading Study';

	    }

		echo json_encode($resultant);
		exit(0);

	break;

	case "lock_study_locations":
		$locations = explode(',',$_POST['locations']);

		if( $resultant['rows'] = lockLocations($locations, $err) ){
			$resultant['err'] = 0;
		} else {
			$resultant['err'] = 1;
		}

		$resultant['message'] = $err?$err:'';//set $err to '' if null
		echo json_encode($resultant);
	break;

	case "delete_count_schedules":
		$count_guid=$_POST['count_guid'];
		if( deleteSchedulesByCountGuidDB($count_guid, $err) ){
			$resultant['err'] = 0;
		}else{
			$resultant['message'] = $err;
			$resultant['err'] = 1;
		}
		echo json_encode($resultant);
	break;


	case "multi_delete_count_schedules":

		$count_array = explode(";", $_POST['c_string']);

		if(sizeof($count_array)>0){
			foreach($count_array as $key=>$value){
				if(strlen($value)==32){
					@deleteSchedulesByCountGuidDB($value, $err);
				}
			}
		}

		$resultant['err'] = 0;

		echo json_encode($resultant);

	break;

	case "multi_tender_counts":

		$count_array = explode(";", $_POST['c_string']);
		$tender = (int)$_POST['tender'];
		$vendor_guid = $_POST['vendor_guid'];

		if(sizeof($count_array)>0){

			foreach($count_array as $key=>$count_guid){

				if(strlen($count_guid)==32){

					$count = getCountDB($count_guid, $err);

					if($tender){

						//Check if this count is already tendered.
						if(!$tenderObj = getTenderByServiceDB($count_guid, $err)){
							addTenderDB($count_guid, 1, $_SESSION['user_guid'], $vendor_guid, null, "5.00", $err);
						}

					} else {

						if($tenderObj = getTenderByServiceDB($count_guid, $err)){
							//Check if a tender exists with this service guid:
							if(!$tenderObj['locked']){
								deleteGenericDB($tenderObj, TABLE_SERVICE_TENDERS, 'tender_guid', $msg, $err);
							} else {
								debugTrace("$count_guid locked");
							}

						} else {
							debugTrace("can't find tender for $count_guid");
						}
					}


				}
			}

		}

		$resultant['err'] = 0;

		echo json_encode($resultant);

	break;

	case "multi_lock_counts":

		$count_array = explode(";", $_POST['c_string']);
		$lock = (int)$_POST['lock'];

		if(sizeof($count_array)>0){
			if($link = dbConnect()){
				foreach($count_array as $key=>$count_guid){

					if(strlen($count_guid)==32){
						$sql = "UPDATE " . TABLE_SERVICE_TENDERS . " SET locked='" . $lock . "' WHERE service_guid = '$count_guid'";
						mysqli_query($link, $sql);
					}
				}
			}

		}

		$resultant['err'] = 0;

		echo json_encode($resultant);

	break;


	case "multi_modify_count_schedules":

		$count_array = explode(";", $_POST['c_string']);
		$schedule_start_date = $_POST['schedule_start_date'];
		$schedule_start_time = $_POST['schedule_start_time'];
		$schedule_hours = $_POST['schedule_hours'];
		$schedule_action = $_POST['schedule_action'];
		$schedule_interval = 15;

		$_SESSION['SCHEDULE_START_TIME'] = $schedule_start_time;
		$_SESSION['SCHEDULE_START_DATE'] = $schedule_start_date;
		$_SESSION['SCHEDULE_HOURS'] = $schedule_hours;

	    $start_time = strtotime($schedule_start_date . " " . $schedule_start_time);
	    $end_time = strtotime($schedule_start_date . " " . $schedule_start_time) + 3600*$schedule_hours;

		if(sizeof($count_array)>0){
			foreach($count_array as $key=>$service_guid){
				if(strlen($service_guid)==32){
					if($count = getCountDB($service_guid, $err)){
						if($schedule_action=="add"){
							addServiceScheduleDB($service_guid, $start_time, $end_time, $schedule_hours, $schedule_interval, $err);
						} elseif($schedule_action=="remove"){
							removeServiceScheduleDB($service_guid, $start_time, $err);
						}
					} else {
						$resultant['err'] = 1;
						$resultant['message'] = $err;
						echo json_encode($resultant);
						exit(0);
					}
				}
			}
		}

		$resultant['err'] = 0;

		echo json_encode($resultant);

	break;

	case "confirm_qaqc":

		$study_guid = $_POST['study_guid'];
		$qaqc = $_POST['qaqc'];

		if(strlen($study_guid)==32){
			if($study = getStudyDB($study_guid, $err)){
				$study['qaqc'] = 1;
				$study['qaqc_by'] = $_SESSION['user_guid'];
				$study['qaqc_date'] = time();
				if(updateStudyDB($study, $err)){
					$resultant['err'] = 0;
				} else {
					$resultant['message'] = $err;
					$resultant['err'] = 1;
				}
			} else {
				$resultant['message'] = $err;
				$resultant['err'] = 1;
			}
		} else {
			$resultant['message'] = "Invalid study guid";
			$resultant['err'] = 1;
		}

		echo json_encode($resultant);

	break;

	case "delete_count_reports":
		$count_guid=$_POST['count_guid'];
		$source=$_POST['source'];

		if( $count = getCountDB($count_guid,$err) ) {
			foreach($count['files'] as $file){
				//jsonTrace($file);
				//debugTrace($source);
				/*
				if( $file['source'] != $source ){
					continue;
				}
				*/
				if( !deleteFileDB($file['file_guid'], $err) ){
					$resultant['message'] = $err;
					$resultant['err'] = 1;
					break;
				}
			}
			$resultant['err'] = 0;
		}else{
			$resultant['message'] = $err;
			$resultant['err'] = 1;
		}
		echo json_encode($resultant);
	break;

	case "use_default_classifications";
		echo dojo::use_default_classifications();
	break;

	case "quickbooks_sync_invoices":

		$resultant['err'] = 0;
		initQB();

		if(!isset($_SESSION['quickbooks']['token'])){
			$resultant['message'] = "You are not currently authenticated in QuickBooks!";
			$resultant['err'] = 1;
			echo json_encode($resultant);
			break;
		} else {
			$accessToken = $_SESSION['quickbooks']['token'];
		}

    $config = include(ROOT . '/QuickBooks/oauth2_config.php');

		$dataService = DataService::Configure(array(
				'auth_mode' => 'oauth2',
				'ClientID' => $config['client_id'],
				'ClientSecret' =>  $config['client_secret'],
				'RedirectURI' => $config['oauth_redirect_uri'],
				'scope' => $config['oauth_scope'],
				'baseUrl' => QUICKBOOKS_BASE_URL
		));

		$dataService->updateOAuth2Token($accessToken);

		//$dataService->setLogLocation(ROOT . '/qblogs');

		$start = $_SESSION['CALENDAR']['start'] . "T00:00:01-05:00";
		$end = $_SESSION['CALENDAR']['end'] . "T00:00:01-05:00";

		$query = "Select Balance, TotalAmt, Id, DueDate from Invoice WHERE MetaData.CreateTime >='$start' AND MetaData.CreateTime<='$end'";

		try{

			$invoicesQB = $dataService->Query($query);

		} catch(Exception $e){

			$resultant['err'] = 1;
			$resultant['message'] = $e->getMessage();
		}

		$invoices_updated = 0;

		foreach($invoicesQB as $key=>$invoiceQB){

				try{

					if($invoice = getInvoiceDB($invoiceQB->Id)){

						$invoice['Balance'] = $invoiceQB->Balance;
						$invoice['TotalAmt'] = $invoiceQB->TotalAmt;
						$invoice['due_date'] = strtotime($invoiceQB->DueDate);
						$invoice['last_sync_date'] = time();

						if((float)$invoiceQB->Balance == 0){
							$invoice['paid'] = 1;
						} else {
							$invoice['paid'] = 0;
						}

						if((float)$invoiceQB->Balance > 0 && $invoice['due_date'] < time()){
							$invoice['overdue'] = 1;
						} else {
							$invoice['overdue'] = 0;
						}

						$resultant['last_sync_date_fmt'] = date('D, M j, Y @ h:i A', time());

						if(updateInvoiceDB($invoice, $err)){
							$invoices_updated++;
						} else {
							$resultant['err'] = 1;
							$resultant['message'] .= "$err\n";
						}

					}

				} catch(Exception $e){

					$resultant['message'] = "Syncing with QuickBooks failed for invoice $qb_invoice_id!\n\n".$e->getMessage();
					$resultant['err'] = 1;
				}


		}


		$resultant['message'] = "$invoices_updated Invoices updated!";
		echo json_encode($resultant);


	break;

	case "quickbooks_sync_invoice":

		$err = null;
		initQB();

		$qb_invoice_id = $_POST['qb_invoice_id'];


		if(!isset($_SESSION['quickbooks']['token'])){
			$resultant['message'] = "You are not currently authenticated in QuickBooks!";
			$resultant['err'] = 1;
			echo json_encode($resultant);
			break;
		} else {
			$accessToken = $_SESSION['quickbooks']['token'];
		}

		if($invoice = getInvoiceDB($qb_invoice_id, $err)){


			// Prep Data Services
			$dataService = DataService::Configure(array(
					'auth_mode' => 'oauth2',
					'ClientID' => $config['client_id'],
					'ClientSecret' =>  $config['client_secret'],
					'RedirectURI' => $config['oauth_redirect_uri'],
					'scope' => $config['oauth_scope'],
					'baseUrl' => QUICKBOOKS_BASE_URL
			));

			$dataService->updateOAuth2Token($accessToken);

			try{

				$invoiceObj = $dataService->Query("SELECT Id, Balance, TotalAmt, DueDate FROM Invoice WHERE Id='$qb_invoice_id'");
				$invoice['Balance'] = $invoiceObj[0]->Balance;
				$invoice['TotalAmt'] = $invoiceObj[0]->TotalAmt;
				$invoice['due_date'] = strtotime($invoiceObj[0]->DueDate);
				$invoice['last_sync_date'] = time();

				if((float)$invoiceObj[0]->Balance == 0){
					$invoice['paid'] = 1;
				} else {
					$invoice['paid'] = 0;
				}

				if((float)$invoiceObj[0]->Balance > 0 && $invoice['due_date'] < time()){
					$invoice['overdue'] = 1;
				} else {
					$invoice['overdue'] = 0;
				}

				$resultant['last_sync_date_fmt'] = date('D, M j, Y @ h:i A', time());

				if(updateInvoiceDB($invoice, $err)){

					$resultant['TotalAmt'] = $invoiceObj[0]->TotalAmt;
					$resultant['Balance'] = $invoiceObj[0]->Balance;
					$resultant['paid'] = $invoice['paid'];
					$resultant['overdue'] = $invoice['overdue'];
					$resultant['err'] = 0;
					echo json_encode($resultant);
					exit(0);

				} else {
					returnJsonErrorAndDie($err);
				}

			}catch(Exception $e){

				$resultant['message'] = "Syncing with QuickBooks failed!\n\n".$e->getMessage();
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}


		} else {
			returnJsonErrorAndDie($err);
		}

	break;

	case "quickbooks_create_invoice":

		initQB();

		$err=null;
		$quote_guid = $_POST['quote_guid'];

		$purchase_order = (isset($_POST['purchase_order']) ? $_POST['purchase_order'] : NULL);
		$special_note = (isset($_POST['special_note']) ?  $_POST['special_note'] : NULL);

		if(!isset($_SESSION['quickbooks']['token'])){
			$resultant['message'] = "You are not currently authenticated in QuickBooks!";
			$resultant['err'] = 1;
			echo json_encode($resultant);
			break;
		}

		if($quote = getQuoteDB($quote_guid, $err)){
			if ( !$study = getStudyDB($quote['study_guid'], $err) )  returnJsonErrorAndDie( $err );
			if ( !$counts = getCountsDB($study['study_guid'],$err) ) returnJsonErrorAndDie( $err );
			if ( !$customer = getCustomerDB($study['customer_guid'],$err) ) returnJsonErrorAndDie( $err );;

			if(!$customer['qb_customer_id']) returnJsonErrorAndDie('QuickBooks customer ID is not set!');

			$accessToken = $_SESSION['quickbooks']['token'];

			$config = include(ROOT . '/QuickBooks/oauth2_config.php');

			$dataService = DataService::Configure(array(
					'auth_mode' => 'oauth2',
					'ClientID' => $config['client_id'],
					'ClientSecret' =>  $config['client_secret'],
					'RedirectURI' => $config['oauth_redirect_uri'],
					'scope' => $config['oauth_scope'],
					'baseUrl' => QUICKBOOKS_BASE_URL
			));

			$dataService->updateOAuth2Token($accessToken);

			if(!$invoiceObj = createInvoiceQB($study, $quote, $counts, $customer['qb_customer_id'], $dataService, $err)){
				$resultant['message'] = $err;
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}

			try{
				$result = $dataService->Add($invoiceObj);
			} catch(Exception $e){
				$resultant['message'] = "Syncing with QuickBooks failed!\n\n".$e->getMessage();
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}

			$quote['qb_invoice_id'] = $result->Id;

			if(!updateQuoteDB($quote, $err)){
				$resultant['message'] = "Error setting QuickBooks transaction ID!\n".$err;
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}

			$quote['DocNumber'] = $result->DocNumber;

			if(!addInvoiceDB($quote, $counts, $purchase_order, $special_note, $err)){
				$resultant['message'] = "Could not add invoice to database!\n".$err;
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}

		}

		$resultant['message'] = $result->Id;
		$resultant['err'] = 0;

		echo json_encode($resultant);

	break;

	case "quickbooks_sync_report":

		$err=null;
    $report_guid = $_POST['report_guid'];
    $act = $_POST['act'];

		$accessToken = $_SESSION['quickbooks']['token'];

		if(!isset($_SESSION['quickbooks']['token'])){
			$resultant['message'] = "You are not currently authenticated in QuickBooks!";
			$resultant['err'] = 1;
			echo json_encode($resultant);
			exit(0);
		}

		if($report = getExpenseReportDB($report_guid, $err)){

			$config = include(ROOT . '/QuickBooks/oauth2_config.php');

			$dataService = DataService::Configure(array(
					'auth_mode' => 'oauth2',
					'ClientID' => $config['client_id'],
					'ClientSecret' =>  $config['client_secret'],
					'RedirectURI' => $config['oauth_redirect_uri'],
					'scope' => $config['oauth_scope'],
					'baseUrl' => QUICKBOOKS_BASE_URL
			));

      $dataService->updateOAuth2Token($accessToken);

			$BillObj = CreateQuickbooksBill($report);

			try{

				if($act=='create'){

					$resultingObj = $dataService->Add($BillObj);

					$error = $dataService->getLastError();

					if ($error != null) {
						$resultant['err'] = 1;
						$resultant['message'] = "The Status code is: " . $error->getHttpStatusCode() . "\n";
						$resultant['message'] .= "The Helper message is: " . $error->getOAuthHelperError() . "\n";
						$resultant['message'] .= "The Response message is: " . $error->getResponseBody() . "\n";
						echo json_encode($resultant);
						break;
					}

				}elseif($act=='delete'){

					$targetBillObj = $dataService->FindById($receipt['qb_transaction_id']);

					$error = $dataService->getLastError();

					if ($error != null) {
						$resultant['err'] = 1;
						$resultant['message'] = "The Status code is: " . $error->getHttpStatusCode() . "\n";
						$resultant['message'] .= "The Helper message is: " . $error->getOAuthHelperError() . "\n";
						$resultant['message'] .= "The Response message is: " . $error->getResponseBody() . "\n";
						echo json_encode($resultant);
						break;

					} else {

						$currentResultObj = $dataService->Delete($targetBillObj);

						$error = $dataService->getLastError();

						if ($error != null) {
							$resultant['err'] = 1;
							$resultant['message'] = "The Status code is: " . $error->getHttpStatusCode() . "\n";
							$resultant['message'] .= "The Helper message is: " . $error->getOAuthHelperError() . "\n";
							$resultant['message'] .= "The Response message is: " . $error->getResponseBody() . "\n";
							echo json_encode($resultant);
							break;
						}

					}

				}

			}catch(Exception $e){
				$resultant['message'] = "Syncing with QuickBooks failed!\n\n".$e->getMessage();
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}

			if($act=='create'){
				$report['summary']['qb_transaction_id'] = $resultingObj->Id;
			}elseif($act=='delete'){
				$report['summary']['qb_transaction_id'] = 0;//transaction no longer exists in QuickBooks
			}

			if(!updateExpenseReportDB($report['summary'], $err)){
				$resultant['message'] = "Error setting QuickBooks transaction ID!\n".$err;
				$resultant['err'] = 1;
				echo json_encode($resultant);
				break;
			}

			//use the result to update qb_transaction_id
		}else{
			$resultant['message'] = "Report not found!\n".$err;
			$resultant['err'] = 1;
			echo json_encode($resultant);
			break;
		}

		$resultant['message'] = $resultingObj->Id;
		$resultant['err'] = 0;
		echo json_encode($resultant);
	break;

	case "download_study_files":

    $count_strn=$_POST['count_strn'];
		$study_guid=$_POST['study_guid'];
		$count_array = explode(":", $count_strn);


				//Create a S3Client
				$s3 = new S3Client([
				  'region' => 'us-east-1',
				  'version' => 'latest',
				  'credentials' =>array(
				    'key'    => AWS_ACCESS_KEY_ID,
				    'secret' => AWS_SECRET_ACCESS_KEY
				  )
				]);



		if($study = getStudyDB($study_guid, $err)){

			$study_id = $study['study_id'];
			rrmdir(ROOT . "/reports/$study_id");

			foreach(array('MIO','SPT', 'EXT') as $source){
				if(!mkdir(ROOT . "/reports/$study_id/$source", 0755, true)) {
					debugTrace("can't make study folder");
				}

				if(!mkdir(ROOT . "/reports/$study_id/$source/pdf", 0755, true)) {
					debugTrace("can't make pdf folder");
				}

				if(!mkdir(ROOT . "/reports/$study_id/$source/xls", 0755, true)) {
					debugTrace("can't make xls folder");
				}

				if(!mkdir(ROOT . "/reports/$study_id/$source/csv", 0755, true)) {
					debugTrace("can't make csv folder");
				}

				if(!mkdir(ROOT . "/reports/$study_id/$source/utdf", 0755, true)) {
					debugTrace("can't make utdf folder");
				}

				if(!mkdir(ROOT . "/reports/$study_id/$source/tes", 0755, true)) {
					debugTrace("can't make tes folder");
				}
			}


			foreach($count_array as $key=>$value){

				if(strlen($value)==32){
					if($count = getCountDB($value, $err)){
						//create a utdf folder for each schedule
						foreach($count['schedule'] as $schedule){
							$day = date("Y-m-d", $schedule['start_time']);
							$start = date("Hi", $schedule['start_time']);
							$end = date("Hi", $schedule['end_time']);

							$schedule_name = "{$day}_{$start}-{$end}";
							mkdir(ROOT . "/reports/$study_id/$source/utdf/$schedule_name", 0755, true);
						}


						if($files = getFilesDB($count['details']['count_guid'], $err)){

							foreach($files as $k=>$v){

								$key = $v['s3_key'];
								$filename = str_replace("/", "-", $v['filename']);

								$file_type = $v['file_type'];
								$ext = str_replace(".", "", $v['ext']);
								$source = $v['source'];

								//debugTrace($key);

								try {
								    // Save object to a file.

								    $result = $s3->getObject(array(
								        'Bucket' => S3_DATA_BUCKET,
								        'Key' => urldecode($key)
								    ));
										$data = $result['Body']->getContents();

										switch($ext){

										case "csv":

												if(@$fp = fopen(ROOT . "/reports/$study_id/$source/csv/$filename", 'w')){
													@fwrite($fp, $data);
													@fclose($fp);
												}
												break;

											case "pdf":

												if($fp = fopen(ROOT . "/reports/$study_id/$source/pdf/$filename", 'w')){
													@fwrite($fp, $data);
													@fclose($fp);
												}

												break;


											case "utdf":

												$peak_folder = '';
												$unix_peak = getFilenameTimestamp($filename);
												foreach($count['schedule'] as $schedule){
													$day = date("Y-m-d", $schedule['start_time']);
													$start = date("Hi", $schedule['start_time']);
													$end = date("Hi", $schedule['end_time']);
													if($unix_peak >= $schedule['start_time'] && $unix_peak <= $schedule['end_time'])
														$peak_folder = "{$day}_{$start}-{$end}";
												}
												if($fp = fopen(ROOT . "/reports/$study_id/$source/utdf/$peak_folder/$filename", 'w')){
													@fwrite($fp, $data);
													@fclose($fp);
												}

												break;

											case "xls":
											case "xlsx":

												if($fp = fopen(ROOT . "/reports/$study_id/$source/xls/$filename", 'w')){
													@fwrite($fp, $data);
													@fclose($fp);
												}

												break;

											case "jcd":

												if($fp = fopen(ROOT . "/reports/$study_id/$source/tes/$filename", 'w')){
													@fwrite($fp, $data);
													@fclose($fp);
												}

												break;

										}

								} catch (S3Exception $e) {
										$resultant['err'] = 1;
										$resultant['err'] = $e->getMessage();

								}

							}
						}
					}
				}
			}


	    $resultant['err'] = 0;
	    $resultant['message'] = "$study_id";
	    echo json_encode($resultant);

	} else {
	    $resultant['err'] = 1;
	    $resultant['message'] = "$study_guid: $err";
	    echo json_encode($resultant);
	}
	exit(0);

	break;

		case "process_anpr_aws_queue":

		$resultant['err'] = 0;

		$camera_id = str_replace("aws_", "", $_POST['camera_id']);
		$aws_queue_id = $_POST['aws_queue_id'];
		$aws_unix_start = $_SESSION['aws_unix_start'];
		$aws_unix_end = $_SESSION['aws_unix_end'];

		$link = dbConnect();

		$start = $_SESSION['aws_queue_current_count'] - 25;

		if($start < 0){
			$end = $start + 25;
			$start = 0;
		} else {
			$end = 25;
		}


		if($queue_process_array = getANPRRecordsDB($camera_id, $aws_unix_start, $aws_unix_end, $start, $end, $err)){

			//Let's process 25 lines:
			//require 'vendor/autoload.php';

			//Create a S3Client
			$client = new RekognitionClient([
				'version' => 'latest',
				'region' => 'us-east-1',
				'version' => 'latest',
				'credentials' =>array(
					'key'    => AWS_ACCESS_KEY_ID,
					'secret' => AWS_SECRET_ACCESS_KEY
				)
			]);

			//Loop through the array, upload to Amazon S3 Cloud + add to td_anpr_data
			foreach($queue_process_array as $file){


				$plate_src = $file['plate_src'];
				$vrm_aws = $file['vrm_aws'];
				$vrm_final = $file['vrm_final'];
				$id = $file['id'];

				if(is_null($vrm_aws)){

					$key = "$camera_id/$plate_src";

					$args = [
						'Image' => [
							'S3Object' => [
								'Bucket' => 'spectrum-anpr-photos',
								'Name' => "$key",
							]
						]
					];

					try {

						// Upload data.
						$text = $client->DetectText($args);

						if(count($text['TextDetections']) > 0){

							$result = "";
							foreach($text['TextDetections'] as $key=>$match){

								$plate = trim(str_replace(" ", "", $match['DetectedText']));
								$plate = trim(str_replace("-", "", $plate));
								$plate = trim(str_replace("*", "", $plate));

								if(strlen($plate) > 6 && strlen($plate) <=8){

									@mysqli_query($link, "UPDATE td_anpr_data SET vrm_aws='$plate' WHERE id = '$id'");

									$r = mysqli_query($link, "SELECT vrm_final FROM td_anpr_data WHERE id = '$id'");

									$record = mysqli_fetch_array($r,  MYSQLI_ASSOC);

									if(is_null($record['vrm_final'])){
										@mysqli_query($link, "UPDATE td_anpr_data SET vrm_final='$plate' WHERE id='$id'");
									}

									break;
								}

							}

						}

					} catch (S3Exception $e) {
					    $resultant['error'] = 1;
					    $resultant['message'] = $e->getMessage() . PHP_EOL;
					}



				}

				$_SESSION['aws_queue_current_count'] = $_SESSION['aws_queue_current_count'] -1;

			}

			$resultant['aws_queue_current_count'] = $_SESSION['aws_queue_current_count'];
			$resultant['aws_queue_total_count'] = $_SESSION['aws_queue_total_count'];
			$resultant['aws_queue_id'] = $aws_queue_id;

		} else {

			$resultant['err'] = 0;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);

	break;


	case "process_anpr_queue":

		$resultant['err'] = 0;

		$root =  $_SERVER['DOCUMENT_ROOT'] . "/anpr/data";

		$camera_id = $_POST['camera_id'];
		$queue_id = $_POST['queue_id'];
		$unix_start = $_SESSION['unix_start'];
		$unix_end = $_SESSION['unix_end'];

		$link = dbConnect();

		if($queue_process_array = getANPRLocalFilesDB($camera_id, $unix_start, $unix_end, 25, $err)){

			//Let's process 25 lines:
			require_once('MIME/Type.php');
			//require 'vendor/autoload.php';

			//Create a S3Client
			$s3 = new S3Client([
				'region' => 'us-east-1',
				'version' => 'latest',
				'credentials' =>array(
					'key'    => AWS_ACCESS_KEY_ID,
					'secret' => AWS_SECRET_ACCESS_KEY
				)
			]);

			//Loop through the array, upload to Amazon S3 Cloud + add to td_anpr_data
			foreach($queue_process_array as $file){

				$full_path = $file['full_path'];
				$filename = pathinfo($full_path, PATHINFO_BASENAME);
				$filename_no_ext = pathinfo($full_path, PATHINFO_FILENAME);
				$directory = pathinfo($full_path, PATHINFO_DIRNAME);
				$unixtime = strtotime($file['file_date']);

				if(file_exists($full_path) && file_exists("$directory/$filename_no_ext-p.jpg") && file_exists("$directory/$filename_no_ext-w.jpg")){

				    if($fp = fopen($full_path, 'rb')){

						$fp_p = fopen("$directory/$filename_no_ext" . "-p.jpg", 'rb');
						$p_contents = fread($fp_p, filesize("$directory/$filename_no_ext" . "-p.jpg"));

						$fp_w = fopen("$directory/$filename_no_ext" . "-w.jpg", 'rb');
						$w_contents = fread($fp_w, filesize("$directory/$filename_no_ext" . "-w.jpg"));

						$sql = "INSERT INTO td_anpr_data ";

						while(!feof($fp)){

						   $line = fgets($fp);
						   $matches = array();

						   preg_match_all('/:/', $line, $matches, PREG_PATTERN_ORDER);

						   if(count($matches[0])==1){

							  $arr = explode(":", $line);
							  $fields[] = trim($arr[0]);
							  $values[] = "'" . trim($arr[1]) . "'";

						   } elseif (count($matches[0]) > 1) {

							  $date = explode("-", $line);
							  $unixtime = strtotime($date[2] . "-" . $date[1] . "-" . $date[0] . " " . $date[3]);

							  $fields[] = "unixtime";
							  $values[] = $unixtime;

							  $fields[] = "rawtime";
							  $values[] = "'" . trim($line) . "'";
						   }
						}

						$fields[] = "camera_id";
						$values[] = "'$camera_id'";

						$fields[] = "plate_src";
						$values[] = "'$filename_no_ext-p.jpg'";

						$fields[] = "full_src";
						$values[] = "'$filename_no_ext-w.jpg'";

						$sql .= "(" . implode(", ", $fields) . ") VALUES (" . implode(", ", $values) . ")";

						if($result = mysqli_query($link, $sql)){

						   $p_type = mime_content_type($fp_p);
						   $fname_w = "$filename_no_ext" . "-w.jpg";
						   $fname_p = "$filename_no_ext" . "-p.jpg";
						   $fname_lpr = $filename;

						   try {
							   // Upload data.
							   $result = $s3->putObject([
								   'Bucket' => 'spectrum-anpr-photos',
								   'Key'    => "$camera_id/$fname_w",
								   'Body'   => $w_contents,
								   'ACL'    => 'public-read',
								   'ContentType' => mime_content_type($fp_w),
								   'StorageClass' => 'ONEZONE_IA'
							   ]);

						   } catch (S3Exception $e) {
							 $resultant['error'] = 1;
							 $resultant['message'] = $e->getMessage() . PHP_EOL;
						   }

						   try {
							   // Upload data.
							   $result2 = $s3->putObject([
								   'Bucket' => 'spectrum-anpr-photos',
								   'Key'    => "$camera_id/$fname_p",
								   'Body'   => $p_contents,
								   'ACL'    => 'public-read',
								   'ContentType' => mime_content_type($fp_p),
								   'StorageClass' => 'ONEZONE_IA'
							   ]);

						   } catch (S3Exception $e) {
							 $resultant['error'] = 1;
							 $resultant['message'] = $e->getMessage() . PHP_EOL;
						   }

						   if($resultant['error'] == 0){

								$fileObj['uploaded'] = 1;
								$fileObj['full_path'] = $full_path;
								updateGenericDB($fileObj, TABLE_ANPR_FILES, 'full_path', $msg, $err);
								$_SESSION['queue_current_count'] = $_SESSION['queue_current_count'] -1;

						   }

						} else {
						   $resultant['error'] = 1;
						   $resultant['message'] = mysqli_error($link);
						}


						unset($fields);
						unset($values);

						fclose($fp);
						fclose($fp_p);
						fclose($fp_w);

						//unlink files.
						/*
						unlink("$root/$camera_id/$fname_w");
						unlink("$root/$camera_id/$fname_p");
						unlink("$root/$camera_id/$fname_lpr");
						*/
					} else {
						 $resultant['error'] = 1;
						 $resultant['message'] = "Cannot open file " . $full_path;
					}

				}  else {
					$resultant['error'] = 1;
					$resultant['message'] = "Cannot open file " . $full_path;
				}

			}

			$resultant['queue_current_count'] = $_SESSION['queue_current_count'];
			$resultant['queue_total_count'] = $_SESSION['queue_total_count'];
			$resultant['queue_id'] = $queue_id;

		} else {

			$resultant['err'] = 0;
			$resultant['message'] = $err;
		}

		echo json_encode($resultant);
		exit(0);

	break;

	case "process_anpr_init":

		$camera_id = $_POST['camera_id'];
		$unix_start = $_POST['unix_start'];
		$unix_end = $_POST['unix_end'];

		$_SESSION['queue_id'] = createUniqueKey();
		$_SESSION['camera_id'] = $camera_id;
		$_SESSION['unix_start'] = $unix_start;
		$_SESSION['unix_end'] = $unix_end;

		//Let's read the queue file into an array
		$root =  $_SERVER['DOCUMENT_ROOT'] . "/anpr/data";

		$err = '';

		if($queue_array = getANPRLocalFilesDB($camera_id, $unix_start, $unix_end, null, $err)){

			//Loop through the queue_array, identify records that fall within our range.
			$resultant['err'] = 0;
			$resultant['queue_total_count'] = $resultant['queue_current_count'] = count($queue_array);
			$resultant['queue_id'] = $_SESSION['queue_id'];
			$_SESSION['queue_total_count'] = $_SESSION['queue_current_count'] =  count($queue_array);

		} else {
			//nothing to process!
			$resultant['err'] = 1;
			$resultant['message'] = "Nothing to process!";
		}

		//jsonTrace($resultant, 1);
		echo json_encode($resultant);

	break;

	case "process_plate_match_init":

		$origin_array = $_POST['origin_array'];
		$destination_array = $_POST['destination_array'];
		$unix_start = $_POST['unix_start'];
		$unix_end = $_POST['unix_end'];
		$levenshtein = $_POST['levenshtein'];
		$max_travel_time = $_POST['max_travel_time'];
		$min_travel_time = $_POST['min_travel_time'];


		$o_cameras = $d_cameras = "";

		foreach($origin_array as $key=>$manifest_guid){
			if($manifest = getManifestDB($manifest_guid, $err)){
				$o_cameras.="camera_id='" . $manifest['serial_no'] . "' OR ";
			}
		}

		foreach($destination_array as $key=>$manifest_guid){
			if($manifest = getManifestDB($manifest_guid, $err)){
				$d_cameras.="camera_id='" . $manifest['serial_no'] . "' OR ";
			}
		}

		$o_cameras = substr($o_cameras, 0, strlen($o_cameras) - 4);

		$d_cameras = substr($d_cameras, 0, strlen($d_cameras) - 4);


		if($link = dbConnect()){

			$sql = "SELECT COUNT(*) FROM td_anpr_data WHERE ($o_cameras) AND unixtime >= $unix_start AND unixtime <= $unix_end AND td_anpr_data.ignore = 0";

			if($o_result = mysqli_query($link, $sql)){
				$o_count = mysqli_fetch_row($o_result);
			}

			$sql2 = "SELECT COUNT(*) FROM td_anpr_data WHERE ($d_cameras) AND unixtime >= $unix_start AND unixtime <= $unix_end AND td_anpr_data.ignore = 0";

			if($d_result = mysqli_query($link, $sql2)){
				$d_count = mysqli_fetch_row($d_result);
			}

			if($o_count[0] > 0 && $d_count[0] >0){

				$resultant['err'] = 0;
				$resultant['o_total_count'] = $o_count[0];
				$resultant['d_total_count'] = $d_count[0];
				$resultant['message'] = "{$o_count[0]} plates to match against {$d_count[0]}";

				$_SESSION['plate_match_queue_id'] = $resultant['plate_match_queue_id'] = createUniqueKey();
				$_SESSION['plate_match_unix_start'] = $unix_start;
				$_SESSION['plate_match_unix_end'] = $unix_end;
				$_SESSION['o_cameras'] = $o_cameras;
				$_SESSION['d_cameras'] = $d_cameras;
				$_SESSION['plate_match_total_count'] = $_SESSION['plate_match_current_count'] =  $o_count[0];
				$_SESSION['levenshtein'] = $levenshtein;
				$_SESSION['max_travel_time'] = $max_travel_time;
				$_SESSION['min_travel_time'] = $min_travel_time;

			} else {

				$resultant['err'] = 1;
				$resultant['message'] = "Minumum origin and destination set not provided!";

			}


		} else {
			$resultant['err'] = 1;
			$resultant['message'] = mysqli_error($link);
		}

		echo json_encode($resultant);

	break;

		case "process_plate_match_queue":


		$resultant['err'] = 0;

		$plate_match_queue_id = $_SESSION['plate_match_queue_id'];
		$unix_start = $_SESSION['plate_match_unix_start'];
		$unix_end = $_SESSION['plate_match_unix_end'];

		$o_cameras = $_SESSION['o_cameras'];
		$d_cameras = $_SESSION['d_cameras'];

		$levenshtein = $_SESSION['levenshtein'];
		$max_travel_time = $_SESSION['max_travel_time'];
		$min_travel_time = $_SESSION['min_travel_time'];

		$link = dbConnect();

		$start = $_SESSION['plate_match_current_count'] - 5;

		if($start < 0){
			$end = $start + 5;
			$start = 0;
		} else {
			$end = 5;
		}

		$sql = "SELECT id, ucase(vrm_final) as vrm_final, td_anpr_data.ignore, unixtime, rawtime, plate_src, camera_id FROM td_anpr_data WHERE ($o_cameras) AND unixtime >= $unix_start AND unixtime <= $unix_end AND td_anpr_data.ignore = 0 ORDER BY unixtime ASC LIMIT $start, $end";

		if($result = mysqli_query($link, $sql)){

			while($o_plate = mysqli_fetch_assoc($result)){

				$max_time = $o_plate['unixtime'] + $max_travel_time;
				$min_time = $o_plate['unixtime'] + $min_travel_time;

				$sql2 = "SELECT id, ucase(vrm_final) as vrm_final, td_anpr_data.ignore, unixtime, rawtime, plate_src, camera_id FROM td_anpr_data WHERE ($d_cameras) AND unixtime >= $min_time AND unixtime <= $max_time AND td_anpr_data.ignore = 0 ORDER BY unixtime ASC";

				if($result2 = mysqli_query($link, $sql2)){
					while($d_plate = mysqli_fetch_assoc($result2)){
						$d_plates[] = $d_plate;
					}
				}


				foreach($d_plates as $k=>$d_plate){

					if($d_plate['unixtime'] != $o_plate['unixtime'] && $d_plate['unixtime'] > $o_plate['unixtime']){
						$elapsed_time = $d_plate['unixtime'] - $o_plate['unixtime'];
						if(strlen($d_plate['vrm_final'])>=3 && strlen($o_plate['vrm_final'])>=3){
							$lev = levenshtein(trim($o_plate['vrm_final']), trim($d_plate['vrm_final']));
							if($lev <= $levenshtein){
								$sql = "INSERT INTO td_anpr_matches (levenshtein, report_guid, origin_id, destination_id, origin_vrm, destination_vrm, origin_rawtime, destination_rawtime, origin_camera_id, destination_camera_id, origin_src, destination_src, elapsed_time)
								VALUES ('$lev','$plate_match_queue_id', '{$o_plate['id']}', '{$d_plate['id']}', '{$o_plate['vrm_final']}', '{$d_plate['vrm_final']}', '{$o_plate['rawtime']}', '{$d_plate['rawtime']}', '{$o_plate['camera_id']}', '{$d_plate['camera_id']}', '{$o_plate['plate_src']}', '{$d_plate['plate_src']}', $elapsed_time)";
								@mysqli_query($link, $sql);
							}
						}
					}

				}

				$_SESSION['plate_match_current_count'] = $_SESSION['plate_match_current_count'] - 1;
			}

			$resultant['plate_match_current_count'] = $_SESSION['plate_match_current_count'];
			$resultant['plate_match_total_count'] = $_SESSION['plate_match_total_count'];
			$resultant['plate_match_queue_id'] = $plate_match_queue_id;
			$resultant['err'] = 0;

		} else {

			$resultant['err'] = 1;
			$resultant['message'] = mysqli_error($link);

		}

		echo json_encode($resultant);
		exit(0);

	break;


	case "process_anpr_aws_init":

		$camera_id = $_POST['camera_id'];
		$unix_start = $_POST['unix_start'];
		$unix_end = $_POST['unix_end'];

		$_SESSION['aws_queue_id'] = createUniqueKey();
		$_SESSION['aws_camera_id'] = $camera_id;
		$_SESSION['aws_unix_start'] = $unix_start;
		$_SESSION['aws_unix_end'] = $unix_end;

		//Let's read the queue file into an array
		$root =  $_SERVER['DOCUMENT_ROOT'] . "/anpr/data";

		$err = '';

		$camera_id = str_replace("aws_", "", $camera_id);

		if($aws_queue_array = getANPRRecordsDB($camera_id, $unix_start, $unix_end, null, null, $err)){

			//Loop through the queue_array, identify records that fall within our range.
			$resultant['err'] = 0;
			$resultant['aws_queue_total_count'] = $resultant['aws_queue_current_count'] = count($aws_queue_array);
			$resultant['aws_queue_id'] = $_SESSION['aws_queue_id'];
			$_SESSION['aws_queue_total_count'] = $_SESSION['aws_queue_current_count'] =  count($aws_queue_array);

		} else {
			//nothing to process!
			$resultant['err'] = 1;
			$resultant['message'] = "Nothing to process!";
		}

		$resultant['err'] = 0;

		echo json_encode($resultant);

	break;

	case "get_equipment_actions":


		$resultant['err'] = 0;

		if(isset($_POST['start']) && isset($_POST['end'])){

			$start = (double)$_POST['start'];
			$end = (double)$_POST['end'];
			$resultant['actions'] =  getActionsDB($start, $end, NULL, $err);

			foreach($resultant['actions'] as $key=>$action){

				//if($action[''])

			}

		} else {

			$resultant['err'] = 1;
			$resultant['message'] = $err;

		}

		echo json_encode($resultant);
		exit(0);

		break;

	case "authenticate_passkey":


		$resultant['err'] = 0;
		$resultant['message'] = '';

		$passkey = $_POST['passkey'];

		if(!$user = authenticatePasskeyDB($passkey, $err)){
			$resultant['err'] = 1;
			$resultant['mesage'] = $err;
		} else {
			unset($_SESSION['quickbooks']['token']);
			$_SESSION['user_guid']=$user['user_guid'];
			$_SESSION['username']=$user['username'];
		}


		echo json_encode($resultant);
		exit(0);
		break;

	case "resync_anpr":

		$equipment_list = getEquipmentDB($err);
		$anpr_list = array();

		foreach($equipment_list as $key=>$equipment){
			if(strstr($equipment['name'], 'ANPR')){
				$anpr_list[$equipment['serial_no']] = $equipment;
			}
		}

		if(count($anpr_list)>0){

			foreach($anpr_list as $key=>$anpr){

				$root =  $_SERVER['DOCUMENT_ROOT'] . "/anpr/data/" . $anpr['serial_no']  . "/\*.lpr";
				$files = glob($root);
				$anpr_list[$key]['plate_count'] = count($files);

				$first_record = $files[0];

				if(preg_match("/[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9]{2}:[0-9]{2}:[0-9]{2}(.)*\.lpr/", $files[0], $match)){
					$min_date_time = explode("_", $match[0]);
					$anpr_list[$key]['min_date'] = $min_date_time[0];
					$min_date_time_array = explode("-", $min_date_time[0]);
					$anpr_list[$key]['min_unixtime'] = strtotime($min_date_time_array[0] . "-" . $min_date_time_array[1] . "-" . $min_date_time_array[2] . " " . $min_date_time_array[3]);
				}

				if(preg_match("/[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9]{2}:[0-9]{2}:[0-9]{2}(.)*\.lpr/", $files[count($files)-1], $match)){
					$max_date_time = explode("_", $match[0]);
					$anpr_list[$key]['max_date'] = $max_date_time[0];
					$max_date_time_array = explode("-", $max_date_time[0]);
					$anpr_list[$key]['max_unixtime'] = strtotime($max_date_time_array[0] . "-" . $max_date_time_array[1] . "-" . $max_date_time_array[2] . " " . $max_date_time_array[3]);
				}

				$sql = "INSERT INTO " . TABLE_ANPR_FILES . " (camera_id, full_path, file_date) VALUES ";


				foreach($files as $index=>$file){

					preg_match("/[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9]{2}:[0-9]{2}:[0-9]{2}/", $file, $f_datetime);

					if(count($f_datetime)>0){
						$f_datetime_array = explode("-", $f_datetime[0]);
						$f_unixtime = strtotime($f_datetime_array[0] . "-" . $f_datetime_array[1] . "-" . $f_datetime_array[2] . " " . $f_datetime_array[3]);
						$n_datetime = date("Y-m-d H:i:s", $f_unixtime);
					}

					$sql .= "('" . $anpr['serial_no'] .  "', '$file', '". $n_datetime . "'), ";
				}

				$sql = substr($sql, 0, strlen($sql) - 2);

				if(count($files)>0){
					if($link = dbConnect()){

						//flush out existing files.
						$d_sql = "DELETE FROM " . TABLE_ANPR_FILES . " WHERE camera_id = '" . $anpr['serial_no'] . "'";

						@mysqli_query($link, $d_sql);

						if(!$result = mysqli_query($link, $sql)){

							//handle the error here.

						} else {

							//add min / max values
							$min_max_sql = "SELECT MIN(file_date) AS min_date, UNIX_TIMESTAMP(MIN(file_date)) AS min_unixtime, MAX(file_date) AS AS max_date, UNIX_TIMESTAMP(MAX(file_date)) AS max_unixtime FROM " . TABLE_ANPR_FILES . "  WHERE camera_id = '". $anpr['serial_no'] . "'";

							if($r_min_max = mysqli_query($link, $min_max_sql)){

								$record = mysqli_fetch_assoc($r_min_max);

								$anpr_list[$key]['min_date'] = $record['min_date'];
								$anpr_list[$key]['min_unixtime'] = $record['min_unixtime'];

								$anpr_list[$key]['max_date'] = $record['max_date'];
								$anpr_list[$key]['max_unixtime'] = $record['max_unixtime'];

							}
						}

					}
				}

			}
		} else {

			$resultant['err'] = 1;
			$resultant['message'] = "Anpr list less than 1: $err";
		}

		//recreate anpr.ini file
		if(file_exists($_SERVER['DOCUMENT_ROOT'] . "/anpr/data/anpr.ini")){

			$fh = fopen($_SERVER['DOCUMENT_ROOT'] . "/anpr/data/anpr.ini", "w");

			fwrite($fh, "last_sync," . time() . "\n");
			fwrite($fh, "total_cameras," . count($anpr_list) . "\n");
			fwrite($fh, "BOR\n");
			foreach($anpr_list as $key=>$anpr){
				fwrite($fh, $anpr['serial_no'] . "," . $anpr['plate_count'] . "," . $anpr['min_date'] . "," . $anpr['min_unixtime'] . "," . $anpr['max_date'] . "," . $anpr['max_unixtime'] . "\n");
			}

			fwrite($fh, "EOR\n");
			fclose($fh);
			$resultant['err'] = 0;

		} else {
			$resultant['error'] = 1;
			$resultant['message'] = "anpr.ini file does not exist on server!";
		}

		echo json_encode($resultant);
		exit(0);


	break;

	default:
		$resultant['err'] = 1;
		$resultant['message'] = "AJAX function not implemented: '$act'";
		echo json_encode($resultant);
		exit(0);
}


function returnJsonErrorAndDie($error){
		$resultant['err'] = 1;
		$resultant['message'] = "$error";
		echo json_encode($resultant);
		die();
}


function uploadReportToCloud($study_id,$count, $report_type, &$smarty, &$err)
{

	//require 'vendor/autoload.php';

	//Create a S3Client
	$s3 = new S3Client([
	  'region' => 'us-east-1',
	  'version' => 'latest',
	  'credentials' =>array(
	    'key'    => AWS_ACCESS_KEY_ID,
	    'secret' => AWS_SECRET_ACCESS_KEY
	  )
	]);

	$resultant['err']=0;
	$count_guid = $count['details']['count_guid'];
	$location_name = $count['details']['major_street'] . ' & '. $count['details']['minor_street'];
	$major_street = $count['details']['major_street'];
	$minor_street = $count['details']['minor_street'];

	$extensions = array('pdf'=>'application/pdf','xlsx'=>'application/vnd.ms-excel','utdf'=>'text/csv', 'jcd'=>'text/jcd');

	$ftype = $extensions[$report_type];


	if(count($count['schedule'])){
		$date = date("Y-m-d", $count['schedule'][0]['start_time']);
	} else {
		$date = "";
	}

	$fname = str_replace('/','-',$location_name)."_"."$date.".$report_type;

	mkdir("files/counts/".$count['details']['count_guid']);

	//$result = FALSE;

	$result = array();
	if($report_type == 'pdf'){

		$content = controller::pdf_report($smarty,$count_guid, $count['details']['customer_guid'], TRUE,TRUE, TRUE, $err);
		$result[] = array('filename'=>$fname,'content'=>$content);

	} else if($report_type=='xlsx'){


		if($count['details']['service_type_id'] == 8){

			$content = controller::scg_report($count_guid,TRUE,$err);
			$result[] = array('filename'=>$fname,'content'=>$content);

		} elseif($count['details']['service_type_id'] == 6 || $count['details']['service_type_id'] == 16) {

			//VSC - class & speed files.
			$content = controller::xls_vsc_report($count_guid,TRUE,$err);

			$major_street = str_replace("/", "-", $count['details']['major_street']);
			$minor_street = str_replace("/", "-", $count['details']['minor_street']);
			$study_id = $study['study_id'];

			if(count($count['schedule'])){
					$date = date("Y-m-d", $count['schedule'][0]['start_time']);
			} else {
					$date = "";
			}

			$filename = "$major_street & $minor_street" . "_" . "$date";
			$filename_class = $filename . '_class.xls';
			$filename_speed = $filename . '_speed.xls';

			$result[] = array('filename'=>$filename_class,'content'=>$content);
			$result[] = array('filename'=>$filename_speed,'content'=>$content);

		} else {

			$content = controller::xls_report($count_guid,TRUE,$err);
			$result[] = array('filename'=>$fname,'content'=>$content);

		}


	}else if($report_type=='utdf'){

		$result = controller::utdf_peak_report_array($smarty,$count_guid,$err);

	} else if($report_type=='jcd'){

		$content = controller::jcd_report($count_guid, NULL, $err);
		$result[] = array('filename'=>$fname,'content'=>$content);

	}

	if(!$result[0]['content']){
		$resultant['err'] = 1;
		$resultant['message'] = $err;
		return $resultant;
	}

	foreach($result as $file){  //utdf is a file for each peak

		$fname = $file['filename'];
		$filepath = "./files/counts/$count_guid/$fname";
		$file_guid=createUniqueKey();

		$mio_id = $count['details']['mio_id'];

		$key = urlencode("$file_guid/$fname");

		if(strlen($file['content']) > 1){
			$contents = $file['content'];//utdf
			$fsize = strlen($file['content']);
			$content_type = "text/csv";
		}else{
			$contents = file_get_contents($filepath);
			$fsize = filesize($filepath);
			$content_type = mime_content_type($filepath);
		}

		try {

		  // Upload data.

		  $out = $s3->putObject([
		    'Bucket' => S3_DATA_BUCKET,
		    'Key'    => "$file_guid/$fname",
		    'Body'   => $contents,
		    'ACL'    => 'public-read',
				'ContentType' => $content_type,
		    'StorageClass' => 'ONEZONE_IA'
		  ]);

		  $res = @addFileDB($key, $file_guid, $count_guid, $fname, $fsize, $ftype, ".$report_type", time(), 'S3', $mio_id,'SPT', $err);

			if(!$res){
				$resultant['err'] = 1;
				$resultant['message'] = "$fname:\n $err";
				return $resultant;//stop generating reports for other counts
			}

		} catch (S3Exception $e) {
		  $resultant['error'] = 1;
		  $resultant['message'] = $e->getMessage() . PHP_EOL;
		}

		sleep(2);


	}
	return $resultant;
}

function initQB(){

	include_once('QuickBooks/accounting.inc.v3.php');

}

?>
