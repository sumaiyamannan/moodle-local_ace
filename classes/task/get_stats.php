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
 * Tasks
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ace\task;

defined('MOODLE_INTERNAL') || die();

/**
 * get_stats class, used to get stats for indicators.
 */
class get_stats extends \core\task\scheduled_task {
    /**
     * Returns the name of this task.
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('getstats', 'local_ace');
    }
    /**
     * Executes task.
     */
    public function execute() {
        global $DB;
        $runlast = get_config('local_ace', 'statsrunlast');
        if (empty($runlast)) {
            // Only do last 3 months of data for first run.
            $runlast = time() - (DAYSECS * 30 * 3);
        }
        $now = time();
        // Get user stats for each context (course) for indicators that we care about.
        // cognitive and social breadth are stored as values between-1 -> 1
        // Exclude potential cognitive/social as these are not user indicators.
        // To convert these to percentages we do: 100 * (var +1)/2.
        // Ignore when only 1 indicator present - this is likely a different process like no course access.

        $sql = 'SELECT DISTINCT c.starttime, c.endtime, c.contextid, ue.userid, count(value) as cnt, SUM((value + 1)/2) as value
                  FROM {analytics_indicator_calc} c
                  JOIN {user_enrolments} ue on ue.id = c.sampleid
                 WHERE c.timecreated > :runlast
                       AND sampleorigin = \'user_enrolments\'
                       AND (indicator like \'%\\\\cognitive_depth\' OR indicator like \'%\\\\social_breadth\'
                            OR indicator = \'\core\analytics\indicator\any_course_access\'
                            OR indicator = \'\core\analytics\indicator\read_actions\')
              GROUP BY c.starttime, c.endtime, c.contextid, ue.userid, c.sampleid
              HAVING count(value) > 1';

        $indicators = $DB->get_recordset_sql($sql, array('runlast' => $runlast));

        $newsamples = array();
        foreach ($indicators as $indicator) {

            $sample = new \stdClass();
            $sample->starttime = $indicator->starttime;
            $sample->endtime = $indicator->endtime;
            $sample->contextid = $indicator->contextid;
            $sample->userid = $indicator->userid;

            if (empty(floatval($indicator->value))) {
                $sample->value = 0;
            } else {
                $sample->value = floatval($indicator->value) / $indicator->cnt; // Get average percentage.
            }
            $newsamples[] = $sample;
        }
        $indicators->close();

        if (!empty($newsamples)) {
            $DB->insert_records('local_ace_samples', $newsamples);
        }

        // TODO: Does this really need to be a 2nd DB call? - or can we generate it from above?

        // Now get average for each context id in the same timeframe.
        $sql = 'SELECT c.starttime, c.endtime, c.contextid, count(value) as cnt, SUM((value + 1)/2) as value
                  FROM {analytics_indicator_calc} c
                  JOIN {user_enrolments} ue on ue.id = c.sampleid
                 WHERE c.timecreated > :runlast
                       AND sampleorigin = \'user_enrolments\'
                       AND (indicator like \'%\\\\cognitive_depth\' OR indicator like \'%\\\\social_breadth\'
                            OR indicator = \'\core\analytics\indicator\any_course_access\'
                            OR indicator = \'\core\analytics\indicator\read_actions\')
              GROUP BY c.starttime, c.endtime, c.contextid';

        $indicators = $DB->get_recordset_sql($sql, array('runlast' => $runlast));

        $newsamples = array();
        foreach ($indicators as $indicator) {

            $sample = new \stdClass();
            $sample->starttime = $indicator->starttime;
            $sample->endtime = $indicator->endtime;
            $sample->contextid = $indicator->contextid;

            if (empty(floatval($indicator->value))) {
                $sample->value = 0;
            } else {
                $sample->value = floatval($indicator->value) / $indicator->cnt; // Get average percentage.
            }
            $newsamples[] = $sample;
        }
        $indicators->close();

        if (!empty($newsamples)) {
            $DB->insert_records('local_ace_contexts', $newsamples);
        }

        set_config('statsrunlast', $now, 'local_ace');
    }
}

