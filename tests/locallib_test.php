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

namespace local_ace;

/**
 * Unit tests for locallib functions
 *
 * @package   local_ace
 * @copyright 2023 onwards Catalyst IT EU {@link https://catalyst-eu.net}
 * @author    Mark Johnson <mark.johnson@catalyst-eu.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class locallib_test extends \advanced_testcase {

    /**
     * Only enrolled courses matching the required conditions should be returned.
     *
     * @covers ::local_ace_get_enrolled_courses()
     */
    public function test_local_ace_get_enrolled_courses(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/ace/locallib.php');

        $this->resetAfterTest();

        set_config('enrol_plugins_enabled', 'manual');
        set_config('courseregex', '^[A-Z]{3}-[0-9]{3}$', 'local_ace');

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();

        $fieldcategory = $generator->create_custom_field_category([]);
        $field = $generator->create_custom_field([
            'categoryid' => $fieldcategory->get('id'),
            'shortname' => 'ucanalyticscourseexclude',
            'type' => 'checkbox',
        ]);

        $currentstart = time() - DAYSECS;
        $oldstart = time() - (YEARSECS / 2) - DAYSECS;
        // Course the user is enrolled in, in the current semester, with an active enrolment.
        $course1 = $generator->create_course(['shortname' => 'ABC-001', 'startdate' => $currentstart]);
        $generator->enrol_user($user->id, $course1->id, 'student');
        // Course the user is enrolled in, not in the current semester.
        $course2 = $generator->create_course(['shortname' => 'ABC-002', 'startdate' => $oldstart]);
        $generator->enrol_user($user->id, $course2->id, 'student');
        // Course the user is enrolled in, with a suspended enrolment.
        $course3 = $generator->create_course(['shortname' => 'ABC-003', 'startdate' => $currentstart]);
        $generator->enrol_user($user->id, $course3->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        // Course the user is enrolled in, with a disabled enrolment instance.
        $course4 = $generator->create_course(['shortname' => 'ABC-004', 'startdate' => $currentstart]);
        $generator->enrol_user($user->id, $course4->id, 'student');
        $enrolinstance = enrol_get_instances($course4->id, true);
        $enrolplugin = new \enrol_manual_plugin();
        $enrolplugin->update_status(reset($enrolinstance), ENROL_INSTANCE_DISABLED);
        // Course the user is enrolled in, with the wrong shortname format.
        $course5 = $generator->create_course(['shortname' => '005-ABC', 'startdate' => $currentstart]);
        $generator->enrol_user($user->id, $course5->id, 'student');
        // Course the user is enrolled in, excluded by a custom field.
        $course6 = $generator->create_course(['shortname' => 'ABC-006', 'startdate' => $currentstart]);
        $generator->enrol_user($user->id, $course6->id, 'student');
        $generator->get_plugin_generator('core_customfield')->add_instance_data($field, $course6->id, 1);
        // Course the user is not enrolled in.
        $course7 = $generator->create_course(['shortname' => 'ABC-007', 'startdate' => $currentstart]);

        // With no start parameter, should return the current course.
        $enrolledcourses = \local_ace_get_enrolled_courses($user->id);
        $this->assertCount(1, $enrolledcourses);
        $this->assertArrayHasKey($course1->id, $enrolledcourses);

        // With the start parameter set, we should return the old course as well.
        $enrolledcourses = \local_ace_get_enrolled_courses($user->id, 1);
        $this->assertCount(2, $enrolledcourses);
        $this->assertArrayHasKey($course1->id, $enrolledcourses);
        $this->assertArrayHasKey($course2->id, $enrolledcourses);
    }
}
