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

class user_analytics_graph extends external_api {

    public static function get_user_analytics_graph_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'ID of user'),
                'courseid' => new external_value(PARAM_INT, 'Course id', false),
                'start' => new external_value(PARAM_INT, 'User history timeline', false),
            ),
        );
    }

    public static function get_user_analytics_graph($userid, $courseid, $startfrom) {
        global $USER, $DB;

        $supplieduser = $DB->get_record('user', array('id' => $userid, 'deleted' => 0), '*', MUST_EXIST);

        $personalcontext = context_user::instance($supplieduser->id);
        if ($userid == $USER->id && !has_capability('local/ace:viewown', $personalcontext)) {
            return array('error' => get_string('nopermissions', 'error', get_capability_string('local/ace:viewown')),
                'series' => [], 'labels' => [], 'average1' => [], 'average2' => []);
        } else if (!has_capability('local/ace:view', $personalcontext)) {
            return array('error' => get_string('nopermissions', 'error', get_capability_string('local/ace:viewown')),
                'series' => [], 'labels' => [], 'average1' => [], 'average2' => []);
        }

        self::validate_parameters(
            self::get_user_analytics_graph_parameters(),
            array(
                'userid' => $userid,
                'courseid' => $courseid,
                'start' => $startfrom,
            )
        );

        return local_ace_student_graph($userid, $courseid, $startfrom);
    }

    public static function get_user_analytics_graph_returns() {
        return new external_single_structure([
            'error' => new external_value(PARAM_TEXT, 'Lang string of error, empty if working'),
            'series' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Series value')
            ),
            'labels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Formatted date string label')
            ),
            'average1' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'Lower average value')
            ),
            'average2' => new external_multiple_structure(
                new external_value(PARAM_FLOAT, 'Upper average value')
            )
        ]);
    }

}
