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
 * Backup structure for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step.
 */
class backup_aisafetyhazards_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $root = new backup_nested_element('aisafetyhazards', ['id'], [
            'name', 'intro', 'introformat', 'industry', 'subindustry', 'task', 'customindustry', 'customsubindustry',
            'customtask', 'contentlang', 'scenecount', 'imagestyle', 'studymode', 'practicemode', 'testmode', 'passmark',
            'shuffleoptions', 'sounds', 'voiceparts', 'mustlisten', 'grade', 'grademethod', 'maxattempts',
            'completionallscenes', 'completionpasstest', 'timecreated', 'timemodified',
        ]);
        $scenes = new backup_nested_element('scenes');
        $scene = new backup_nested_element('scene', ['id'], ['sortorder', 'title', 'context', 'imageprompt', 'teachingnote',
            'timecreated', 'timemodified']);
        $hazards = new backup_nested_element('hazards');
        $hazard = new backup_nested_element('hazard', ['id'], ['sortorder', 'name', 'anchor', 'why', 'likelihood',
            'consequence', 'x', 'y', 'placed', 'distractor', 'timemodified']);
        $controls = new backup_nested_element('controls');
        $control = new backup_nested_element('control', ['id'], ['sortorder', 'kind', 'text', 'correct', 'eliminates',
            'level', 'dl', 'dc', 'reason']);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], ['userid', 'attempt', 'state', 'playmode', 'sceneorder', 'score',
            'safety', 'timestart', 'timefinish', 'timemodified']);
        $choices = new backup_nested_element('choices');
        $choice = new backup_nested_element('choice', ['id'], ['sceneid', 'hazardid', 'kind', 'itemid', 'correct',
            'timecreated']);

        $root->add_child($scenes);
        $scenes->add_child($scene);
        $scene->add_child($hazards);
        $hazards->add_child($hazard);
        $hazard->add_child($controls);
        $controls->add_child($control);
        $root->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($choices);
        $choices->add_child($choice);

        $root->set_source_table('aisafetyhazards', ['id' => backup::VAR_ACTIVITYID]);
        $scene->set_source_table('aisafetyhazards_scene', ['aisafetyhazardsid' => backup::VAR_PARENTID], 'sortorder ASC');
        $hazard->set_source_table('aisafetyhazards_hazard', ['sceneid' => backup::VAR_PARENTID], 'sortorder ASC');
        $control->set_source_table('aisafetyhazards_control', ['hazardid' => backup::VAR_PARENTID], 'sortorder ASC');
        if ($userinfo) {
            $attempt->set_source_table('aisafetyhazards_attempt', ['aisafetyhazardsid' => backup::VAR_PARENTID], 'id ASC');
            $choice->set_source_table('aisafetyhazards_choice', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
        }
        $attempt->annotate_ids('user', 'userid');

        $root->annotate_files('mod_aisafetyhazards', 'intro', null);
        $scene->annotate_files('mod_aisafetyhazards', 'sceneimage', 'id');
        $scene->annotate_files('mod_aisafetyhazards', 'voiceover', 'id');

        return $this->prepare_activity_structure($root);
    }
}
