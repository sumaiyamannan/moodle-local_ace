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

$calclifetime = get_config('analytics', 'calclifetime');
$from = time() - ($calclifetime * DAYSECS);
set_config('statsrunlast', $from, 'local_ace');

mtrace ("now manually trigger get stats call");
$task = new \local_ace\task\get_stats();
$task->insertemptyengagementrecords = false;
$task->onlyaceperiod = true;
$task->deleteexisting = true;
$task->execute();
mtrace("done.");
