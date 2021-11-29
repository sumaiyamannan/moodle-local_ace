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
 * Get courseregex from settings and use as a filter.
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courseregex extends \core_reportbuilder\local\filters\base {

    /**
     * Adds controls specific to this filter in the form.
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        // This filter doesn't have any specific controls.
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
        $shortnameregs = get_config('local_ace', 'courseregex');
        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();
        if (!empty($shortnameregs)) {
            return ["{$fieldsql} ~ '$shortnameregs'", $params];
        }
        return ['', []];
    }

    /**
     * Validate filter form values
     *
     * @param int|null $operator
     * @return bool
     */
    private function validate_filter_values(?int $operator): bool {
        return true; // No form config for this filter.
    }
}
