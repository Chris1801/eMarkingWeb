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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library of interface functions and constants for module emarking
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 * All the emarking specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_emarking
 * @copyright  2013 Jorge Villalón
 * @copyright  2014 Nicolas Perez <niperez@alumnos.uai.cl>
 * @copyright  2014 Carlos Villarroel <cavillarroel@alumnos.uai.cl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Print orders status
define('EMARKING_EXAM_UPLOADED',1);
define('EMARKING_EXAM_SENT_TO_PRINT',2);
define('EMARKING_EXAM_PRINTED',3);

// Grading status
define ( 'EMARKING_STATUS_MISSING', 0 );//not submitted
define ( 'EMARKING_STATUS_ABSENT', 5 );//absent
define ( 'EMARKING_STATUS_SUBMITTED', 10 );//submitted
define ( 'EMARKING_STATUS_GRADING', 15 );//Feedback generated.
define ( 'EMARKING_STATUS_RESPONDED', 20 );//Feedback generated.
define ( 'EMARKING_STATUS_REGRADING', 30 );//Student did not accept. asked for regrade
define ( 'EMARKING_STATUS_REGRADING_RESPONDED', 35); // Regrades were processed
define ( 'EMARKING_STATUS_ACCEPTED' , 40 ); // Student accepted the submission

// Regrade motives
define('EMARKING_REGRADE_MISASSIGNED_SCORE',1);
define('EMARKING_REGRADE_UNCLEAR_FEEDBACK',2);
define('EMARKING_REGRADE_STATEMENT_PROBLEM',3);
define('EMARKING_REGRADE_OTHER',10);

////////////////////////////////////////////////////////////////////////////////
// Moodle core API                                                            //
////////////////////////////////////////////////////////////////////////////////

/**
 * Returns the information on whether the module supports a feature
 *
 * @see plugin_supports() in lib/moodlelib.php
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
*/
function emarking_supports($feature) {
	switch($feature) {
		case FEATURE_GRADE_HAS_GRADE:	return true;
		case FEATURE_ADVANCED_GRADING:  return true;

		default:                        return null;
	}
}

/**
 * Saves a new instance of the emarking into the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $emarking An object from the form in mod_form.php
 * @param mod_emarking_mod_form $mform
 * @return int The id of the newly inserted emarking record
 */
function emarking_add_instance(stdClass $data, mod_emarking_mod_form  $mform = null) {
	global $DB,$CFG;
	$data->timecreated = time();
	$id = $DB->insert_record('emarking', $data);
	$data->id = $id;
	emarking_grade_item_update($data);

	return $id;
}

/**
 * Updates an instance of the emarking in the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object|\stdClass $emarking An object from the form in mod_form.php
 * @param mod_emarking_mod_form $mform
 * @return boolean Success/Fail
 */
function emarking_update_instance(stdClass $emarking, mod_emarking_mod_form $mform = null) {
	global $DB,$CFG,$COURSE;
	
	if(!isset($mform->get_data()->linkrubric)){
		$emarking->linkrubric=0;
	}
	
	if(!isset($mform->get_data()->collaborativefeatures)){
		$emarking->collaborativefeatures=0;
	}
	
	if(!isset($mform->get_data()->experimentalgroups)){
		$emarking->experimentalgroups=0;
		$DB->delete_records("emarking_experimental_groups", array("emarkingid"=>$emarking->instance));
	}else{
		$groups = $DB->get_records("groups", array("courseid"=>$COURSE->id));
		foreach ($groups as $group){
			if(!$DB->get_record("emarking_experimental_groups", array("emarkingid"=>$emarking->instance, "groupid"=>$group->id))){
				$expGroup = new stdClass();
				$expGroup->emarkingid = $emarking->instance;
				$expGroup->groupid = $group->id;
				$expGroup->datestart = time();
				$expGroup->dateend = time() + 30*24*60*60;
				$DB->insert_record("emarking_experimental_groups", $expGroup);
			}
		}
		
	}

	if(!isset($mform->get_data()->enableduedate)) {
		$emarking->markingduedate=NULL;
	}
	$emarking->timemodified = time();
	$emarking->id = $emarking->instance;
	$ret= $DB->update_record('emarking', $emarking);

	emarking_grade_item_update($emarking);
	
	return $ret;
}

/**
 * Removes an instance of the emarking from the database
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function emarking_delete_instance($id) {
	global $DB;

	if (! $emarking = $DB->get_record('emarking', array('id' => $id))) {
		return false;
	}

	$result = true;

	// Delete dependent records

	if(!$DB->delete_records('emarking', array('id' => $emarking->id))) {
		$result = false;
	}
	
	$submissions = $DB->get_records('emarking_submission', array('emarking'=>$emarking->id));

	foreach($submissions as $submission) {	
		if (! $DB->delete_records('emarking_submission', array('id'=>$submission->id))) {
			$result = false;
		}
	
	$drafts =  $DB->get_records('emarking_draft', array('emarkingid'=> $emarking->id) );
	foreach ($drafts as $draft){
		if (! $DB->delete_records('emarking_draft', array('id'=>$submissions->id))) {
			$result = false;
		}
		$pages = $DB->get_records('emarking_page', array('submission'=>$submission->id));

		foreach($pages as $page) {
			if (! $DB->delete_records('emarking_comment', array('page'=>$page->id))) {
				$result = false;
			}
		}
		
		if (! $DB->delete_records('emarking_page', array('submission'=>$submission->id))) {
			$result = false;
		}
		
		if (! $DB->delete_records('emarking_regrade', array('submission'=>$submission->id))) {
			$result = false;			
		}
	}
	}

	// We do not delete exams for DB purposes

	return $result;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param $course
 * @param $user
 * @param $mod
 * @param $emarking
 * @return stdClass|null
 */
function emarking_user_outline($course, $user, $mod, $emarking) {

	$return = new stdClass();
	$return->time = 0;
	$return->info = '';
	return $return;
}

/**
 * Prints a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param stdClass $course the current course record
 * @param stdClass $user the record of the user we are generating report for
 * @param cm_info $mod course module info
 * @param stdClass $emarking the module instance record
 * @return void, is supposed to echp directly
 */
function emarking_user_complete($course, $user, $mod, $emarking) {
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in emarking activities and print it out.
 * Return true if there was output, or false is there was none.
 *
 * @return boolean
 */
function emarking_print_recent_activity($course, $viewfullnames, $timestart) {
	return false;  //  True if anything was printed, otherwise false
}

/**
 * Prepares the recent activity data
 *
 * This callback function is supposed to populate the passed array with
 * custom activity records. These records are then rendered into HTML via
 * {@link emarking_print_recent_mod_activity()}.
 *
 * @param array $activities sequentially indexed array of objects with the 'cmid' property
 * @param int $index the index in the $activities to use for the next record
 * @param int $timestart append activity since this time
 * @param int $courseid the id of the course we produce the report for
 * @param int $cmid course module id
 * @param int $userid check for a particular user's activity only, defaults to 0 (all users)
 * @param int $groupid check for a particular group's activity only, defaults to 0 (all groups)
 * @return void adds items into $activities and increases $index
 */
function emarking_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0) {
}

/**
 * Prints single activity item prepared by {@see emarking_get_recent_mod_activity()}

 * @return void
 */
function emarking_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
}

/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @return boolean
 * @todo Finish documenting this function
 **/
function emarking_cron () {
	return true;
}

/**
 * Returns all other caps used in the module
 *
 * @example return array('moodle/site:accessallgroups');
 * @return array
 */
function emarking_get_extra_capabilities() {
	return array();
}
////////////////////////////////////////////////////////////////////////////////
// Gradebook API                                                              //
////////////////////////////////////////////////////////////////////////////////

/**
 * Is a given scale used by the instance of emarking?
 *
 * This function returns if a scale is being used by one emarking
 * if it has support for grading and scales. Commented code should be
 * modified if necessary. See forum, glossary or journal modules
 * as reference.
 *
 * @param int $emarkingid ID of an instance of this module
 * @return bool true if the scale is used by the given emarking instance
 */
function emarking_scale_used($emarkingid, $scaleid) {
	global $DB;
	return false;
}

/**
 * Checks if scale is being used by any instance of emarking.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param $scaleid int
 * @return boolean true if the scale is used by any emarking instance
 */
function emarking_scale_used_anywhere($scaleid) {

	return false;
}

/**
 * Creates or updates grade item for the give emarking instance
 *
 * Needed by grade_update_mod_grades() in lib/gradelib.php
 *
 * @param stdClass $emarking instance object with extra cmidnumber and modname property
 * @param null $grades
 * @return int 0 if ok, error code otherwise
 */
function emarking_grade_item_update(stdClass $emarking, $grades=null) {
	global $CFG;

	require_once($CFG->libdir.'/gradelib.php');
	require_once($CFG->dirroot.'/mod/emarking/locallib.php');
	
	if($grades==null) {
		emarking_calculate_grades_users($emarking);
	}
	
	$params = array();
	$params['itemname'] = clean_param($emarking->name, PARAM_NOTAGS);
	$params['gradetype'] = GRADE_TYPE_VALUE;
	$params['grademax']  = $emarking->grade;
	$params['grademin']  = $emarking->grademin;
	
	if ($grades  === 'reset') {
		$params['reset'] = true;
		$grades = null;
	}

	$ret = grade_update(
			'mod/emarking', 
			$emarking->course, 
			'mod', 
			'emarking', 
			$emarking->id, 
			0, 
			$grades, 
			$params);

	emarking_publish_all_grades($emarking);
	
	return $ret;
}

 
/**
 * Update emarking grades in the gradebook
 *
 * Needed by grade_update_mod_grades() in lib/gradelib.php
 *
 * @param stdClass $emarking instance object with extra cmidnumber and modname property
 * @param int $userid update grade of specific user only, 0 means all participants
 * @param bool $nullifnone not used in emarking.
 * @return void
 */
function emarking_update_grades(stdClass $emarking, $userid = 0, $nullifnone = true) {
	global $CFG;
	require_once($CFG->libdir.'/gradelib.php');

	if ($emarking->grade == 0) {
		emarking_grade_item_update($emarking);
	} else if ($grades = emarking_get_user_grades($emarking, $userid)) {
		foreach ($grades as $k => $v) {
			$grades[$k]->rawgrade = null;
		}
		emarking_grade_item_update($emarking, $grades);
	} else {
		emarking_grade_item_update($emarking);
	}
}

/**
 * Get emarking grades in a format compatible with the gradebook
 * @param $emarking
 * @param int $userid
 * @return array
 */
function emarking_get_user_grades($emarking,$userid=0){
	global $DB, $CFG;
	
	require_once $CFG->dirroot . '/grade/grading/lib.php';
	
	emarking_calculate_grades_users($emarking, $userid);
	
	$gradebookgrades=array();

	$params = array('emarking'=>$emarking->id);
	if($userid > 0)
		$params['student'] = $userid;

	$submissions = $DB->get_records('emarking_submission', $params);

	foreach($submissions as $submission) {
		$gradebookgrade = new stdClass();
		$gradebookgrade->userid = $submission->student;
		$gradebookgrade->datesubmitted = $submission->timecreated;
		$gradebookgrade->rawgrade = $submission->grade;
		$gradebookgrade->usermodified = $submission->teacher;
		$gradebookgrade->dategraded = $submission->timemodified;
		$gradebookgrades[$submission->student]=$gradebookgrade;
	}

	return $gradebookgrades;
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid The ID of the course to reset
 * @param string $type Optional type of assignment to limit the reset to a particular assignment type
 */
function emarking_reset_gradebook($courseid, $type='') {
	global $CFG, $DB;

	$params = array('moduletype'=>'emarking', 'courseid'=>$courseid);
	$sql = 'SELECT a.*, cm.idnumber as cmidnumber, a.course as courseid
            FROM {assign} a, {course_modules} cm, {modules} m
            WHERE m.name=:moduletype AND m.id=cm.module AND cm.instance=a.id AND a.course=:courseid';

	if ($emarkings = $DB->get_records_sql($sql, $params)) {
		foreach ($emarkings as $emarking) {
			emarking_grade_item_update($emarking, 'reset');
		}
	}
}


/**
 * Lists all gradable areas for the advanced grading methods framework
 *
 * @return array('string'=>'string') An array with area names as keys and descriptions as values
 */
function emarking_grading_areas_list() {
	return array('attempt'=>get_string('attempt', 'mod_emarking'));
}

////////////////////////////////////////////////////////////////////////////////
// File API                                                                   //
////////////////////////////////////////////////////////////////////////////////

/**
 * Returns the lists of all browsable file areas within the given module context
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by {@link file_browser::get_file_info_context_module()}
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 */
function emarking_get_file_areas($course, $cm, $context) {
	return array();
}

/**
 * File browsing support for emarking file areas
 *
 * @package mod_emarking
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info instance or null if not found
 */
function emarking_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
	return null;
}

/**
 * Serves the files from the emarking file areas
 *
 * @package mod_emarking
 * @category files
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the emarking's context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function emarking_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options=array()) {
	global $DB, $CFG, $USER;
	
	require_once $CFG->dirroot.'/mod/emarking/locallib.php';
	
	require_login();

	$filename = array_pop ( $args );
	$arg0 = array_pop ( $args );

	$contextcategory = context_coursecat::instance($course->category);
	$contextcourse = context_course::instance($course->id);
	
	$examid = 0;
	// Security!
	if ($filearea === 'exams') {
	
		if (! has_capability ( 'mod/emarking:downloadexam', $contextcategory ) 
				&& ! ($CFG->emarking_teachercandownload 
						&& has_capability ( 'mod/emarking:downloadexam', $contextcourse ))) {
			send_file_not_found ();
		}
	
		$sesskey = required_param ( 'sesskey', PARAM_ALPHANUM );
		$token = optional_param ( 'token', 0, PARAM_INT );
	
		// Validate session key
		if ($sesskey != $USER->sesskey) {
			send_file_not_found ();
		}
	
		if ($token > 9999) {
			$examid = $_SESSION [$USER->sesskey . 'examid'];
		}
	
		// A token was sent to validate download
		if ($token > 9999) {
			if ($_SESSION [$USER->sesskey . 'smstoken'] === $token) {
				$now = new DateTime ();
				$tokendate = new DateTime ();
				$tokendate->setTimestamp ( $_SESSION [$USER->sesskey . 'smsdate'] );
				$diff = $now->diff ( $tokendate );
				if ($diff->i > 5) {
					send_file_not_found ();
				}
			} else {
				send_file_not_found ();
			}
		} elseif ($token > 0) {
			send_file_not_found ();
		}
	}
	
	if($filearea === 'pages') {
		$parts = explode('-', $filename);
		if(count($parts)!=3) {
			send_file_not_found();
		}
		if(!($parts[0] === intval($parts[0])."") || !($parts[1] === intval($parts[1])."")) {
			send_file_not_found();
		}
		$subparts = explode('.', $parts[2]);
		$isanonymous = substr($subparts[0], -strlen('_a')) === '_a';
		
		$imageuser = intval($parts[0]);		
		$usercangrade = has_capability('mod/emarking:grade', $context);
		
		$bothenrolled = is_enrolled($contextcourse) && is_enrolled($contextcourse, $imageuser);
		
		if($USER->id != $imageuser // If user does not owns the image 
			&& !$usercangrade // And can not grade 
			&& !$isanonymous // And we are not in anonymous mode
			&& !is_siteadmin($USER) // And the user is not admin
			&& !$bothenrolled
		) {
			send_file_not_found();
		}
	}
	
	if($filearea === 'response') {
		$parts = explode('_', $filename);
		if(count($parts)!=3) {
			send_file_not_found();
		}
		if(!($parts[0] === "response") || !($parts[1] === intval($parts[1])."")) {
			send_file_not_found();
		}
		$subparts = explode('.', $parts[2]);
		$studentid = intval($subparts[0]);		
		$emarkingid = intval($parts[1]);		
		if(!$emarking = $DB->get_record('emarking', array('id'=>$emarkingid))) {
			send_file_not_found();
		}
		
		$useristeacher = emarking_user_is_teacher($emarking->course);
		
		if($studentid != $USER->id && !is_siteadmin($USER) && !$useristeacher) {
			send_file_not_found();
		}
	}
	
	$fs = get_file_storage();
	
    /*
     *  Check if this module is part of crowd module linking.
     */
    if( $markermap = $DB->get_record('emarking_markers',array('activityid'=>$cm->instance))){ //(Its linked)

        if($markermap->masteractivity != $cm->instance){
            //This is a child soreplace the context for the parent's
            $parentcm = get_coursemodule_from_instance("emarking",$markermap->masteractivity);
            $context = context_module::instance($parentcm->id);
            $arg0 = $markermap->masteractivity;
        }
    }
    
    //echo $context->id."..".$filearea."..".$arg0;die();
    if (! $file = $fs->get_file ( $context->id, 'mod_emarking', $filearea, $arg0, '/', $filename)) {
		//submission .pdf hay que cambiar al nombre del png user-curso-pag.png
		echo "File really not found";
		send_file_not_found();
	}
	

	if ($examid > 0) {
		if (! $exam = $DB->get_record ( 'emarking_exams', array (
				'id' => $examid
		) )) {
			echo "Exam not found";
			send_file_not_found ();
		}
	
		$exam->status = EMARKING_EXAM_SENT_TO_PRINT;
		$DB->update_record ( 'emarking_exams', $exam );
	}
	
	send_file($file, $filename);
}

////////////////////////////////////////////////////////////////////////////////
// Navigation API                                                             //
////////////////////////////////////////////////////////////////////////////////

/**
 * Extends the global navigation tree by adding emarking nodes if there is a relevant content
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $navref An object representing the navigation tree node of the emarking module instance
 * @param stdClass $course
 * @param stdClass $module
 * @param cm_info $cm
 */
function emarking_extend_navigation(navigation_node $navref, stdclass $course, stdclass $module, cm_info $cm) {
	$navref->add('Foo', new moodle_url('/mod/emarking/view.php',array('id'=>1)), navigation_node::TYPE_SETTING);
}

/**
 * Extends the settings navigation with the emarking settings
 *
 * This function is called when the context for the page is a emarking module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav {@link settings_navigation}
 * @param navigation_node $emarkingnode {@link navigation_node}
 */
function emarking_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $emarkingnode=null) {
	global $PAGE, $DB, $USER, $CFG;
	
	$emarkingnode->add('Foo');

}
