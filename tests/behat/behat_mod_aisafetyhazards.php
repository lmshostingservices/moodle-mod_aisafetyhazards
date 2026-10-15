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
 * Behat steps for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps for mod_aisafetyhazards.
 */
class behat_mod_aisafetyhazards extends behat_base {
    /**
     * Resolves the plugin's own pages for "I am on the ... page" steps.
     *
     * Recognised page types, each identified by the activity idnumber or name:
     * - "Builder": the lesson builder wizard.
     * - "Build": the build step (AI prompt and import).
     * - "Scenes": the scene list.
     * - "Check": the check step of the set-up.
     * - "Report": the learners report.
     *
     * @param string $type page type
     * @param string $identifier activity idnumber or name
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        $cm = $this->get_cm_by_activity_name('aisafetyhazards', $identifier);
        switch (strtolower($type)) {
            case 'builder':
                return new moodle_url('/mod/aisafetyhazards/builder.php', ['id' => $cm->id]);
            case 'build':
                return new moodle_url('/mod/aisafetyhazards/builder.php', ['id' => $cm->id, 'step' => 'build']);
            case 'check':
                return new moodle_url('/mod/aisafetyhazards/scenes.php', ['id' => $cm->id, 'step' => 'check']);
            case 'scenes':
                return new moodle_url('/mod/aisafetyhazards/scenes.php', ['id' => $cm->id]);
            case 'report':
                return new moodle_url('/mod/aisafetyhazards/report.php', ['id' => $cm->id]);
            default:
                throw new Exception('Unrecognised mod_aisafetyhazards page type "' . $type . '"');
        }
    }
}
