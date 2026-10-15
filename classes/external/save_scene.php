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
use mod_aisafetyhazards\local\manager;

/**
 * Saves a scene from the editor: its text, its hazards with their positions, and every control label.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_scene extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
            'sceneid' => new external_value(PARAM_INT, 'Scene id'),
            'data' => new external_value(PARAM_TEXT, 'JSON: title, context, imageprompt, teachingnote, hazards'),
            ]
        );
    }

    /**
     * Saves.
     *
     * @param int $sceneid
     * @param string $data
     * @return array
     */
    public static function execute(int $sceneid, string $data): array {
        $params = self::validate_parameters(self::execute_parameters(), ['sceneid' => $sceneid, 'data' => $data]);
        [, , , $context, $scene] = self::load_scene($params['sceneid']);
        $input = json_decode($params['data'], true);
        if (!is_array($input) || !is_array($input['hazards'] ?? null)) {
            throw new \moodle_exception('hazardsincomplete', 'mod_aisafetyhazards');
        }
        $fields = array_intersect_key($input, array_flip(['title', 'context', 'imageprompt', 'teachingnote']));
        manager::save_scene($scene, $fields, $input['hazards']);
        return ['saved' => true, 'ready' => manager::scene_ready($context, $scene)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
            'saved' => new external_value(PARAM_BOOL, 'Saved'),
            'ready' => new external_value(PARAM_BOOL, 'The scene is ready to play (a picture and complete hazards)'),
            ]
        );
    }
}
