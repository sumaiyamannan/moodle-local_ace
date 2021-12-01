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
 * Redirect to correct dashboard page based on parameters passed.
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$userid = optional_param('userid', null, PARAM_INT);
$courseid = optional_param('course', null, PARAM_INT);
$cmid = optional_param('cmid', null, PARAM_INT);
if (empty($userid) && empty($courseid) && empty($cmid)) {
    redirect($CFG->wwwroot);
}
require_login($courseid);

if (!empty($userid)) {
    // Get user context from userid.
    $context = context_user::instance($userid);
    $url = new moodle_url(get_config('local_ace', 'userdashboardurl'), ['contextid' => $context->id]);
    if (!empty($courseid)) { // Add courseid to param if passed.
        $url->param('course', $courseid);
    }
    redirect($url);
} else if (!empty($courseid)) {
    $context = context_course::instance($courseid);
    $url = new moodle_url(get_config('local_ace', 'coursedashboardurl'), ['contextid' => $context->id]);
    redirect($url);
} else if (!empty($cmid)) {
    $context = context_module::instance($cmid);
    $url = new moodle_url(get_config('local_ace', 'coursemoduledashboardurl'), ['contextid' => $context->id]);
    redirect($url);
}

redirect($CFG->wwwroot);
