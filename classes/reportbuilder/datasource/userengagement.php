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
use local_ace\local\entities\acecourse;
use local_ace\local\entities\aceuser;
use local_ace\local\entities\engagementlevels;
use local_ace\local\entities\userenrolment;
use local_ace\local\entities\userentity;

/**
 * User engagement data source.
 *
 * @package    local_ace
 * @copyright  2021 University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userengagement extends datasource {

    /**
     * Return user friendly name of the datasource
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('userengagementdatasource', 'local_ace');
    }

    /**
     * Initialise report
     */
    protected function initialise(): void {
        $enrolmententity = new userenrolment();
        $uetablealias = $enrolmententity->get_table_alias('user_enrolments');

        $this->set_main_table('user_enrolments', $uetablealias);
        $this->add_entity($enrolmententity);

        // Add core user join.
        $usercore = new aceuser();
        $usercorealias = $usercore->get_table_alias('user');
        $usercorejoin = "JOIN {user} {$usercorealias} ON {$usercorealias}.id = {$uetablealias}.userid";
        $this->add_entity($usercore->add_join($usercorejoin));

        // Add course entity.
        $courseentity = new acecourse();
        $coursetablealias = $courseentity->get_table_alias('course');
        $coursejoin = "JOIN {enrol} exex1 ON exex1.id = $uetablealias.enrolid
                       JOIN {course} {$coursetablealias} ON {$coursetablealias}.id = exex1.courseid";
        $this->add_entity($courseentity->add_join($coursejoin));

        // Add the engagement level columns.
        $engagementlevels = new engagementlevels();
        $this->add_entity($engagementlevels->add_join($coursejoin));

        // Add the user entity for the last X days columns.
        $userentity = new userentity();
        $this->add_entity($userentity->add_join($coursejoin));

        $this->add_all_from_entities();

        $this->add_action_button([
            'id' => 'emailallselected',
            'value' => get_string('bulkemailallselected', 'local_ace'),
            'module' => 'local_ace/bulk_email'
        ]);

        $this->add_action_button([
            'id' => 'emailall',
            'value' => get_string('bulkemailall', 'local_ace'),
            'module' => 'local_ace/bulk_email_all'
        ]);
    }

    /**
     * Get list of default columns in data source.
     *
     * @return array
     */
    public function get_default_columns(): array {
        return [];
    }

    /**
     * Get list of default filters in data source.
     *
     * @return array
     */
    public function get_default_filters(): array {
        return [];
    }

    /**
     * Get list of default conditions in data source.
     *
     * @return array
     */
    public function get_default_conditions(): array {
        return ['acecourse:courseselect'];
    }
}
