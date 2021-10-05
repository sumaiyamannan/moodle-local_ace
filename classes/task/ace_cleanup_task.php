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
 * Analytics for course engagement irrelevant logs cleanup 
 *
 * @package     local_ace
 * @category    admin
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ace\task;

use context_system;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to delete logs with origin cli and restore
 */

class ace_cleanup_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('acetaskcleanup', 'local_ace');
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        global $DB;
        $context = context_system::instance();
        $loglifetime = (int)get_config('local_ace', 'allloglifetime');

        if (empty($loglifetime) || $loglifetime < 0) {
            return;
        }

        $loglifetime = time() - ($loglifetime * 3600 * 24); // Value in days.
        $lifetimep = array($loglifetime);
        $start = time();

        while ($min = $DB->get_field_select("logstore_standard_log", "MIN(timecreated)", "timecreated < ?", $lifetimep)) {
            // Break this down into chunks to avoid transaction for too long and generally thrashing database.
            // Experiments suggest deleting one day takes up to a few seconds; probably a reasonable chunk size usually.
            // If the cleanup has just been enabled, it might take e.g a month to clean the years of logs.          

            $params = array(min($min + 3600 * 24, $loglifetime));
            $DB->delete_records_select("logstore_standard_log", "timecreated < ? AND (origin='cli' or origin='restore') ", $params);
            if (time() > $start + 300) {
                // Do not churn on log deletion for too long each run.
                break;
            }
        }

        mtrace(" Deleted old log records with origin restore and cli from standard store.");
    }
}
