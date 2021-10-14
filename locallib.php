<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * ACE functions
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Returns a list of courses and a course id that meet the following conditions:
 * - Contain analytics data
 * - The user is enrolled in
 * - Not excluded
 *
 * @param $userid
 * @param $courseid
 * @return array array[0] = int[] (courseid) and array[1] = array (courses)
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_get_student_courses($userid, $courseid) {
    global $DB;

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

    $courses = $DB->get_records_sql($sql, array('userid' => $userid, 'per' => $period, 'start' => $startfrom));

    // TODO: Rename field to acecourseexclude, or define via setting.
    $excludefield = \core_customfield\field::get_record(array('shortname' => 'ucanalyticscourseexclude'));
    foreach ($courses as $course) {
        // Check enrollment.
        if (!is_enrolled(context_course::instance($course->id), $userid) ||
            empty($course->enddate) || $course->enddate < time()) {
            unset($courses[$course->id]);
        } else if (!empty($excludefield)) { // Check if this is an excluded course using the custom course field.
            $data = \core_customfield\data::get_record(array('instanceid' => $course->id, 'fieldid' => $excludefield->get('id')));
            if (!empty($data) && !empty($data->get("intvalue"))) {
                unset($courses[$course->id]);
            }
        }
    }

    if (count($courses) == 1 || ($courseid === null && !empty($courses))) {
        // Set courseid to the first course this user is enrolled in to make graph clear.
        $courseid = reset($courses)->id;
    }

    return array($courseid, $courses);
}

/**
 * Renders the chart based on given parameters.
 *
 * @param int $userid
 * @param int|array $courses
 * @param bool $showxtitles
 * @return bool|string
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_student_graph($userid, $courses, $showxtitles = true) {
    global $OUTPUT;

    $config = get_config('local_ace');

    list($series, $labels, $average1, $average2, $max, $stepsize) = local_ace_student_graph_data($userid, $courses,
        null, $showxtitles);
    if (empty($series)) {
        return '';
    }

    $chart = new \core\chart_line();
    $chart->set_legend_options(['display' => false]);
    $chart->set_smooth(true);

    $chart->set_labels($labels);

    $chartseries = new \core\chart_series(get_string('yourengagement', 'local_ace'), $series);
    $chartseries->set_color($config->colouruserhistory);
    $chart->add_series($chartseries);

    if (empty($course)) {
        $averagelabel = get_string('averageengagement', 'local_ace');
    } else {
        $averagelabel = get_string('averagecourseengagement', 'local_ace');
    }
    $averageseries = new \core\chart_series($averagelabel, $average1);
    $averageseries->set_color($config->colourusercoursehistory);
    $chart->add_series($averageseries);

    $averageseries2 = new \core\chart_series($averagelabel, $average2);
    $averageseries2->set_color($config->colourusercoursehistory);
    $averageseries2->set_fill(1);
    $chart->add_series($averageseries2);

    $yaxis0 = $chart->get_yaxis(0, true);
    $yaxis0->set_min(0);
    $yaxis0->set_max($max);
    $yaxis0->set_stepsize($stepsize);
    $yaxis0->set_labels(array(0 => get_string('low', 'local_ace'),
        $stepsize => get_string('medium', 'local_ace'),
        $max => get_string('high', 'local_ace')));

    return $OUTPUT->render($chart);
}

/**
 * Fetch graph data for specific user.
 *
 * @param int $userid
 * @param int|array $course
 * @param int|null $startfrom Display period start, defaults to displaying all course history to date.
 * @param bool $showxtitles
 * @return array|string
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_student_graph_data($userid, $course, $startfrom = null, $showxtitles = true) {
    global $DB;

    $config = get_config('local_ace');

    $period = (int) $config->displayperiod;

    $courseids = array();
    if (empty($course)) {
        // Get users enrolled courses, and use that instead.
        $courses = enrol_get_users_courses($userid, true, 'enddate');

        foreach ($courses as $course) {
            if (!empty($course->enddate) && $course->enddate > time()) {
                $courseids[] = $course->id;
            }
        }
    } else {
        $courseids = array($course);
    }
    if (empty($courseids)) {
        return get_string('noanalyticsfound', 'local_ace');
    }

    // Restrict to course passed, or enrolled users courses.
    list($insql, $inparamscf1) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pa');
    $coursefilter = "AND co.id $insql";

    // Get this users stats.
    // Get Latest values first, so we always show the most recent data-set.
    $sql = "WITH samples AS (
                SELECT EXTRACT('epoch' FROM date_trunc('day', to_timestamp(starttime))) AS starttime,
                       EXTRACT('epoch' FROM date_trunc('day', to_timestamp(endtime))) AS endtime,
                       value,
                       userid
                FROM {report_ucanalytics_samples} s
                JOIN {context} cx ON s.contextid = cx.id AND cx.contextlevel = 50
                JOIN {course} co ON cx.instanceid = co.id
                WHERE (endtime - starttime = :per) " . ($startfrom != null ? "AND endtime > :start" : "") . " $coursefilter
            )
            SELECT s.starttime, s.endtime, count(s.value) AS count, sum(s.value) AS value, a.avg AS avg, a.stddev AS stddev
              FROM samples s
              JOIN (
                        SELECT starttime, endtime, stddev(value), avg(value)
                        FROM samples s
                        GROUP BY starttime, endtime
                    ) a ON a.starttime = s.starttime AND a.endtime = a.endtime
              WHERE s.userid = :userid
              GROUP BY s.starttime, s.endtime, avg, stddev
              ORDER BY s.starttime DESC";

    $params = $inparamscf1 + array('userid' => $userid, 'per' => $period, 'start' => $startfrom);
    if ($startfrom == null) {
        $params['start'] = time() - (int) $config->userhistory;
    }

    $values = $DB->get_records_sql($sql, $params);

    $labels = array();
    $series = array();
    $average1 = array();
    $average2 = array();
    $laststart = null;

    foreach ($values as $value) {
        if (!empty($laststart) && $value->endtime > ($laststart + (DAYSECS))) {
            // If this period overlaps with the last week, skip it in the display.
            continue;
        }
        if ($showxtitles) {
            $labels[] = userdate($value->endtime, get_string('strftimedate'));
        } else {
            $labels[] = '';
        }

        if (empty($value->value)) {
            $series[] = 0;
        } else {
            $series[] = ($value->value / $value->count) * 100; // Convert to average percentage.
        }

        if (empty($value->avg)) {
            $average1[] = 0;
            $average2[] = 0;
        } else {
            $average1[] = ($value->avg - ($value->stddev / 2)) * 100;
            $average2[] = ($value->avg + ($value->stddev / 2)) * 100;
        }
        // Make sure we don't show overlapping periods.
        $laststart = $value->starttime;
    }

    if (empty($series)) {
        return get_string('noanalyticsfound', 'local_ace');
    }

    // Get max value to use as upper level of graph.
    $max = ceil(max(max($series), max($average1), max($average2)));

    // Charts.js doesn't cope when the stepsize is under 1.
    // Some of the courses have very little engagement so we occasionally end up with very low values.
    // This results in the Y axis having "high/high/high" instead of low/medium/high.
    // We do not want to show "real" values on the student graph, so the y-axis just autoscales to the max and low values.
    if ($max < 2) {
        $max = 2;
    }
    $stepsize = ceil($max / 2);

    // Reverse Series/labels to order by date correctly.
    return array(
        'error' => null,
        'series' => array_reverse($series),
        'labels' => array_reverse($labels),
        'average1' => array_reverse($average1),
        'average2' => array_reverse($average2),
        'max' => $max,
        'stepsize' => $stepsize,
    );
}
