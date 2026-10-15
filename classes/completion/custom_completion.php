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

declare(strict_types=1);

namespace mod_aisafetyhazards\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules: finish every scene (one finished attempt), and pass the test (a finished test attempt at or
 * above the pass mark).
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * State of a custom rule for the user.
     *
     * @param string $rule
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $params = ['aid' => $this->cm->instance, 'userid' => (int)$this->userid, 'state' => 'finished'];
        if ($rule === 'completionpasstest') {
            $passmark = (int)$DB->get_field('aisafetyhazards', 'passmark', ['id' => $this->cm->instance]);
            $done = $DB->record_exists_select(
                'aisafetyhazards_attempt',
                "aisafetyhazardsid = :aid AND userid = :userid AND state = :state AND playmode = :mode AND score >= :passmark",
                $params + ['mode' => 'test', 'passmark' => max(1, $passmark)]
            );
        } else {
            $done = $DB->record_exists('aisafetyhazards_attempt', ['aisafetyhazardsid' => $params['aid'],
                'userid' => $params['userid'], 'state' => $params['state']]);
        }
        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Custom rules.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionallscenes', 'completionpasstest'];
    }

    /**
     * Rule descriptions.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionallscenes' => get_string('completiondetail:allscenes', 'mod_aisafetyhazards'),
            'completionpasstest' => get_string('completiondetail:passtest', 'mod_aisafetyhazards'),
        ];
    }

    /**
     * Sort order of rules.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionallscenes', 'completionpasstest', 'completionusegrade', 'completionpassgrade'];
    }
}
