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
 * Plugin test page
 *
 * @package     local_ace
 * @category    string
 * @copyright   2021 University of Canterbury
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
declare(strict_types=1);
global $DB;

require_once('../../config.php');

require_login();
$context = context_system::instance();
   
$PAGE->set_url(new moodle_url('/local/ace/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_ace'));
$PAGE->set_heading(get_string('pluginname', 'local_ace'));
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_ace'));

echo "Start Testing Here ....";        

$loglifetime = (int)get_config('local_ace', 'allloglifetime');
echo "<br>loglifetime: ".$loglifetime;    

$loglifetime = time() - ($loglifetime * 3600 * 24) ; //Unix value in days.
$lifetimep = array($loglifetime);
$start = time();
echo "<br>lifetimep: "; print_r($lifetimep);   
//while ($min = $DB->get_field_select("logstore_standard_log", "MIN(timecreated)", "timecreated < ?", $lifetimep)) {
 
  $min = $DB->get_field_select("logstore_standard_log", "MIN(timecreated)", "timecreated < ?", $lifetimep);
   echo "<br>min: ". $min;
  $params = array(min($min + 3600 * 24, $loglifetime));
    echo "<br>params: ";print_r($params);
    $records = $DB->get_records_select("logstore_standard_log", "timecreated < ? AND (origin='cli' or origin='restore') ", $params);
    print "<pre>";print_r($records);print "</pre>";
//}


echo $OUTPUT->footer();