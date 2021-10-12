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

$shortnameregs = get_config('local_ace', 'courseregex');
$shortnamesql = '';
if (!empty($shortnameregs)) {
    $shortnamesql = " AND co.shortname ~ '$shortnameregs' ";
}
$startfrom = time() - get_config('local_ace', 'userhistory');
$period = get_config('local_ace', 'displayperiod');

$sql = "SELECT DISTINCT co.id, co.shortname, co.enddate, co.fullname
              FROM {report_ucanalytics_samples} s
              JOIN {report_ucanalytics_contexts} c ON c.contextid = s.contextid
                   AND s.starttime = c.starttime AND s.endtime = c.endtime
              JOIN {context} cx ON c.contextid = cx.id AND cx.contextlevel = " . CONTEXT_COURSE . "
              JOIN {course} co ON cx.instanceid = co.id
              WHERE s.userid = :userid AND (s.endtime - s.starttime = :per) $shortnamesql
              AND s.endtime > :start ORDER BY co.shortname";

$courses = $DB->get_records_sql($sql, array('userid' => $user->id, 'per' => $period, 'start' => $startfrom));

// TODO: Rename field to acecourseexclude, or define via setting.
$excludefield = \core_customfield\field::get_record(array('shortname' => 'ucanalyticscourseexclude'));
foreach ($courses as $course) {
    // Check enrollment.
    if (!is_enrolled(context_course::instance($course->id), $user->id) ||
        empty($course->enddate) || $course->enddate < time()) {
        unset($courses[$course->id]);
    } else if (!empty($excludefield)) { // Check if this is an excluded course using the custom course field.
        $data = \core_customfield\data::get_record(array('instanceid' => $course->id, 'fieldid' => $excludefield->get('id')));
        if (!empty($data) && !empty($data->get("intvalue"))) {
            unset($courses[$course->id]);
        }
    }
}
$tabs = array();

if (count($courses) == 1 || ($courseid === null && !empty($courses))) {
    // Set courseid to the first course this user is enrolled in to make graph clear.
    $courseid = reset($courses)->id;
}

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
