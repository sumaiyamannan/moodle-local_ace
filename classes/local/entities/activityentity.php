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
use core_reportbuilder\local\entities\user;
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
class activityentity extends base {

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
                'logstore_standard_log' => 'ls',
                'context' => 'ctx',
               ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('pluginname', 'local_ace');
    }

    /**
     * Initialise the entity, add all user fields and all 'visible' user profile fields
     *
     * @return base
     */
    public function initialise(): base {

        $userentity = new user();
        $usertablealias = $userentity->get_table_alias('user');

        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        $filters = $this->get_all_filters();
        foreach ($filters as $filter) {
            $this->add_filter($filter);
        }

        // TODO: differentiate between filters and conditions (specifically the 'date' type: MDL-72662).
        $conditions = $this->get_all_filters();
        foreach ($conditions as $condition) {
            $this->add_condition($condition);
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

        $columns = [];
        $usertablealias = $this->get_table_alias('user');
        $userenrolmentsalias = $this->get_table_alias('user_enrolments');
        $coursealias = $this->get_table_alias('course');
        $coursemodulesalias = $this->get_table_alias('course_modules');
        $modulesalias = $this->get_table_alias('modules');
        $enrolalias = $this->get_table_alias('enrol');
        $assignalias = $this->get_table_alias('assign');
        $assignsubmissionalias = $this->get_table_alias('assign_submission');
        $logstorealias = $this->get_table_alias('logstore_standard_log');
        $contexttablealias = $this->get_table_alias('context');

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

        $joinlog = "
                    LEFT JOIN {context} {$contexttablealias} 
                    ON {$contexttablealias}.contextlevel = " . CONTEXT_MODULE . " 
                    AND {$contexttablealias}.instanceid = {$coursemodulesalias}.instance
                    LEFT JOIN (
                        SELECT contextid, max(timecreated) as timecreated
                        FROM {logstore_standard_log}
                        GROUP BY contextid
                    ) AS {$logstorealias} ON {$logstorealias}.contextid = {$contexttablealias}.id
                ";

        $join = $join . $joinlog;

        // Module name column.
        $columns[] = (new column(
            'name',
            new lang_string('name'),
            $this->get_entity_name()
        ))
            ->add_join($join)
            ->set_is_sortable(true)
            ->add_field("{$modulesalias}.name")
            ->add_callback(static function ($v): string {
                return html_writer::img('/mod/'.$v.'/pix/icon.png', new lang_string('logo', 'local_ace')).' '.$v;
            });

        // Last accessed.
        $columns[] = (new column(
            'lastaccessed',
            new lang_string('lastaccessed', 'local_ace'),
            $this->get_entity_name()
        ))
            ->add_join($join)
            ->set_is_sortable(true)
            ->add_fields("{$logstorealias}.timecreated, {$coursemodulesalias}.id")
            ->add_callback(static function ($value, $row): string {
                return userdate($value);
            });

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
                $now = time();
                $your_date = $row->duedate;
                $datediff = $your_date - $now;
                $duein = round($datediff / (60 * 60 * 24));
                if ($duein <= 7) {
                    return html_writer::start_span('fa fa-calendar-o') . $duein . html_writer::end_span() . ' ' .userdate($row->duedate);
                } elseif ($duein <= 0) {
                    return html_writer::start_span('', array('style' => "color: red;")) . userdate($row->duedate) . html_writer::end_span();
                }
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
        ->add_fields("{$assignsubmissionalias}.status, {$assignalias}.duedate")
        ->add_callback(static function ($value, $row): string {
            if ($row->status == 'submitted') {
                return ucfirst($value);
            } else {
                    $now = time();
                    $your_date = $row->duedate;
                    $datediff = $your_date - $now;
                    $duein = round($datediff / (60 * 60 * 24));
                if ($duein <= 0) {
                    return html_writer::start_span('submitted', array('style' => "color: red;")) . 'Not Submitted' . html_writer::end_span();
                }
                return html_writer::start_span('submitted') . 'Not Submitted' . html_writer::end_span();
            }
        });

        return $columns;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {

        $filters = [];

        $usertablealias = $this->get_table_alias('user');
        $userenrolmentsalias = $this->get_table_alias('user_enrolments');
        $coursealias = $this->get_table_alias('course');
        $coursemodulesalias = $this->get_table_alias('course_modules');
        $modulesalias = $this->get_table_alias('modules');
        $enrolalias = $this->get_table_alias('enrol');
        $assignalias = $this->get_table_alias('assign');
        $assignsubmissionalias = $this->get_table_alias('assign_submission');
        $logstorealias = $this->get_table_alias('logstore_standard_log');
        $contexttablealias = $this->get_table_alias('context');

        $join = "
                    INNER JOIN {user_enrolments} {$userenrolmentsalias}
                    ON {$userenrolmentsalias}.userid = {$usertablealias}.id
                    INNER JOIN {enrol} {$enrolalias}
                    ON {$enrolalias}.id = {$userenrolmentsalias}.enrolid
                    INNER JOIN {course} {$coursealias}
                    ON {$enrolalias}.courseid = {$coursealias}.id
                    INNER JOIN {course_modules} {$coursemodulesalias}
                    ON {$coursemodulesalias}.course = {$coursealias}.id
                    LEFT JOIN {context} {$contexttablealias} 
                    ON {$contexttablealias}.contextlevel = " . CONTEXT_MODULE . " 
                    AND {$contexttablealias}.instanceid = {$coursemodulesalias}.instance
                    INNER JOIN {logstore_standard_log} {$logstorealias} 
                    ON {$logstorealias}.contextid = {$contexttablealias}.id
                    LEFT JOIN {assign} {$assignalias}
                    ON {$coursemodulesalias}.instance = {$assignalias}.id
                    INNER JOIN {assign_submission} {$assignsubmissionalias}
                    ON {$assignalias}.id = {$assignsubmissionalias}.assignment 
                    INNER JOIN {modules} {$modulesalias}
                    ON {$coursemodulesalias}.module = {$modulesalias}.id
                ";

        // Module name filter.
        $filters[] = (new filter(
            text::class,
            'nameselector',
            new lang_string('name'),
            $this->get_entity_name(),
            "{$modulesalias}.name"
        ))
            ->add_join($join);

        // Last accessed filter.
        $filters[] = (new filter(
            date::class,
            'lastaccessedselector',
            new lang_string('lastaccessed', 'local_ace'),
            $this->get_entity_name(),
            "{$logstorealias}.timecreated"
        ))
            ->add_join($join);

        // Due date filter.
        $filters[] = (new filter(
            date::class,
            'dueselector',
            new lang_string('due', 'local_ace'),
            $this->get_entity_name(),
            "{$assignalias}.duedate"
        ))
            ->add_join($join);


        // Date submitted filter.
        $filters[] = (new filter(
            date::class,
            'submittedselector',
            new lang_string('submitted', 'local_ace'),
            $this->get_entity_name(),
            "{$assignsubmissionalias}.status"
        ))
            ->add_join($join);

        return $filters;
    }
}