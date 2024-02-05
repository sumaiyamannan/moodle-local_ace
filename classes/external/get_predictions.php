<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ace\external;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External function to allow simple export of predictions via a web service.
 *
 * @package     local_ace
 * @copyright   2024 Catalyst IT
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_predictions extends external_api {
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters (
            ['modelid' => new external_value(PARAM_INT, 'Model to download', VALUE_REQUIRED),
             'datesince' => new external_value(PARAM_INT, 'unix timestamp, usually last time the service was called',  VALUE_REQUIRED),
             'dateto'    => new external_value(PARAM_INT, 'unix timestamp, defaults to the current time now.',  VALUE_DEFAULT, 0),
             'includeusers' => new external_value(PARAM_INT,
                  '0 = only predictions, 1 = include users with no course access',  VALUE_DEFAULT, 0),
             'role' => new external_value(PARAM_ALPHANUMEXT, 'role to return when includeusers set to 1 - defaults to "student"', VALUE_DEFAULT, 'student'),
            ]);
    }


    /**
     * Execute web service.
     *
     * @param int $modelid
     * @param int $datesince
     * @param int $dateto
     * @param int $includeusers
     * @param string $role
     * @return void
     */
    public static function execute($modelid, $datesince, $dateto, $includeusers, $role) {
        global $DB;
        require_capability('local/ace:getpredictions', context_system::instance());

        $wsparams = self::validate_parameters(self::execute_parameters(), [
            'modelid' => $modelid,
            'datesince' => $datesince,
            'dateto' => $dateto,
            'includeusers' => $includeusers,
            'role' => $role
        ]);

        if (empty($dateto)) {
            $dateto = time();
        }

        $model = $DB->get_record('analytics_models', ['id' => $modelid]);
        if (empty($model) || empty($model->enabled)) {
            return [
                'result' => false,
                'message' => "Selected model not enabled",
            ];
        }

        $list = self::get_data($modelid, $datesince, $dateto, $includeusers, $role);

        return $list;
    }

    /**
     * Describe the return values of the external service.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        $headers = self::get_headers();
        $returns = [];
        foreach ($headers as $header) {
            $returns[$header] = new external_value(PARAM_TEXT, $header);
        }

        return new external_multiple_structure(new external_single_structure($returns));
    }

    /**
     * helper function to return headers.
     *
     * @return array
     */
    private static function get_headers() : array {
        return ['id', 'predictionscore', 'predictiongenerated', 'courseshortname', 'coursefullname',
                'useridnumber', 'useremail', 'userfirstname', 'userlastname', 'userlastaccess',
                'userlastcourseaccess', 'userloggedin'];
    }

    /**
     * Get Data for export.
     *
     * @param int $modelid
     * @param int $datesince
     * @param int $dateto
     * @param int $includeusers
     * @param string $role
     * @return array
     */
    private static function get_data($modelid, $datesince, $dateto, $includeusers, $role) : array {
        global $DB;
        $params = [];

        // Get list of headers, and extra columns.
        $headers = self::get_headers();

        // Collect data.
        $sql = "SELECT DISTINCT ON(c.shortname, u.idnumber, date_trunc('day', to_timestamp(ap.timecreated))) ap.id as id,
                       ap.predictionscore, ap.timecreated as predictiongenerated,
                       c.shortname as courseshortname, c.fullname as coursefullname, u.idnumber as useridnumber,
                       u.email as useremail, u.firstname as userfirstname, u.lastname as userlastname,
                       u.lastaccess as userlastaccess, ul.timeaccess as userlastcourseaccess,
                       u.currentlogin as userloggedin
                 FROM {analytics_predictions} ap
                 JOIN {context} cx on cx.id = ap.contextid
                 JOIN {course} c on (c.id = cx.instanceid AND cx.contextlevel = :contextcourse)
                 JOIN {user_enrolments} ue on ue.id = ap.sampleid
                 JOIN {user} u on u.id = ue.userid
                 LEFT JOIN {user_lastaccess} ul ON ul.userid = u.id AND ul.courseid = c.id
                 WHERE ap.modelid = :modelid AND ap.timecreated > :datesince AND ap.timecreated < :dateto
              ORDER BY c.shortname, u.idnumber ASC, date_trunc('day', to_timestamp(ap.timecreated))";

        $params = $params + ['modelid' => $modelid, 'contextcourse' => CONTEXT_COURSE,
                             'datesince' => $datesince, 'dateto' => $dateto];

        $predictions = $DB->get_recordset_sql($sql, $params);
        $list = [];
        foreach ($predictions as $prediction) {
            $list[] = self::get_row($prediction, $headers);
        }
        $predictions->close();

        // Add the extra list of data if it's asked for.
        if (!empty($includeusers)) {
            $rolejoin = '';
            $roleparams = [];
            if (!empty($role)) {
                // We only want users enrolled at the course context with this role.
                // Excludes users enrolled at course category level or higher.
                list($rolesql, $roleparams) = $DB->get_in_or_equal(explode(',', $role), SQL_PARAMS_NAMED);
                $rolejoin = "JOIN {context} cx ON cx.instanceid = c.id AND cx.contextlevel = " . CONTEXT_COURSE
                    . " JOIN {role_assignments} ra ON ra.contextid = cx.id AND u.id = ra.userid
                          JOIN {role} r ON r.id = ra.roleid AND r.shortname $rolesql";
            }

            $params = $roleparams + ['modelid' => $modelid, 'dateto' => $dateto, 'datesince2' => $datesince,
                                     'now' => time(), 'contextcourse' => CONTEXT_COURSE];
            $noaccesssql = '';
            if ($includeusers == 1) {
                // We only want to show users who have not accessed since datefrom.
                $params['datesince'] = $datesince;
                $noaccesssql = "AND (ul.timeaccess IS NULL OR ul.timeaccess < :datesince)";
            }

            $sql = "SELECT DISTINCT c.id || '-' || u.id as id, c.shortname as courseshortname, c.fullname as coursefullname, u.idnumber as useridnumber, u.email as useremail,
                       u.firstname as userfirstname, u.lastname as userlastname, u.lastaccess as userlastaccess,
                       ul.timeaccess as userlastcourseaccess,
                       u.currentlogin as userloggedin
                 FROM {user} u
                 JOIN {user_enrolments} ue on ue.userid = u.id
                 JOIN {enrol} e on e.id = ue.enrolid
                 JOIN {course} c on c.id = e.courseid
                 JOIN {context} cx2 on cx2.instanceid = c.id AND cx2.contextlevel = :contextcourse
                 LEFT JOIN {user_lastaccess} ul ON ul.userid = u.id AND ul.courseid = c.id
                 $rolejoin
                 LEFT JOIN {analytics_predictions} ap ON ap.modelid = :modelid AND ap.sampleid = ue.id AND
                 ap.timecreated > :datesince2 AND ap.timecreated < :dateto
                 WHERE ap.id is null AND c.enddate > :now $noaccesssql
                 ORDER BY ul.timeaccess";

            $users = $DB->get_recordset_sql($sql, $params);
            foreach ($users as $user) {
                $list[] = self::get_row($user, $headers);
            }
            $users->close();
        }
        return $list;
    }

    /**
     * Helper function to format row for export
     *
     * @param stdclass $user
     * @param array $headers
     * @return array
     */
    private static function get_row($user, $headers) : array {
        $userdates = ['predictiongenerated', 'userlastaccess', 'currentengagementtime', 'userlastcourseaccess', 'userloggedin'];
        foreach ($userdates as $var) {
            if (!empty($user->$var)) {
                $user->$var = userdate($user->$var, get_string('strftimeexportformat', 'local_ace'));
            }
        }

        $row = [];
        foreach ($headers as $h) {
            if (empty($user->$h)) {
                $row[$h] = '';
            } else {
                $row[$h] = $user->$h;
            }
        }
        return $row;
    }
}
