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
 * Web service definitions
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = array(
    'local_ace_get_user_analytics_graph' => array(
        'classname' => 'user_analytics_graph',
        'methodname' => 'get_user_analytics_graph',
        'classpath' => 'local/ace/classes/external/user_analytics_graph.php',
        'description' => 'Get the analytics graph for a specific user.',
        'type' => 'read',
        'capabilities' => '', // Capabilities depend on content user is fetching, checked in webservice method.
        'ajax' => true,
        'services' => array('local_ace_webservice'),
    ),
);

$services = array(
    'ACE Webservice' => array(
        'functions' => array(),
        'enabled' => 1,
        'restrictedusers' => 0,
        'shortname' => 'local_ace_webservice',
    ),
);
