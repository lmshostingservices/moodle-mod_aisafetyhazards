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
 * Asks LMS Labs again about a stored request, with the same Idempotency-Key and body (never charged twice).
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_request extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'requestid' => new external_value(PARAM_INT, 'Request id in this site'),
            ]
        );
    }

    /**
     * Checks.
     *
     * @param int $cmid
     * @param int $requestid
     * @return array
     */
    public static function execute(int $cmid, int $requestid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'requestid' => $requestid]);
        [, , $instance, $modcontext] = self::load_ai($params['cmid'], false);
        // LMS Labs can take minutes: the session is released first, so the teacher's other pages are not held up.
        \core\session\manager::write_close();
        $row = requests::check(requests::get((int)$instance->id, $params['requestid']), $instance);
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
