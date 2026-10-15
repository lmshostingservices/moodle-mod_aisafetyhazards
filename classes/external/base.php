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

use core_external\external_api;
use mod_aisafetyhazards\local\learning;

/**
 * Shared loading and permission checks for mod_aisafetyhazards external functions.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * Loads a course module, validates its context and checks a capability.
     *
     * @param int $cmid
     * @param string $capability capability name without the mod/aisafetyhazards: prefix
     * @param bool $needsactive false only for looking at or hiding requests already made (recovery stays possible)
     * @return array [course, cm, instance, context]
     */
    protected static function load_cm(int $cmid, string $capability, bool $needsactive = true): array {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aisafetyhazards');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aisafetyhazards:' . $capability, $context);
        if ($needsactive) {
            // The whole plugin needs this site to be unlocked with LMS Labs.
            \mod_aisafetyhazards\local\unlock::require_active();
        }
        $instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$course, $cm, $instance, $context];
    }

    /**
     * Loads the current user's attempt and checks they may attempt the activity.
     *
     * @param int $attemptid
     * @return array [course, cm, instance, context, attempt]
     */
    protected static function load_attempt(int $attemptid): array {
        global $DB, $USER;
        $record = $DB->get_record('aisafetyhazards_attempt', ['id' => $attemptid]);
        if (!$record) {
            throw new \moodle_exception('notyourattempt', 'mod_aisafetyhazards');
        }
        $cm = get_coursemodule_from_instance('aisafetyhazards', $record->aisafetyhazardsid, 0, false, MUST_EXIST);
        [$course, $cm, $instance, $context] = self::load_cm((int)$cm->id, 'attempt');
        $attempt = learning::get_user_attempt($instance, $attemptid, (int)$USER->id);
        return [$course, $cm, $instance, $context, $attempt];
    }

    /**
     * Loads a scene for a teacher.
     *
     * @param int $sceneid
     * @param string $capability extra capability to require besides manage
     * @return array [course, cm, instance, context, scene]
     */
    protected static function load_scene(int $sceneid, string $capability = 'manage'): array {
        global $DB;
        $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $sceneid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aisafetyhazards', $scene->aisafetyhazardsid, 0, false, MUST_EXIST);
        [$course, $cm, $instance, $context] = self::load_cm((int)$cm->id, 'manage');
        if ($capability !== 'manage') {
            require_capability('mod/aisafetyhazards:' . $capability, $context);
        }
        return [$course, $cm, $instance, $context, $scene];
    }

    /**
     * Loads an activity for a teacher who may use LMS Labs AI (manage and useai).
     *
     * @param int $cmid
     * @param bool $needsactive false only for looking at or hiding requests already made
     * @return array [course, cm, instance, context]
     */
    protected static function load_ai(int $cmid, bool $needsactive = true): array {
        $loaded = self::load_cm($cmid, 'manage', $needsactive);
        require_capability('mod/aisafetyhazards:useai', $loaded[3]);
        return $loaded;
    }

    /**
     * Structure of one stored LMS Labs request, as shown on the page.
     *
     * @return \core_external\external_single_structure
     */
    protected static function request_structure(): \core_external\external_single_structure {
        return new \core_external\external_single_structure(
            [
            'id' => new \core_external\external_value(PARAM_INT, 'Request id in this site'),
            'operation' => new \core_external\external_value(PARAM_ALPHA, 'scene or image'),
            'status' => new \core_external\external_value(
                PARAM_ALPHA,
                'pending, uncertain, completed, failed, conflict, '
                . 'expired, lost or dismissed'
            ),
            'title' => new \core_external\external_value(PARAM_TEXT, 'Brief or scene title'),
            'message' => new \core_external\external_value(PARAM_TEXT, 'What happened and what to do next'),
            'requestid' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'LMS Labs reference'),
            'targetid' => new \core_external\external_value(PARAM_INT, 'The scene the request is for (0 for drafts and imports)'),
            'canrecheck' => new \core_external\external_value(PARAM_BOOL, 'Check again is possible (same key and body)'),
            'candismiss' => new \core_external\external_value(PARAM_BOOL, 'Can be dismissed'),
            'poll' => new \core_external\external_value(PARAM_BOOL, 'Still in progress at LMS Labs'),
            'retryafter' => new \core_external\external_value(PARAM_INT, 'Seconds to wait before checking again'),
            'sceneid' => new \core_external\external_value(PARAM_INT, 'Scene id, 0 none'),
            'sceneurl' => new \core_external\external_value(PARAM_URL, 'Scene editor URL, empty when none'),
            'openscene' => new \core_external\external_value(PARAM_BOOL, 'Offer a link to the drafted scene'),
            'timecreated' => new \core_external\external_value(PARAM_TEXT, 'When it was asked for'),
            ]
        );
    }
}
