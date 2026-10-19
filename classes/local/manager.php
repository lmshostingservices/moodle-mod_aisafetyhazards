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

namespace mod_aisafetyhazards\local;

use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Activity content: scenes (one picture each), their hazards and the control labels of each hazard, pictures and
 * grades.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string[] Accepted picture types. */
    public const IMAGE_TYPES = ['.png', '.jpg', '.jpeg', '.gif', '.webp'];

    /**
     * @var int[] Longest text, in characters, that still sits neatly on the picture and in the label box. Longer text
     *     (pasted from an AI assistant) is kept, but flagged in the check step and must be shortened when the scene is
     *     next saved in the editor.
     */
    public const LIMITS = ['context' => 400, 'name' => 60, 'text' => 90, 'why' => 220, 'reason' => 220];

    /** @var int Most distractor hazard labels per scene (hazards that are not in the picture). */
    public const MAX_DISTRACTORS = 2;

    /** @var int[] Fewest and most labels in each control row. */
    public const ROW_SIZE = [2, 5];

    /** @var int Most right answers in the reduce and PPE rows. */
    public const MAX_CORRECT = 3;

    /** @var string[] File areas. */
    public const FILEAREAS = ['sceneimage', 'voiceover'];

    /** @var int Most pictures accepted in one upload. */
    public const MAX_UPLOAD_IMAGES = 100;

    /** @var int Largest ZIP accepted (unpacked), in bytes. */
    public const MAX_ZIP_BYTES = 209715200;

    /** @var int Grading method: highest attempt. */
    public const GRADE_HIGHEST = 1;

    /** @var int Grading method: average of attempts. */
    public const GRADE_AVERAGE = 2;

    /** @var int Grading method: first attempt. */
    public const GRADE_FIRST = 3;

    /** @var int Grading method: last attempt. */
    public const GRADE_LAST = 4;

    /**
     * Normalises activity settings before saving.
     *
     * @param stdClass $data
     * @return stdClass
     */
    public static function prepare_instance_data(stdClass $data): stdClass {
        $flags = ['studymode', 'practicemode', 'testmode', 'shuffleoptions', 'sounds', 'mustlisten',
            'completionallscenes', 'completionpasstest'];
        foreach ($flags as $flag) {
            if (property_exists($data, $flag)) {
                $data->$flag = empty($data->$flag) ? 0 : 1;
            }
        }
        if (isset($data->industry) || isset($data->subindustry) || isset($data->task)) {
            [$data->industry, $data->subindustry, $data->task] = catalogue::valid_path(
                (string)($data->industry ?? ''),
                (string)($data->subindustry ?? ''),
                (string)($data->task ?? '')
            );
        }
        foreach (['customindustry', 'customsubindustry', 'customtask'] as $field) {
            if (property_exists($data, $field)) {
                $data->$field = self::clean_line($data->$field ?? '');
            }
        }
        if (isset($data->contentlang) && !catalogue::is_language($data->contentlang)) {
            $data->contentlang = 'en';
        }
        if (isset($data->imagestyle) && !in_array($data->imagestyle, ['illustration', 'photo'], true)) {
            $data->imagestyle = 'photo';
        }
        if (isset($data->scenecount)) {
            $data->scenecount = max(1, min(lesson::MAX_SCENES_PER_LESSON, (int)$data->scenecount));
        }
        if (isset($data->grademethod) && !in_array((int)$data->grademethod, [1, 2, 3, 4], true)) {
            $data->grademethod = self::GRADE_HIGHEST;
        }
        if (
            property_exists($data, 'studymode') && property_exists($data, 'practicemode') && property_exists($data, 'testmode')
                && !$data->studymode && !$data->practicemode && !$data->testmode
        ) {
            // At least one way to play.
            $data->practicemode = 1;
        }
        if (isset($data->voiceparts) && is_array($data->voiceparts)) {
            // From the form: one checkbox per part.
            $data->voiceparts = implode(',', array_keys(array_filter($data->voiceparts)));
        }
        if (property_exists($data, 'voiceparts')) {
            $data->voiceparts = implode(',', array_intersect(voiceover::PARTS, explode(',', (string)$data->voiceparts)));
        }
        if (property_exists($data, 'passmark')) {
            $data->passmark = max(0, min(100, (int)$data->passmark));
        }
        if (property_exists($data, 'maxattempts')) {
            $data->maxattempts = max(0, min(100, (int)$data->maxattempts));
        }
        if (!isset($data->grade)) {
            $data->grade = 100;
        }
        return $data;
    }

    /**
     * User grades computed from finished attempts.
     *
     * @param stdClass $instance
     * @param int $userid 0 for all users
     * @return array userid => stdClass(userid, rawgrade, dategraded, datesubmitted)
     */
    public static function get_user_grades(stdClass $instance, int $userid = 0): array {
        global $DB;
        // Only the graded mode counts: the test when the activity has one, otherwise practice.
        $params = ['aid' => $instance->id, 'state' => 'finished', 'mode' => learning::graded_mode($instance)];
        $where = 'aisafetyhazardsid = :aid AND state = :state AND playmode = :mode';
        if ($userid) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $grades = [];
        $current = null;
        $list = [];
        $flush = function () use (&$grades, &$current, &$list, $instance) {
            if ($current === null || !$list) {
                return;
            }
            $percent = self::calculate_percent($list, (int)$instance->grademethod);
            $last = end($list);
            $grade = new stdClass();
            $grade->userid = $current;
            $grade->rawgrade = $instance->grade > 0 ? round($percent * $instance->grade / 100, 5) : null;
            $grade->dategraded = $last->timefinish;
            $grade->datesubmitted = $last->timefinish;
            $grades[$current] = $grade;
        };
        $rs = $DB->get_recordset_select(
            'aisafetyhazards_attempt',
            $where,
            $params,
            'userid, attempt',
            'id, userid, attempt, score AS grade, timefinish'
        );
        try {
            foreach ($rs as $attempt) {
                if ((int)$attempt->userid !== $current) {
                    $flush();
                    $current = (int)$attempt->userid;
                    $list = [];
                }
                $list[] = $attempt;
            }
            $flush();
        } finally {
            $rs->close();
        }
        return $grades;
    }

    /**
     * Scenes of an activity, in order.
     *
     * @param int $aid
     * @return stdClass[]
     */
    public static function get_scenes(int $aid): array {
        global $DB;
        return $DB->get_records('aisafetyhazards_scene', ['aisafetyhazardsid' => $aid], 'sortorder ASC, id ASC');
    }

    /**
     * Hazards of the given scenes (the hazards in the picture first, then the distractors).
     *
     * @param int[] $sceneids
     * @return array sceneid => stdClass[]
     */
    public static function get_hazards(array $sceneids): array {
        global $DB;
        $out = array_fill_keys($sceneids, []);
        if (!$sceneids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($sceneids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select('aisafetyhazards_hazard', "sceneid $insql", $params, 'sceneid, distractor, sortorder, id');
        foreach ($rows as $hazard) {
            $out[$hazard->sceneid][] = $hazard;
        }
        return $out;
    }

    /**
     * Control labels of the given hazards, row by row.
     *
     * @param int[] $hazardids
     * @return array hazardid => [kind => stdClass[]]
     */
    public static function get_controls(array $hazardids): array {
        global $DB;
        $out = [];
        foreach ($hazardids as $id) {
            $out[$id] = array_fill_keys(catalogue::KINDS, []);
        }
        if (!$hazardids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($hazardids, SQL_PARAMS_NAMED);
        foreach ($DB->get_records_select('aisafetyhazards_control', "hazardid $insql", $params, 'hazardid, sortorder, id') as $c) {
            if (isset($out[$c->hazardid][$c->kind])) {
                $out[$c->hazardid][$c->kind][] = $c;
            }
        }
        return $out;
    }

    /**
     * A scene's hazards with their controls, as arrays (for the editor, the checks and the player).
     *
     * @param int $sceneid
     * @return array list of hazard arrays, each with controls: kind => list of control arrays
     */
    public static function scene_hazards(int $sceneid): array {
        $hazards = self::get_hazards([$sceneid])[$sceneid];
        $controls = self::get_controls(array_map(fn($h) => (int)$h->id, $hazards));
        $out = [];
        foreach ($hazards as $h) {
            $item = (array)$h;
            $item['controls'] = [];
            foreach (catalogue::KINDS as $kind) {
                $item['controls'][$kind] = array_map(fn($c) => (array)$c, $controls[$h->id][$kind] ?? []);
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Whether a scene's hazards are complete: exactly three hazards in the picture with a name, at most two
     * distractors, and for each hazard an eliminate row with exactly one right answer, a reduce row and a PPE row each
     * with at least one right answer, even when the elimination removes the hazard (it may not be approved). Rows have
     * 2 to 5 labels.
     *
     * @param array $hazards from {@see self::scene_hazards()} or a cleaned draft
     * @param bool $older true only to keep playing a scene saved before every hazard needed reduce and PPE rows
     * @return bool
     */
    public static function hazards_ok(array $hazards, bool $older = false): bool {
        $real = array_values(array_filter($hazards, fn($h) => empty($h['distractor'])));
        $distractors = count($hazards) - count($real);
        if (count($real) !== catalogue::HAZARDS || $distractors > self::MAX_DISTRACTORS) {
            return false;
        }
        foreach ($hazards as $h) {
            if (trim((string)($h['name'] ?? '')) === '') {
                return false;
            }
        }
        foreach ($real as $h) {
            $rows = (array)($h['controls'] ?? []);
            $elim = array_values((array)($rows['eliminate'] ?? []));
            $right = array_values(array_filter($elim, fn($c) => !empty($c['correct'])));
            if (!self::row_ok($elim) || count($right) !== 1) {
                return false;
            }
            foreach (['reduce', 'ppe'] as $kind) {
                $row = array_values((array)($rows[$kind] ?? []));
                if (!$row && $older && !empty($right[0]['eliminates'])) {
                    // Older content may have no reduce or PPE row for a hazard that can be eliminated. It still plays,
                    // but it cannot be saved like that: the elimination may not be approved, so learners need both rows.
                    continue;
                }
                $count = count(array_filter($row, fn($c) => !empty($c['correct'])));
                if (!self::row_ok($row) || $count < 1 || $count > self::MAX_CORRECT) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Whether a row has 2 to 5 labels, all with text, and at least one wrong answer to choose from.
     *
     * @param array $row control arrays
     * @return bool
     */
    protected static function row_ok(array $row): bool {
        if (count($row) < self::ROW_SIZE[0] || count($row) > self::ROW_SIZE[1]) {
            return false;
        }
        foreach ($row as $c) {
            if (trim((string)($c['text'] ?? '')) === '') {
                return false;
            }
        }
        return count(array_filter($row, fn($c) => empty($c['correct']))) >= 1;
    }

    /**
     * Whether a scene is ready to play: a picture and complete hazards.
     *
     * @param \context $context
     * @param stdClass $scene
     * @return bool
     */
    public static function scene_ready(\context $context, stdClass $scene): bool {
        return self::get_scene_file($context, (int)$scene->id) !== null && self::hazards_ok(
            self::scene_hazards((int)$scene->id),
            true
        );
    }

    /**
     * Scenes that are ready to play.
     *
     * @param stdClass $instance
     * @param \context $context
     * @return stdClass[]
     */
    public static function ready_scenes(stdClass $instance, \context $context): array {
        return array_values(
            array_filter(
                self::get_scenes((int)$instance->id),
                fn($scene) => self::scene_ready($context, $scene)
            )
        );
    }

    /**
     * The fields of a scene that are longer than {@see self::LIMITS}.
     *
     * @param stdClass $scene
     * @param array $hazards from {@see self::scene_hazards()}
     * @return string[] field names, each once
     */
    public static function too_long(stdClass $scene, array $hazards): array {
        $out = [];
        $check = function (string $field, $value) use (&$out) {
            if (\core_text::strlen(trim((string)$value)) > self::LIMITS[$field] && !in_array($field, $out, true)) {
                $out[] = $field;
            }
        };
        $check('context', $scene->context);
        foreach ($hazards as $h) {
            $check('name', $h['name']);
            $check('why', $h['why'] ?? '');
            foreach ($h['controls'] ?? [] as $row) {
                foreach ($row as $c) {
                    $check('text', $c['text']);
                    $check('reason', $c['reason'] ?? '');
                }
            }
        }
        return $out;
    }

    /**
     * Adds a scene at the end.
     *
     * @param stdClass $instance
     * @param array $data title, context, imageprompt, teachingnote
     * @return int scene id
     */
    public static function add_scene(stdClass $instance, array $data): int {
        global $DB;
        $sortorder = (int)$DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {aisafetyhazards_scene} WHERE aisafetyhazardsid = :aid',
            ['aid' => $instance->id]
        );
        $now = time();
        $title = self::clean_line($data['title'] ?? '');
        return (int)$DB->insert_record(
            'aisafetyhazards_scene',
            (object)[
            'aisafetyhazardsid' => $instance->id,
            'sortorder' => $sortorder + 1,
            'title' => $title !== '' ? $title : get_string('newscene', 'mod_aisafetyhazards'),
            'context' => self::clean_text($data['context'] ?? '', 2000),
            'imageprompt' => self::clean_text($data['imageprompt'] ?? '', 2000),
            'teachingnote' => self::clean_text($data['teachingnote'] ?? '', 2000),
            'timecreated' => $now,
            'timemodified' => $now,
            ]
        );
    }

    /**
     * Cleans one hazard (without its controls).
     *
     * @param array $item name, anchor, why, likelihood, consequence, x, y, placed, distractor
     * @return array
     */
    public static function clean_hazard(array $item): array {
        $placed = (int)($item['placed'] ?? 0);
        $distractor = empty($item['distractor']) ? 0 : 1;
        $placed = $distractor ? 0 : (in_array($placed, [0, 1, 2], true) ? $placed : 0);
        return [
            'name' => self::clean_line($item['name'] ?? ''),
            'anchor' => self::clean_line($item['anchor'] ?? ''),
            'why' => self::clean_text($item['why'] ?? '', 1000),
            'likelihood' => max(1, min(5, (int)($item['likelihood'] ?? 3))),
            'consequence' => max(1, min(5, (int)($item['consequence'] ?? 3))),
            'x' => $placed ? max(0, min(100, round((float)($item['x'] ?? 0), 4))) : 0,
            'y' => $placed ? max(0, min(100, round((float)($item['y'] ?? 0), 4))) : 0,
            'placed' => $placed,
            'distractor' => $distractor,
        ];
    }

    /**
     * Cleans one control label. A wrong answer never lowers the risk; PPE is always level PPE, and a right answer that
     * removes the hazard is level elimination.
     *
     * @param array $item text, correct, eliminates, level, dl, dc, reason
     * @param string $kind eliminate, reduce or ppe
     * @return array
     */
    public static function clean_control(array $item, string $kind): array {
        $kind = in_array($kind, catalogue::KINDS, true) ? $kind : 'reduce';
        $correct = empty($item['correct']) ? 0 : 1;
        $eliminates = $kind === 'eliminate' && $correct && !empty($item['eliminates']) ? 1 : 0;
        $level = (string)($item['level'] ?? '');
        if ($kind === 'ppe') {
            $level = 'ppe';
        } else if ($eliminates) {
            $level = 'elimination';
        } else if (!catalogue::is_level($level) || $level === 'ppe' || ($kind === 'reduce' && $level === 'elimination')) {
            $level = $kind === 'eliminate' ? 'elimination' : 'administrative';
        }
        $dl = $correct && !$eliminates ? max(0, min(4, (int)($item['dl'] ?? 0))) : 0;
        $dc = $correct && !$eliminates ? max(0, min(4, (int)($item['dc'] ?? 0))) : 0;
        if ($correct && $kind !== 'eliminate' && !$dl && !$dc) {
            // A right control always lowers something: by default PPE the consequence, other controls the likelihood.
            if ($kind === 'ppe') {
                $dc = 1;
            } else {
                $dl = 1;
            }
        }
        return [
            'kind' => $kind,
            'text' => self::clean_line($item['text'] ?? ''),
            'correct' => $correct,
            'eliminates' => $eliminates,
            'level' => $level,
            'dl' => $dl,
            'dc' => $dc,
            'reason' => self::clean_text($item['reason'] ?? '', 1000),
        ];
    }

    /**
     * Cleans a list of hazards with their control rows.
     *
     * @param array $hazards list of hazard arrays with controls: kind => list
     * @return array
     */
    public static function clean_hazards(array $hazards): array {
        $out = [];
        foreach (array_values($hazards) as $h) {
            if (!is_array($h)) {
                continue;
            }
            $clean = self::clean_hazard($h);
            if (isset($h['id'])) {
                $clean['id'] = (int)$h['id'];
            }
            $clean['controls'] = array_fill_keys(catalogue::KINDS, []);
            if (!$clean['distractor']) {
                foreach (catalogue::KINDS as $kind) {
                    foreach (array_slice(array_values((array)($h['controls'][$kind] ?? [])), 0, self::ROW_SIZE[1]) as $c) {
                        if (!is_array($c)) {
                            continue;
                        }
                        $control = self::clean_control($c, $kind);
                        if (isset($c['id'])) {
                            $control['id'] = (int)$c['id'];
                        }
                        if ($control['text'] !== '') {
                            $clean['controls'][$kind][] = $control;
                        }
                    }
                }
            }
            $out[] = $clean;
        }
        // Distractors last, as the player and the checks expect.
        usort($out, fn($a, $b) => $a['distractor'] <=> $b['distractor']);
        return $out;
    }

    /**
     * Gives every hazard in the picture that has no position a guessed one, spread across the picture (placed = 2), so
     * the teacher only moves each one onto the right place.
     *
     * @param array $hazards cleaned hazard arrays
     * @return array
     */
    public static function guess_positions(array $hazards): array {
        $todo = array_keys(array_filter($hazards, fn($h) => empty($h['distractor']) && empty($h['placed'])));
        $n = count($todo);
        foreach ($todo as $i => $k) {
            $hazards[$k]['x'] = round($n === 1 ? 50 : 20 + 60 * $i / ($n - 1), 2);
            $hazards[$k]['y'] = $i % 2 ? 58 : 42;
            $hazards[$k]['placed'] = 2;
        }
        return $hazards;
    }

    /**
     * Saves a scene and its hazards with their controls. Hazards and controls that keep their id are updated, so
     * learners' earlier labels still point at them; the rest are added, and those left out are deleted.
     *
     * @param stdClass $scene
     * @param array $data title, context, imageprompt, teachingnote
     * @param array $hazards list of hazard arrays with controls: kind => list
     */
    public static function save_scene(stdClass $scene, array $data, array $hazards): void {
        global $DB;
        $clean = self::guess_positions(self::clean_hazards($hazards));
        if (!self::hazards_ok($clean)) {
            throw new moodle_exception('hazardsincomplete', 'mod_aisafetyhazards');
        }
        $transaction = $DB->start_delegated_transaction();
        $update = (object)['id' => $scene->id, 'timemodified' => time()];
        if (array_key_exists('title', $data)) {
            $update->title = self::clean_line($data['title']) ?: get_string('newscene', 'mod_aisafetyhazards');
        }
        foreach (['context', 'imageprompt', 'teachingnote'] as $field) {
            if (array_key_exists($field, $data)) {
                $update->$field = self::clean_text($data[$field], 2000);
            }
        }
        $DB->update_record('aisafetyhazards_scene', $update);
        $existing = $DB->get_records('aisafetyhazards_hazard', ['sceneid' => $scene->id], '', 'id');
        $keep = [];
        $now = time();
        foreach ($clean as $i => $h) {
            $record = (object)array_diff_key($h, ['controls' => 1, 'id' => 1]);
            $record->sceneid = $scene->id;
            $record->sortorder = $i + 1;
            $record->timemodified = $now;
            if (!empty($h['id']) && isset($existing[$h['id']])) {
                $record->id = $h['id'];
                $DB->update_record('aisafetyhazards_hazard', $record);
            } else {
                $record->id = $DB->insert_record('aisafetyhazards_hazard', $record);
            }
            $keep[] = (int)$record->id;
            self::save_controls((int)$record->id, $h['controls']);
        }
        foreach (array_diff(array_keys($existing), $keep) as $gone) {
            $DB->delete_records('aisafetyhazards_control', ['hazardid' => $gone]);
            $DB->delete_records('aisafetyhazards_hazard', ['id' => $gone]);
        }
        $transaction->allow_commit();
    }

    /**
     * Saves the control rows of one hazard.
     *
     * @param int $hazardid
     * @param array $rows kind => list of cleaned control arrays
     */
    protected static function save_controls(int $hazardid, array $rows): void {
        global $DB;
        $existing = $DB->get_records('aisafetyhazards_control', ['hazardid' => $hazardid], '', 'id');
        $keep = [];
        foreach (catalogue::KINDS as $kind) {
            foreach (array_values($rows[$kind] ?? []) as $i => $c) {
                $record = (object)array_diff_key($c, ['id' => 1]);
                $record->hazardid = $hazardid;
                $record->sortorder = $i + 1;
                if (!empty($c['id']) && isset($existing[$c['id']])) {
                    $record->id = $c['id'];
                    $DB->update_record('aisafetyhazards_control', $record);
                } else {
                    $record->id = $DB->insert_record('aisafetyhazards_control', $record);
                }
                $keep[] = (int)$record->id;
            }
        }
        $gone = array_diff(array_keys($existing), $keep);
        if ($gone) {
            $DB->delete_records_list('aisafetyhazards_control', 'id', $gone);
        }
    }

    /**
     * Moves hazards to positions (from the picture's label editor or detected positions).
     *
     * @param stdClass $scene
     * @param array $positions hazard id => [x, y]
     * @param int $placed 1 when the teacher placed them, 2 when they were placed automatically
     */
    public static function place_hazards(stdClass $scene, array $positions, int $placed = 1): void {
        global $DB;
        foreach ($positions as $id => [$x, $y]) {
            $DB->set_field_select(
                'aisafetyhazards_hazard',
                'x',
                max(0, min(100, round((float)$x, 4))),
                'id = :id AND sceneid = :sceneid AND distractor = 0',
                ['id' => (int)$id, 'sceneid' => $scene->id]
            );
            $DB->set_field_select(
                'aisafetyhazards_hazard',
                'y',
                max(0, min(100, round((float)$y, 4))),
                'id = :id AND sceneid = :sceneid AND distractor = 0',
                ['id' => (int)$id, 'sceneid' => $scene->id]
            );
            $DB->set_field_select(
                'aisafetyhazards_hazard',
                'placed',
                $placed,
                'id = :id AND sceneid = :sceneid AND distractor = 0',
                ['id' => (int)$id, 'sceneid' => $scene->id]
            );
        }
        $DB->set_field('aisafetyhazards_scene', 'timemodified', time(), ['id' => $scene->id]);
    }

    /**
     * Deletes a scene with its hazards, controls, picture, voiceover and learner labels.
     *
     * @param \context $context
     * @param stdClass $scene
     */
    public static function delete_scene(\context $context, stdClass $scene): void {
        global $DB;
        $DB->delete_records('aisafetyhazards_choice', ['sceneid' => $scene->id]);
        $ids = $DB->get_fieldset_select('aisafetyhazards_hazard', 'id', 'sceneid = :sceneid', ['sceneid' => $scene->id]);
        if ($ids) {
            $DB->delete_records_list('aisafetyhazards_control', 'hazardid', $ids);
        }
        $DB->delete_records('aisafetyhazards_hazard', ['sceneid' => $scene->id]);
        $DB->delete_records('aisafetyhazards_scene', ['id' => $scene->id]);
        get_file_storage()->delete_area_files($context->id, 'mod_aisafetyhazards', 'sceneimage', $scene->id);
        get_file_storage()->delete_area_files($context->id, 'mod_aisafetyhazards', 'voiceover', $scene->id);
        self::normalise_sortorder((int)$scene->aisafetyhazardsid);
    }

    /**
     * Marks the activity viewed and triggers the event.
     *
     * @param stdClass $instance
     * @param stdClass $course
     * @param \cm_info|stdClass $cm
     * @param \context_module $context
     */
    public static function view(stdClass $instance, stdClass $course, $cm, \context_module $context): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $event = \mod_aisafetyhazards\event\course_module_viewed::create(
            [
            'objectid' => $instance->id,
            'context' => $context,
            ]
        );
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('aisafetyhazards', $instance);
        $event->trigger();
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);
    }

    /**
     * Applies the grading method to a list of attempts (ordered by attempt number).
     *
     * @param array $attempts
     * @param int $method
     * @return float percentage
     */
    public static function calculate_percent(array $attempts, int $method): float {
        $attempts = array_values($attempts);
        if (!$attempts) {
            return 0.0;
        }
        switch ($method) {
            case self::GRADE_AVERAGE:
                $sum = 0;
                foreach ($attempts as $a) {
                    $sum += (float)$a->grade;
                }
                return $sum / count($attempts);
            case self::GRADE_FIRST:
                return (float)$attempts[0]->grade;
            case self::GRADE_LAST:
                return (float)$attempts[count($attempts) - 1]->grade;
            default:
                $max = 0.0;
                foreach ($attempts as $a) {
                    $max = max($max, (float)$a->grade);
                }
                return $max;
        }
    }

    /**
     * The stored picture of a scene.
     *
     * @param \context $context
     * @param int $sceneid
     * @return \stored_file|null
     */
    public static function get_scene_file(\context $context, int $sceneid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_aisafetyhazards',
            'sceneimage',
            $sceneid,
            'sortorder, id',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Picture information for a scene.
     *
     * @param \context $context
     * @param int $sceneid
     * @return array [url|null, width, height]
     */
    public static function get_scene_image(\context $context, int $sceneid): array {
        $file = self::get_scene_file($context, $sceneid);
        if (!$file) {
            return [null, 16, 10];
        }
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_aisafetyhazards',
            'sceneimage',
            $sceneid,
            $file->get_filepath(),
            $file->get_filename()
        );
        $url->param('rev', $file->get_timemodified());
        $info = $file->get_imageinfo();
        $width = !empty($info['width']) ? (int)$info['width'] : 1600;
        $height = !empty($info['height']) ? (int)$info['height'] : 1000;
        return [$url->out(false), $width, $height];
    }

    /**
     * Checks that bytes really are a picture of an accepted type.
     *
     * @param string $head the first bytes of the file (at least 16)
     * @return string|null the extension (png, jpg, gif, webp) or null
     */
    public static function image_signature(string $head): ?string {
        if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'png';
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }

    /**
     * Whether an image file on disk is a real, readable picture.
     *
     * @param string $path
     * @return bool
     */
    protected static function valid_image_path(string $path): bool {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $head = (string)fread($handle, 16);
        fclose($handle);
        if (self::image_signature($head) === null) {
            return false;
        }
        $info = getimagesize($path);
        return !empty($info[0]) && !empty($info[1]);
    }

    /**
     * Whether a stored file is a real picture.
     *
     * @param \stored_file $file
     * @return bool
     */
    protected static function valid_image_file(\stored_file $file): bool {
        $handle = $file->get_content_file_handle();
        if (!$handle) {
            return false;
        }
        $head = (string)fread($handle, 16);
        fclose($handle);
        return self::image_signature($head) !== null && $file->is_valid_image();
    }

    /**
     * Makes a readable title from a file name.
     *
     * @param string $filename
     * @return string
     */
    public static function title_from_filename(string $filename): string {
        $title = preg_replace('/\.[^.]+$/', '', $filename);
        $title = trim(preg_replace('/[_\-]+/', ' ', $title));
        return \core_text::substr($title !== '' ? $title : $filename, 0, 255);
    }

    /**
     * Collects the pictures in a draft area, including pictures inside ZIP files.
     *
     * @param int $draftitemid
     * @return array list of [name, file|path]
     */
    protected static function draft_images(int $draftitemid): array {
        global $USER;
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
        $sources = [];
        foreach ($files as $file) {
            $filename = $file->get_filename();
            if (preg_match('/\.zip$/i', $filename)) {
                $packer = get_file_packer('application/zip');
                $list = $file->list_files($packer);
                if (!is_array($list)) {
                    throw new moodle_exception('zipinvalid', 'mod_aisafetyhazards');
                }
                $unpacked = 0;
                $images = 0;
                foreach ($list as $entry) {
                    $unpacked += (int)$entry->size;
                    if (!$entry->is_directory && preg_match('/\.(png|jpe?g|gif|webp)$/i', $entry->pathname)) {
                        $images++;
                    }
                }
                if ($unpacked > self::MAX_ZIP_BYTES || $images > self::MAX_UPLOAD_IMAGES) {
                    throw new moodle_exception('ziptoolarge', 'mod_aisafetyhazards', '', self::MAX_UPLOAD_IMAGES);
                }
                $tmpdir = make_request_directory();
                $file->extract_to_pathname($packer, $tmpdir);
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpdir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $path) {
                    $name = $path->getFilename();
                    if (
                        !$path->isLink() && $path->isFile() && strpos($name, '.') !== 0 &&
                        strpos($path->getPathname(), '__MACOSX') === false &&
                        preg_match('/\.(png|jpe?g|gif|webp)$/i', $name) && self::valid_image_path($path->getPathname())
                    ) {
                        $sources[] = ['name' => $name, 'path' => $path->getPathname()];
                    }
                }
            } else if (self::valid_image_file($file)) {
                $sources[] = ['name' => $filename, 'file' => $file];
            }
        }
        if (count($sources) > self::MAX_UPLOAD_IMAGES) {
            throw new moodle_exception('ziptoolarge', 'mod_aisafetyhazards', '', self::MAX_UPLOAD_IMAGES);
        }
        usort($sources, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $sources;
    }

    /**
     * File record for a scene picture.
     *
     * @param \context $context
     * @param int $sceneid
     * @param string $filename
     * @return array
     */
    protected static function image_record(\context $context, int $sceneid, string $filename): array {
        return [
            'contextid' => $context->id,
            'component' => 'mod_aisafetyhazards',
            'filearea' => 'sceneimage',
            'itemid' => $sceneid,
            'filepath' => '/',
            'filename' => clean_param($filename, PARAM_FILE) ?: 'scene.png',
        ];
    }

    /**
     * Replaces a scene picture with the first picture in a draft area.
     *
     * @param \context $context
     * @param stdClass $scene
     * @param int $draftitemid
     * @return bool whether a picture was saved
     */
    public static function replace_scene_image(\context $context, stdClass $scene, int $draftitemid): bool {
        global $DB;
        $sources = self::draft_images($draftitemid);
        if (!$sources) {
            return false;
        }
        $source = reset($sources);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_aisafetyhazards', 'sceneimage', $scene->id);
        $record = self::image_record($context, (int)$scene->id, $source['name']);
        if (isset($source['file'])) {
            $fs->create_file_from_storedfile($record, $source['file']);
        } else {
            $fs->create_file_from_pathname($record, $source['path']);
        }
        $DB->set_field('aisafetyhazards_scene', 'timemodified', time(), ['id' => $scene->id]);
        return true;
    }

    /**
     * Saves picture bytes (from an AI provider) as the scene picture, after checking they are a real picture.
     *
     * @param \context $context
     * @param stdClass $scene
     * @param string $bytes
     */
    public static function save_scene_image_bytes(\context $context, stdClass $scene, string $bytes): void {
        global $DB;
        $type = self::image_signature(substr($bytes, 0, 16));
        if ($type === null || strlen($bytes) > 20 * 1048576) {
            throw new moodle_exception('aibadimage', 'mod_aisafetyhazards');
        }
        $dir = make_request_directory();
        $path = $dir . '/scene.' . $type;
        file_put_contents($path, $bytes);
        if (!self::valid_image_path($path)) {
            throw new moodle_exception('aibadimage', 'mod_aisafetyhazards');
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_aisafetyhazards', 'sceneimage', $scene->id);
        $fs->create_file_from_pathname(self::image_record($context, (int)$scene->id, 'scene-' . time() . '.' . $type), $path);
        $DB->set_field('aisafetyhazards_scene', 'timemodified', time(), ['id' => $scene->id]);
    }

    /**
     * File manager options for scene pictures.
     *
     * @param int $maxfiles
     * @return array
     */
    public static function image_filemanager_options(int $maxfiles = -1): array {
        global $CFG;
        require_once($CFG->dirroot . '/repository/lib.php');
        return [
            'subdirs' => 0,
            'maxfiles' => $maxfiles,
            'accepted_types' => $maxfiles === 1 ? self::IMAGE_TYPES : array_merge(self::IMAGE_TYPES, ['.zip']),
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * Re-numbers scene sort order 1..n.
     *
     * @param int $aid
     */
    public static function normalise_sortorder(int $aid): void {
        global $DB;
        $i = 0;
        foreach (self::get_scenes($aid) as $scene) {
            $i++;
            if ((int)$scene->sortorder !== $i) {
                $DB->set_field('aisafetyhazards_scene', 'sortorder', $i, ['id' => $scene->id]);
            }
        }
    }

    /**
     * Moves a scene earlier or later.
     *
     * @param stdClass $scene
     * @param int $direction -1 earlier, 1 later
     */
    public static function move_scene(stdClass $scene, int $direction): void {
        global $DB;
        self::normalise_sortorder((int)$scene->aisafetyhazardsid);
        $scenes = array_values(self::get_scenes((int)$scene->aisafetyhazardsid));
        foreach ($scenes as $index => $s) {
            if ((int)$s->id === (int)$scene->id) {
                $target = $index + $direction;
                if (isset($scenes[$target])) {
                    $DB->set_field('aisafetyhazards_scene', 'sortorder', $scenes[$target]->sortorder, ['id' => $s->id]);
                    $DB->set_field('aisafetyhazards_scene', 'sortorder', $s->sortorder, ['id' => $scenes[$target]->id]);
                }
                return;
            }
        }
    }

    /**
     * Cleans one line of text.
     *
     * @param mixed $value
     * @param int $length
     * @return string
     */
    public static function clean_line($value, int $length = 255): string {
        $value = trim(preg_replace('/\s+/u', ' ', clean_param((string)$value, PARAM_TEXT)));
        return \core_text::substr($value, 0, $length);
    }

    /**
     * Cleans multi-line text.
     *
     * @param mixed $value
     * @param int $length
     * @return string
     */
    public static function clean_text($value, int $length = 4000): string {
        $lines = preg_split('/\R/u', clean_param((string)$value, PARAM_TEXT));
        $lines = array_map(fn($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), $lines);
        return \core_text::substr(trim(implode("\n", $lines)), 0, $length);
    }
}
