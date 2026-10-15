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
 * Data generator for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aisafetyhazards_generator extends testing_module_generator {
    /**
     * Creates an instance with sensible defaults (construction › civil construction › trenching).
     *
     * @param array|stdClass $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'industry' => 'construction', 'subindustry' => 'civil', 'task' => 'trenching', 'customindustry' => '',
            'customsubindustry' => '', 'customtask' => '', 'contentlang' => 'en', 'scenecount' => 3,
            'imagestyle' => 'photo', 'studymode' => 1, 'practicemode' => 1, 'testmode' => 0, 'passmark' => 70,
            'shuffleoptions' => 0, 'sounds' => 1, 'grade' => 100, 'grademethod' => 1, 'maxattempts' => 0,
            'completionallscenes' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($record->$key)) {
                $record->$key = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }

    /**
     * The trenching scene from the test fixture draft, cleaned: three hazards (one that can be eliminated) and two
     * distractors.
     *
     * @return array {title, context, imageprompt, teachingnote, hazards}
     */
    public function fixture_scene(): array {
        global $CFG;
        $draft = json_decode(file_get_contents($CFG->dirroot . '/mod/aisafetyhazards/tests/fixtures/draft.json'), true);
        return \mod_aisafetyhazards\local\lesson::clean($draft)['scenes'][0];
    }

    /**
     * Creates a scene with a picture and the fixture hazards (or the hazards given).
     *
     * @param stdClass $instance
     * @param string $title
     * @param array|null $hazards cleaned hazards with controls (defaults to the fixture scene)
     * @param string|null $imagepath picture (defaults to the test fixture; '' for no picture)
     * @return stdClass scene
     */
    public function create_scene(stdClass $instance, string $title, ?array $hazards = null, ?string $imagepath = null): stdClass {
        global $CFG, $DB;
        $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $fixture = $this->fixture_scene();
        $sceneid = \mod_aisafetyhazards\local\manager::add_scene(
            $instance,
            [
            'title' => $title,
            'context' => $fixture['context'],
            'imageprompt' => $fixture['imageprompt'],
            'teachingnote' => $fixture['teachingnote'],
            ]
        );
        $imagepath = $imagepath ?? $CFG->dirroot . '/mod/aisafetyhazards/tests/fixtures/scene.png';
        if ($imagepath !== '') {
            get_file_storage()->create_file_from_pathname(
                [
                'contextid' => $context->id, 'component' => 'mod_aisafetyhazards', 'filearea' => 'sceneimage',
                'itemid' => $sceneid, 'filepath' => '/', 'filename' => basename($imagepath),
                ],
                $imagepath
            );
        }
        $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $sceneid], '*', MUST_EXIST);
        \mod_aisafetyhazards\local\manager::save_scene($scene, [], $hazards ?? $fixture['hazards']);
        return $DB->get_record('aisafetyhazards_scene', ['id' => $sceneid], '*', MUST_EXIST);
    }

    /**
     * Plays every scene of an attempt: each hazard label on its spot and the right controls. With $mistakes, a
     * distractor first goes on the first spot and a wrong label into each reduce row (Practice only for the rows).
     *
     * @param stdClass $instance
     * @param \context $context
     * @param int $attemptid
     * @param bool $mistakes
     * @return stdClass the attempt
     */
    public function play_attempt(stdClass $instance, \context $context, int $attemptid, bool $mistakes = false): stdClass {
        global $DB;
        $learning = \mod_aisafetyhazards\local\learning::class;
        $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        $test = $attempt->playmode === 'test';
        // Labels already on the attempt are skipped, so a partly played attempt can be finished.
        $try = function (callable $call) {
            try {
                $call();
            } catch (\moodle_exception $e) {
                if (!in_array($e->errorcode, ['alreadyplaced', 'rowsinorder'], true)) {
                    throw $e;
                }
            }
        };
        foreach (json_decode($attempt->sceneorder, true) as $item) {
            $sceneid = (int)$item['scene'];
            $hazards = [];
            foreach (\mod_aisafetyhazards\local\manager::scene_hazards($sceneid) as $h) {
                $hazards[(int)$h['id']] = $h;
            }
            foreach ($item['hazards'] as $n => $hid) {
                $h = $hazards[$hid];
                if ($mistakes && $n === 0 && !$test) {
                    $distractor = array_values(array_filter($hazards, fn($x) => !empty($x['distractor'])))[0];
                    $try(fn() => $learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $hid, (int)$distractor['id']));
                }
                $try(fn() => $learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $hid, $hid));
                foreach (['eliminate', 'reduce', 'ppe'] as $kind) {
                    if ($mistakes && $kind === 'reduce' && !$test && $h['controls'][$kind]) {
                        $wrong = array_values(array_filter($h['controls'][$kind], fn($c) => empty($c['correct'])))[0];
                        $try(fn() => $learning::drop($instance, $context, $attempt, $sceneid, $kind, $hid, (int)$wrong['id']));
                    }
                    foreach ($h['controls'][$kind] as $c) {
                        if (!empty($c['correct'])) {
                            $try(fn() => $learning::drop($instance, $context, $attempt, $sceneid, $kind, $hid, (int)$c['id']));
                        }
                    }
                    if ($test && $kind !== 'eliminate') {
                        $try(fn() => $learning::done_row($instance, $context, $attempt, $sceneid, $hid, $kind));
                    }
                }
            }
        }
        return $DB->get_record('aisafetyhazards_attempt', ['id' => $attemptid], '*', MUST_EXIST);
    }

    /**
     * Creates a scene from Behat table data.
     *
     * @param array $data activityid (instance id), title, picture ("none" for no picture)
     * @return stdClass scene
     */
    public function create_behat_scene(array $data): stdClass {
        global $DB;
        $instance = $DB->get_record('aisafetyhazards', ['id' => $data['activityid']], '*', MUST_EXIST);
        // Column "picture": "none" creates the scene without a picture.
        return $this->create_scene($instance, $data['title'], null, ($data['picture'] ?? '') === 'none' ? '' : null);
    }
}
