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

/**
 * Tasks
 *
 * @package     local_ace
 * @copyright   2022 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ace\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Used to get stats for logs to use in reports.
 */
class modules_views extends \core\task\scheduled_task {
    /**
     * Returns the name of this task.
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('modulesviews', 'local_ace');
    }
    /**
     * Executes task.
     */
    public function execute() {
        global $DB;
        $DB->delete_records('local_ace_modules_views');

        $recentviewduration = (int) get_config('local_ace', 'coursemodulerecentviewduration');

        $pastweek = time() - (!empty($recentviewduration) || $recentviewduration === 0) ? $recentviewduration : WEEKSECS;

        $sql = "INSERT INTO {local_ace_modules_views} (courseid, cmid, viewcount)
                 (SELECT courseid,
                        contextinstanceid as cmid,
                        count(*) AS viewcounttotal
                        FROM {logstore_standard_log}
                       WHERE contextlevel = ".CONTEXT_MODULE ." AND timecreated > ". $pastweek ." AND crud = 'r'
                       GROUP BY courseid, contextinstanceid)";

        $DB->execute($sql);
    }
}
