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

namespace local_ace\navigation\views;

use core\navigation\views\secondary as core_secondary;

/**
 * Class secondary_navigation_view.
 *
 * Custom implementation for a plugin.
 *
 * @package     local_ace
 * @copyright   2021 University of Canterbury
 * @author      Sumaiya Javed <sumaiya.javed@catalyst.net.nz>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secondary extends core_secondary {

    /**
     * Defines the default structure for the secondary nav in a course context.
     *
     * This will add the coursedashboardurl in the desired location.
     *
     * @return array
     */
    protected function get_default_course_mapping(): array {
        $defaultmaping = parent::get_default_course_mapping();
        $context = $this->page->context;
        $course = $this->page->course;
        $showonnavigation = has_capability('local/ace:view',  $context);
        $coursedashboardurl = get_config('local_ace', 'coursedashboardurl');
        if ($course->id != 1 && $showonnavigation && !empty($coursedashboardurl)) {
            $settingsmapping = $defaultmaping['settings'][self::TYPE_CONTAINER];
            foreach ($settingsmapping as $key => $value) {
                if (isset($nextposition)) {
                    unset($defaultmaping['settings'][self::TYPE_CONTAINER][$key]);
                }
                if ($key == 'coursereports') {
                    $nextposition = $value + 1;
                    $defaultmaping['settings'][self::TYPE_CONTAINER] = array_merge($settingsmapping, [
                        'courseacedashboard' => $nextposition,
                    ]);
                }
            }
        }
        return $defaultmaping;
    }
}
