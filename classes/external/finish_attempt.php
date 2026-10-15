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
use core_external\external_single_structure;
use core_external\external_value;
use mod_aisafetyhazards\local\learning;

/**
 * Finishes and grades an attempt, and returns its results.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_attempt extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            ]
        );
    }

    /**
     * Finishes the attempt.
     *
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $attemptid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        [$course, $cm, $instance, $context, $attempt] = self::load_attempt($params['attemptid']);
        $summary = learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        return ['data' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
            'data' => new external_value(PARAM_TEXT, 'The results, as JSON'),
            ]
        );
    }
}
