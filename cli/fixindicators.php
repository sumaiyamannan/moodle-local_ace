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
 * This script updates view count in the ace stats table.
 *
 * @package    local_ace
 * @copyright  2023 Canterbury University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$calclifetime = get_config('analytics', 'calclifetime');
$timestart = time() - ($calclifetime * DAYSECS);
$displayperiod = get_config('local_ace', 'displayperiod');
$now = time();
mtrace("fix indicators");
$sql = "SELECT DISTINCT starttime, endtime
          FROM {analytics_indicator_calc}
         WHERE starttime > :timestart AND endtime - starttime = :displayperiod order by starttime";

$timeperiods = $DB->get_recordset_sql($sql, ['timestart' => $timestart, 'displayperiod' => $displayperiod]);
$lastperiodstart = 0;
foreach ($timeperiods as $period) {
    // Skip periods that are less than 3 days apart.
    if (!empty($lastperiodstart) && $period->starttime < ($lastperiodstart + (2 * DAYSECS))) {
        mtrace('skipping period with starttime: ' . $period->starttime);
        continue;
    }
    $lastperiodstart = $period->starttime;
    mtrace("fix for timeperiod:" . $period->starttime);
    /* For each course I care about (start date later than 1st Jan,
    / enddate greater than our timestart setting, and only courseregex courses.) */
    $sql = "shortname ~ :cregx AND enddate > :timestart AND startdate > 1672615440 AND visible = 1";
    $courses = $DB->get_recordset_select('course', $sql, ['timestart' => $timestart,
        'cregx' => get_config('local_ace', 'courseregex')]);
    $coursecount = 0;
    $newrecords = [];
    foreach ($courses as $course) {
        // For each user enrolment in that course.
        $sql = "select ue.*
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid";
        $userenrolments = $DB->get_recordset_sql($sql, ['courseid' => $course->id]);
        // We need to check if the users have any log entries during the time period to allow any course access to work.

        $sql = "SELECT userid, max(timecreated) as timeaccess
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid AND timecreated > :timestart AND timecreated < :timeend AND anonymous = 0
                 GROUP BY userid";

        $lastaccess = $DB->get_records_sql($sql, ['courseid' => $course->id,
            'timestart' => $period->starttime, 'timeend' => $period->endtime]);
        foreach ($userenrolments as $ue) {
            // Set up some data in the samples so it can find user/course in the analytics generation.
            $data = [];
            $user = new stdClass();
            $user->id = $ue->userid;
            $data[$ue->id]['course'] = $course;
            $data[$ue->id]['user'] = $user;
            // Generate the calcuated indicators.

            // Create the class and set the relevant data.
            $anywriteaction = new core\analytics\indicator\any_write_action_in_course();
            $anywriteaction->add_sample_data($data);

            // Use reflection to call private calculate method.
            $r = new ReflectionMethod('core\analytics\indicator\any_write_action_in_course', 'calculate_sample');
            $r->setAccessible(true);
            $writevalue = $r->invoke($anywriteaction, $ue->id, 'user_enrolments', $period->starttime, $period->endtime);

            // Generate the calcuated indicators.
            $anycourseaccess = new core\analytics\indicator\any_course_access();
            $anycourseaccess->lastaccesses = $lastaccess;
            $anycourseaccess->add_sample_data($data);

            $ra = new ReflectionMethod($anycourseaccess, 'calculate_sample');
            $ra->setAccessible(true);

            $readvalue = $ra->invoke($anycourseaccess, $ue->id, 'user_enrolments', $period->starttime, $period->endtime);

            // Now we have a value, lets insert it into the db.
            $writeaction = new stdClass();
            $writeaction->sampleid = $ue->id;
            $writeaction->starttime = $period->starttime;
            $writeaction->endtime = $period->endtime;
            $writeaction->contextid = context_course::instance($course->id)->id;
            $writeaction->sampleorigin = 'user_enrolments';
            $writeaction->indicator = '\core\analytics\indicator\any_write_action_in_course';
            $writeaction->value = $writevalue;
            if (!$DB->record_exists('analytics_indicator_calc', (array) $writeaction)) {
                $writeaction->timecreated = $now;
                $newrecords[] = $writeaction;
            }

            // Now we have a value, lets insert it into the db.
            $readaction = new stdClass();
            $readaction->sampleid = $ue->id;
            $readaction->starttime = $period->starttime;
            $readaction->endtime = $period->endtime;
            $readaction->contextid = context_course::instance($course->id)->id;
            $readaction->sampleorigin = 'user_enrolments';
            $readaction->indicator = '\core\analytics\indicator\any_course_access';
            $record = $DB->get_record('analytics_indicator_calc', (array) $readaction);
            if (empty($record)) {
                $readaction->value = $readvalue;
                $readaction->timecreated = $now;
                $newrecords[] = $readaction;
            } else if ($record->value <> $readvalue) {
                $record->value = $readvalue;
                $DB->update_record('analytics_indicator_calc', $record);
            }
        }
        $userenrolments->close();
    }
    $courses->close();

    mtrace("inserting " . count($newrecords) . " new indicator values");
    $DB->insert_records('analytics_indicator_calc', $newrecords);

    mtrace("fixed for $coursecount courses");
}
$timeperiods->close();

