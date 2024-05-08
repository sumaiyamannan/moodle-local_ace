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

require_once($CFG->libdir . '/enrollib.php');

/**
 * Returns the HTML output for the teacher course engagement graph
 *
 * @param int $userid
 * @return string
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_ace_teacher_course_graph(int $userid): string {
    global $PAGE;

    $config = get_config('local_ace');
    $renderer = $PAGE->get_renderer('core');

    $tabs = array();
    $courses = local_ace_get_enrolled_courses($userid);

    foreach ($courses as $course) {
        $context = context_course::instance($course->id);
        $newurl = new moodle_url($config->coursedashboardurl, ['contextid' => $context->id]);
        $tabs[] = new tabobject($course->id,
            $newurl,
            $course->shortname);
    }

    $output = html_writer::start_div('useranalytics');
    $output .= print_tabs(array($tabs), null, null, null, true);
    $output .= $renderer->render_from_template('local_ace/teacher_course_engagement_chart', null);

    $params = [
        'colours' => explode(',', $config->colours),
    ];

    $PAGE->requires->js_call_amd('local_ace/teacher_course_engagement', 'init', [$params]);

    $output .= html_writer::end_div();

    return $output;
}

/**
 * Returns users engagement data in their enrolled courses.
 *
 * @param int $userid
 * @param int|null $period
 * @param int|null $start
 * @param int|null $end
 * @return array
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_enrolled_courses_user_data(int $userid, ?int $period = null, ?int $start = null, ?int $end = null): array {
    $data = array('series' => [], 'xlabels' => []);

    $config = get_config('local_ace');

    if ($period === null) {
        $period = (int) $config->displayperiod;
    }

    if ($start === null) {
        $start = time() - $config->userhistory;
    }

    list($defaultcourseid, $courses) = local_ace_get_user_courses($userid);

    $allvalues = [];
    foreach ($courses as $course) {
        $allvalues[$course->shortname] = local_ace_get_individuals_course_data($userid, $course->id, $period, $start, $end);
    }

    list($series, $xlabels, $max) = local_ace_get_matching_values_to_labels($allvalues);
    $data['series'] = $series;
    $data['xlabels'] = $xlabels;

    $data['ylabels'] = local_ace_get_percentage_ylabels();

    return $data;
}

/**
 * Get a users engagement data in a single course, returned data contains only the engagement values.
 *
 * @param int $userid
 * @param int $courseid
 * @param int|null $period
 * @param int|null $start
 * @param int|null $end
 * @return array
 * @throws dml_exception
 */
function local_ace_get_individuals_course_data(int $userid, int $courseid, int $period = null, int $start = null,
    int $end = null): array {
    global $DB, $CFG;

    $params = [
        'userid' => $userid,
        'courseid' => $courseid,
        'per' => $period
    ];
    $startendsql = "";
    if ($start != null) {
        $startendsql .= "AND endtime > :start ";
        $params['start'] = $start;
    }
    if ($end != null) {
        $startendsql .= "AND endtime < :end ";
        $params['end'] = $end;
    }
    if ($CFG->dbtype !== 'pgsql') {
        $timefields = " SELECT
            round(UNIX_TIMESTAMP(DATE_FORMAT( FROM_UNIXTIME(starttime), '%Y-%m-%d'))) as starttime,
            round(UNIX_TIMESTAMP(DATE_FORMAT( FROM_UNIXTIME(endtime), '%Y-%m-%d'))) as endtime,
        ";
    } else {
        $timefields = " SELECT
            EXTRACT('epoch' FROM date_trunc('day', to_timestamp(starttime))) AS starttime,
            EXTRACT('epoch' FROM date_trunc('day', to_timestamp(endtime))) AS endtime,
        ";
    }
    $sql = "WITH samples AS (
                " . $timefields . "
                       value,
                       userid
                FROM {local_ace_samples} s
                JOIN {context} cx ON s.contextid = cx.id AND cx.contextlevel = 50
                JOIN {course} co ON cx.instanceid = co.id
                WHERE (endtime - starttime = :per) "
        . $startendsql . " AND co.id = :courseid
            )
            SELECT s.starttime, s.endtime, count(s.value) AS count, sum(s.value) AS value
              FROM samples s
              WHERE s.userid = :userid
              GROUP BY s.starttime, s.endtime
              ORDER BY s.starttime DESC";
    return $DB->get_records_sql($sql, $params);
}

/**
 * Get list of courses user is enrolled in
 *
 * @param int $userid User ID
 * @param int|null $start Start of course, defaults to today - half a year
 * @return array Array of courses keyed by course id
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_get_enrolled_courses(int $userid, ?int $start = null): array {
    global $DB;

    $config = get_config('local_ace');

    $params = [
        'userid' => $userid,
        'active' => ENROL_USER_ACTIVE,
        'enabled' => ENROL_INSTANCE_ENABLED
    ];

    // Selecting courses which have start date within the last six months, this ~should~ only get courses in the current semester.
    // Unless $start is defined, in which case we do not filter for the current semester.
    $filtersql = "WHERE ";
    if (!isset($start)) {
        $filtersql .= "co.startdate >= :coursestart AND co.startdate <= :now";
        $params['coursestart'] = time() - (YEARSECS / 2); // TODO: Move into a setting, to support uni's that do trimesters.
        $params['now'] = time();
    }

    $shortnameregs = $config->courseregex;
    if ($DB->sql_regex_supported()) {
        if (!empty($shortnameregs)) {
            if (!isset($start)) {
                $filtersql .= " AND ";
            }

            $filtersql .= "co.shortname " . $DB->sql_regex() . " '$shortnameregs'";
        }
    } else {
        debugging('Cannot filter by shortname, database does not support regular expressions.');
    }

    // Only get enrolled courses, filter by shortname if required.
    $sql = "SELECT co.id, co.shortname, co.enddate, co.fullname
                FROM {course} co
                JOIN (SELECT DISTINCT e.courseid
                        FROM {enrol} e
                        JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = :userid)
                        WHERE ue.status = :active AND e.status = :enabled
                    ) en ON (en.courseid = co.id)
                    $filtersql ORDER BY co.shortname";
    $courses = $DB->get_records_sql($sql, $params);

    // Filter courses that are excluded by custom field.
    $excludefield = \core_customfield\field::get_record(['shortname' => 'ucanalyticscourseexclude']);
    foreach ($courses as $course) {
        if (!empty($excludefield)) {
            $fielddata = \core_customfield\data::get_record(['instanceid' => $course->id, 'fieldid' => $excludefield->get('id')]);
            if (!empty($fielddata) && !empty($fielddata->get('intvalue'))) {
                unset($courses[$course->id]);
            }
        }
    }

    return $courses;
}

/**
 * Returns the course average data from the courses the user is enrolled in.
 *
 * @param int $userid
 * @param int|null $period
 * @param int|null $start
 * @param int|null $end
 * @return array|array[]
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_enrolled_courses_average_data(int $userid, ?int $period = null, ?int $start = null, ?int $end = null): array {
    $data = array('series' => [], 'xlabels' => []);

    $config = get_config('local_ace');

    if ($period === null) {
        $period = (int) $config->displayperiod;
    }

    $courses = local_ace_get_enrolled_courses($userid, $start);

    $allvalues = [];

    foreach ($courses as $course) {
        $values = local_ace_course_data_values($course->id, $period, $start, $end);
        if (!empty($values)) {
            $allvalues[$course->shortname] = $values;
        }
    }

    list($series, $xlabels, $max) = local_ace_get_matching_values_to_labels($allvalues);
    $data['series'] = $series;
    $data['xlabels'] = $xlabels;

    $data['ylabels'] = local_ace_get_percentage_ylabels();

    return $data;
}

/**
 * Returns a matching set of labels to values.
 *
 * Not every result returned from `local_ace_course_data_values` will have the same amount of values
 * even within the same period.
 * This function will take a set of series and create labels, then it fills in any values that don't have a matching label.
 *
 * @param array $coursevalues
 * @return array
 * @throws coding_exception
 */
function local_ace_get_matching_values_to_labels(array $coursevalues): array {
    $tempvalues = [];
    $ongoinglargestcount = 0;
    $labels = [];
    // Go through every course and arrange the values by date. Find the course with the most values and use its labels.
    // Doing this means that we end up with the most data possible to display.
    foreach ($coursevalues as $shortname => $values) {
        $series = [];
        $laststart = null;
        $templabels = [];
        $allowoverlap = DAYSECS; // Allow an overlap between periods of up to this amount - takes in account slow cron.
        foreach ($values as $value) {
            if (!empty($laststart) && $value->endtime > $laststart + $allowoverlap) {
                // If this period overlaps with the last week, skip it in the display.
                continue;
            }

            $date = userdate($value->endtime, get_string('strftimedateshortmonthabbr', 'langconfig'));
            $templabels[] = $date;
            $calcval = 0;
            if (!empty($value->value)) {
                $calcval =
                    round(($value->value / $value->count) * 100); // Convert to average percentage.
            }
            if (!empty($value->viewcountvalue)) {
                $viewcount = local_ace_normalise_value($value->viewcountvalue / $value->viewcount, 0, 750);
                // Lets make the view count 50% of the displayed value for now - maybe change later?
                $calcval = local_ace_nonegative_value(($calcval + $viewcount) / 2);
            }
            $series[$date] = $calcval;
            // Make sure we don't show overlapping periods.
        }
        $tempvalues[$shortname] = array_reverse($series);
        $count = count($tempvalues[$shortname]);
        if ($count > $ongoinglargestcount) {
            $ongoinglargestcount = $count;
            $labels = $templabels;
        }
    }

    $labels = array_reverse($labels);

    $finalvalues = [];
    $max = 2;
    // Loop the labels, check that for each course a corresponding value against the label exists, if one doesn't set it to 0.
    foreach ($labels as $label) {
        foreach ($tempvalues as $shortname => $valueset) {
            if (isset($valueset[$label])) {
                $finalvalues[$shortname][] = $valueset[$label];
                if ($valueset[$label] > $max) {
                    $max = ceil($valueset[$label]);
                }
            } else {
                $finalvalues[$shortname][] = 0;
            }
        }
    }

    // We need to return the values ready for displaying in chart.js.
    $preparedvalues = [];
    foreach ($finalvalues as $shortname => $values) {
        $preparedvalues[] = [
            'label' => $shortname,
            'values' => $values
        ];
    }

    return [$preparedvalues, $labels, $max];
}

/**
 * Returns the course summary graph
 *
 * @param int $courseid
 * @return string
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_ace_course_graph(int $courseid): string {
    global $PAGE, $USER;

    $context = array(
        'courseid' => $courseid,
        'defaultchartfilter' => get_user_preferences('local_ace_default_chart_filter', null, $USER->id),
    );

    $renderer = $PAGE->get_renderer('core');
    $output = $renderer->render_from_template('local_ace/course_engagement_chart', $context);
    $PAGE->requires->js_call_amd('local_ace/course_engagement', 'init', [$context]);
    $PAGE->requires->css('/local/ace/styles.css');

    $output .= html_writer::div(local_ace_get_dedication($courseid), '', ['id' => 'local_ace-course-dedication']);

    return $output;
}

/**
 * Generate SQL used for select filters.
 *
 * @param array $filtervalues Filter values
 * @param string $filterkey Filter name
 * @param string $studentattribute Student attribute column name
 * @return array
 */
function local_ace_filter_sql_select(array $filtervalues, string $filterkey, string $studentattribute) {
    $wheresql = [];
    $params = [];

    if (isset($filtervalues[$filterkey . '_operator']) && isset($filtervalues[$filterkey . '_value'])) {
        $params[$studentattribute] = $filtervalues[$filterkey . '_value'];
        if ($filtervalues[$filterkey . '_operator'] == 1) {
            $wheresql[] = "AND studentattributes.$studentattribute = :$studentattribute";
        }
        if ($filtervalues[$filterkey . '_operator'] == 2) {
            $wheresql[] = "AND studentattributes.$studentattribute != :$studentattribute";
        }
    }

    return [
        $wheresql,
        $params
    ];
}

/**
 * Generate SQL used for text filters.
 *
 * @param array $filtervalues Filter values
 * @param string $filterkey Filter name
 * @param string $field SQL table and field snippet, e.g. mdl_user.idnumber
 * @return array
 */
function local_ace_filter_sql_text(array $filtervalues, string $filterkey, string $field) {
    global $DB;

    $wheresql = [];
    $params = [];

    if (isset($filtervalues[$filterkey . '_operator']) && isset($filtervalues[$filterkey . '_value'])) {
        $operator = (int) $filtervalues[$filterkey . '_operator'] ?? 0;
        $value = $filtervalues[$filterkey . '_value'];
        $param = core_reportbuilder\local\helpers\database::generate_param_name();

        switch ($operator) {
            case 1:
                $like = $DB->sql_like("$field", ":$param", false, false);
                $wheresql[] = 'AND ' . $like;
                $value = $DB->sql_like_escape($value);
                $params[$param] = "%$value%";
                break;
            case 2:
                $notlike = $DB->sql_like("$field", ":$param", false, false, true);
                $wheresql[] = 'AND ' . $notlike;
                $value = $DB->sql_like_escape($value);
                $params[$param] = "%$value%";
                break;
            case 3:
                $equal = $DB->sql_equal("$field", ":$param", false, false);
                $wheresql[] = 'AND ' . $equal;
                $params[$param] = $value;
                break;
            case 4:
                $notequal = $DB->sql_equal("$field", ":$param", false, false, true);
                $wheresql[] = 'AND ' . $notequal;
                $params[$param] = $value;
                break;
            case 5:
                $like = $DB->sql_like("$field", ":$param", false, false);
                $wheresql[] = 'AND ' . $like;
                $value = $DB->sql_like_escape($value);
                $params[$param] = "$value%";
                break;
            case 6:
                $like = $DB->sql_like("$field", ":$param", false, false);
                $wheresql[] = 'AND ' . $like;
                $value = $DB->sql_like_escape($value);
                $params[$param] = "%$value";
                break;
            case 7:
                $paramempty = $param . 'empty';
                $wheresql = "AND COALESCE($field, :{$paramempty}) = :{$param}";
                $params[$paramempty] = $params[$param] = '';
                break;
            case 8:
                $paramempty = $param . 'empty';
                $wheresql = "AND COALESCE($field, :{$paramempty}) != :{$param}";
                $params[$paramempty] = $params[$param] = '';
                break;
            default:
                break;
        }
    }

    return [
        $wheresql,
        $params
    ];
}

/**
 * Generate SQL used for multiselect filters.
 *
 * @param array $filtervalues Filter values
 * @param string $filterkey Filter name
 * @param string $studentattribute Student attribute column name
 * @return array
 */
function local_ace_filter_sql_multiselect(array $filtervalues, string $filterkey, string $studentattribute) {
    global $DB;

    $wheresql = [];
    $params = [];

    if (isset($filtervalues[$filterkey . '_operator']) && !empty($filtervalues[$filterkey . '_value'])) {
        $operator = (int) $filtervalues[$filterkey . '_operator'] ?? 0;
        $value = $filtervalues[$filterkey . '_value'];
        switch ($operator) {
            case 1:
                list($sql, $params) =
                    $DB->get_in_or_equal($value, SQL_PARAMS_NAMED, "{$studentattribute}param");
                $wheresql[] = "AND studentattributes.$studentattribute " . $sql;
                break;
            case 2:
                list($sql, $params) =
                    $DB->get_in_or_equal($value, SQL_PARAMS_NAMED, "{$studentattribute}param", false);
                $wheresql[] = "AND studentattributes.$studentattribute " . $sql;
                break;
            default:
                break;
        }
    }

    return [
        $wheresql,
        $params
    ];
}

/**
 * Generate SQL based on filters.
 *
 * @param array $filtervalues
 * @return array
 */
function local_ace_generate_filter_sql(array $filtervalues = []): array {
    global $DB;

    $joinsql = [];
    $wheresql = [];
    $params = [];

    $joinsql[] = "LEFT JOIN {ucdw_studentattributes} studentattributes
                       ON (CASE WHEN u.idnumber ~ '^\d+$' THEN " . $DB->sql_cast_char2int('u.idnumber') . " ELSE NULL END) = studentattributes.studentidentifier";

    $selectfilters = [
        ['aceuser:gender', 'gender'],
        ['aceuser:ethnicity', 'etnicitypriority'],
        ['aceuser:firstinfamily', 'firstinfamily'],
        ['aceuser:fullfee', 'fullfee'],
        ['aceuser:fullpart', 'fullpart'],
        ['aceuser:firstyearkaitoko', 'firstyearkaitoko'],
    ];
    foreach ($selectfilters as $filter) {
        list($filterwhere, $filterparams) = local_ace_filter_sql_select($filtervalues, $filter[0], $filter[1]);
        $wheresql = array_merge($wheresql, $filterwhere);
        $params = array_merge($params, $filterparams);
    }

    $multiselectfilters = [
        ['aceuser:schooldecile', 'schooldecile'],
        ['aceuser:programme', 'programmecode1']
    ];
    foreach ($multiselectfilters as $filter) {
        list($filterwhere, $filterparams) = local_ace_filter_sql_multiselect($filtervalues, $filter[0], $filter[1]);
        $wheresql = array_merge($wheresql, $filterwhere);
        $params = array_merge($params, $filterparams);
    }

    [$fullnamesql, $fullnameparams] = core_user\fields::get_sql_fullname('u');

    $textfilters = [
        ['aceuser:idnumber', 'u.idnumber'],
        ['aceuser:fullname', "($fullnamesql)"]
    ];
    foreach ($textfilters as $filter) {
        list($filterwhere, $filterparams) = local_ace_filter_sql_text($filtervalues, $filter[0], $filter[1]);
        $wheresql = array_merge($wheresql, $filterwhere);
        $params = array_merge($params, $filterparams);
    }

    if (isset($filtervalues['aceuser:fullname_operator']) && isset($filtervalues['aceuser:fullname_value'])) {
        $params = array_merge($params, $fullnameparams);
    }

    if ($filtervalues['aceuser:activityviewed_operator'] != 0 && !empty($filtervalues['aceuser:activityviewed_value'])) {
        $course = local_ace_get_course_helper();
        if (!empty($course)) {
            $courseid = $course->id;
        } else {
            $courseid = SITEID;
        }
        $joinsql[] =
            'LEFT JOIN {local_ace_log_summary} acelogsummary ON acelogsummary.courseid = :activityviewedcourseid AND acelogsummary.userid = u.id';
        $params['activityviewedcourseid'] = $courseid;

        if ($filtervalues['aceuser:activityviewed_operator'] == 1) {
            list($sql, $inparams) =
                $DB->get_in_or_equal($filtervalues['aceuser:activityviewed_value'], SQL_PARAMS_NAMED, 'activityviewedparam');
            $wheresql[] = "AND acelogsummary.cmid " . $sql;
            $params = array_merge($params, $inparams);
        }
        if ($filtervalues['aceuser:activityviewed_operator'] == 2) {
            list($sql, $inparams) =
                $DB->get_in_or_equal($filtervalues['aceuser:activityviewed_value'], SQL_PARAMS_NAMED, 'activityviewedparam', false);
            $wheresql[] = "AND acelogsummary.cmid " . $sql;
            $params = array_merge($params, $inparams);
        }
    }

    if ($filtervalues['aceuser:activitycompleted_operator'] != 0 && !empty($filtervalues['aceuser:activitycompleted_value'])) {
        $course = local_ace_get_course_helper();
        if (!empty($course)) {
            $courseid = $course->id;
        } else {
            $courseid = SITEID;
        }

        $joinsql[] = 'LEFT JOIN {course_modules} cm ON cm.course = :activitycompletedcourseid';
        $joinsql[] = 'LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id
        AND cmc.userid = u.id AND cmc.completionstate in (1,2,3)';
        $params['activitycompletedcourseid'] = $courseid;

        if ($filtervalues['aceuser:activitycompleted_operator'] == 1) {
            list($sql, $inparams) =
                $DB->get_in_or_equal($filtervalues['aceuser:activitycompleted_value'], SQL_PARAMS_NAMED, 'activitycompletedparam');
            $wheresql[] = "AND cmc.coursemoduleid " . $sql;
            $params = array_merge($params, $inparams);
        }
        if ($filtervalues['aceuser:activitycompleted_operator'] == 2) {
            list($sql, $inparams) =
                $DB->get_in_or_equal($filtervalues['aceuser:activitycompleted_value'], SQL_PARAMS_NAMED, 'activitycompletedparam',
                    false);
            $wheresql[] = "AND cmc.coursemoduleid " . $sql;
            $params = array_merge($params, $inparams);
        }
    }

    return [
        $joinsql,
        $wheresql,
        $params,
    ];
}

/**
 * Returns series data for course engagement data.
 *
 * @param int $courseid
 * @param int|null $period
 * @param int|null $start
 * @param int|null $end
 * @param bool $allowfilters If filters should be used on selecting data
 * @return array
 * @throws dml_exception
 */
function local_ace_course_data_values(
    int $courseid,
    ?int $period = null,
    ?int $start = null,
    ?int $end = null,
    bool $allowfilters = true
): array {
    global $DB, $SESSION;

    $config = get_config('local_ace');

    if ($period === null) {
        $period = (int) $config->displayperiod;
    }

    if ($start === null) {
        $start = time() - $config->userhistory;
    }

    $context = context_course::instance($courseid);

    $parameters = array(
        'context' => $context->id,
        'period' => $period,
        'start' => $start
    );

    if (!empty($SESSION->local_ace_filtervalues) && $allowfilters) {
        list($joinsql, $wheresql, $params) = local_ace_generate_filter_sql($SESSION->local_ace_filtervalues);
        $sql = "SELECT lap.starttime, lap.endtime, count(lap.value) as count, sum(lap.value) as value
                FROM {local_ace_samples} lap
                JOIN {user} u ON u.id = lap.userid
                " . implode(" ", $joinsql) . "
                WHERE lap.contextid = :context AND (lap.endtime - lap.starttime = :period) AND lap.endtime > :start
                " . ($end != null ? "AND endtime < :end " : "") . "
                " . implode(" ", $wheresql) . "
                GROUP BY lap.starttime, lap.endtime
                ORDER BY lap.starttime DESC";
        $parameters = array_merge($parameters, $params);
    } else {
        $sql = "SELECT starttime, endtime, count(value) as count, sum(value) as value
              FROM {local_ace_contexts}
              WHERE contextid = :context AND (endtime - starttime = :period) AND endtime > :start
              " . ($end != null ? "AND endtime < :end " : "") . "
              GROUP BY starttime, endtime
              ORDER BY starttime DESC";
    }

    if ($end != null) {
        $parameters['end'] = $end;
    }
    return $DB->get_records_sql($sql, $parameters);
}

/**
 * Get course summary graph data.
 *
 * @param int $courseid
 * @param int|null $period
 * @param int|null $start
 * @param int|null $end
 * @param bool $allowfilters If filters should be used on selecting data
 * @return array|string
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_course_data(
    int $courseid,
    ?int $period = null,
    ?int $start = null,
    ?int $end = null,
    bool $allowfilters = true
) {
    $values = local_ace_course_data_values($courseid, $period, $start, $end, $allowfilters);

    $labels = array();
    $series = array();
    $laststart = null;
    $allowoverlap = DAYSECS; // Allow an overlap between periods of up to this amount - takes in account slow cron.
    foreach ($values as $value) {
        if (!empty($laststart) && $value->endtime > ($laststart + $allowoverlap)) {
            // If this period overlaps with the last week, skip it in the display.
            continue;
        }
        $labels[] = userdate($value->endtime, get_string('strftimedateshortmonthabbr', 'langconfig'));
        $calcval = 0;
        if (!empty($value->value)) {
            $calcval = round(($value->value / $value->count) * 100); // Convert to average percentage.
        }
        if (!empty($value->viewcountvalue)) {
            $viewcount = local_ace_normalise_value($value->viewcountvalue / $value->viewcount, 0, 750);
            // Lets make the view count 50% of the displayed value for now - maybe change later?
            $calcval = ($calcval + $viewcount) / 2;
        }
        $series[] = $calcval;
        // Make sure we don't show overlapping periods.
        $laststart = $value->starttime;
    }

    if (empty($series)) {
        return get_string('noanalyticsfoundcourse', 'local_ace');
    }
    $ylabels = local_ace_get_percentage_ylabels();

    return array(
        'series' => array_reverse($series),
        'xlabels' => array_reverse($labels),
        'ylabels' => $ylabels,
    );
}

/**
 * Returns the HTML output for the student engagement graph that includes a tab selector for courses.
 *
 * @param int $userid
 * @param int|null $courseid
 * @return string
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_ace_student_full_graph(int $userid, ?int $courseid = 0): string {
    global $PAGE, $OUTPUT, $USER;

    list($courseid, $courses) = local_ace_get_user_courses($userid, $courseid);
    if (empty($courses)) {
        return get_string('noanalytics', 'local_ace');
    }

    $config = get_config('local_ace');

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

    $output = html_writer::start_div('useranalytics');

    $output .= print_tabs(array($tabs), $courseid, null, null, true);

    if (!empty($courseid)) {
        $url = '/course/view.php?id=' . $courseid;
        $output .= $OUTPUT->heading(html_writer::link($url, $courses[$courseid]->fullname), 3, 'coursename');
    }

    $context = array(
        'colourusercoursehistory' => $config->colourusercoursehistory,
        'colouruserhistory' => $config->colouruserhistory,
        'userid' => $userid,
        'colours' => explode(',', $config->colours),
        'defaultchartfilter' => get_user_preferences('local_ace_default_chart_filter', null, $USER->id),
        'chartcomparisonmethod' => get_user_preferences('local_ace_comparison_method', 'average-course-engagement', $USER->id),
    );

    $renderer = $PAGE->get_renderer('core');
    $output .= $renderer->render_from_template('local_ace/student_engagement_chart', $context);
    $PAGE->requires->js_call_amd('local_ace/student_engagement', 'init', [$context]);

    $output .= html_writer::end_div();
    $output .= local_ace_get_dedication($courseid);

    return $output;
}

/**
 * Returns the number of users enrolled in the given course after applying filters.
 *
 * @param int $courseid
 * @return int
 */
function local_ace_get_num_users_with_filters_applied(int $courseid): int {
    global $DB, $SESSION;

    $context = context_course::instance($courseid);

    list($filterjoinsql, $filterwheresql, $filterparams) = local_ace_generate_filter_sql($SESSION->local_ace_filtervalues);

    $sql = "SELECT COUNT(u.id)
            FROM {user} u
            JOIN {user_enrolments} ue ON ue.userid = u.id
            JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
            JOIN {role_assignments} ra ON ra.contextid = :contextid AND ra.userid = u.id
            JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
            " . implode(" ", $filterjoinsql) . "
            WHERE 1=1 " . implode(" ", $filterwheresql);
    $params = array_merge(['courseid' => $courseid, 'contextid' => $context->id], $filterparams);

    return $DB->count_records_sql($sql, $params);
}

/**
 * Helper function to print average time spent in course from dedication plugin.
 *
 * @param [type] $courseid
 * @return string
 */
function local_ace_get_dedication($courseid) {
    global $SESSION;

    $config = get_config('local_ace');

    if (class_exists("\block_dedication\lib\utils") && !empty($courseid)) {
        $dedicationhistory = (empty($config->dedicationhistory)) ? WEEKSECS : $config->dedicationhistory;
        $timespent = \block_dedication\lib\utils::get_average($courseid, $dedicationhistory);
        $a = new stdClass();
        $a->timespent = !empty($timespent['average']) ? $timespent['average'] : get_string('none');
        $a->days = $dedicationhistory / DAYSECS;

        $helper = '<a class="btn btn-link p-0" role="button" data-container="body"
        		data-toggle="popover" data-placement="right" data-content="<p>' .
            get_string('averagetimespentincoursehelper', 'block_ace') . '</p>"
            		data-html="true" tabindex="0" data-trigger="focus" data-original-title="" title="">
            		<i class="icon fa fa-question-circle text-info fa-fw " title="' .
            get_string('averagetimespentincoursehelper', 'block_ace') . '" role="img" aria-label=""></i></a>';

        $output = html_writer::start_div('course_dedication');
        $output .= html_writer::tag('p', get_string('averagetimespentincourse', 'local_ace', $a) . $helper);

        if (!empty($SESSION->local_ace_filtervalues)) {
            $filtertimespent = \block_dedication\lib\utils::get_average($courseid, $dedicationhistory, true);
            $filtera = new stdClass();
            $filtera->timespent = !empty($filtertimespent['average']) ? $filtertimespent['average'] : get_string('none');
            $filtera->days = $dedicationhistory / DAYSECS;
            $output .= html_writer::tag('p', get_string('averagetimespentincoursefiltered', 'local_ace', $filtera) . $helper);
        }

        $output .= html_writer::end_div();

        return $output;
    }
    return '';
}

/**
 * Returns a list of courses and a course id that meet the following conditions:
 * - Contain analytics data
 * - The user is enrolled
 * - Not excluded
 *
 * @param int $userid
 * @param int|null $courseid
 * @return array array[0] = int (courseid) and array[1] = array (courses)
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_get_user_courses(int $userid, ?int $courseid = 0): array {
    global $DB;

    $startfrom = time() - get_config('local_ace', 'userhistory');
    $period = get_config('local_ace', 'displayperiod');

    $activeenrolledcourses = enrol_get_users_courses($userid, true);
    if (empty($activeenrolledcourses)) {
        return array(
            0 => 0,
            1 => 0,
        );
    }
    list($sqlin, $params) = $DB->get_in_or_equal(array_keys($activeenrolledcourses), SQL_PARAMS_NAMED);

    // Get courses where analytics data exists.
    $sql = "SELECT DISTINCT co.id, co.shortname, co.enddate, co.fullname
              FROM {local_ace_samples} s
              JOIN {local_ace_contexts} c ON c.contextid = s.contextid
                   AND s.starttime = c.starttime AND s.endtime = c.endtime
              JOIN {context} cx ON c.contextid = cx.id AND cx.contextlevel = " . CONTEXT_COURSE . "
              JOIN {course} co ON cx.instanceid = co.id AND co.id $sqlin
              WHERE s.userid = :userid AND (s.endtime - s.starttime = :per)
              AND s.endtime > :start ORDER BY co.shortname";
    $params['userid'] = $userid;
    $params['per'] = $period;
    $params['start'] = $startfrom;

    $courses = $DB->get_records_sql($sql, $params);

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
function local_ace_student_graph(int $userid, $courses, bool $showxtitles = true) {
    global $OUTPUT;

    $config = get_config('local_ace');

    $data = local_ace_student_graph_data($userid, $courses, null, null, $showxtitles);
    if (empty($data['data'])) {
        return '';
    }

    $chart = new \core\chart_line();
    $chart->set_legend_options(['display' => false]);
    $chart->set_smooth(true);

    $chart->set_labels($data['xlabels']);

    foreach ($data['data'] as $series) {
        $chartseries = new \core\chart_series($series['label'], $series['values']);
        $chartseries->set_color($series['colour']);
        if (isset($series['fill'])) {
            $chartseries->set_fill(1);
        }
        $chart->add_series($chartseries);
    }

    $yaxis0 = $chart->get_yaxis(0, true);
    $yaxis0->set_min(0);
    $yaxis0->set_max($data['max']);
    $yaxis0->set_stepsize($data['stepsize']);
    $yaxis0->set_labels(array(0 => '0%',
        25 => '25%',
        50 => '50%',
        75 => '75%',
        100 => '100%'
    ));

    return $OUTPUT->render($chart);
}

/**
 * Fetch graph data for specific user.
 * When passing in an array of course ids it will only return the averaged value, not individual course data.
 *
 * @param int $userid
 * @param int|array $course
 * @param int|null $start Display period start, defaults to displaying all course history to date.
 * @param int|null $end Display period end
 * @param bool $showxtitles
 * @param string $comparison Comparison data source, defaults to average course engagement
 * @param bool $normalisevalues normalise values to be within a 0-100 range
 * @return array|string
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_student_graph_data(int $userid, $course, ?int $start = null, ?int $end = null, ?bool $showxtitles = true,
    string $comparison = 'average-course-engagement', bool $normalisevalues = true) {
    global $DB, $CFG;

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
        return get_string('noanalytics', 'local_ace');
    }

    // Restrict to course passed, or enrolled users courses.
    list($insql, $inparamscf1) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pa');
    $coursefilter = "AND co.id $insql";

    // Get the users stats.
    // Get the latest values first, so we always show the most recent data-set.
    if ($CFG->dbtype !== 'pgsql') {
        $timefields = " SELECT
            round(UNIX_TIMESTAMP(DATE_FORMAT( FROM_UNIXTIME(starttime), '%Y-%m-%d'))) as starttime,
            round(UNIX_TIMESTAMP(DATE_FORMAT( FROM_UNIXTIME(endtime), '%Y-%m-%d'))) as endtime,
        ";
    } else {
        $timefields = " SELECT
            EXTRACT('epoch' FROM date_trunc('day', to_timestamp(starttime))) AS starttime,
            EXTRACT('epoch' FROM date_trunc('day', to_timestamp(endtime))) AS endtime,
        ";
    }
    $sql = "WITH samples AS (
               " . $timefields . "
                       value,
                       userid
                FROM {local_ace_samples} s
                JOIN {context} cx ON s.contextid = cx.id AND cx.contextlevel = 50
                JOIN {course} co ON cx.instanceid = co.id
                WHERE (endtime - starttime = :per) "
        . ($start != null ? "AND endtime > :start" : "")
        . ($end != null ? "AND endtime < :end" : "")
        . " $coursefilter
            )
            SELECT s.starttime, s.endtime, count(s.value) AS count, sum(s.value) AS value, a.avg AS avg, a.stddev AS stddev
              FROM samples s
              JOIN (
                        SELECT starttime, endtime, stddev(value) as stddev, avg(value) as avg
                        FROM samples s
                        GROUP BY starttime, endtime
                    ) a ON a.starttime = s.starttime AND a.endtime = a.endtime
              WHERE s.userid = :userid
              GROUP BY s.starttime, s.endtime, avg, stddev
              ORDER BY s.starttime DESC";

    $params = $inparamscf1 + array('userid' => $userid, 'per' => $period, 'start' => $start);
    if ($start == null) {
        $params['start'] = time() - (int) $config->userhistory;
    }
    if ($end != null) {
        $params['end'] = $end;
    }

    $values = $DB->get_records_sql($sql, $params);

    $labels = array();
    $series = array();
    $average1 = array();
    $average2 = array();
    $laststart = null;
    $allowoverlap = DAYSECS; // Allow an overlap between periods of up to this amount - takes in account slow cron.
    foreach ($values as $value) {
        if (!empty($laststart) && $value->endtime > ($laststart + $allowoverlap)) {
            // If this period overlaps with the last week, skip it in the display.
            continue;
        }
        if ($showxtitles) {
            $labels[] = userdate($value->endtime, get_string('strftimedateshortmonthabbr', 'langconfig'));
        } else {
            $labels[] = '';
        }
        $calcval = 0;
        if (!empty($value->value)) {
            if ($normalisevalues) {
                $calcval = round(local_ace_normalise_value(($value->value / $value->count) * 100, 0, 100));
            } else {
                $calcval = round(($value->value / $value->count) * 100);
            }
        }
        if (!empty($value->viewcountvalue)) {
            $viewcount = local_ace_normalise_value($value->viewcountvalue / $value->viewcount, 0, 750);
            // Lets make the view count 50% of the displayed value for now - maybe change later?
            $calcval = ($calcval + $viewcount) / 2;
        }
        $series[] = $calcval;
        $a1 = 0;
        $a2 = 0;
        if (!empty($value->avg)) {
            if (!empty($value->vcavg)) {
                $navg = local_ace_normalise_value($value->vcavg, 0, 750);
                $avg = local_ace_normalise_value($value->avg * 100, 0, 100);
                $stddev = ($value->stddev / 2) * 100;
                $a1 = round((($navg + $avg) / 2) - $stddev);
                $a2 = round((($navg + $avg) / 2) + $stddev);
            } else {
                $a1 = round(local_ace_normalise_value(($value->avg - ($value->stddev / 2)) * 100, 0, 100));
                $a2 = round(local_ace_normalise_value(($value->avg + ($value->stddev / 2)) * 100, 0, 100));
            }
        }
        $average1[] = local_ace_nonegative_value($a1);
        $average2[] = local_ace_nonegative_value($a2);

        // Make sure we don't show overlapping periods.
        $laststart = $value->starttime;
    }

    if (empty($series)) {
        return get_string('noanalytics', 'local_ace');
    }

    // Get max value to use as upper level of graph.
    if ($normalisevalues) {
        $max = 100;
    } else {
        $max = ceil(max(max($series), max($average1), max($average2)));
    }

    // Charts.js doesn't cope when the stepsize is under 1.
    // Some of the courses have very little engagement so we occasionally end up with very low values.
    // This results in the Y axis having "high/high/high" instead of low/medium/high.
    // We do not want to show "real" values on the student graph, so the y-axis just autoscales to the max and low values.
    if ($max < 2) {
        $max = 2;
    }
    $stepsize = ceil($max / 4);

    $allseries = [
        [
            'label' => get_string('yourengagement', 'local_ace'),
            'values' => array_reverse($series),
            'colour' => $config->colouruserhistory
        ]
    ];

    switch ($comparison) {
        case 'average-course-engagement':
            $allseries[] = [
                'label' => get_string('averagecourseengagement', 'local_ace'),
                'values' => array_reverse($average1),
                'colour' => $config->colourusercoursehistory,
            ];
            $allseries[] = [
                'label' => get_string('averagecourseengagement', 'local_ace'),
                'values' => array_reverse($average2),
                'colour' => $config->colourusercoursehistory,
                'fill' => true,
            ];
            break;
    }

    // Reverse Series/labels to order by date correctly.
    return array(
        'data' => $allseries,
        'xlabels' => array_reverse($labels),
        'max' => $max,
        'stepsize' => $stepsize,
        'ylabels' => local_ace_get_percentage_ylabels()
    );
}

/**
 * Returns the HTML output for showing the activity engagement graph.
 *
 * @param int $cmid Course module ID
 * @return string
 * @throws moodle_exception
 */
function local_ace_course_module_engagement_graph(int $cmid): string {
    global $PAGE;

    $config = get_config('local_ace');

    $renderer = $PAGE->get_renderer('core');
    $output = $renderer->render_from_template('local_ace/activity_engagement_chart', null);
    $context = [
        'colouractivityengagement' => $config->colouractivityengagement ?? '#613d7c',
        'cmid' => $cmid,
    ];
    $PAGE->requires->js_call_amd('local_ace/activity_engagement', 'init', [$context]);
    return $output;
}

/**
 * Get the activity engagement data from the logstore table.
 *
 * @param int $cmid Course module ID
 * @param int|null $start
 * @param int|null $end
 * @param bool $cumulative
 * @return array|string
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_course_module_engagement_data(int $cmid, ?int $start = null, ?int $end = null, bool $cumulative = false) {
    global $DB;

    $cm = $DB->get_record('course_modules', ['id' => $cmid], 'course');
    if ($cm == null) {
        return get_string('noanalytics', 'local_ace');
    }

    $config = get_config('local_ace');
    if ($start === null) {
        $start = time() - $config->userhistory;
    }
    if ($end === null) {
        $end = time();
    }

    // It's not required to add the course in the WHERE, but it helps with performance.
    $sql = "SELECT to_timestamp(timecreated)::date as date, COUNT(*)
                FROM {logstore_standard_log}
                WHERE contextlevel = :modulelevel AND courseid = :courseid AND contextinstanceid = :contextinstanceid
                    AND timecreated >= :start AND timecreated <= :end
                GROUP BY date ORDER BY date";
    $params = [
        'modulelevel' => CONTEXT_MODULE,
        'courseid' => $cm->course,
        'contextinstanceid' => $cmid,
        'start' => $start,
        'end' => $end,
    ];
    $records = $DB->get_records_sql($sql, $params);

    // Graph requires 2+ records to operate.
    if (count($records) <= 1) {
        return get_string('noanalytics', 'local_ace');
    }

    $series = [];
    $labels = [];

    if ($cumulative) {
        $max = array_sum(array_column($records, 'count'));
    } else {
        $max = max(array_column($records, 'count'));
    }

    $count = 0;
    foreach ($records as $record) {
        $labels[] = userdate(strtotime($record->date), get_string('strftimedateshortmonthabbr', 'langconfig'));
        // Normalise the value into a 0-100 range.
        if ($cumulative) {
            $count += $record->count;
            $series[] = $count;
        } else {
            $series[] = $record->count;
        }
    }

    if ($max < 4) {
        $max = 4;
    }

    $stepsize = ceil($max / 4);
    if ($stepsize * 4 > $max) {
        $max = $stepsize * 4;
    }

    $ylabels = [];
    for ($val = 0; $val <= $max; $val += $stepsize) {
        $ylabels[] = [
            'value' => $val,
            'label' => $val
        ];
    }

    return array(
        'series' => $series,
        'xlabels' => $labels,
        'ylabels' => $ylabels,
        'max' => $max,
        'stepsize' => $stepsize,
    );
}

/**
 * Normalise value to be within a 0-100 range.
 *
 * @param float $value
 * @param float $min
 * @param float $max
 * @return float
 */
function local_ace_normalise_value(float $value, float $min, float $max) {
    return min((($value - $min) / ($max - $min)) * 100, 100);
}

/**
 * Return 0 for negative score.
 *
 * @param float $value
 * @return int
 */
function local_ace_nonegative_value(float $value) {
    if ($value < 0) {
        return 0;
    }
    return $value;
}

/**
 * Send bulk emails to users.
 *
 * @param array $userids submitted user id's.
 * @param string $emailsubject email subject.
 * @param string $messagehtml email message.
 * @return int Number of emails sent
 */
function local_ace_send_bulk_email(array $userids, string $emailsubject, string $messagehtml): int {
    global $DB;

    $emailssent = 0;
    if (!empty($userids)) {
        foreach ($userids as $userid) {
            // Get user emails address from id.
            $userdata = $DB->get_record('user', array('id' => $userid));
            if (!$userdata) {
                continue;
            }

            $fromuser = \core_user::get_support_user();
            if (!$fromuser) {
                break;
            }

            $messagetext = html_to_text($messagehtml);
            if (email_to_user($userdata, $fromuser, $emailsubject, $messagetext, $messagehtml)) {
                $emailssent++;
            }
        }
    }
    return $emailssent;
}

/**
 * Returns an array of 4 labels with percentage labels.
 *
 * @return array[]
 */
function local_ace_get_percentage_ylabels(): array {
    return [
        [
            'value' => 0,
            'label' => '0%'
        ],
        [
            'value' => 25,
            'label' => '25%'
        ],
        [
            'value' => 50,
            'label' => '50%'
        ],
        [
            'value' => 75,
            'label' => '75%'
        ],
        [
            'value' => 100,
            'label' => '100%'
        ],
    ];
}

/**
 * Gets the course module context ID from these places in this order:
 * - $PAGE context
 * - `contextid` set in url parameter
 * - `contextid` set in the referrer
 *
 * @return int|null
 * @throws coding_exception
 */
function local_ace_get_coursemodule_helper() {
    global $PAGE, $CFG;

    try {
        $context = $PAGE->context;
        if ($context->contextlevel === CONTEXT_MODULE) {
            return $context->instanceid;
        }
        // @codingStandardsIgnoreStart
    } catch (\coding_exception $ignored) {
    }
    // @codingStandardsIgnoreStart

    $contextid = optional_param('contextid', '0', PARAM_INT);
    if (!empty($contextid)) {
        $context = context::instance_by_id($contextid);
        if ($context->contextlevel === CONTEXT_MODULE) {
            return $context->instanceid;
        }
    }

    $referrer = get_local_referer(false);
    if (!empty($referrer) && (
            strpos($referrer, $CFG->wwwroot . '/local/dboard/index.php') === 0 ||
            strpos($referrer, $CFG->wwwroot . '/local/vxg_dashboard/index.php') === 0)) {

        $urlcomponents = parse_url($referrer);
        if (!empty($urlcomponents['query'])) {
            parse_str($urlcomponents['query'], $params);

            if (!empty($params['contextid'])) {
                $context = context::instance_by_id($params['contextid']);
                if ($context->contextlevel === CONTEXT_MODULE) {
                    return $context->instanceid;
                }
            }
        }
    }

    return null;
}

/**
 * Gets the user module context ID from these places in this order:
 * - $PAGE context
 * - `contextid` set in url parameter
 * - `contextid` set in the referrer
 *
 * @return int|null
 * @throws coding_exception
 */
function local_ace_get_user_helper() {
    global $PAGE, $CFG, $USER;

    try {
        $context = $PAGE->context;
        if ($context->contextlevel === CONTEXT_USER) {
            return $context->instanceid;
        }
        // @codingStandardsIgnoreStart
    } catch (\coding_exception $ignored) {
    }
    // @codingStandardsIgnoreStart

    $contextid = optional_param('contextid', '0', PARAM_INT);
    if (!empty($contextid)) {
        $context = context::instance_by_id($contextid);
        if ($context->contextlevel === CONTEXT_USER) {
            return $context->instanceid;
        }
    }

    $referrer = get_local_referer(false);
    if (!empty($referrer) && (
            strpos($referrer, $CFG->wwwroot . '/local/dboard/index.php') === 0 ||
            strpos($referrer, $CFG->wwwroot . '/local/vxg_dashboard/index.php') === 0)) {

        $urlcomponents = parse_url($referrer);
        if (!empty($urlcomponents['query'])) {
            parse_str($urlcomponents['query'], $params);

            if (!empty($params['contextid'])) {
                $context = context::instance_by_id($params['contextid']);
                if ($context->contextlevel === CONTEXT_USER) {
                    return $context->instanceid;
                }
            }
        }
    }

    return $USER->id;
}

/**
 * Nasty hack function to get the course from either the referring page (called by webservice) or from optional_param.
 *
 * @return false|stdClass
 * @throws coding_exception
 * @throws dml_exception
 */
function local_ace_get_course_helper() {
    global $CFG, $COURSE, $PAGE;

    // Check if loaded in current url.
    $courseid = optional_param('course', '0', PARAM_INT);
    if (!empty($courseid) && $courseid != SITEID) {
        $course = get_course($courseid);
        return $course;
    }

    // Check if set by course global.
    if (!empty($COURSE) && $COURSE->id != SITEID) {
        return $COURSE;
    }

    // See if $PAGE is set, and if it relates to a course context.
    if (!empty($PAGE)) {
        try { // Don't trigger an exception if we can't get a coursecontext.
            $coursecontext = $PAGE->context->get_course_context(false);
            if (!empty($coursecontext) && !empty($coursecontext->instanceid) && $coursecontext->instanceid != SITEID) {
                return get_course($coursecontext->instanceid);
            }
            // @codingStandardsIgnoreStart
        } catch (coding_exception $e) {
            // We get the course another way below.
        }
        // @codingStandardsIgnoreEnd
        // See if $PAGE is set, and if it relates to a user context.
        // And if user only has one course in the list - use that.
        try {
            if ($PAGE->context->contextlevel == CONTEXT_USER) {
                // Get list of allowed courses.
                list($courseid, $courses) = local_ace_get_user_courses($PAGE->context->instanceid, 0);
                if (isset($courses) && count($courses) == 1) {
                    return get_course($courseid);
                }
            }
            // @codingStandardsIgnoreStart
        } catch (coding_exception $e) {
            // We get the course another way below.
        }

        // If this is a user context - check to see if they are only enrolled in one active course.
        // If so - return that single course.
    }

    // Finally check if set in HTTP_REFERRER - will be a webservice call from the dashboard page.
    $referrer = get_local_referer(false);
    if (!empty($referrer) && (
            strpos($referrer, $CFG->wwwroot . '/local/dboard/index.php') === 0 ||
            strpos($referrer, $CFG->wwwroot . '/local/vxg_dashboard/index.php') === 0)) {

        $urlcomponents = parse_url($referrer);
        if (!empty($urlcomponents['query'])) {
            parse_str($urlcomponents['query'], $params);
            if (!empty($params['course']) && $params['course'] != SITEID) {
                $course = get_course((int) $params['course']);
                return $course;
            } else if (!empty($params['contextid'])) {
                $context = context::instance_by_id($params['contextid'], IGNORE_MISSING);
                if (!empty($context)) {
                    if ($context->contextlevel == CONTEXT_USER) {
                        // Get list of allowed courses.
                        list($courseid, $courses) = local_ace_get_user_courses($context->instanceid, 0);
                        if (isset($courses) && count($courses) == 1) {
                            return get_course($courseid);
                        }
                    }
                    $coursecontext = $context->get_course_context(false);
                    if (!empty($coursecontext) && !empty($coursecontext->instanceid) && $coursecontext->instanceid != SITEID) {
                        return get_course($coursecontext->instanceid);
                    }
                }
            }
        }
    }
    return false;
}
