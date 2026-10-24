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
 * Upgrade steps for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_aisafetyhazards_upgrade($oldversion) {
    global $DB;
    if ($oldversion < 2026101720) {
        // The voiceover can now read each step's question. Activities that read anything at all read these too; the
        // clips are only made (and paid for) when the teacher makes them in Set up > Voiceover.
        $rs = $DB->get_recordset_select('aisafetyhazards', "voiceparts <> ''", null, '', 'id, voiceparts');
        foreach ($rs as $row) {
            $parts = array_filter(explode(',', (string)$row->voiceparts));
            if ($parts && !in_array('questions', $parts, true)) {
                $parts[] = 'questions';
                $DB->set_field('aisafetyhazards', 'voiceparts', implode(',', $parts), ['id' => $row->id]);
            }
        }
        $rs->close();
        upgrade_mod_savepoint(true, 2026101720, 'aisafetyhazards');
    }
    return true;
}
