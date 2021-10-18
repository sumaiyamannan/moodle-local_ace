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
 * Display user analytics report
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/local/ace/locallib.php');

$userid = required_param('id', PARAM_INT);
$courseid = optional_param('course', null, PARAM_INT);

require_login();
$course = $DB->get_record('course', array('id' => SITEID));
$PAGE->set_course($course);
$user = $DB->get_record('user', array('id' => $userid, 'deleted' => 0), '*', MUST_EXIST);

$usercontext = context_user::instance($user->id);
if ($userid == $USER->id) {
    require_capability('local/ace:viewown', $usercontext);
} else {
    require_capability('local/ace:view', $usercontext);
}

$config = get_config('local_ace');

$strtitle = get_string('userreport', 'local_ace');

$PAGE->set_pagelayout('report');
$PAGE->set_context($usercontext);
$PAGE->set_url('/local/ace/user.php', array('id' => $userid));

$PAGE->set_title($strtitle);

$PAGE->navbar->add(fullname($user), new moodle_url('/user/profile.php', array('id' => $userid)));
$PAGE->navbar->add(get_string('navigationlink', 'local_ace'));

$PAGE->set_heading(fullname($user));

// TODO: Create userreport_viewed event in local_ace.
// Trigger a report viewed event.
$event = \report_ucanalytics\event\userreport_viewed::create(array('context' => $usercontext,
    'relateduserid' => $userid));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studentdetailheader', 'local_ace'), 4);
echo html_writer::start_div('useranalytics');

list($courseid, $courses) = local_ace_get_student_courses($user->id, $courseid);

$tabs = array();

foreach ($courses as $course) {
    $newurl = clone $PAGE->url;
    $newurl->param('course', $course->id);
    $tabs[] = new tabobject($course->id,
        $newurl,
        $course->shortname);
}

// Add overall tab last.
if (count($courses) > 1) {
    $url = new moodle_url($PAGE->url);
    $url->param('course', 0);
    $tabs[] = new tabobject(0,
        $url,
        get_string('overallengagement', 'local_ace'));
}

print_tabs(array($tabs), $courseid);

if (!empty($courses)) { // If user is not enrolled in any relevant coureses, don't show the graph.
    if (!empty($courseid)) {
        echo $OUTPUT->heading(format_string($courses[$courseid]->fullname), 3, 'coursename');
    }

    $context = array(
        'colourusercoursehistory' => $config->colourusercoursehistory,
        'colouruserhistory' => $config->colouruserhistory,
    );

    $renderer = $PAGE->get_renderer('core');
    echo $renderer->render_from_template('local_ace/chart_page', $context);
    $PAGE->requires->js_call_amd('local_ace/graphs', 'init');
} else {
    echo $OUTPUT->box(get_string('noanalytics', 'local_ace'));
}

echo html_writer::end_div();
echo html_writer::div(get_string('userfooter', 'local_ace'), 'footertext');
echo $OUTPUT->footer();
