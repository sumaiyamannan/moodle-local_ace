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

/**
 * Select report filter
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course extends \core_reportbuilder\local\filters\base {

    /** @var int Any value */
    public const ANY_VALUE = 0;

    /** @var int Equal to */
    public const EQUAL_TO = 1;

    /** @var int Not equal to */
    public const NOT_EQUAL_TO = 2;

    /**
     * Returns an array of comparison operators
     *
     * @return array
     */
    private function get_operators(): array {
        $operators = [
            self::ANY_VALUE => get_string('filterisanyvalue', 'core_reportbuilder'),
            self::EQUAL_TO => get_string('filterisequalto', 'core_reportbuilder'),
            self::NOT_EQUAL_TO => get_string('filterisnotequalto', 'core_reportbuilder')
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

        $mform->hideIf($this->name . '_value', $this->name . '_operator', 'eq', self::ANY_VALUE);
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
        global $PAGE;
        $coursecontext = $PAGE->context->get_course_context(false);
        if (!empty($coursecontext)) {
            $courseid = $coursecontext->instanceid;
        } else {
            $courseid = SITEID;
        }
        $name = database::generate_param_name();

        $operator = $values["{$this->name}_operator"] ?? self::ANY_VALUE;

        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        // Validate filter form values.
        if (!$this->validate_filter_values((int) $operator)) {
            // Filter configuration is invalid. Ignore the filter.
            return ['', []];
        }

        switch ($operator) {
            case self::EQUAL_TO:
                $fieldsql .= "=:$name";
                $params[$name] = $courseid;
                break;
            case self::NOT_EQUAL_TO:
                $fieldsql .= "<>:$name";
                $params[$name] = $courseid;
                break;
            default:
                return ['', []];
        }
        return [$fieldsql, $params];
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
