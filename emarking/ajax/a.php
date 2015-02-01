<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * @package mod
 * @subpackage emarking
 * @copyright 2012 Jorge Villalón {@link http://www.uai.cl}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define ( 'AJAX_SCRIPT', true );
define ( 'NO_DEBUG_DISPLAY', true );

require_once (dirname ( dirname ( dirname ( dirname ( __FILE__ ) ) ) ) . '/config.php');
require_once ($CFG->libdir . '/formslib.php');
require_once ($CFG->libdir . '/gradelib.php');
require_once ("$CFG->dirroot/grade/grading/lib.php");
require_once $CFG->dirroot . '/grade/lib.php';
require_once ("$CFG->dirroot/grade/grading/form/rubric/lib.php");
require_once ("$CFG->dirroot/lib/filestorage/file_storage.php");
require_once ($CFG->dirroot . "/mod/emarking/locallib.php");

global $CFG, $DB, $OUTPUT, $PAGE, $USER;

// Required and optional params for ajax interaction in emarking
$ids = required_param ( 'ids', PARAM_INT );
$action = required_param ( 'action', PARAM_ALPHA );
$pageno = optional_param ( 'pageno', 0, PARAM_INT );
$testingmode = optional_param ( 'testing', false, PARAM_BOOL );

// If we are in testing mode then submission 1 is the only one admitted
if ($testingmode) {
	$username = required_param ( 'username', PARAM_ALPHANUMEXT );
	$password = required_param ( 'password', PARAM_RAW_TRIMMED );
	
	if (! $user = authenticate_user_login ( $username, $password ))
		emarking_json_error ( 'Invalid username or password' );
	
	complete_user_login ( $user );
	
	// Limit testing to submission id 1
	$ids = 1;
}

// If it's just a heartbeat, answer as quickly as possible
if ($action === 'heartbeat') {
	emarking_json_array ( array (
			'time' => time () 
	) );
}

// A valid submission is required
if (! $submission = $DB->get_record ( 'emarking_draft', array (
		'id' => $ids 
) )) {
	emarking_json_error ( 'Invalid submission' );
}

// The submission's student
$userid = $submission->student;

// User object for student
if (! $user = $DB->get_record ( 'user', array (
		'id' => $userid 
) )) {
	emarking_json_error ( 'Invalid user from submission' );
}

// Assignment to which the submission belong

if (! $emarking = $DB->get_record ( "emarking", array (
		"id" => $submission->emarkingid 
) )) {
	emarking_json_error ( 'Invalid assignment' );
}

// PROGRESS BAR

if ($emarking->experimentalgroups == 2) { // When draft has overlap
	$groupwhere = " AND groupid <> 0 ";
} else {
	$groupwhere = " AND groupid = 0 ";
}

// Progress querys
$totaltest = $DB->count_records_sql ( "SELECT COUNT(*) from {emarking_draft} WHERE  emarkingid = $emarking->id $groupwhere" );
$inprogesstest = $DB->count_records_sql ( "SELECT COUNT(*) from {emarking_draft} WHERE  emarkingid = $emarking->id $groupwhere AND status = 15" );
$publishtest = $DB->count_records_sql ( "SELECT COUNT(*) from {emarking_draft} WHERE  emarkingid = $emarking->id $groupwhere AND status > 15" );
// Agree level query
$agreeRecords = $DB->get_records_sql ( "
		SELECT d.id, STDDEV(d.grade)*2/6 as dispersion, d.submissionid, count(d.id) as conteo
		FROM {emarking_draft} d
		INNER JOIN {emarking_submission} s ON s.emarking = $emarking->id AND s.id = d.submissionid
		INNER JOIN mdl_emarking_page p ON p.submission = d.id
		INNER JOIN mdl_emarking_comment c ON c.page= p.id 
		WHERE d.groupid <> 0  
		GROUP by d.submissionid
		HAVING COUNT(*) > 1" );

// Set agree level average of all active grading assignments
if ($agreeRecords) {
	$agreeLevel = array ();
	foreach ( $agreeRecords as $dispersion ) {
		
		$agreeLevel [] = ( float ) $dispersion->dispersion;
	}
	$agreeLevelAvg = round ( 100 * (1 - (array_sum ( $agreeLevel ) / count ( $agreeLevel ))), 1 );
} else {
	$agreeLevelAvg = 0;
}

// Set agree level average of current active assignment
$agreeAssignment = $DB->get_record_sql ( "SELECT d.submissionid, STDDEV(d.grade)*2/6 as dispersion, count(d.id) as conteo
										FROM mdl_emarking_draft d
										WHERE d.groupid <> 0 AND d.submissionid = $submission->submissionid 
										GROUP BY d.submissionid" );
if ($agreeAssignment) {
	$agreeAsignmentLevelAvg = $agreeAssignment->dispersion;
} else {
	$agreeAssignmentLevelAvg = 0;
}

$anonymous = $emarking->anonymous === "1";

$submissionid = $submission->id;

// The course to which the assignment belongs
if (! $course = $DB->get_record ( "course", array (
		"id" => $emarking->course 
) )) {
	emarking_json_error ( 'Invalid course' );
}

// The marking process course module
if (! $cm = get_coursemodule_from_instance ( "emarking", $emarking->id, $course->id )) {
	emarking_json_error ( 'Invalid emarking course module' );
}

// Verify that user is logged in, otherwise return error
if (! isloggedin () && ! $testingmode)
	emarking_json_error ( 'User is not logged in', array (
			'url' => $CFG->wwwroot . '/login/index.php' 
	) );
	
	// Create the context within the course module
$context = context_module::instance ( $cm->id );

$usercangrade = has_capability ( 'mod/emarking:grade', $context );
$usercanregrade = has_capability ( 'mod/emarking:regrade', $context );
$issupervisor = has_capability ( 'mod/emarking:supervisegrading', $context ) || is_siteadmin ( $USER );
$isgroupmode = $cm->groupmode == SEPARATEGROUPS;

if ($USER->id != $userid && ! $usercangrade) {
	$anonymous = true;
}

if ($submission->status >= EMARKING_STATUS_RESPONDED && ! $usercanregrade) {
	$readonly = true;
}

// Get markers info
$markers = get_enrolled_users ( $context, 'mod/emarking:grade' );
$markersToSend = array ();
foreach ( $markers as $marker ) {
	$markersToSend [] = array (
			
			"id" => $marker->id,
			"username" => $marker->username,
			"firstname" => $marker->firstname,
			"lastname" => $marker->lastname 
	);
}

// Get actual user role
$userRole = null;
if ($usercangrade == 1 && $issupervisor == 0) {
	$userRole = "marker";
} else if ($usercangrade == 1 && $issupervisor == 1) {
	$userRole = "teacher";
}

if ($emarking->experimentalgroups != 0) {
	
	$experimentalgroup = $DB->get_record_sql ( "SELECT *
							FROM {emarking_experimental_groups}
							WHERE 	emarkingid = $emarking->id AND 
									groupid IN (
										SELECT groupid 
										FROM {groups_members} 
										WHERE userid = $userid
												
									)" );
	if ($experimentalgroup) {
		$linkrubric = $experimentalgroup->linkrubric;
	} else {
		$linkrubric = 0;
	}
} else {
	$linkrubric = $emarking->linkrubric;
}
// $totaltest, $inprogesstest, $publishtest
// Ping action for fast validation of user logged in and communication with server
if ($action === 'ping') {
	emarking_json_array ( array (
			'user' => $USER->id,
			'student' => $userid,
			'username' => $USER->firstname . " " . $USER->lastname,
			'realUsername' => $USER->username, // real username, not name and lastname.
			'role' => $userRole,
			'groupID' => $emarking->id, // emarkig->id assigned to groupID for chat and wall rooms.
			'sesskey' => $USER->sesskey,
			'anonymous' => $anonymous,
			'hascapability' => $usercangrade,
			'supervisor' => $issupervisor,
			'markers' => json_encode ( $markersToSend ),
			'totalTests' => $totaltest, // Progress bar indicator
			'inProgressTests' => $inprogesstest, // Progress bar indicator
			'publishedTests' => $publishtest, // Progress bar indicator
			'agreeLevel' => $agreeLevelAvg, // General agree bar indicator (avg of all overlapped students).
			'heartbeat' => $emarking->heartbeatenabled,
			'linkrubric' => $linkrubric,
			'collaborativefeatures' => $emarking->collaborativefeatures 
	) );
}

// Now require login so full security is checked
require_login ( $course->id, false, $cm );

$url = new moodle_url ( '/mod/emarking/ajax/a.php', array (
		'ids' => $ids,
		'action' => $action,
		'pageno' => $pageno 
) );

$readonly = true;
// Validate grading capability and stop and log unauthorized access
if (! $usercangrade) {
	// If the student owns the exam
	if ($USER->id == $userid) {
		$readonly = true;
	} else if (has_capability ( 'mod/emarking:submit', $context )) { // If the student belongs to the course and is allowed to submit
		$readonly = true;
		$anonymous = true;
	} else { // This is definitely a hacking attempt
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		// Add to Moodle log so some auditing can be done
		\mod_emarking\event\unauthorized_granted::create ( $item )->trigger ();
		emarking_json_error ( 'Unauthorized access!' );
	}
} else {
	$readonly = false;
}

// Switch according to action
switch ($action) {
	
	case 'emarking' :
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		// Add to Moodle log so some auditing can be done
		\mod_emarking\event\emarking_graded::create ( $item )->trigger ();
		
		$module = new stdClass ();
		include "../version.php";
		include "view/emarking.php";
		break;
	
	case 'getsubmission' :
		
		include "qry/getSubmissionGrade.php";
		$output = $results;
		emarking_json_array ( $output );
		break;
	
	case 'getcomments' :
		
		include "qry/getCommentsSubmission.php";
		emarking_json_resultset ( $results );
		break;
	
	case 'getstudents' :
		
		include "qry/getStudentsInMarking.php";
		emarking_json_resultset ( $results );
		break;
	
	case 'gettab' :
		
		list ( $imageurl, $imgwidth, $imgheight, $pagecount ) = emarking_get_page_image ( $pageno, $submission, $anonymous, $context->id );
		if (strlen ( $imageurl ) == 0)
			emarking_json_error ( 'Image is empty' );
		$r = random_string ( 5 );
		$output = array (
				'tabsNumber' => $pagecount,
				'imageurl' => $imageurl . '?r=' . $r,
				'width' => $imgwidth,
				'height' => $imgheight 
		);
		emarking_json_array ( $output );
		break;
	
	case 'rotatepage' :
		if (! $issupervisor) {
			emarking_json_error ( 'Invalid access' );
		}
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\rotatepage_switched::create ( $item )->trigger ();
		
		list ( $imageurl, $anonymousurl, $imgwidth, $imgheight ) = emarking_rotate_image ( $pageno, $submission, $context );
		if (strlen ( $imageurl ) == 0)
			emarking_json_error ( 'Image is empty' );
		$output = array (
				'imageurl' => $imageurl,
				'anonymousimageurl' => $anonymousurl,
				'width' => $imgwidth,
				'height' => $imgheight 
		);
		emarking_json_array ( $output );
		break;
	
	case 'getalltabs' :
		
		$resultset = emarking_get_all_pages ( $emarking, $submission, $anonymous, $context );
		emarking_json_resultset ( $resultset );
		break;
	
	case 'sortpages' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\sortpage_switched::create ( $item )->trigger ();
		
		$neworder = required_param ( 'neworder', PARAM_SEQUENCE );
		$neworderarr = explode ( ',', $neworder );
		if (! emarking_sort_submission_pages ( $submission, $neworderarr )) {
			emarking_json_error ( 'Error trying to resort pages!' );
		}
		$output = array (
				'neworder' => $neworder 
		);
		emarking_json_array ( $output );
		break;
	
	case 'prevcomments' :
		
		include "qry/getPreviousCommentsSubmission.php";
		emarking_json_resultset ( $results );
		break;
	
	case 'getrubric' :
		
		include "qry/getRubricSubmission.php";
		emarking_json_resultset ( $results );
		break;
	
	case 'addcomment' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\addcomment_added::create ( $item )->trigger ();
		
		include "act/actCheckGradePermissions.php";
		include "act/actAddComment.php";
		emarking_json_array ( $output );
		break;
	
	case 'addregrade' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\addregrade_added::create ( $item )->trigger ();
		
		//include "act/actCheckRegradePermissions.php";
		
		include "act/actRegrade.php";
		emarking_json_array ( $output );
		break;
	
	case 'updcomment' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\updcomment_updated::create ( $item )->trigger ();
		
		include "act/actCheckGradePermissions.php";
		
		include "qry/updComment.php";
		emarking_json_array ( array (
				'message' => 'Success!',
				'newgrade' => $newgrade,
				'timemodified' => time () 
		) );
		break;
	
	case 'deletecomment' :
		
		// Add to Moodle log so some auditing can be done
		/*
		 * $item = array (
		 * 'context' => context_module::instance ( $cm->id ),
		 * 'objectid' => $cm->id,
		 * );
		 * \mod_emarking\event\deletecomment_deleted::create ( $item )->trigger ();
		 */
		// include "act/actCheckGradePermissions.php";
		
		include "act/actDeleteComment.php";
		// emarking_json_array ( $output );
		break;
	
	case 'getsimilaranswers' :
		
		include "qry/getSimilarAnswers.php";
		emarking_json_error ( 'Invalid parameters' );
		break;
	
	case 'getnextsubmission' :
		
		$nextsubmission = emarking_get_next_submission ( $emarking, $submission, $context, $user );
		emarking_json_array ( array (
				'nextsubmission' => $nextsubmission 
		) );
		break;
	
	case 'addmark' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\addmark_added::create ( $item )->trigger ();
		
		include "act/actCheckGradePermissions.php";
		
		include "act/actAddMark.php";
		emarking_json_array ( $output );
		break;
	
	case 'regrade' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\regrade_graded::create ( $item )->trigger ();
		
		include "act/actRegrade.php";
		emarking_json_array ( $output );
		break;
	
	case 'deletemark' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\deletemark_deleted::create ( $item )->trigger ();
		
		include "act/actCheckGradePermissions.php";
		
		include "act/actDeleteMark.php";
		emarking_json_array ( $output );
		break;
	
	case 'finishmarking' :
		
		// Add to Moodle log so some auditing can be done
		$item = array (
				'context' => context_module::instance ( $cm->id ),
				'objectid' => $cm->id 
		);
		\mod_emarking\event\marking_ended::create ( $item )->trigger ();
		
		include "act/actCheckGradePermissions.php";
		include "qry/getRubricSubmission.php";
		include "act/actFinishMarking.php";
		
		emarking_json_array ( $output );
		break;
	
	default :
		emarking_json_error ( 'Invalid action!' );
}
?>