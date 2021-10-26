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
 * External functions for the user analytics grpah
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/ace/locallib.php');

/**
 * Class user_analytics_graph
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_analytics_graph extends external_api {

    /**
     * Returns parameter types for get_user_analytics_graph function.
     *
     * @return external_function_parameters
     */
    public static function get_user_analytics_graph_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'ID of user'),
                'courseid' => new external_value(PARAM_INT, 'Course id', false),
                'start' => new external_value(PARAM_INT, 'History start', false),
                'end' => new external_value(PARAM_INT, 'History end', false)
            )
        );
    }

    /**
     * Get data required to create a chart for user engagement/analytics.
     *
     * @param int $userid
     * @param int|null $courseid
     * @param int|null $start Unix timestamp of start date
     * @param int|null $end Unix timestamp of end date
     * @return array|string
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     */
    public static function get_user_analytics_graph(int $userid, int $courseid, int $start, int $end) {
        global $USER, $DB;

        self::validate_parameters(
            self::get_user_analytics_graph_parameters(),
            array(
                'userid' => $userid,
                'courseid' => $courseid,
                'start' => $start,
                'end' => $end
            )
        );

        $supplieduser = $DB->get_record('user', array('id' => $userid, 'deleted' => 0), '*', MUST_EXIST);
        $personalcontext = context_user::instance($supplieduser->id);
        if ($userid == $USER->id && !has_capability('local/ace:viewown', $personalcontext)) {
            return array(
                'error' => get_string('nopermissions', 'error', get_capability_string('local/ace:viewown')),
                'series' => [],
                'labels' => [],
                'average1' => [],
                'average2' => [],
                'max' => null,
                'stepsize' => null,
                'ylabels' => []
            );
        } else if ($userid != $USER->id && !has_capability('local/ace:view', $personalcontext)) {
            return array(
                'error' => get_string('nopermissions', 'error', get_capability_string('local/ace:view')),
                'series' => [],
                'labels' => [],
                'average1' => [],
                'average2' => [],
                'max' => null,
                'stepsize' => null,
                'ylabels' => []
            );
        }

        list($courseid, $courses) = local_ace_get_student_courses($userid, $courseid);
        $data = local_ace_student_graph_data($userid, $courseid, $start, $end);
        if (!is_array($data)) {
            return array(
                'error' => $data,
                'series' => [],
                'labels' => [],
                'average1' => [],
                'average2' => [],
                'max' => null,
                'stepsize' => null,
                'ylabels' => []
            );
        }

        $data['ylabels'] = array(
            [
                'value' => 0,
                'label' => get_string('low', 'local_ace')
            ],
            [
                'value' => $data['stepsize'],
                'label' => get_string('medium', 'local_ace')
            ],
            [
                'value' => $data['max'],
                'label' => get_string('high', 'local_ace')
            ]
        );

        return $data;
    }

    /**
     * Returns description of get_user_analytics_graph() result values
     *
     * @return external_single_structure
     */
    public static function get_user_analytics_graph_returns() {
        return new external_single_structure([
            'error' => new external_value(PARAM_TEXT, 'Lang string of error, empty if working', false),
            'series' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'Series value')
            ),
            'labels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Formatted date string label')
            ),
            'average1' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'Lower average value')
            ),
            'average2' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'Upper average value')
            ),
            'max' => new external_value(PARAM_FLOAT, 'Maximum engagement value'),
            'stepsize' => new external_value(PARAM_FLOAT, 'Engagement stepsize between Y labels'),
            'ylabels' => new external_multiple_structure(
                new external_single_structure([
                    'value' => new external_value(PARAM_FLOAT, 'Engagement Value'),
                    'label' => new external_value(PARAM_TEXT, 'Engagement Label')
                ])
            )
        ]);
    }

}
