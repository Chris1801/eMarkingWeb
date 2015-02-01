<?php
require_once(dirname(__FILE__) . '/../../config.php');
require_once("lib.php");
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->dirroot."/mod/emarking/locallib.php");
require_once($CFG->dirroot."/lib/externallib.php");


global $USER, $OUTPUT, $DB, $CFG, $PAGE;

// Course module id
$cmid=required_param('id', PARAM_INT);

$criterionid=optional_param('criterion', 0, PARAM_INT);

// Validate course module
if(!$cm = get_coursemodule_from_id('emarking', $cmid)) {
	print_error ( get_string('invalidcoursemodule','mod_emarking' ) . " id: $cmid" );
}

// Validate eMarking activity
if(!$emarking = $DB->get_record('emarking', array('id'=>$cm->instance))) {
	print_error ( get_string('invalidid','mod_emarking' ) . " id: $cmid" );
}

// Validate course
if(!$course = $DB->get_record('course', array('id'=>$emarking->course))) {
	print_error(get_string('invalidcourseid', 'mod_emarking'));
}

$criterion = null;
if($criterionid > 0 && !$criterion = $DB->get_record('gradingform_rubric_criteria', array('id'=>$criterionid))) {
	print_error(get_string('invalidcourseid', 'mod_emarking'));
}

// Get the course module for the emarking, to build the emarking url
$urlemarking = new moodle_url('/mod/emarking/kanban.php', array('id'=>$cm->id, 'criterion'=>$criterionid));
$context = context_module::instance($cm->id);

// Check that user is logued in the course
require_login($course->id);
if (isguestuser()) {
	die();
}

// Check if user has an editingteacher role
require_capability ( 'mod/emarking:grade', $context );

$useristeacher = emarking_user_is_teacher($course->id);
if($useristeacher || is_siteadmin($USER)) {
	$emarking->anonymous = false;
}

// Page navigation and URL settings
$PAGE->set_url($urlemarking);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_cm($cm);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('emarking','mod_emarking'));

// Show header and heading
echo $OUTPUT->header();
echo $OUTPUT->heading_with_help(get_string('emarking','mod_emarking'), 'annotatesubmission', 'mod_emarking');

// Navigation tabs
echo $OUTPUT->tabtree(emarking_tabs($context, $cm, $emarking), "mark" );

// Get rubric instance
$gradingmanager = get_grading_manager($context, 'mod_emarking', 'attempt');
$gradingmethod = $gradingmanager->get_active_method();

// Validate that activity has a rubric ready
if($gradingmethod !== 'rubric') {
	$managerubricurl = new moodle_url('/grade/grading/manage.php', 
			array('contextid'=>$context->id, 'component'=>'mod_emarking', 'area'=>'attempt'));
	echo $OUTPUT->notification(get_string('rubricneeded', 'mod_emarking'), 'notifyproblem');
	echo $OUTPUT->single_button($managerubricurl, get_string('createrubric','mod_emarking'));
	echo $OUTPUT->footer();
	die();
}

// User filter checking capabilities. If user can not grade, then she can not
// see other users
$userfilter = 'WHERE 1=1 ';
if(!$usercangrade) {
	$userfilter.= 'AND ue.userid = ' . $USER->id;
}

// As we have a rubric we can get the controller
$rubriccontroller = $gradingmanager->get_controller($gradingmethod);
if(!$rubriccontroller instanceof gradingform_rubric_controller) {
	print_error(get_string('invalidrubric', 'mod_emarking'));
}

// Calculates the number of criteria for this evaluation
$numcriteria = 0;
$rubricscores = $rubriccontroller->get_min_max_score();

$levels = array();
if($rubriccriteria = $rubriccontroller->get_definition()) {
	foreach($rubriccriteria->rubric_criteria as $criterion) {
		if($criterion['id'] == $criterionid) {
			foreach($criterion['levels'] as $lvl) {
				$levels[] = intval($lvl['id']);
			}
		}
	}
}
$levels = implode(",",$levels);

// Calculates the number of criteria assigned to current user
$criteriafilter = "
	SELECT u.*,
		CASE WHEN S.comments = 0 AND S.status < 20 THEN 5
			WHEN S.comments = 0 AND S.status >= 20 THEN 20
			WHEN S.comments > 0 THEN 30 END AS status,
		S.submission
	FROM (SELECT
	s.id as submission,
	s.student,
	COUNT(DISTINCT ec.id) AS comments,
	s.sort,
	s.status
	FROM {emarking_submission} AS s
	INNER JOIN {emarking_page} AS p ON (s.emarking = $emarking->id AND p.submission = s.id)
	LEFT JOIN {emarking_comment} AS ec ON (ec.page = p.id AND ec.levelid IN ($levels))
	GROUP BY s.id) AS S
	INNER JOIN {user} AS u ON (S.student = u.id)";

// Check if activity is configured with separate groups to filter users
if($cm->groupmode == SEPARATEGROUPS 
	&& $usercangrade 
	&& !is_siteadmin($USER)
	&& !$useristeacher) {
$userfilter .= "
AND u.id in (SELECT userid
		FROM {groups_members}
WHERE groupid in (SELECT groupid
FROM {groups_members} as gm
INNER JOIN {groups} as g on (gm.groupid = g.id)
WHERE gm.userid = $USER->id AND g.courseid = e.courseid))";
}

// Define flexible table (can be sorted in different ways)
$showpages=new flexible_table('emarking-kanban-'.$cmid);
$showpages->define_headers(array(
		get_string('notcorrected', 'mod_emarking'),
		get_string('marking', 'mod_emarking'),
		get_string('corrected', 'mod_emarking')));
$showpages->define_columns(array(
		'notcorrected',
		'marking',
		'corrected'));
$showpages->define_baseurl($urlemarking);
$defaulttsort = $emarking->anonymous ? null : 'status';
$showpages->sortable(false);
$showpages->pageable(false);
$showpages->setup();

// Decide on sorting depending on URL parameters and flexible table configuration
$orderby = $emarking->anonymous ? 'ORDER BY sort ASC' : 'ORDER BY u.lastname ASC';

// Get submissions with extra info to show
$sql = $criterionid == 0 ? "
SELECT u.*, 
		IFNULL(s.id,0) as submission,
		IFNULL(s.status,0) as status,
		s.sort
FROM {emarking_submission} AS s
	INNER JOIN {user} AS u ON (s.emarking = ? AND s.student = u.id)
$userfilter
$orderby" :
$criteriafilter . $userfilter . $orderby; 

// Run the query on the database
$emarkingpages=$DB->get_records_sql($sql, array($emarking->id));

$notcorrected = "";
$marking = "";
$corrected = "";
// Prepare data for the table
foreach($emarkingpages as $pageinfo ){
	
	// Student info
	$userinfo = $emarking->anonymous ? 
			get_string('anonymousstudent', 'mod_emarking') : 
			$pageinfo->firstname.' '.$pageinfo->lastname . '</a>';
	
	// eMarking popup url
	$popup_url = new moodle_url ( '/mod/emarking/ajax/a.php', array (
					'ids' => $pageinfo->submission,
					'action' => 'emarking'));
	
	$actions = $OUTPUT->action_link($popup_url, $userinfo,
			new popup_action ( 'click', $popup_url, 'emarking' . $pageinfo->submission, array (
								'menubar' => 'no',
								'titlebar' => 'no',
								'status' => 'no',
								'toolbar' => 'no' 
						)));
	
	if($pageinfo->status < EMARKING_STATUS_GRADING) {
		$notcorrected .= $actions . "<br/>";
	} elseif($pageinfo->status == EMARKING_STATUS_GRADING) {
		$marking .= $actions . "<br/>";
	} else {
		$corrected .= $actions . "<br/>";
	}
}

$showpages->add_data(array(
		$notcorrected,
		$marking,
		$corrected
));

?>
<style>.scol, .generaltable td{vertical-align: middle;}</style>
<?php

$showpages->print_html();

echo $OUTPUT->footer();