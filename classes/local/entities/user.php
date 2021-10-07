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

use context_course;
use context_system;
use context_helper;
use core_course_category;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\course_selector;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\custom_fields;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\local\entities\base;
use core_user\fields;
use core_reportbuilder\local\helpers\user_profile_fields;
use html_writer;
use lang_string;
use stdClass;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Course entity class implementation
 *
 * This entity defines all the course columns and filters to be used in any report.
 *
 * @package     local_ace
 * @copyright   2021 Ant
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user extends base {

    /**
     * Database tables that this entity uses and their default aliases
     *
     * @return array
     */
    protected function get_default_table_aliases(): array {
        return [
                'user' => 'u',
                'enrol' => 'e',
                'user_enrolments' => 'ue',
                'course' => 'c',
                'course_modules' => 'cm',
                'modules' => 'm',
                'assign' => 'a',
                'assign_submission' => 'asub',
               ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entityuser', 'core_reportbuilder');
    }

    /**
     * Initialise the entity, add all user fields and all 'visible' user profile fields
     *
     * @return base
     */
    public function initialise(): base {

        $userentity = new user();
        $usertablealias = $userentity->get_table_alias('user');

        $userprofilefields = $this->get_user_profile_fields();

        $columns = array_merge($this->get_all_columns(), $userprofilefields->get_columns());
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        $filters = array_merge($this->get_all_filters(), $userprofilefields->get_filters());
        foreach ($filters as $filter) {
            $this->add_filter($filter);
        }

        // TODO: differentiate between filters and conditions (specifically the 'date' type: MDL-72662).
        $conditions = array_merge($this->get_all_filters(), $userprofilefields->get_filters());
        foreach ($conditions as $condition) {
            $this->add_condition($condition);
        }

        return $this;
    }

    /**
     * Get user profile fields helper instance
     *
     * @return user_profile_fields
     */
    protected function get_user_profile_fields(): user_profile_fields {
        $userprofilefields = new user_profile_fields($this->get_table_alias('user') . '.id', $this->get_entity_name());
        $userprofilefields->add_joins($this->get_joins());
        return $userprofilefields;
    }

    /**
     * Returns list of all available columns
     *
     * These are all the columns available to use in any report that uses this entity.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {

        $columns = [];
        $usertablealias = $this->get_table_alias('user');
        $userenrolmentsalias = $this->get_table_alias('user_enrolments');
        $coursealias = $this->get_table_alias('course');
        $coursemodulesalias = $this->get_table_alias('course_modules');
        $modulesalias = $this->get_table_alias('modules');
        $enrolalias = $this->get_table_alias('enrol');
        $assignalias = $this->get_table_alias('assign');
        $assignsubmissionalias = $this->get_table_alias('assign_submission');

        $fullnameselect = self::get_name_fields_select($usertablealias);
        $userpictureselect = fields::for_userpic()->get_sql($usertablealias, false, '', '', false)->selects;
        $viewfullnames = has_capability('moodle/site:viewfullnames', context_system::instance());

        $join = "
                    INNER JOIN {user_enrolments} {$userenrolmentsalias}
                    ON {$userenrolmentsalias}.userid = {$usertablealias}.id
                    INNER JOIN {enrol} {$enrolalias}
                    ON {$enrolalias}.id = {$userenrolmentsalias}.enrolid
                    INNER JOIN {course} {$coursealias}
                    ON {$enrolalias}.courseid = {$coursealias}.id
                    INNER JOIN {course_modules} {$coursemodulesalias}
                    ON {$coursemodulesalias}.course = {$coursealias}.id
                    LEFT JOIN {assign} {$assignalias}
                    ON {$coursemodulesalias}.instance = {$assignalias}.id
                    INNER JOIN {assign_submission} {$assignsubmissionalias}
                    ON {$assignalias}.id = {$assignsubmissionalias}.assignment 
                    INNER JOIN {modules} {$modulesalias}
                    ON {$coursemodulesalias}.module = {$modulesalias}.id
                ";

        // Logo column.
        $columns[] = (new column(
            'logo',
            new lang_string('logo', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_join($join)
            ->set_is_sortable(true)
            ->add_field("{$modulesalias}.name")
            ->add_callback(static function ($value): string {
                return html_writer::img('/mod/'.$value.'/pix/icon.png', new lang_string('logo', 'local_ace'));
            });

        // Module name column.
        $columns[] = (new column(
            'name',
            new lang_string('name'),
            $this->get_entity_name()
        ))
            ->add_join($join)
            ->set_is_sortable(true)
            ->add_field("{$modulesalias}.name");

        // // Last accessed.
        // $columns[] = (new column(
        //     'name',
        //     new lang_string('name'),
        //     $this->get_entity_name()
        // ))
        //     ->add_join("
        //                 INNER JOIN {user_enrolments} {$userenrolmentsalias}
        //                 ON {$userenrolmentsalias}.userid = {$usertablealias}.id
        //                 INNER JOIN {enrol} {$enrolalias}
        //                 ON {$enrolalias}.id = {$userenrolmentsalias}.enrolid
        //                 INNER JOIN {course} {$coursealias}
        //                 ON {$enrolalias}.courseid = {$coursealias}.id
        //                 INNER JOIN {course_modules} {$coursemodulesalias}
        //                 ON {$coursemodulesalias}.course = {$coursealias}.id
        //                 INNER JOIN {modules} {$modulesalias}
        //                 ON {$coursemodulesalias}.module = {$modulesalias}.id
        //             ")
        //     ->set_is_sortable(true)
        //     ->add_field("{$modulesalias}.name");

        // Date due.
        $columns[] = (new column(
            'due',
            new lang_string('due', 'local_ace'),
            $this->get_entity_name()
        ))
        ->add_join($join)
        ->set_is_sortable(true)
        ->add_field("{$modulesalias}.name")
        ->add_fields("{$assignalias}.duedate")
        ->add_callback(static function ($value, $row): string {
            if ($row->name == 'assign') {
                return userdate($row->duedate);
            } else {
                return 'N/A';
            }
        });
        
        // Date submitted.
        $columns[] = (new column(
            'submitted',
            new lang_string('submitted', 'local_ace'),
            $this->get_entity_name()
        ))
        ->add_join($join)
        ->set_is_sortable(true)
        ->add_field("{$assignsubmissionalias}.status")
        ->add_callback(static function ($value): string {
            if ($value == 'submitted') {
                return $value;
            } else {
                return 'Not Submitted';
            }
        });

        return $columns;
    }

    /**
     * Check if this field is sortable
     *
     * @param string $fieldname
     * @return bool
     */
    protected function is_sortable(string $fieldname): bool {
        // Some columns can't be sorted, like longtext or images.
        $nonsortable = [
            'picture',
        ];

        return !in_array($fieldname, $nonsortable);
    }

    /**
     * Formats the user field for display.
     *
     * @param mixed $value Current field value.
     * @param stdClass $row Complete row.
     * @param string $fieldname Name of the field to format.
     * @return string
     */
    public function format($value, stdClass $row, string $fieldname): string {
        if ($this->get_user_field_type($fieldname) === column::TYPE_BOOLEAN) {
            return format::boolean_as_text($value);
        }

        if ($this->get_user_field_type($fieldname) === column::TYPE_TIMESTAMP) {
            return format::userdate($value, $row);
        }

        return s($value);
    }

    /**
     * Returns a SQL statement to select all user fields necessary for fullname() function
     *
     * @param string $usertablealias
     * @return string
     */
    public static function get_name_fields_select(string $usertablealias = 'u'): string {
        $userfields = array_map(static function(string $userfield) use ($usertablealias): string {
            if (!empty($usertablealias)) {
                $userfield = "{$usertablealias}.{$userfield}";
            }

            return $userfield;
        }, fields::get_name_fields(true));

        return implode(', ', $userfields);
    }

    /**
     * User fields
     *
     * @return lang_string[]
     */
    protected function get_user_fields(): array {
        return [
            'firstname' => new lang_string('firstname'),
            'lastname' => new lang_string('lastname'),
            'email' => new lang_string('email'),
            'city' => new lang_string('city'),
            'country' => new lang_string('country'),
            'firstnamephonetic' => new lang_string('firstnamephonetic'),
            'lastnamephonetic' => new lang_string('lastnamephonetic'),
            'middlename' => new lang_string('middlename'),
            'alternatename' => new lang_string('alternatename'),
            'idnumber' => new lang_string('idnumber'),
            'institution' => new lang_string('institution'),
            'department' => new lang_string('department'),
            'phone1' => new lang_string('phone1'),
            'phone2' => new lang_string('phone2'),
            'address' => new lang_string('address'),
            'lastaccess' => new lang_string('lastaccess'),
            'suspended' => new lang_string('suspended'),
            'confirmed' => new lang_string('confirmed', 'admin'),
            'username' => new lang_string('username'),
            'moodlenetprofile' => new lang_string('moodlenetprofile', 'user'),
        ];
    }

    /**
     * Return appropriate column type for given user field
     *
     * @param string $userfield
     * @return int
     */
    protected function get_user_field_type(string $userfield): int {
        switch ($userfield) {
            case 'confirmed':
            case 'suspended':
                $fieldtype = column::TYPE_BOOLEAN;
                break;
            case 'lastaccess':
                $fieldtype = column::TYPE_TIMESTAMP;
                break;
            default:
                $fieldtype = column::TYPE_TEXT;
                break;
        }

        return $fieldtype;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $filters = [];
        $tablealias = $this->get_table_alias('user');

        // Fullname filter.
        $canviewfullnames = has_capability('moodle/site:viewfullnames', context_system::instance());
        [$fullnamesql, $fullnameparams] = fields::get_sql_fullname($tablealias, $canviewfullnames);
        $filters[] = (new filter(
            text::class,
            'fullname',
            new lang_string('fullname'),
            $this->get_entity_name(),
            $fullnamesql,
            $fullnameparams
        ))
            ->add_joins($this->get_joins());

        // User fields filters.
        $fields = $this->get_user_fields();
        foreach ($fields as $field => $name) {
            $optionscallback = [static::class, 'get_options_for_' . $field];
            if (is_callable($optionscallback)) {
                $classname = select::class;
            } else if ($this->get_user_field_type($field) === column::TYPE_BOOLEAN) {
                $classname = boolean_select::class;
            } else if ($this->get_user_field_type($field) === column::TYPE_TIMESTAMP) {
                $classname = date::class;
            } else {
                $classname = text::class;
            }

            $filter = (new filter(
                $classname,
                $field,
                $name,
                $this->get_entity_name(),
                $tablealias . '.' . $field
            ))
                ->add_joins($this->get_joins());

            // Populate filter options by callback, if available.
            if (is_callable($optionscallback)) {
                $filter->set_options_callback($optionscallback);
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * List of options for the field country.
     *
     * @return string[]
     */
    public static function get_options_for_country(): array {
        return array_map('shorten_text', get_string_manager()->get_list_of_countries());
    }
}