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

use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_user\fields;
use lang_string;
use local_ace\local\filters\multi_select;
use moodle_url;

/**
 * User entity class implementation.
 *
 * This entity defines all the user columns and filters to be used in any report.
 *
 * @package    local_ace
 * @copyright  2021 Canterbury University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aceuser extends \core_reportbuilder\local\entities\user {

    /**
     * Database tables that this entity uses and their default aliases
     *
     * @return array
     */
    protected function get_default_table_aliases(): array {
        $aliases = parent::get_default_table_aliases();
        $aliases['course'] = 'c';
        $aliases['ucdw_studentattributes'] = 'studentattributes';
        $aliases['local_ace_log_summary'] = 'acelogsummary';
        $aliases['course_modules'] = 'cm';
        $aliases['course_modules_completion'] = 'cmc';
        return $aliases;
    }

    /**
     * Get all columns
     *
     * @return array
     */
    protected function get_all_columns(): array {
        $columns = parent::get_all_columns();

        $usertablealias = $this->get_table_alias('user');
        $coursetablealias = $this->get_table_alias('course');
        $viewfullnames = self::get_name_fields_select($usertablealias);
        // Fullname column.
        $columns[] = (new column(
            'fullnamedashboardlink',
            new lang_string('fullnamedasboardlink', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields($viewfullnames)
            ->add_field("{$usertablealias}.id")
            ->add_field("{$coursetablealias}.id", 'courseid')
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static function(?string $value, \stdClass $row) use ($viewfullnames): string {
                if ($value === null) {
                    return '';
                }

                // Ensure we populate all required name properties.
                $namefields = fields::get_name_fields();
                foreach ($namefields as $namefield) {
                    $row->{$namefield} = $row->{$namefield} ?? '';
                }
                $url = new moodle_url('/local/ace/goto.php', ['userid' => $row->id, 'course' => $row->courseid]);
                return \html_writer::link($url, fullname($row, $viewfullnames));
            });

        // Fullname column.
        $columns[] = (new column(
            'fullnamelogslink',
            new lang_string('fullnamelogslink', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields($viewfullnames)
            ->add_field("{$usertablealias}.id")
            ->add_field("cm.id", 'cmid')
            ->add_field("{$coursetablealias}.id", 'courseid')
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static function(?string $value, \stdClass $row) use ($viewfullnames): string {
                if ($value === null) {
                    return '';
                }

                // Ensure we populate all required name properties.
                $namefields = fields::get_name_fields();
                foreach ($namefields as $namefield) {
                    $row->{$namefield} = $row->{$namefield} ?? '';
                }
                $url = new moodle_url('/report/log/index.php', ['id' => $row->courseid, 'user' => $row->id,
                    'modid' => $row->cmid, 'logreader' => 'logstore_standard', 'chooselog' => 1]);
                return \html_writer::link($url, fullname($row, $viewfullnames));
            });

        $studentattralias = $this->get_table_alias('ucdw_studentattributes');
        $attributesjoin =
            "LEFT JOIN {ucdw_studentattributes} {$studentattralias}
                       ON cast({$studentattralias}.studentidentifier as varchar) = {$usertablealias}.idnumber";

        $columns[] = (new column(
            'gender',
            new lang_string('gender', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.gender")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'ethnicity',
            new lang_string('ethnicity', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.etnicitypriority")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'firstinfamily',
            new lang_string('firstinfamily', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.firstinfamily")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'programme',
            new lang_string('programme', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.programmecode1")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'fullfee',
            new lang_string('fullfee', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.fullfee")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'fullpart',
            new lang_string('fullpart', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.fullpart")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'schooldecile',
            new lang_string('schooldecile', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.schooldecile")
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'firstyearkaitoko',
            new lang_string('firstyearkaitoko', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->add_field("{$studentattralias}.firstyearkaitoko")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $filters = parent::get_all_filters();

        $studentattralias = $this->get_table_alias('ucdw_studentattributes');
        $usertablealias = $this->get_table_alias('user');
        $attributesjoin =
            "LEFT JOIN {ucdw_studentattributes} {$studentattralias}
                       ON cast({$studentattralias}.studentidentifier as varchar) = {$usertablealias}.idnumber";

        $filters[] = (new filter(
            select::class,
            'gender',
            new lang_string('gender', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.gender"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('gender');
            });

        $filters[] = (new filter(
            select::class,
            'ethnicity',
            new lang_string('ethnicity', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.etnicitypriority"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('etnicitypriority');
            });

        $filters[] = (new filter(
            select::class,
            'firstinfamily',
            new lang_string('firstinfamily', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.firstinfamily"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('firstinfamily');
            });

        $filters[] = (new filter(
            multi_select::class,
            'programme',
            new lang_string('programme', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.programmecode1"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('programmecode1');
            });

        $filters[] = (new filter(
            select::class,
            'fullfee',
            new lang_string('fullfee', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.fullfee"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('fullfee');
            });

        $filters[] = (new filter(
            select::class,
            'fullpart',
            new lang_string('fullpart', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.fullpart"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('fullpart');
            });

        $filters[] = (new filter(
            multi_select::class,
            'schooldecile',
            new lang_string('schooldecile', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.schooldecile"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('schooldecile');
            });

        $filters[] = (new filter(
            select::class,
            'firstyearkaitoko',
            new lang_string('firstyearkaitoko', 'local_ace'),
            $this->get_entity_name(),
            "{$studentattralias}.firstyearkaitoko"
        ))->add_joins($this->get_joins())
            ->add_join($attributesjoin)
            ->set_options_callback(static function(): array {
                return aceuser::get_studentattribute_options('firstyearkaitoko');
            });

        $coursetablealias = $this->get_table_alias('course');
        $acelogsummaryalias = $this->get_table_alias('local_ace_log_summary');
        $logsummaryjoin =
            "LEFT JOIN {local_ace_log_summary} {$acelogsummaryalias} ON {$acelogsummaryalias}.courseid = {$coursetablealias}.id
            AND {$acelogsummaryalias}.userid = {$usertablealias}.id";

        $filters[] = (new filter(
            multi_select::class,
            'activityviewed',
            new lang_string('activityviewed', 'local_ace'),
            $this->get_entity_name(),
            "{$acelogsummaryalias}.cmid"
        ))->add_joins($this->get_joins())
            ->add_join($logsummaryjoin)
            ->set_options_callback(static function(): array {
                global $DB;

                $course = local_ace_get_course_helper();
                if (!empty($course)) {
                    $courseid = $course->id;
                } else {
                    return [1 => 'Fake Module']; // Add fake module so the filter is listed as an option in the global filter block.
                }

                $coursemodules = [];
                $cmids = $DB->get_records_sql("
                    SELECT cm.id, m.name, cm.instance
                    FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module
                    WHERE cm.course = {$courseid}");
                foreach ($cmids as $record) {
                    $module = $DB->get_record_sql("SELECT dm.name
                        FROM {{$record->name}} dm
                        WHERE dm.id = {$record->instance}");
                    $coursemodules[$record->id] = $module->name;
                }

                return $coursemodules;
            });

        $coursemodulealias = $this->get_table_alias('course_modules');
        $modulecompletionalias = $this->get_table_alias('course_modules_completion');
        $modulecompletionjoin =
            "LEFT JOIN {course_modules} {$coursemodulealias} ON {$coursemodulealias}.course = {$coursetablealias}.id
             LEFT JOIN {course_modules_completion} {$modulecompletionalias} ON {$modulecompletionalias}.coursemoduleid = {$coursemodulealias}.id
             AND {$modulecompletionalias}.userid = {$usertablealias}.id AND {$modulecompletionalias}.completionstate IN (1,2,3)";

        $filters[] = (new filter(
            multi_select::class,
            'activitycompleted',
            new lang_string('activitycompleted', 'local_ace'),
            $this->get_entity_name(),
            "{$modulecompletionalias}.coursemoduleid"
        ))->add_joins($this->get_joins())
            ->add_join($modulecompletionjoin)
            ->set_options_callback(static function(): array {
                global $DB;

                $course = local_ace_get_course_helper();
                if (!empty($course)) {
                    $courseid = $course->id;
                } else {
                    return [1 => 'Fake Module']; // Add fake module so the filter is listed as an option in the global filter block.
                }

                $coursemodules = [];
                $cmids = $DB->get_records_sql("
                    SELECT cm.id, m.name, cm.instance
                    FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module
                    WHERE cm.course = {$courseid}");
                foreach ($cmids as $record) {
                    $module = $DB->get_record_sql("SELECT dm.name
                        FROM {{$record->name}} dm
                        WHERE dm.id = {$record->instance}");
                    $coursemodules[$record->id] = $module->name;
                }

                return $coursemodules;
            });

        return $filters;
    }

    /**
     * Returns an array of student attribute options based on the given column name.
     * Selects from users enrolled in the current course context if course is found.
     *
     * @param string $column
     * @return array
     */
    public static function get_studentattribute_options(string $column): array {
        global $DB;

        $course = local_ace_get_course_helper();
        if (!empty($course)) {
            $courseid = $course->id;
            $options = $DB->get_records_sql("
                    SELECT DISTINCT sa.$column
                      FROM {ucdw_studentattributes} sa
                      JOIN {user} u ON u.idnumber = CAST(sa.studentidentifier AS VARCHAR)
                      JOIN {user_enrolments} ue ON ue.userid = u.id
                      JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                    ORDER BY {$column}", ['courseid' => $courseid]);
        } else {
            $options = $DB->get_records_sql("
                    SELECT DISTINCT $column
                      FROM {ucdw_studentattributes}
                    ORDER BY {$column}");
        }
        return array_map(function($record) use($column) {
            return $record->$column;
        }, $options);
    }
}
