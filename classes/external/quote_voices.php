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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aisafetyhazards\local\ai\requests;
use mod_aisafetyhazards\local\manager;
use mod_aisafetyhazards\local\voiceover;

/**
 * The current price of voiceover clips (free: nothing is made, charged or reserved). With free remakes a clip made
 * again after an edit is often free; the teacher confirms the total before anything is made.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quote_voices extends base {
    /** @var int Most clips priced in one call. */
    public const MAX = 300;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'clips' => new external_multiple_structure(
                new external_single_structure(
                    [
                    'sceneid' => new external_value(PARAM_INT, 'Scene id'),
                    'index' => new external_value(PARAM_INT, 'Clip number in the scene'),
                    ]
                )
            ),
            ]
        );
    }

    /**
     * Prices the clips.
     *
     * @param array $clips
     * @return array
     */
    public static function execute(array $clips): array {
        $params = self::validate_parameters(self::execute_parameters(), ['clips' => $clips]);
        $out = [];
        $scenes = [];
        $configs = [];
        // Price checks are free but not waited for forever: after 60 seconds the rest count as full price.
        $until = time() + 60;
        foreach (array_slice($params['clips'], 0, self::MAX) as $clip) {
            $sceneid = (int)$clip['sceneid'];
            if (!isset($scenes[$sceneid])) {
                [, , $instance, , $scene] = self::load_scene($sceneid, 'useai');
                $configs[$instance->id] = $configs[$instance->id] ?? voiceover::config($instance);
                $scenes[$sceneid] = [$instance, $scene,
                    voiceover::segments($scene, $configs[$instance->id], null, voiceover::parts($instance))];
            }
            [$instance, $scene, $segments] = $scenes[$sceneid];
            $out[] = ['sceneid' => $sceneid, 'index' => (int)$clip['index'], 'credits' => time() < $until
                ? requests::quote_voice($instance, $scene, (int)$clip['index'], $segments)
                : \mod_aisafetyhazards\local\ai\lmslabs::VOICE_CREDITS];
        }
        return $out;
    }

    /**
     * Return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure(
                [
                'sceneid' => new external_value(PARAM_INT, 'Scene id'),
                'index' => new external_value(PARAM_INT, 'Clip number in the scene'),
                'credits' => new external_value(PARAM_INT, 'Current price: 0 (a free remake) or the price per clip'),
                ]
            )
        );
    }
}
