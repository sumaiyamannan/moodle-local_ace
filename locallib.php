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

    $graphdata = local_ace_student_graph_data($userid, $courses, null, $showxtitles);
    $series = $graphdata['series'];
    $labels = $graphdata['labels'];
    $average1 = $graphdata['average1'];
    $average2 = $graphdata['average2'];

    if ($graphdata['error'] != null) {
        return '';
    }

    // Get max value to use as upper level of graph.
    $max = max(max($series), max($average1), max($average2));

    // Charts.js doesn't cope when the stepsize is under 1.
    // Some of the courses have very little engagement so we occasionally end up with very low values.
    // This results in the Y axis having "high/high/high" instead of low/medium/high.
    // UC do not want to show "real" values on the student graph, so the y-axis just autoscales to the max and low values.
    if ($max < 2) {
        $max = 2;
    }
    $stepsize = $max / 2;

    $chart = new \core\chart_line();
    $chart->set_legend_options(['display' => false]);
    $chart->set_smooth(true);

    $chart->set_labels($labels);

    $chartseries = new \core\chart_series(get_string('yourengagement', 'report_ucanalytics'), $series);
    $chartseries->set_color($config->colouruserhistory);
    $chart->add_series($chartseries);

    if (empty($course)) {
        $averagelabel = get_string('averageengagement', 'report_ucanalytics');
    } else {
        $averagelabel = get_string('averagecourseengagement', 'report_ucanalytics');
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
    $yaxis0->set_labels(array(0 => get_string('low', 'report_ucanalytics'),
        $stepsize => get_string('medium', 'report_ucanalytics'),
        $max => get_string('high', 'report_ucanalytics')));

    return $OUTPUT->render($chart);
}

/**
 * Fetch graph data for specific user
 *
 * @param int $userid
 * @param int|array $course
 * @param int|null $startfrom Display period start, defaults to 'displayperiod' setting.
 * @param bool $showxtitles
 * @return array
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_student_graph_data($userid, $course, $startfrom = null, $showxtitles = true) {
    global $DB;

    $config = get_config('local_ace');

    $period = (int) $config->displayperiod;
    if ($startfrom == null) {
        $startfrom = time() - (int) $config->userhistory;
    }

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
        return array('error' => null, 'series' => [], 'labels' => [], 'average1' => [], 'average2' => []);
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
                WHERE (endtime - starttime = :per) AND endtime > :start $coursefilter
            )
            SELECT s.starttime, s.endtime, count(s.value) AS count, sum(s.value) AS value, a.avg AS avg, a.stddev AS stddev
              FROM samples s
              JOIN (
                        SELECT starttime, endtime, stddev(value), avg(value)
                        FROM samples s
                        GROUP BY starttime, endtime
                    ) a ON a.starttime = s.starttime AND a.endtime = a.endtime
              WHERE s.userid = :userid AND s.starttime > :startt
              GROUP BY s.starttime, s.endtime, avg, stddev
              ORDER BY s.starttime DESC";

    $params = $inparamscf1 + array('userid' => $userid, 'per' => $period, 'start' => $startfrom, 'startt' => $startfrom);

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
        return array('error' => get_string('noanalyticsfound', 'local_ace'), 'series' => [], 'labels' => [],
            'average1' => [], 'average2' => []);
    }

    // Reverse Series/labels to order by date correctly.
    return array(
        'error' => null,
        'series' => array_reverse($series),
        'labels' => array_reverse($labels),
        'average1' => array_reverse($average1),
        'average2' => array_reverse($average2),
    );
}
