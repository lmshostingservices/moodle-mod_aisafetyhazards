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

namespace mod_aisafetyhazards\external;

use core_external\external_function_parameters;
use core_external\external_value;
use mod_aisafetyhazards\local\ai\requests;

/**
 * Drafts one scene (a step of the work task with three hazards) with LMS Labs.
 *
 * 5 credits per draft LMS Labs settles; the request is stored first and never retried.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_scene extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'brief' => new external_value(PARAM_TEXT, 'What the scene should be about (at most 2,000 characters)'),
            ]
        );
    }

    /**
     * Drafts.
     *
     * @param int $cmid
     * @param string $brief
     * @return array
     */
    public static function execute(int $cmid, string $brief): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'brief' => $brief]);
        [, , $instance, $modcontext] = self::load_ai($params['cmid']);
        // LMS Labs can take minutes: the session is released first, so the teacher's other pages are not held up.
        \core\session\manager::write_close();
        $row = requests::start_scene($instance, (int)$USER->id, $params['brief']);
        return requests::export($row, $modcontext);
    }

    /**
     * Return structure.
     *
     * @return \core_external\external_single_structure
     */
    public static function execute_returns(): \core_external\external_single_structure {
        return self::request_structure();
    }
}
