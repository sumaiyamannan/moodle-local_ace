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

use lang_string;
use MoodleQuickForm;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\filters\base;

/**
 * Ace last access date filter - based on core date filter.
 *
 * This filter accepts a unix timestamp to perform date filtering on
 *
 * @package     local_ace
 * @copyright   2021 Canterbury University
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acedate extends base {

    /** @var int Any value */
    public const DATE_ANY = 0;

    /** @var int Non-empty (positive) value */
    public const DATE_NOT_EMPTY = 1;

    /** @var int Empty (zero) value */
    public const DATE_EMPTY = 2;

    /** @var int Not accessed since  */
    public const DATE_NOTSINCE = 3;

    /** @var int Relative date unit for a day */
    public const DATE_UNIT_DAY = 1;

    /** @var int Relative date unit for a week */
    public const DATE_UNIT_WEEK = 2;

    /** @var int Relative date unit for a month */
    public const DATE_UNIT_MONTH = 3;

    /** @var int Relative date unit for a month */
    public const DATE_UNIT_YEAR = 4;

    /**
     * Return an array of operators available for this filter
     *
     * @return lang_string[]
     */
    private function get_operators(): array {
        $operators = [
            self::DATE_ANY => new lang_string('filterisanyvalue', 'core_reportbuilder'),
            self::DATE_NOT_EMPTY => new lang_string('filterisnotempty', 'core_reportbuilder'),
            self::DATE_EMPTY => new lang_string('filterisempty', 'core_reportbuilder'),
            self::DATE_NOTSINCE => new lang_string('filternotsince', 'local_ace'),
        ];

        return $this->filter->restrict_limited_operators($operators);
    }

    /**
     * Setup form
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        // Operator selector.

        $mform->addElement('select', "{$this->name}_operator", '', $this->get_operators());
        $mform->setType("{$this->name}_operator", PARAM_INT);
        $mform->setDefault("{$this->name}_operator", self::DATE_ANY);

        // Date selectors for range operator.
        $mform->addElement('date_selector', "{$this->name}_to", get_string('date'),
            ['optional' => true]);
        $mform->setType("{$this->name}_to", PARAM_INT);
        $mform->setDefault("{$this->name}_to", 0);
        $mform->hideIf("{$this->name}_to", "{$this->name}_operator", 'neq', self::DATE_NOTSINCE);
    }

    /**
     * Return filter SQL
     *
     * @param array $values
     * @return array
     */
    public function get_sql_filter(array $values): array {
        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        $operator = (int) ($values["{$this->name}_operator"] ?? self::DATE_ANY);

        switch ($operator) {
            case self::DATE_NOT_EMPTY:
                $sql = "{$fieldsql} IS NOT NULL AND {$fieldsql} <> 0";
                break;
            case self::DATE_EMPTY:
                $sql = "{$fieldsql} IS NULL OR {$fieldsql} = 0";
                break;
            case self::DATE_NOTSINCE:
                $clauses = [];

                $datefrom = (int)($values["{$this->name}_from"] ?? 0);
                if ($datefrom > 0) {
                    $paramdatefrom = database::generate_param_name();
                    $clauses[] = "{$fieldsql} >= :{$paramdatefrom}";
                    $params[$paramdatefrom] = $datefrom;
                }

                $dateto = (int)($values["{$this->name}_to"] ?? 0);
                if ($dateto > 0) {
                    $paramdateto = database::generate_param_name();
                    $clauses[] = "({$fieldsql} < :{$paramdateto} OR {$fieldsql} IS NULL OR {$fieldsql} = 0)";
                    $params[$paramdateto] = $dateto;
                }

                $sql = implode(' AND ', $clauses);

                break;
            default:
                // Invalid or inactive filter.
                return ['', []];
        }

        return [$sql, $params];
    }
}
