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

/**
 * Behat data generator for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_aisafetyhazards_generator extends behat_generator_base {
    /**
     * Entities that feature files can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'scenes' => [
                'singular' => 'scene',
                'datagenerator' => 'behat_scene',
                'required' => ['activity', 'title'],
                'switchids' => ['activity' => 'activityid'],
            ],
        ];
    }

    /**
     * Looks up an activity instance id from its idnumber.
     *
     * @param string $idnumber
     * @return int
     */
    protected function get_activity_id(string $idnumber): int {
        global $DB;
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        return (int)$cm->instance;
    }
}
