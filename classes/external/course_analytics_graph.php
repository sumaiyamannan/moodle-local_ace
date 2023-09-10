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
 * External functions for the course analytics graph
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/ace/locallib.php');

/**
 * Class course_analytics_graph
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_analytics_graph extends external_api {

    /**
     * Returns parameter types for get_course_analytics_graph function.
     *
     * @return external_function_parameters
     */
    public static function get_course_analytics_graph_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'period' => new external_value(PARAM_INT, 'History period', false),
                'start' => new external_value(PARAM_INT, 'History start', false),
                'end' => new external_value(PARAM_INT, 'History end', false)
            )
        );
    }

    /**
     * Get data required to create a chart for course engagement/analytics.
     *
     * @param int $courseid Course id
     * @param int|null $period Display period
     * @param int|null $start Unix timestamp of start date
     * @param int|null $end Unix timestamp of end date
     * @return array|string
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     */
    public static function get_course_analytics_graph(int $courseid, ?int $period, ?int $start, ?int $end) {
        global $DB, $SESSION;

        self::validate_parameters(
            self::get_course_analytics_graph_parameters(),
            array(
                'courseid' => $courseid,
                'period' => $period,
                'start' => $start,
                'end' => $end
            )
        );

        $coursecontext = context_course::instance($courseid);
        if (!has_capability('local/ace:view', $coursecontext)) {
            return array(
                'error' => get_string('nopermissions', 'error', get_capability_string('local/ace:view')),
                'series' => [],
                'xlabels' => [],
                'ylabels' => []
            );
        }

        // Don't filter as we always need unfiltered current engagement data.
        $data = local_ace_course_data($courseid, $period, $start, $end, false);
        if (!is_array($data)) {
            return array(
                'error' => $data,
                'series' => [],
                'xlabels' => [],
                'ylabels' => []
            );
        }

        $config = get_config('local_ace');
        $series = [];
        $length = count($data['series']);

        // Add filtered engagement series.
        if (!empty($SESSION->local_ace_filtervalues)) {
            $filtereddata = local_ace_course_data($courseid, $period, $start, $end);
            if (!is_array($filtereddata)) {
                return array(
                    'error' => $filtereddata,
                    'series' => [],
                    'xlabels' => [],
                    'ylabels' => []
                );
            }

            // Swap out labels for the filtered axes.
            $data['xlabels'] = $filtereddata['xlabels'];

            // We may need to lengthen or shorten series as they don't often align between current and filtered data.
            $length = count($filtereddata['series']);
            if (count($data['series']) < $length) {
                $data['series'] = array_pad($data['series'], $length, 0);
            } else if (count($data['series']) > $length) {
                // Limit values of current engagement series as they won't align with filtered data.
                $data['series'] = array_slice($data['series'], 0, $length);
            }

            $series[] = [
                'legend' => get_string('filteredcourseengagement', 'local_ace'),
                'label' => 'Filtered Engagement',
                'colour' => $config->colourfilteredengagement,
                'values' => $filtereddata['series'],
            ];
        }

        // Add the current series second so if the view is filtered the filtered line appears on top.
        $series[] = [
            'legend' => get_string('courseengagement', 'local_ace'),
            'label' => 'Engagement',
            'colour' => $config->colourteachercoursehistory,
            'values' => $data['series'],
        ];

        // Get previous year course shortname.
        $shortname = $DB->get_field('course', 'shortname', ['id' => $courseid], MUST_EXIST);
        if (preg_match($config->courseshortnameyearregex, $shortname, $matches)) {
            // A year was found in the shortname, lets reassemble the shortname, find last years course & retrieve data.
            $year = $matches[2] - 1;
            $lastyearshortname = $matches[1] . $year . $matches[3];

            if ($course = $DB->get_record('course', ['shortname' => $lastyearshortname], 'id')) {
                $lastyeardata =
                    local_ace_course_data($course->id, $period, $start - 3.154e+7, $end, false); // Start is set to a year ago.

                if (is_array($lastyeardata)) {
                    // We may need to lengthen or shorten series as they don't often align between last years and current data.
                    if (count($lastyeardata['series']) < $length) {
                        $lastyeardata['series'] = array_pad($lastyeardata['series'], $length, 0);
                    } else if (count($lastyeardata['series']) > $length) {
                        $lastyeardata['series'] = array_slice($lastyeardata['series'], 0, $length);
                    }

                    $series[] = [
                        'legend' => get_string('lastyearsengagement', 'local_ace'),
                        'label' => 'Last Year',
                        'colour' => $config->colourlastyeardata,
                        'values' => $lastyeardata['series'],
                        'warning' => get_string('lastyearsengagementdatealignment', 'local_ace'),
                    ];
                }
            }
        }

        $dedicationhtml = local_ace_get_dedication($courseid);

        return [
            'series' => $series,
            'xlabels' => $data['xlabels'],
            'ylabels' => $data['ylabels'],
            'dedicationhtml' => $dedicationhtml,
        ];
    }

    /**
     * Returns description of get_course_analytics_graph() result values
     *
     * @return external_single_structure
     */
    public static function get_course_analytics_graph_returns() {
        return new external_single_structure([
            'error' => new external_value(PARAM_TEXT, 'Lang string of error, empty if working', false),
            'series' => new external_multiple_structure(
                new external_single_structure([
                    'legend' => new external_value(PARAM_TEXT, 'Series legend'),
                    'label' => new external_value(PARAM_TEXT, 'Series label'),
                    'colour' => new external_value(PARAM_TEXT, 'Series line colour'),
                    'values' => new external_multiple_structure(
                        new external_value(PARAM_FLOAT, 'Series value')
                    ),
                    'warning' => new external_value(PARAM_TEXT, 'Warning text displayed at the bottom of the graph', false),
                ]),
                'List of series to be displayed on the graph',
            ),
            'xlabels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Formatted date string labels')
            ),
            'ylabels' => new external_multiple_structure(
                new external_single_structure([
                    'value' => new external_value(PARAM_FLOAT, 'Engagement Value'),
                    'label' => new external_value(PARAM_TEXT, 'Engagement Label')
                ])
            ),
            'dedicationhtml' => new external_value(PARAM_RAW, 'Dedication block HTML content', false),
        ]);
    }

}
