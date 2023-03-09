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

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

$calclifetime = get_config('analytics', 'calclifetime');
$timestart = time() - ($calclifetime * DAYSECS);
$displayperiod = get_config('local_ace', 'displayperiod');
$now = time();
mtrace ("fix indicators");
$sql = "SELECT DISTINCT starttime, endtime
          FROM {analytics_indicator_calc}
         WHERE starttime > :timestart AND endtime - starttime = :displayperiod";

$timeperiods = $DB->get_recordset_sql($sql, ['timestart' => $timestart, 'displayperiod' => $displayperiod]);
foreach ($timeperiods as $period) {
    mtrace("fix for timeperiod:". $period->start);
    // For each course I care about (start date later than 1st Jan, enddate greater than our timestart setting, and only courseregex courses.)
    $sql = "shortname ~ :cregx AND enddate > :timestart AND startdate > 1672615440";
    $courses = $DB->get_recordset_select('course', $sql, ['timestart' => $timestart, 'cregx' => get_config('local_ace', 'courseregex')]);
    $coursecount = 0;
    foreach ($courses as $course) {
        // For each user enrolment in that course.
        $sql = "select ue.*
                  FROM {user_enrolments} ue 
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid";      
        $userenrolments = $DB->get_recordset_sql($sql, ['courseid' => $course->id]);
        $newrecords = [];
        foreach ($userenrolments as $ue) {
            // Set up some data in the samples so it can find user/course in the analytics generation.
            $data = [];
            $user = new stdClass();
            $user->id = $ue->userid;
            $data[$ue->id]['course'] = $course;
            $data[$ue->id]['user'] = $userid;
            
            // Generate the calcuated indicators.
            $r = new ReflectionMethod('core\analytics\indicator\any_write_action_in_course', 'calculate_sample');
            $r->setAccessible(true); 
            $r->add_sample_data($data);

            $writevalue = $r->invoke(new core\analytics\indicator\any_write_action_in_course, $ue->id, 'user_enrolments', $period->starttime, $period->endtime);

            // Generate the calcuated indicators.
            $ra = new ReflectionMethod('core\analytics\indicator\any_course_access', 'calculate_sample');
            $ra->setAccessible(true); 
            $ra->add_sample_data($data);

            $readvalue = $ra->invoke(new core\analytics\indicator\any_course_access, $ue->id, 'user_enrolments', $period->starttime, $period->endtime);

            // Now we have a value, lets insert it into the db.
            $writeaction = new stdClass();
            $writeaction->starttime = $period->starttime;
            $writeaction->endtime = $period->endtime;
            $writeaction->contextid = context_course::instance($course->id);
            $writeaction->sampleorigin = 'user_enrolments';
            $writeaction->indicator = 'core\analytics\indicator\any_write_action_in_course';
            $writeaction->value = $writevalue;
            if (!$DB->record_exists('analytics_indicator_calc', (array) $writeaction)) {
                $writeaction->timecreated = $now;
                $newrecords[] = $writeaction;
            }

            // Now we have a value, lets insert it into the db.
            $readaction = new stdClass();
            $readaction->starttime = $period->starttime;
            $readaction->endtime = $period->endtime;
            $readaction->contextid = context_course::instance($course->id);
            $readaction->sampleorigin = 'user_enrolments';
            $readaction->indicator = 'core\analytics\indicator\any_course_access';
            $readaction->value = $writevalue;
          
            if (!$DB->record_exists('analytics_indicator_calc', (array) $readaction)) {
                $readaction->timecreated = $now;
                $newrecords[] = $readaction;
            }
            
        }
        $userenrolments->close();
        mtrace("inserting ". count($newrecords). " new indicator values");
        $DB->insert_records('analytics_indicator_calc', $newrecords);
    }
    $courses->close();
    mtrace("fixed for $coursecount courses");
}
$timeperiods->close();