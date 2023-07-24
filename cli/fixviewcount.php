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
 * This script updates view count in the ace stats table.
 *
 * @package    local_ace
 * @copyright  2023 Canterbury University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__.'/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

$from = 1672617438; // 1st Jan 2023
$limit = 10000;
$count = 0;
$sql = "SELECT * FROM {local_ace_samples}
         WHERE viewcount is null AND starttime > ?";
$records = $DB->get_recordset_sql($sql, [$from]);
foreach ($records as $record) {
    $sql = "SELECT count(l.id) as vcount
              FROM {logstore_standard_log} l
              JOIN {context} cx on cx.instanceid = l.courseid AND cx.contextlevel = ". CONTEXT_COURSE. "
             WHERE (origin = 'web' OR origin = 'ws') AND timecreated > :starttime AND timecreated < :endtime
                   AND userid = :userid AND cx.id = :contextid";
    $viewcount = $DB->get_field_sql($sql, ['starttime' => $record->starttime, 'endtime' => $record->endtime,
                                           'userid' => $record->userid, 'contextid' => $record->contextid]);
    $record->viewcount = empty($viewcount) ? 0 : $viewcount;
    $DB->update_record('local_ace_samples', $record);
    $count++;
    if ($count >= $limit) {
        mtrace("Reached limit of $limit records, run again to continue processing.");
        break;
    }
}
$records->close();
mtrace("updated $count records");

