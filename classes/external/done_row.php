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
use mod_aisafetyhazards\local\learning;

/**
 * Test: the learner has finished choosing labels for one row of a hazard.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class done_row extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'sceneid' => new external_value(PARAM_INT, 'Scene id'),
            'hazardid' => new external_value(PARAM_INT, 'Hazard id'),
            'kind' => new external_value(PARAM_ALPHA, 'reduce or ppe'),
        ]);
    }

    /**
     * Ends the row.
     *
     * @param int $attemptid
     * @param int $sceneid
     * @param int $hazardid
     * @param string $kind
     * @return array
     */
    public static function execute(int $attemptid, int $sceneid, int $hazardid, string $kind): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid, 'sceneid' => $sceneid,
            'hazardid' => $hazardid, 'kind' => $kind]);
        [, , $instance, $context, $attempt] = self::load_attempt($params['attemptid']);
        $result = learning::done_row($instance, $context, $attempt, $params['sceneid'], $params['hazardid'], $params['kind']);
        if ($result['now'] === null) {
            unset($result['now']);
        }
        return $result;
    }

    /**
     * Structure of the result of one label.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $flags = [];
        foreach (\mod_aisafetyhazards\local\catalogue::KINDS as $kind) {
            $flags[$kind] = new external_value(PARAM_BOOL, 'The ' . $kind . ' row is finished');
        }
        return new external_single_structure([
            'marked' => new external_value(PARAM_BOOL, 'Marked now (Practice); Test marks at the end'),
            'correct' => new external_value(PARAM_BOOL, 'The label was right (Practice)'),
            'reason' => new external_value(PARAM_TEXT, 'Why (Practice)'),
            'level' => new external_value(PARAM_ALPHA, 'Hierarchy level of the control (Practice)'),
            'eliminates' => new external_value(PARAM_BOOL, 'It removes the hazard (Practice)'),
            'dl' => new external_value(PARAM_INT, 'Steps it lowers the likelihood (Practice)'),
            'dc' => new external_value(PARAM_INT, 'Steps it lowers the consequence (Practice)'),
            'found' => new external_value(PARAM_BOOL, 'The hazard has been found'),
            'name' => new external_value(PARAM_TEXT, 'The hazard, once found'),
            'why' => new external_value(PARAM_TEXT, 'Why it is a hazard (Practice)'),
            'done' => new external_single_structure($flags),
            'complete' => new external_value(PARAM_BOOL, 'The hazard is finished'),
            'scenecomplete' => new external_value(PARAM_BOOL, 'The scene is finished'),
            'allcomplete' => new external_value(PARAM_BOOL, 'Every scene is finished'),
            'now' => new external_single_structure([
                'likelihood' => new external_value(PARAM_INT, 'Likelihood now, 1 to 5'),
                'consequence' => new external_value(PARAM_INT, 'Consequence now, 1 to 5'),
                'eliminated' => new external_value(PARAM_BOOL, 'The hazard is removed'),
                'rating' => new external_value(PARAM_ALPHA, 'low, medium, high, extreme or eliminated'),
            ], 'The hazard\'s risk now (Practice)', VALUE_OPTIONAL),
            'voice' => new external_multiple_structure(
                new external_value(PARAM_URL, 'Voiceover clip'),
                'What is read now (Practice), empty when there is nothing'
            ),
        ]);
    }
}
