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
 * Adds admin settings for the plugin.
 *
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

        $options = array(
            0 => new lang_string('neverdeletelogs'),
            1000 => new lang_string('numdays', '', 1000),
            365 => new lang_string('numdays', '', 365),
            180 => new lang_string('numdays', '', 180),
            150 => new lang_string('numdays', '', 150),
            120 => new lang_string('numdays', '', 120),
            90 => new lang_string('numdays', '', 90),
            60 => new lang_string('numdays', '', 60),
            35 => new lang_string('numdays', '', 35),
            10 => new lang_string('numdays', '', 10),
            5 => new lang_string('numdays', '', 5),
            2 => new lang_string('numdays', '', 2),
            1 => new lang_string('numdays', '', 1));

        $settings->add(new admin_setting_configselect(
            'local_ace/allloglifetime',
            new lang_string('allloglifetime', 'local_ace'),
            new lang_string('configallloglifetime', 'local_ace'), 90, $options));

        $settings->add(new admin_setting_configtext(
            'local_ace/displayperiod',
            new lang_string('displayperiod', 'local_ace'),
            new lang_string('displayperiod_desc', 'local_ace'),
            DAYSECS * 3
        ));

        $settings->add(new admin_setting_configtext(
            'local_ace/userhistory',
            new lang_string('userhistory', 'local_ace'),
            new lang_string('userhistory_desc', 'local_ace'),
            WEEKSECS * 4
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colourteachercoursehistory',
            new lang_string('colourteachercoursehistory', 'local_ace'),
            new lang_string('colourteachercoursehistory_desc', 'local_ace'),
            '#613d7c'
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colourteachercoursehigh',
            new lang_string('colourteachercoursehigh', 'local_ace'),
            new lang_string('colourteachercoursehigh_desc', 'local_ace'),
            '#5cb85c'
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colourteachercoursegood',
            new lang_string('colourteachercoursegood', 'local_ace'),
            new lang_string('colourteachercoursegood_desc', 'local_ace'),
            '#5bc0de'
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colourteachercourselow',
            new lang_string('colourteachercourselow', 'local_ace'),
            new lang_string('colourteachercourselow_desc', 'local_ace'),
            '#ff7518'
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colourteachercoursenone',
            new lang_string('colourteachercoursenone', 'local_ace'),
            new lang_string('colourteachercoursenone_desc', 'local_ace'),
            '#d9534f'
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colourusercoursehistory',
            new lang_string('colourusercoursehistory', 'local_ace'),
            new lang_string('colourusercoursehistory_desc', 'local_ace'),
            '#CEE9CE'
        ));

        $settings->add(new admin_setting_configcolourpicker(
            'local_ace/colouruserhistory',
            new lang_string('colouruserhistory', 'local_ace'),
            new lang_string('colouruserhistory_desc', 'local_ace'),
            '#5cb85c'
        ));

        $settings->add(new admin_setting_configtext(
            'local_ace/courseregex',
            new lang_string('courseregex', 'local_ace'),
            new lang_string('courseregex_desc', 'local_ace'),
            '^[a-zA-Z]+1[0-9]{1,2}-'
        ));

        $ADMIN->add('localplugins', $settings);
    }
}
