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

declare(strict_types=1);

namespace local_ace\reportbuilder\datasource;

use core_reportbuilder\datasource;
use local_ace\local\entities\coursemodules;
use local_ace\local\entities\activitycompletion;
use core_reportbuilder\local\helpers\database;

/**
 * Users datasource
 *
 * @package   local_ace
 * @copyright 2021 University of Canterbury
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity extends datasource {

    /**
     * Return user friendly name of the datasource
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('activity');
    }

    /**
     * Initialise report
     */
    protected function initialise(): void {
        global $CFG,$SESSION;
        require_once($CFG->dirroot.'/local/ace/locallib.php');

        $activityentity = new coursemodules();
        $activitycompletion = new activitycompletion();

        $coursemodulealias = $activityentity->get_table_alias('course_modules');
        $activitycompletionalias = $activitycompletion->get_table_alias('course_modules_completion');

        $this->set_main_table('course_modules', $coursemodulealias);

        $this->add_entity($activityentity);
        $course = local_ace_get_course_helper();
        if (!empty($course) && can_access_course($course)) {
            $fieldvalueparam = database::generate_param_name();
            $this->add_base_condition_sql("{$coursemodulealias}.course = :{$fieldvalueparam}", [$fieldvalueparam => $course->id]);
        } else {
            $this->add_base_condition_sql("{$coursemodulealias}.course is null");
        }
        // ACE panel filters to be applied to main query.
        if (!empty($SESSION->local_ace_filtervalues)) {
            list($joinsql, $wheresql) = local_ace_generate_filter_sql_column($SESSION->local_ace_filtervalues, 'module');
            $filterjoin =  implode(" ", $wheresql);
            $filterjoin = str_replace('tablealias', $coursemodulealias, $filterjoin);
            if (!empty($filterjoin)) {
                $this->add_base_condition_sql($filterjoin);
            }
        }
        $userentityname = $activityentity->get_entity_name();

        // Determine which user to use within the user specific columns - use $PAGE->context if user context or global $USER.
        $userid = local_ace_get_user_helper();

        $activitycompletionjoin = "LEFT JOIN {course_modules_completion} {$activitycompletionalias}
                             ON {$activitycompletionalias}.coursemoduleid = {$coursemodulealias}.id
                            AND {$activitycompletionalias}.userid = " . $userid;

        $this->add_entity($activitycompletion->add_join($activitycompletionjoin));

        $this->add_columns_from_entity($userentityname);
        $this->add_columns_from_entity($activitycompletion->get_entity_name());

        $this->add_filters_from_entity($userentityname);
        $this->add_filters_from_entity($activitycompletion->get_entity_name());

        $this->add_conditions_from_entity($userentityname);
        $this->add_conditions_from_entity($activitycompletion->get_entity_name());
    }

    /**
     * Return the columns that will be added to the report once is created
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [];
    }

    /**
     * Return the filters that will be added to the report once is created
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [];
    }

    /**
     * Return the conditions that will be added to the report once is created
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [];
    }
}
