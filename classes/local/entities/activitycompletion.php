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

namespace local_ace\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\helpers\format;
use lang_string;

/**
 * Columns/filters for the user activity report.
 *
 * @package     local_ace
 * @copyright   2022 University of Canterbury
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activitycompletion extends base {

    /**
     * Database tables that this entity uses and their default aliases
     *
     * @return array
     */
    protected function get_default_table_aliases(): array {
        return [
            'course_modules_completion' => 'cmc',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('activitycompletion', 'local_ace');
    }

    /**
     * Initialise the entity, add all user fields and all 'visible' user profile fields
     *
     * @return base
     */
    public function initialise(): base {
        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        $filters = $this->get_all_filters();
        foreach ($filters as $filter) {
            $this
                ->add_filter($filter)
                ->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns list of all available columns
     *
     * These are all the columns available to use in any report that uses this entity.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/ace/locallib.php');

        // Completion state column.
        $columns[] = (new column(
            'completionstate',
            new lang_string('completionstate', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_fields("cmc.completionstate")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                global $OUTPUT;
                if ($value === 0 || $value === null) {
                    return 'Not completed';
                }
                return $OUTPUT->pix_icon('t/check', 'completed');
            });

        // Date activity was completed on column.
        $columns[] = (new column(
            'completedon',
            new lang_string('completedon', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_is_sortable(true)
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("cmc.timemodified")
            ->set_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {

        $filters[] = (new filter(
            date::class,
            'datecompleted',
            new lang_string('completedon', 'local_ace'),
            $this->get_entity_name(),
            "cmc.timemodified"
        ))->add_joins($this->get_joins());

        return $filters;
    }
}
