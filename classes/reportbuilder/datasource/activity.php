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
use local_ace\local\entities\activityentity;
use local_ace\local\entities\course;
use core_reportbuilder\local\helpers\database;

/**
 * Users datasource
 *
 * @package   local_ace
 * @copyright 2021 Ant
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
        global $CFG;

        $userentity = new activityentity();
        $usertablealias = $userentity->get_table_alias('user');

        $this->set_main_table('user', $usertablealias);

        $userparamguest = database::generate_param_name();
        // $this->add_base_condition_sql("{$usertablealias}.id != :{$userparamguest} AND {$usertablealias}.deleted = 0", [
        //     $userparamguest => $CFG->siteguest,
        // ]);

        $this->add_entity($userentity);

        $userentityname = $userentity->get_entity_name();
        $this->add_columns_from_entity($userentityname);
        $this->add_filters_from_entity($userentityname);
        $this->add_conditions_from_entity($userentityname);
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