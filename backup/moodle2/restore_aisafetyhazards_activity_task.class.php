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
 * Restore task for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aisafetyhazards/backup/moodle2/restore_aisafetyhazards_stepslib.php');

/**
 * Restore task.
 */
class restore_aisafetyhazards_activity_task extends restore_activity_task {
    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the structure step.
     */
    protected function define_my_steps() {
        $this->add_step(
            new restore_aisafetyhazards_activity_structure_step(
                'aisafetyhazards_structure',
                'aisafetyhazards.xml'
            )
        );
    }

    /**
     * Contents to decode.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('aisafetyhazards', ['intro'], 'aisafetyhazards'),
        ];
    }

    /**
     * Decoding rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('AISAFETYHAZARDSVIEWBYID', '/mod/aisafetyhazards/view.php?id=$1', 'course_module'),
            new restore_decode_rule('AISAFETYHAZARDSINDEX', '/mod/aisafetyhazards/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Restore log rules.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Restore log rules for course.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
