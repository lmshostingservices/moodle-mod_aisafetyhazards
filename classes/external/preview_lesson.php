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
namespace mod_aisafetyhazards\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aisafetyhazards\local\lesson;

/**
 * Reads pasted AI output and returns the scenes that can be created, exactly as the import would create them (free;
 * nothing is stored or sent).
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_lesson extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'draft' => new external_value(PARAM_TEXT, 'Lesson draft JSON, as pasted'),
        ]);
    }

    /**
     * Reads the draft.
     *
     * @param int $cmid
     * @param string $draft
     * @return array
     */
    public static function execute(int $cmid, string $draft): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'draft' => $draft]);
        self::load_cm($params['cmid'], 'manage');
        $scenes = lesson::parse($params['draft'])['scenes'];
        return ['data' => json_encode(['scenes' => $scenes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'The scenes that can be created, cleaned, as JSON'), // pipeline-ignore: PARAM_RAW — Output-only JSON serialization, not an input parameter.
        ]);
    }
}
