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

namespace local_ace\local\filters;

use MoodleQuickForm;
use core_reportbuilder\local\helpers\database;
use context_system;

/**
 * Restricts the courses displayed to the ones the logged in user is enrolled in.
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class myenrolledcourses extends \core_reportbuilder\local\filters\base {

    /** @var int Any value */
    public const ENROLLED_ONLY = 0;

    /** @var int Equal to */
    public const ALL_ACCESSIBLE = 1;

    /**
     * Returns an array of comparison operators
     *
     * @return array
     */
    private function get_operators(): array {
        $operators = [
            self::ENROLLED_ONLY => get_string('enrolledonly', 'local_ace'),
            self::ALL_ACCESSIBLE => get_string('allaccessible', 'local_ace')
        ];

        return $this->filter->restrict_limited_operators($operators);
    }

    /**
     * Adds controls specific to this filter in the form.
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        $elements = [];
        $elements['operator'] = $mform->createElement('select', $this->name . '_operator',
            get_string('filterfieldoperator', 'core_reportbuilder', $this->get_header()), $this->get_operators());

        $mform->addElement('group', $this->name . '_group', '', $elements, '', false);

        $mform->hideIf($this->name . '_value', $this->name . '_operator', 'eq', self::ENROLLED_ONLY);
    }

    /**
     * Return filter SQL
     *
     * Note that operators must be of type integer, while values can be integer or string.
     *
     * @param array $values
     * @return array array of two elements - SQL query and named parameters
     */
    public function get_sql_filter(array $values): array {
        global $DB;
        $operator = $values["{$this->name}_operator"] ?? self::ENROLLED_ONLY;

        if (!$this->validate_filter_values((int) $operator)) {
            // Filter configuration is invalid. Ignore the filter.
            return ['', []];
        }

        $allaccessible = false;
        if ($operator == self::ALL_ACCESSIBLE) {
            if (is_siteadmin() || has_capability('moodle/course:view', context_system::instance())) {
                // This user can view all courses - just ignore the filter rather than passing all of the site course ids.
                return ['', []];
            }
            $allaccessible = true;
        }

        $courses = enrol_get_my_courses('id', null, 0, [], $allaccessible);
        // This user is not enrolled in any course. Ignore filter.
        if (empty($courses)) {
            return ['', []];
        } else {
            $courseids = array_keys($courses);

            $fieldsql = $this->filter->get_field_sql();
            $params = $this->filter->get_field_params();

            $paramprefix = database::generate_param_name() . '_';
            [$courseselect, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, $paramprefix);
            return ["{$fieldsql} $courseselect", array_merge($params, $courseparams)];
        }
    }

    /**
     * Validate filter form values
     *
     * @param int|null $operator
     * @return bool
     */
    private function validate_filter_values(?int $operator): bool {
        return !($operator === null);
    }
}
