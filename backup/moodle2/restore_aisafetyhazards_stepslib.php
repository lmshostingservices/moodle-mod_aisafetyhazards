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
 * Restore structure for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step.
 */
class restore_aisafetyhazards_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the paths.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $base = '/activity/aisafetyhazards';
        $paths = [
            new restore_path_element('aisafetyhazards', $base),
            new restore_path_element('aisafetyhazards_scene', $base . '/scenes/scene'),
            new restore_path_element('aisafetyhazards_hazard', $base . '/scenes/scene/hazards/hazard'),
            new restore_path_element('aisafetyhazards_control', $base . '/scenes/scene/hazards/hazard/controls/control'),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('aisafetyhazards_attempt', $base . '/attempts/attempt');
            $paths[] = new restore_path_element('aisafetyhazards_choice', $base . '/attempts/attempt/choices/choice');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the instance.
     *
     * @param array $data
     */
    protected function process_aisafetyhazards($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->timemodified = time();
        $newid = $DB->insert_record('aisafetyhazards', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restores a scene.
     *
     * @param array $data
     */
    protected function process_aisafetyhazards_scene($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aisafetyhazardsid = $this->get_new_parentid('aisafetyhazards');
        $newid = $DB->insert_record('aisafetyhazards_scene', $data);
        $this->set_mapping('aisafetyhazards_scene', $oldid, $newid, true);
    }

    /**
     * Restores a hazard.
     *
     * @param array $data
     */
    protected function process_aisafetyhazards_hazard($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->sceneid = $this->get_new_parentid('aisafetyhazards_scene');
        $newid = $DB->insert_record('aisafetyhazards_hazard', $data);
        $this->set_mapping('aisafetyhazards_hazard', $oldid, $newid);
    }

    /**
     * Restores a control label.
     *
     * @param array $data
     */
    protected function process_aisafetyhazards_control($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->hazardid = $this->get_new_parentid('aisafetyhazards_hazard');
        $newid = $DB->insert_record('aisafetyhazards_control', $data);
        $this->set_mapping('aisafetyhazards_control', $oldid, $newid);
    }

    /**
     * Restores an attempt, remapping its scene order to the new scene, hazard and control ids.
     *
     * @param array $data
     */
    protected function process_aisafetyhazards_attempt($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aisafetyhazardsid = $this->get_new_parentid('aisafetyhazards');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $map = fn($table, $ids) => array_values(array_filter(array_map(
            fn($id) => (int)$this->get_mappingid($table, $id),
            (array)$ids
        )));
        $order = json_decode((string)$data->sceneorder, true);
        $neworder = [];
        foreach (is_array($order) ? $order : [] as $item) {
            $sceneid = (int)$this->get_mappingid('aisafetyhazards_scene', $item['scene'] ?? 0);
            if (!$sceneid) {
                continue;
            }
            $rows = [];
            foreach ((array)($item['rows'] ?? []) as $hid => $kinds) {
                $newhid = (int)$this->get_mappingid('aisafetyhazards_hazard', $hid);
                if ($newhid) {
                    foreach ((array)$kinds as $kind => $ids) {
                        $rows[$newhid][$kind] = $map('aisafetyhazards_control', $ids);
                    }
                }
            }
            $neworder[] = ['scene' => $sceneid, 'hazards' => $map('aisafetyhazards_hazard', $item['hazards'] ?? []),
                'chips' => $map('aisafetyhazards_hazard', $item['chips'] ?? []), 'rows' => $rows];
        }
        $data->sceneorder = json_encode($neworder);
        $newid = $DB->insert_record('aisafetyhazards_attempt', $data);
        $this->set_mapping('aisafetyhazards_attempt', $oldid, $newid);
    }

    /**
     * Restores one label a learner dropped.
     *
     * @param array $data
     */
    protected function process_aisafetyhazards_choice($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('aisafetyhazards_attempt');
        $data->sceneid = (int)$this->get_mappingid('aisafetyhazards_scene', $data->sceneid);
        $data->hazardid = (int)$this->get_mappingid('aisafetyhazards_hazard', $data->hazardid);
        if ($data->kind === 'hazard') {
            $data->itemid = (int)$this->get_mappingid('aisafetyhazards_hazard', $data->itemid);
        } else if ($data->kind !== 'done') {
            $data->itemid = (int)$this->get_mappingid('aisafetyhazards_control', $data->itemid);
        }
        if ($data->attemptid && $data->sceneid && $data->hazardid && ($data->itemid || $data->kind === 'done')) {
            $DB->insert_record('aisafetyhazards_choice', $data);
        }
    }

    /**
     * Restores files after the structure.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aisafetyhazards', 'intro', null);
        $this->add_related_files('mod_aisafetyhazards', 'sceneimage', 'aisafetyhazards_scene');
        $this->add_related_files('mod_aisafetyhazards', 'voiceover', 'aisafetyhazards_scene');
    }
}
