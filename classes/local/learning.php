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
 * Learner attempts: hazards found on the picture, control labels dropped on each hazard, the risk each hazard is left
 * with, scores and grades.
 *
 * The browser never receives the answers in Practice or Test: every label is marked here. In Practice a wrong label
 * comes back with the reason and the learner keeps going until every right label is placed; in Test every label
 * counts and nothing is marked until the end. Study shows every answer with its effect on the risk.
 *
 * Risk uses a 5 × 5 matrix. A hazard starts with its likelihood and consequence. Each right control lowers the
 * likelihood and/or the consequence by its tags (never below 1); a right elimination removes the hazard. A wrong label
 * changes nothing.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learning {
    /** @var string Attempt in progress. */
    public const STATE_INPROGRESS = 'inprogress';

    /** @var string Attempt finished. */
    public const STATE_FINISHED = 'finished';

    /** @var string Study: every hazard and right control shown with its effect. */
    public const MODE_STUDY = 'study';

    /** @var string Practice: wrong labels come back with the reason. */
    public const MODE_PRACTICE = 'practice';

    /** @var string Test: every label counts; marked at the end. */
    public const MODE_TEST = 'test';

    /** @var string[] Modes in order. */
    public const MODES = [self::MODE_STUDY, self::MODE_PRACTICE, self::MODE_TEST];

    /**
     * Plain text for the browser (Mustache or textContent escapes it there).
     *
     * @param string|null $text
     * @param \context $context
     * @return string
     */
    protected static function line(?string $text, \context $context): string {
        return format_string((string)$text, true, ['context' => $context, 'escape' => false]);
    }

    /**
     * Multi-line plain text for the browser.
     *
     * @param string|null $text
     * @param \context $context
     * @return string
     */
    protected static function para(?string $text, \context $context): string {
        $lines = array_map(fn($l) => self::line($l, $context), preg_split('/\R/u', (string)$text));
        return implode("\n", $lines);
    }

    /**
     * The learner's attempt, checked to belong to them and to this activity.
     *
     * @param stdClass $instance
     * @param int $attemptid
     * @param int $userid
     * @return stdClass
     */
    public static function get_user_attempt(stdClass $instance, int $attemptid, int $userid): stdClass {
        global $DB;
        $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $attemptid]);
        if (!$attempt || (int)$attempt->userid !== $userid || (int)$attempt->aisafetyhazardsid !== (int)$instance->id) {
            throw new moodle_exception('notyourattempt', 'mod_aisafetyhazards');
        }
        return $attempt;
    }

    /**
     * Finished attempts of a learner.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param string|null $mode only attempts in this mode
     * @return stdClass[]
     */
    public static function finished_attempts(stdClass $instance, int $userid, ?string $mode = null): array {
        global $DB;
        $params = ['aisafetyhazardsid' => $instance->id, 'userid' => $userid, 'state' => self::STATE_FINISHED];
        if ($mode !== null) {
            $params['playmode'] = $mode;
        }
        return $DB->get_records('aisafetyhazards_attempt', $params, 'attempt ASC');
    }

    /**
     * The ways learners can play an activity, in order: study, practice, test.
     *
     * @param stdClass $instance
     * @return string[]
     */
    public static function modes(stdClass $instance): array {
        $modes = [];
        foreach (self::MODES as $mode) {
            if (!empty($instance->{$mode . 'mode'})) {
                $modes[] = $mode;
            }
        }
        return $modes ?: [self::MODE_PRACTICE];
    }

    /**
     * The mode whose attempts are graded and limited: the test when there is one, otherwise practice, otherwise study.
     *
     * @param stdClass $instance
     * @return string
     */
    public static function graded_mode(stdClass $instance): string {
        $modes = self::modes($instance);
        foreach ([self::MODE_TEST, self::MODE_PRACTICE] as $mode) {
            if (in_array($mode, $modes, true)) {
                return $mode;
            }
        }
        return self::MODE_STUDY;
    }

    /**
     * Whether the learner may start another attempt in a mode ("Attempts allowed" counts the graded mode only).
     *
     * @param stdClass $instance
     * @param int $userid
     * @param string|null $mode default: the graded mode
     * @return bool
     */
    public static function can_start(stdClass $instance, int $userid, ?string $mode = null): bool {
        $graded = self::graded_mode($instance);
        $mode = $mode ?? $graded;
        if (!in_array($mode, self::modes($instance), true)) {
            return false;
        }
        if ($mode !== $graded) {
            return true;
        }
        return !$instance->maxattempts
            || count(self::finished_attempts($instance, $userid, $graded)) < (int)$instance->maxattempts;
    }

    /**
     * Resumes the learner's attempt in progress in a mode, or starts a new one, and returns the player data.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param int $userid
     * @param string $mode study, practice or test; '' for the activity's first mode
     * @return array
     */
    public static function start_attempt(stdClass $instance, \context $context, int $userid, string $mode = ''): array {
        global $DB;
        $mode = $mode !== '' ? $mode : self::modes($instance)[0];
        if (!in_array($mode, self::modes($instance), true)) {
            throw new moodle_exception('nomoreattempts', 'mod_aisafetyhazards');
        }
        $attempt = $DB->get_record(
            'aisafetyhazards_attempt',
            ['aisafetyhazardsid' => $instance->id, 'userid' => $userid,
            'state' => self::STATE_INPROGRESS, 'playmode' => $mode]
        );
        if (!$attempt) {
            if (!self::can_start($instance, $userid, $mode)) {
                throw new moodle_exception('nomoreattempts', 'mod_aisafetyhazards');
            }
            $ready = manager::ready_scenes($instance, $context);
            if (!$ready) {
                throw new moodle_exception('noscenes', 'mod_aisafetyhazards');
            }
            $order = [];
            foreach ($ready as $scene) {
                $order[] = self::new_order($scene, !empty($instance->shuffleoptions));
            }
            $number = (int)$DB->get_field_sql(
                'SELECT MAX(attempt) FROM {aisafetyhazards_attempt} WHERE aisafetyhazardsid = :aid AND userid = :userid',
                ['aid' => $instance->id, 'userid' => $userid]
            ) + 1;
            $now = time();
            $attempt = (object)[
                'aisafetyhazardsid' => $instance->id,
                'userid' => $userid,
                'attempt' => $number,
                'state' => self::STATE_INPROGRESS,
                'playmode' => $mode,
                'sceneorder' => json_encode($order),
                'timestart' => $now,
                'timemodified' => $now,
            ];
            $attempt->id = $DB->insert_record('aisafetyhazards_attempt', $attempt);
        }
        return self::player_data($instance, $context, $attempt);
    }

    /**
     * The order a new attempt shows a scene's labels in: the hazard labels (with the distractors) and each hazard's
     * rows, shuffled when the activity shuffles.
     *
     * @param stdClass $scene
     * @param bool $shuffle
     * @return array {scene, hazards: real hazard ids, chips: hazard label ids, rows: hazard id => kind => control ids}
     */
    protected static function new_order(stdClass $scene, bool $shuffle): array {
        $hazards = manager::scene_hazards((int)$scene->id);
        $mix = function (array $ids) use ($shuffle): array {
            if ($shuffle) {
                shuffle($ids);
            }
            return $ids;
        };
        $real = array_values(array_filter($hazards, fn($h) => empty($h['distractor'])));
        $rows = [];
        foreach ($real as $h) {
            foreach (catalogue::KINDS as $kind) {
                $rows[(int)$h['id']][$kind] = $mix(array_map(fn($c) => (int)$c['id'], $h['controls'][$kind]));
            }
        }
        return [
            'scene' => (int)$scene->id,
            'hazards' => array_map(fn($h) => (int)$h['id'], $real),
            'chips' => $mix(array_map(fn($h) => (int)$h['id'], $hazards)),
            'rows' => $rows,
        ];
    }

    /**
     * The scene order of an attempt.
     *
     * @param stdClass $attempt
     * @return array list of {@see self::new_order()} entries
     */
    protected static function order(stdClass $attempt): array {
        $order = json_decode((string)$attempt->sceneorder, true);
        return is_array($order) ? $order : [];
    }

    /**
     * The labels dropped in an attempt, by scene.
     *
     * @param int $attemptid
     * @return array sceneid => list of choice records, in the order dropped
     */
    protected static function choices(int $attemptid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('aisafetyhazards_choice', ['attemptid' => $attemptid], 'id') as $c) {
            $out[(int)$c->sceneid][] = $c;
        }
        return $out;
    }

    /**
     * Where a learner is in one scene, worked out from the labels dropped.
     *
     * @param array $item the scene's order entry
     * @param array $hazards the scene's hazards (from {@see manager::scene_hazards()}), by id
     * @param stdClass[] $choices the labels dropped in this scene
     * @param bool $test whether the attempt is a test (a row ends when the learner says it is done)
     * @return array hazard id => {found, chosen: kind => [control id => correct], done: kind => bool, eliminated,
     *     likelihood, consequence, complete}; plus 'complete' => bool for the whole scene under key 0
     */
    public static function progress(array $item, array $hazards, array $choices, bool $test): array {
        $out = [];
        $all = true;
        foreach ($item['hazards'] as $hid) {
            $h = $hazards[$hid] ?? null;
            if (!$h) {
                continue;
            }
            $state = ['found' => false, 'chosen' => array_fill_keys(catalogue::KINDS, []),
                'done' => array_fill_keys(catalogue::KINDS, false), 'eliminated' => false,
                'likelihood' => (int)$h['likelihood'], 'consequence' => (int)$h['consequence'], 'complete' => false];
            $controls = [];
            foreach (catalogue::KINDS as $kind) {
                foreach ($h['controls'][$kind] as $c) {
                    $controls[(int)$c['id']] = $c;
                }
            }
            foreach ($choices as $c) {
                if ((int)$c->hazardid !== $hid) {
                    continue;
                }
                if ($c->kind === 'hazard') {
                    // Practice: found once the right label is on it. Test: any label dropped on it counts (marked at
                    // the end), so the learner always goes on.
                    $state['found'] = $state['found'] || (int)$c->correct === 1 || $test;
                    $state['label'] = $state['label'] ?? (int)$c->itemid;
                } else if ($c->kind === 'done') {
                    $kind = catalogue::KINDS[(int)$c->itemid] ?? '';
                    if ($kind !== '') {
                        $state['done'][$kind] = true;
                    }
                } else if (isset($controls[(int)$c->itemid]) && isset($state['chosen'][$c->kind])) {
                    $state['chosen'][$c->kind][(int)$c->itemid] = (int)$c->correct === 1;
                }
            }
            // Effect of the right controls chosen.
            $l = (int)$h['likelihood'];
            $q = (int)$h['consequence'];
            foreach ($state['chosen'] as $kind => $list) {
                foreach ($list as $cid => $right) {
                    if (!$right) {
                        continue;
                    }
                    $control = $controls[$cid];
                    if ($kind === 'eliminate' && !empty($control['eliminates'])) {
                        $state['eliminated'] = true;
                    }
                    $l -= (int)$control['dl'];
                    $q -= (int)$control['dc'];
                }
            }
            $state['likelihood'] = max(1, $l);
            $state['consequence'] = max(1, $q);
            // Which rows are finished.
            foreach (catalogue::KINDS as $kind) {
                $rights = array_filter($h['controls'][$kind], fn($c) => !empty($c['correct']));
                $chosenright = count(array_filter($state['chosen'][$kind]));
                if ($kind === 'eliminate') {
                    // One answer: Practice until the right one, Test the first label dropped.
                    $state['done'][$kind] = $test ? count($state['chosen'][$kind]) > 0 : $chosenright > 0;
                } else if (!$test) {
                    $state['done'][$kind] = $chosenright >= count($rights);
                } else if (count($state['chosen'][$kind]) >= count($h['controls'][$kind])) {
                    $state['done'][$kind] = true;
                }
            }
            if ($state['eliminated'] || !$h['controls']['reduce']) {
                // Nothing left to control (or, in a test, nothing more to choose for a hazard that could have been
                // eliminated: its other rows are empty).
                $state['done']['reduce'] = true;
                $state['done']['ppe'] = true;
            }
            $state['complete'] = $state['found'] && !in_array(false, $state['done'], true);
            $all = $all && $state['complete'];
            $out[$hid] = $state;
        }
        $out[0] = ['complete' => $all && $out];
        return $out;
    }

    /**
     * The scene's hazards by id.
     *
     * @param int $sceneid
     * @return array
     */
    protected static function hazards_by_id(int $sceneid): array {
        $out = [];
        foreach (manager::scene_hazards($sceneid) as $h) {
            $out[(int)$h['id']] = $h;
        }
        return $out;
    }

    /**
     * Player data for an attempt. Practice and Test get no answer key; Study gets the answers and their effects.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $attempt
     * @return array
     */
    public static function player_data(stdClass $instance, \context $context, stdClass $attempt): array {
        global $DB;
        $mode = (string)$attempt->playmode;
        $study = $mode === self::MODE_STUDY;
        $test = $mode === self::MODE_TEST;
        $order = self::order($attempt);
        $sceneids = array_column($order, 'scene');
        $scenes = $sceneids ? $DB->get_records_list('aisafetyhazards_scene', 'id', $sceneids) : [];
        $choices = self::choices((int)$attempt->id);
        $voiceconfig = voiceover::enabled_for_learners() ? voiceover::learner_config($instance) : null;
        $teacher = has_capability('mod/aisafetyhazards:manage', $context);
        $out = [];
        foreach ($order as $item) {
            $scene = $scenes[$item['scene']] ?? null;
            if (!$scene) {
                continue;
            }
            $hazards = self::hazards_by_id((int)$scene->id);
            $progress = self::progress($item, $hazards, $choices[$scene->id] ?? [], $test);
            $voice = $voiceconfig ? voiceover::playlist($instance, $context, $scene, $voiceconfig) : [];
            $spots = [];
            foreach ($item['hazards'] as $n => $hid) {
                if (!isset($hazards[$hid])) {
                    continue;
                }
                $h = $hazards[$hid];
                $p = $progress[$hid];
                $rows = [];
                foreach (catalogue::KINDS as $kind) {
                    $byid = [];
                    foreach ($h['controls'][$kind] as $c) {
                        $byid[(int)$c['id']] = $c;
                    }
                    $labels = [];
                    foreach ($item['rows'][$hid][$kind] ?? [] as $cid) {
                        if (!isset($byid[$cid])) {
                            continue;
                        }
                        $c = $byid[$cid];
                        $label = ['id' => (int)$cid, 'text' => self::line($c['text'], $context)];
                        $chosen = array_key_exists((int)$cid, $p['chosen'][$kind]);
                        $label['chosen'] = $chosen ? 1 : 0;
                        if ($study || (!$test && $chosen)) {
                            // Study shows every answer; Practice shows the labels already marked.
                            $label += self::control_detail($c, $context);
                            $label['voice'] = $voice['control'][(int)$cid] ?? [];
                        }
                        $labels[] = $label;
                    }
                    $rows[$kind] = $labels;
                }
                $spot = [
                    'id' => (int)$hid,
                    'number' => $n + 1,
                    'x' => (float)$h['x'],
                    'y' => (float)$h['y'],
                    'likelihood' => (int)$h['likelihood'],
                    'consequence' => (int)$h['consequence'],
                    'rating' => catalogue::rating((int)$h['likelihood'], (int)$h['consequence']),
                    'found' => $p['found'] ? 1 : 0,
                    'done' => array_map(fn($d) => $d ? 1 : 0, $p['done']),
                    'complete' => $p['complete'] ? 1 : 0,
                    'rows' => $rows,
                    'voice' => $voice['hazard'][$hid] ?? [],
                ];
                if (!$test) {
                    // How many right labels each row has (not which), so Practice can say "1 of 3 found".
                    $spot['need'] = array_map(
                        fn($row) => count(array_filter($row, fn($c) => !empty($c['correct']))),
                        $h['controls']
                    );
                }
                if ($study || ($p['found'] && !$test)) {
                    $spot['name'] = self::line($h['name'], $context);
                    $spot['why'] = self::para($h['why'], $context);
                } else if ($p['found']) {
                    // Test: the label the learner dropped on the spot.
                    $spot['name'] = self::line($hazards[$p['label'] ?? 0]['name'] ?? '', $context);
                }
                if ($study || !$test) {
                    // The risk now (Practice), or the risk with every right control (Study).
                    $now = $study ? self::best_case($h) : $p;
                    $spot['now'] = ['likelihood' => $now['likelihood'], 'consequence' => $now['consequence'],
                        'eliminated' => $now['eliminated'] ? 1 : 0,
                        'rating' => $now['eliminated'] ? 'eliminated' : catalogue::rating($now['likelihood'], $now['consequence'])];
                }
                $spots[] = $spot;
            }
            $chips = [];
            $used = [];
            foreach ($choices[$scene->id] ?? [] as $c) {
                if ($c->kind === 'hazard' && ($test || (int)$c->correct === 1)) {
                    $used[(int)$c->itemid] = true;
                }
            }
            foreach ($item['chips'] as $hid) {
                if (isset($hazards[$hid])) {
                    $chips[] = ['id' => (int)$hid, 'text' => self::line($hazards[$hid]['name'], $context),
                        'placed' => isset($used[(int)$hid]) ? 1 : 0];
                }
            }
            [$image] = manager::get_scene_image($context, (int)$scene->id);
            $out[] = [
                'sceneid' => (int)$scene->id,
                'number' => count($out) + 1,
                'title' => self::line($scene->title, $context),
                'context' => self::para($scene->context, $context),
                'image' => (string)$image,
                'spots' => $spots,
                'chips' => $chips,
                'complete' => $progress[0]['complete'] ? 1 : 0,
                'voice' => $voice['scene'] ?? [],
                'voicenote' => $teacher ? voiceover::silent_reason($instance, $context, $scene, $voiceconfig) : '',
            ];
        }
        return [
            'attemptid' => (int)$attempt->id,
            'attempt' => (int)$attempt->attempt,
            'mode' => $mode,
            'scenes' => $out,
        ];
    }

    /**
     * What learners see about a control once it is marked (and in Study): right or wrong, why, its level and effect.
     *
     * @param array $c
     * @param \context $context
     * @return array
     */
    protected static function control_detail(array $c, \context $context): array {
        return [
            'correct' => !empty($c['correct']) ? 1 : 0,
            'eliminates' => !empty($c['eliminates']) ? 1 : 0,
            'reason' => self::para($c['reason'], $context),
            'level' => (string)$c['level'],
            'dl' => (int)$c['dl'],
            'dc' => (int)$c['dc'],
        ];
    }

    /**
     * A hazard's risk with every right control applied (Study, and the best result for the safety score).
     *
     * @param array $h
     * @return array {likelihood, consequence, eliminated}
     */
    public static function best_case(array $h): array {
        $l = (int)$h['likelihood'];
        $q = (int)$h['consequence'];
        $eliminated = false;
        foreach (catalogue::KINDS as $kind) {
            foreach ($h['controls'][$kind] as $c) {
                if (empty($c['correct'])) {
                    continue;
                }
                if ($kind === 'eliminate' && !empty($c['eliminates'])) {
                    $eliminated = true;
                }
                if (!$eliminated || $kind === 'eliminate') {
                    $l -= (int)$c['dl'];
                    $q -= (int)$c['dc'];
                }
            }
        }
        return ['likelihood' => max(1, $l), 'consequence' => max(1, $q), 'eliminated' => $eliminated];
    }

    /**
     * Drops one label: a hazard label on a spot of the picture, or a control label on a found hazard.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $attempt
     * @param int $sceneid
     * @param string $kind hazard, eliminate, reduce or ppe
     * @param int $hazardid the spot or hazard the label was dropped on
     * @param int $itemid the label: a hazard id (hazard row) or a control id
     * @return array result for the player
     */
    public static function drop(
        stdClass $instance,
        \context $context,
        stdClass $attempt,
        int $sceneid,
        string $kind,
        int $hazardid,
        int $itemid
    ): array {
        global $DB;
        if ($attempt->state !== self::STATE_INPROGRESS) {
            throw new moodle_exception('attemptfinished', 'mod_aisafetyhazards');
        }
        if ($attempt->playmode === self::MODE_STUDY) {
            throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
        }
        $test = $attempt->playmode === self::MODE_TEST;
        $item = self::scene_item($attempt, $sceneid);
        if (!in_array($hazardid, array_map('intval', $item['hazards']), true)) {
            throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
        }
        $hazards = self::hazards_by_id($sceneid);
        $choices = self::choices((int)$attempt->id)[$sceneid] ?? [];
        $before = self::progress($item, $hazards, $choices, $test);
        $state = $before[$hazardid];
        $h = $hazards[$hazardid];
        $correct = false;
        $reason = '';
        $detail = [];
        if ($kind === 'hazard') {
            if (!in_array($itemid, array_map('intval', $item['chips']), true) || !isset($hazards[$itemid])) {
                throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
            }
            // A label is used once: in Practice once it is on its spot, in Test once it is dropped anywhere.
            $used = array_filter(
                $choices,
                fn($c) => $c->kind === 'hazard' && (int)$c->itemid === $itemid
                && ($test || (int)$c->correct === 1)
            );
            if ($state['found'] || $used) {
                throw new moodle_exception('alreadyplaced', 'mod_aisafetyhazards');
            }
            $correct = $itemid === $hazardid;
            $label = $hazards[$itemid];
            $reason = !empty($label['distractor']) ? get_string(
                'distractor_reason',
                'mod_aisafetyhazards',
                self::line($label['name'], $context)
            ) . ($label['why'] !== '' ? ' ' . self::para($label['why'], $context) : '')
                : ($correct ? self::para($h['why'], $context) : get_string('wrongspot', 'mod_aisafetyhazards'));
        } else if (in_array($kind, catalogue::KINDS, true)) {
            if (!$state['found']) {
                throw new moodle_exception('findhazardfirst', 'mod_aisafetyhazards');
            }
            $control = null;
            foreach ($h['controls'][$kind] as $c) {
                if ((int)$c['id'] === $itemid) {
                    $control = $c;
                }
            }
            if (!$control || array_key_exists($itemid, $state['chosen'][$kind]) || $state['done'][$kind]) {
                throw new moodle_exception('alreadyplaced', 'mod_aisafetyhazards');
            }
            // Rows are played in order: eliminate, then reduce, then PPE.
            foreach (catalogue::KINDS as $earlier) {
                if ($earlier === $kind) {
                    break;
                }
                if (!$state['done'][$earlier]) {
                    throw new moodle_exception('rowsinorder', 'mod_aisafetyhazards');
                }
            }
            $correct = !empty($control['correct']);
            $reason = self::para($control['reason'], $context);
            $detail = self::control_detail($control, $context);
        } else {
            throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
        }
        $DB->insert_record(
            'aisafetyhazards_choice',
            (object)['attemptid' => $attempt->id, 'sceneid' => $sceneid,
            'hazardid' => $hazardid, 'kind' => $kind, 'itemid' => $itemid, 'correct' => $correct ? 1 : 0,
            'timecreated' => time()]
        );
        $DB->set_field('aisafetyhazards_attempt', 'timemodified', time(), ['id' => $attempt->id]);
        $result = self::drop_result($instance, $context, $attempt, $sceneid, $hazardid, $correct, $reason, $detail);
        if (!$test && voiceover::enabled_for_learners()) {
            // What is read now: the hazard once found, or the control's reason (only clips already made).
            $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $sceneid], '*', MUST_EXIST);
            $voice = voiceover::playlist($instance, $context, $scene, voiceover::learner_config($instance));
            $result['voice'] = $kind === 'hazard' ? ($correct ? ($voice['hazard'][$hazardid] ?? []) : [])
                : ($voice['control'][$itemid] ?? []);
        }
        return $result;
    }

    /**
     * Test: the learner has finished choosing labels for a row of one hazard.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $attempt
     * @param int $sceneid
     * @param int $hazardid
     * @param string $kind reduce or ppe
     * @return array result for the player
     */
    public static function done_row(
        stdClass $instance,
        \context $context,
        stdClass $attempt,
        int $sceneid,
        int $hazardid,
        string $kind
    ): array {
        global $DB;
        if ($attempt->state !== self::STATE_INPROGRESS || $attempt->playmode !== self::MODE_TEST) {
            throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
        }
        $item = self::scene_item($attempt, $sceneid);
        $index = array_search($kind, catalogue::KINDS, true);
        if ($index === false || !in_array($hazardid, array_map('intval', $item['hazards']), true)) {
            throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
        }
        $state = self::progress(
            $item,
            self::hazards_by_id($sceneid),
            self::choices((int)$attempt->id)[$sceneid] ?? [],
            true
        )[$hazardid];
        if (!$state['found'] || !$state['done']['eliminate'] || $state['done'][$kind]) {
            throw new moodle_exception('rowsinorder', 'mod_aisafetyhazards');
        }
        $DB->insert_record(
            'aisafetyhazards_choice',
            (object)['attemptid' => $attempt->id, 'sceneid' => $sceneid,
            'hazardid' => $hazardid, 'kind' => 'done', 'itemid' => $index, 'correct' => 0, 'timecreated' => time()]
        );
        return self::drop_result($instance, $context, $attempt, $sceneid, $hazardid, false, '', []);
    }

    /**
     * The scene's entry in an attempt.
     *
     * @param stdClass $attempt
     * @param int $sceneid
     * @return array
     */
    protected static function scene_item(stdClass $attempt, int $sceneid): array {
        foreach (self::order($attempt) as $entry) {
            if ((int)$entry['scene'] === $sceneid) {
                return $entry;
            }
        }
        throw new moodle_exception('invalidlabel', 'mod_aisafetyhazards');
    }

    /**
     * What the player needs after a label: right or wrong (Practice only), the hazard's risk now (Practice only),
     * which rows are done, and whether the scene and the attempt are complete.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $attempt
     * @param int $sceneid
     * @param int $hazardid
     * @param bool $correct
     * @param string $reason
     * @param array $detail
     * @return array
     */
    protected static function drop_result(
        stdClass $instance,
        \context $context,
        stdClass $attempt,
        int $sceneid,
        int $hazardid,
        bool $correct,
        string $reason,
        array $detail
    ): array {
        $test = $attempt->playmode === self::MODE_TEST;
        $hazards = self::hazards_by_id($sceneid);
        $progress = self::progress(
            self::scene_item($attempt, $sceneid),
            $hazards,
            self::choices((int)$attempt->id)[$sceneid] ?? [],
            $test
        );
        $p = $progress[$hazardid];
        $h = $hazards[$hazardid];
        $result = [
            'marked' => $test ? 0 : 1,
            'correct' => !$test && $correct ? 1 : 0,
            'reason' => $test ? '' : $reason,
            'level' => $test ? '' : (string)($detail['level'] ?? ''),
            'eliminates' => !$test && !empty($detail['eliminates']) ? 1 : 0,
            'dl' => $test ? 0 : (int)($detail['dl'] ?? 0),
            'dc' => $test ? 0 : (int)($detail['dc'] ?? 0),
            'found' => $p['found'] ? 1 : 0,
            // Test: the label the learner dropped on the spot (it may be wrong); Practice: the hazard.
            'name' => !$p['found'] ? '' : self::line($test ? ($hazards[$p['label'] ?? 0]['name'] ?? '') : $h['name'], $context),
            'why' => $p['found'] && !$test ? self::para($h['why'], $context) : '',
            'done' => array_map(fn($d) => $d ? 1 : 0, $p['done']),
            'complete' => $p['complete'] ? 1 : 0,
            'scenecomplete' => $progress[0]['complete'] ? 1 : 0,
            'allcomplete' => self::all_complete($attempt) ? 1 : 0,
            'now' => null,
            'voice' => [],
        ];
        if (!$test) {
            $result['now'] = ['likelihood' => $p['likelihood'], 'consequence' => $p['consequence'],
                'eliminated' => $p['eliminated'] ? 1 : 0,
                'rating' => $p['eliminated'] ? 'eliminated' : catalogue::rating($p['likelihood'], $p['consequence'])];
        }
        return $result;
    }

    /**
     * Whether every scene of an attempt is complete (Study: always).
     *
     * @param stdClass $attempt
     * @return bool
     */
    public static function all_complete(stdClass $attempt): bool {
        if ($attempt->playmode === self::MODE_STUDY) {
            return true;
        }
        $choices = self::choices((int)$attempt->id);
        $test = $attempt->playmode === self::MODE_TEST;
        foreach (self::order($attempt) as $item) {
            $progress = self::progress($item, self::hazards_by_id((int)$item['scene']), $choices[$item['scene']] ?? [], $test);
            if (!$progress[0]['complete']) {
                return false;
            }
        }
        return (bool)self::order($attempt);
    }

    /**
     * Marks every scene of an attempt: right labels, wrong labels, the right labels it needed, and the risk removed.
     *
     * @param stdClass $attempt
     * @return array {score, safety, right, wrong, needed, scenes: sceneid => per-scene marks}
     */
    public static function marks(stdClass $attempt): array {
        $choices = self::choices((int)$attempt->id);
        $test = $attempt->playmode === self::MODE_TEST;
        $right = 0;
        $wrong = 0;
        $needed = 0;
        $removed = 0;
        $possible = 0;
        $scenes = [];
        foreach (self::order($attempt) as $item) {
            $hazards = self::hazards_by_id((int)$item['scene']);
            $list = $choices[$item['scene']] ?? [];
            $progress = self::progress($item, $hazards, $list, $test);
            $s = ['right' => 0, 'wrong' => 0, 'needed' => 0, 'hazards' => []];
            foreach ($item['hazards'] as $hid) {
                if (!isset($hazards[$hid])) {
                    continue;
                }
                $h = $hazards[$hid];
                $p = $progress[$hid];
                $best = self::best_case($h);
                // Needed: find it, its right elimination, and (unless it can be eliminated) every right control.
                $need = 2;
                if (!$best['eliminated']) {
                    $need += count(
                        array_filter(
                            array_merge($h['controls']['reduce'], $h['controls']['ppe']),
                            fn($c) => !empty($c['correct'])
                        )
                    );
                }
                $start = (int)$h['likelihood'] * (int)$h['consequence'];
                $end = $p['eliminated'] ? 0 : $p['likelihood'] * $p['consequence'];
                $bestend = $best['eliminated'] ? 0 : $best['likelihood'] * $best['consequence'];
                $removed += max(0, $start - $end);
                $possible += max(0, $start - $bestend);
                $s['needed'] += $need;
                $s['hazards'][$hid] = ['state' => $p, 'best' => $best, 'start' => $start, 'end' => $end];
            }
            foreach ($list as $c) {
                if ($c->kind === 'done') {
                    continue;
                }
                if ((int)$c->correct === 1) {
                    $s['right']++;
                } else {
                    $s['wrong']++;
                }
            }
            $right += $s['right'];
            $wrong += $s['wrong'];
            $needed += $s['needed'];
            $scenes[(int)$item['scene']] = $s;
        }
        $total = $needed + $wrong;
        return [
            'score' => $total ? round(100 * min($right, $needed) / $total, 2) : 0,
            'safety' => $possible ? round(100 * min($removed, $possible) / $possible, 2) : 100,
            'right' => $right,
            'wrong' => $wrong,
            'needed' => $needed,
            'scenes' => $scenes,
        ];
    }

    /**
     * Finishes an attempt: score, safety, grade, completion and event.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param stdClass $attempt
     * @return array summary
     */
    public static function finish_attempt(stdClass $instance, $cm, stdClass $course, \context $context, stdClass $attempt): array {
        global $DB, $CFG;
        if ($attempt->state === self::STATE_INPROGRESS) {
            if (!self::all_complete($attempt)) {
                throw new moodle_exception('scenesleft', 'mod_aisafetyhazards');
            }
            if ($attempt->playmode === self::MODE_STUDY) {
                // Studying every scene is complete: it counts in full where Study is the graded mode.
                $attempt->score = 100;
                $attempt->safety = 100;
            } else {
                $marks = self::marks($attempt);
                $attempt->score = $marks['score'];
                $attempt->safety = $marks['safety'];
            }
            $attempt->state = self::STATE_FINISHED;
            $attempt->timefinish = time();
            $attempt->timemodified = $attempt->timefinish;
            $DB->update_record('aisafetyhazards_attempt', $attempt);
            require_once($CFG->dirroot . '/mod/aisafetyhazards/lib.php');
            aisafetyhazards_update_grades($instance, (int)$attempt->userid);
            require_once($CFG->libdir . '/completionlib.php');
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
            }
            \mod_aisafetyhazards\event\attempt_finished::create(
                [
                'objectid' => $attempt->id,
                'context' => $context,
                'relateduserid' => $attempt->userid,
                'other' => ['score' => (float)$attempt->score],
                ]
            )->trigger();
        }
        return self::summary($instance, $context, $attempt);
    }

    /**
     * Summary of a finished attempt: the score, the safety bar, and each scene's hazards with the risk they started
     * with and were left with, the right controls missed and the wrong ones chosen.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $attempt
     * @return array
     */
    public static function summary(stdClass $instance, \context $context, stdClass $attempt): array {
        global $DB;
        $mode = (string)$attempt->playmode;
        $marks = self::marks($attempt);
        $order = self::order($attempt);
        $sceneids = array_column($order, 'scene');
        $scenes = $sceneids ? $DB->get_records_list('aisafetyhazards_scene', 'id', $sceneids) : [];
        $choices = self::choices((int)$attempt->id);
        $recap = [];
        foreach ($order as $item) {
            $scene = $scenes[$item['scene']] ?? null;
            if (!$scene) {
                continue;
            }
            $hazards = self::hazards_by_id((int)$scene->id);
            $chosen = [];
            $labelled = [];
            foreach ($choices[$scene->id] ?? [] as $c) {
                if ($c->kind !== 'hazard' && $c->kind !== 'done') {
                    $chosen[(int)$c->itemid] = true;
                } else if ($c->kind === 'hazard' && !(int)$c->correct && $mode === self::MODE_TEST) {
                    // Test: the wrong hazard label the learner put on this spot.
                    $labelled[(int)$c->hazardid] = (int)$c->itemid;
                }
            }
            $list = [];
            foreach ($item['hazards'] as $hid) {
                if (!isset($hazards[$hid]) || !isset($marks['scenes'][$scene->id]['hazards'][$hid])) {
                    continue;
                }
                $h = $hazards[$hid];
                $m = $marks['scenes'][$scene->id]['hazards'][$hid];
                $state = $mode === self::MODE_STUDY ? self::best_case($h) : $m['state'];
                $missed = [];
                $wrong = [];
                foreach (catalogue::KINDS as $kind) {
                    foreach ($h['controls'][$kind] as $c) {
                        $picked = isset($chosen[(int)$c['id']]);
                        if (
                            !empty($c['correct']) && !$picked && $mode !== self::MODE_STUDY
                                && !($m['best']['eliminated'] && $kind !== 'eliminate')
                        ) {
                            $missed[] = ['text' => self::line($c['text'], $context), 'reason' => self::para($c['reason'], $context),
                                'kind' => $kind];
                        }
                        if (empty($c['correct']) && $picked) {
                            $wrong[] = ['text' => self::line($c['text'], $context), 'reason' => self::para($c['reason'], $context),
                                'kind' => $kind];
                        }
                    }
                }
                $list[] = [
                    'name' => self::line($h['name'], $context),
                    'start' => catalogue::rating((int)$h['likelihood'], (int)$h['consequence']),
                    'startl' => (int)$h['likelihood'],
                    'startc' => (int)$h['consequence'],
                    'end' => !empty($state['eliminated']) ? 'eliminated'
                        : catalogue::rating((int)$state['likelihood'], (int)$state['consequence']),
                    'endl' => (int)$state['likelihood'],
                    'endc' => (int)$state['consequence'],
                    'missed' => $missed,
                    'wrong' => $wrong,
                    'labelled' => isset($labelled[$hid]) ? self::line($hazards[$labelled[$hid]]['name'] ?? '', $context) : '',
                    'perfect' => !$missed && !$wrong && !isset($labelled[$hid]) ? 1 : 0,
                ];
            }
            [$image] = manager::get_scene_image($context, (int)$scene->id);
            $recap[] = [
                'number' => count($recap) + 1,
                'title' => self::line($scene->title, $context),
                'image' => (string)$image,
                'hazards' => $list,
            ];
        }
        $finished = count(self::finished_attempts($instance, (int)$attempt->userid, $mode));
        $limited = $mode === self::graded_mode($instance) && $instance->maxattempts;
        $passmark = $mode === self::MODE_TEST ? (int)$instance->passmark : 0;
        $score = (int)round((float)$attempt->score);
        return [
            'recap' => $recap,
            'score' => $score,
            'safety' => (int)round((float)($attempt->safety ?? $marks['safety'])),
            'right' => $marks['right'],
            'wrong' => $marks['wrong'],
            'needed' => $marks['needed'],
            'mode' => $mode,
            'passmark' => $passmark,
            'passed' => $passmark > 0 && (float)$attempt->score >= $passmark ? 1 : 0,
            'canretake' => in_array($mode, self::modes($instance), true)
                && (!$limited || $finished < (int)$instance->maxattempts) ? 1 : 0,
            'attemptsleft' => $limited ? max(0, (int)$instance->maxattempts - $finished) : -1,
            'next' => self::next_mode($instance, $mode, (int)$attempt->userid),
        ];
    }

    /**
     * The mode to suggest after an attempt: Practice after Study, Test after Practice ('' when there is none).
     *
     * @param stdClass $instance
     * @param string $mode
     * @param int $userid
     * @return string
     */
    public static function next_mode(stdClass $instance, string $mode, int $userid): string {
        $modes = self::modes($instance);
        $i = array_search($mode, $modes, true);
        $next = $i === false ? '' : ($modes[$i + 1] ?? '');
        return $next !== '' && self::can_start($instance, $userid, $next) ? $next : '';
    }

    /**
     * Review of an attempt for teachers: each scene's marks.
     *
     * @param stdClass $attempt
     * @param \context $context
     * @return array
     */
    public static function review(stdClass $attempt, \context $context): array {
        global $DB;
        $marks = self::marks($attempt);
        $rows = [];
        $order = self::order($attempt);
        $scenes = $order ? $DB->get_records_list('aisafetyhazards_scene', 'id', array_column($order, 'scene')) : [];
        foreach ($order as $i => $item) {
            $scene = $scenes[$item['scene']] ?? null;
            $m = $marks['scenes'][$item['scene']] ?? null;
            if (!$scene || !$m) {
                continue;
            }
            $rows[] = [
                'number' => $i + 1,
                'title' => self::line($scene->title, $context),
                'right' => $m['right'],
                'wrong' => $m['wrong'],
                'needed' => $m['needed'],
            ];
        }
        return $rows;
    }

    /**
     * Deletes all learner data of an activity.
     *
     * @param int $aid
     */
    public static function delete_all_user_data(int $aid): void {
        global $DB;
        $ids = $DB->get_fieldset_select('aisafetyhazards_attempt', 'id', 'aisafetyhazardsid = :aid', ['aid' => $aid]);
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('aisafetyhazards_choice', "attemptid $insql", $params);
        }
        $DB->delete_records('aisafetyhazards_attempt', ['aisafetyhazardsid' => $aid]);
    }

    /**
     * Deletes one learner's data in an activity.
     *
     * @param int $aid
     * @param int $userid
     */
    public static function delete_user_data(int $aid, int $userid): void {
        global $DB;
        $ids = $DB->get_fieldset_select(
            'aisafetyhazards_attempt',
            'id',
            'aisafetyhazardsid = :aid AND userid = :userid',
            ['aid' => $aid, 'userid' => $userid]
        );
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('aisafetyhazards_choice', "attemptid $insql", $params);
            $DB->delete_records_select('aisafetyhazards_attempt', "id $insql", $params);
        }
        $DB->delete_records('aisafetyhazards_ailog', ['aisafetyhazardsid' => $aid, 'userid' => $userid]);
        $DB->delete_records('aisafetyhazards_aireq', ['aisafetyhazardsid' => $aid, 'userid' => $userid]);
    }

    /**
     * Deletes attempts chosen on the report.
     *
     * @param stdClass $instance
     * @param int[] $attemptids
     * @return int number deleted
     */
    public static function delete_attempts(stdClass $instance, array $attemptids): int {
        global $DB, $CFG;
        if (!$attemptids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
        $params['aid'] = $instance->id;
        $attempts = $DB->get_records_select('aisafetyhazards_attempt', "id $insql AND aisafetyhazardsid = :aid", $params);
        if (!$attempts) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($attempts), SQL_PARAMS_NAMED);
        $DB->delete_records_select('aisafetyhazards_choice', "attemptid $insql", $params);
        $DB->delete_records_select('aisafetyhazards_attempt', "id $insql", $params);
        require_once($CFG->dirroot . '/mod/aisafetyhazards/lib.php');
        foreach (array_unique(array_map(fn($a) => (int)$a->userid, $attempts)) as $userid) {
            aisafetyhazards_update_grades($instance, $userid);
        }
        return count($attempts);
    }

    /**
     * URL of the activity.
     *
     * @param int $cmid
     * @return moodle_url
     */
    public static function view_url(int $cmid): moodle_url {
        return new moodle_url('/mod/aisafetyhazards/view.php', ['id' => $cmid]);
    }
}
