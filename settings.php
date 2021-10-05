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
 * Plugin administration pages are defined here.
 * Adds admin settings for the plugin.
 * @package     local_ace
 * @category    admin
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_ace_settings', new lang_string('pluginname', 'local_ace')));
    $settings = new admin_settingpage('managelocalace', new lang_string('manage', 'local_ace'));

    if ($ADMIN->fulltree) {
        // TODO: Define the plugin settings page - {@link https://docs.moodle.org/dev/Admin_settings}.

    $options = array(
        0    => new lang_string('neverdeletelogs'),
        1000 => new lang_string('numdays', '', 1000),
        365  => new lang_string('numdays', '', 365),
        180  => new lang_string('numdays', '', 180),
        150  => new lang_string('numdays', '', 150),
        120  => new lang_string('numdays', '', 120),
        90   => new lang_string('numdays', '', 90),
        60   => new lang_string('numdays', '', 60),
        35   => new lang_string('numdays', '', 35),
        10   => new lang_string('numdays', '', 10),
        5    => new lang_string('numdays', '', 5),
        2    => new lang_string('numdays', '', 2),
        1    => new lang_string('numdays', '', 1));
    $settings->add(new admin_setting_configselect(
        'local_ace/allloglifetime',
        new lang_string('allloglifetime', 'local_ace'),
        new lang_string('configallloglifetime', 'local_ace'), 90, $options));

    $ADMIN->add('localplugins', $settings);




    }
}
