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

namespace mod_aisafetyhazards;

use mod_aisafetyhazards\local\learning;
use mod_aisafetyhazards\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for playing scenes: dropping labels, the risk they leave, scores and modes.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\learning
 */
#[CoversClass(learning::class)]
final class learning_test extends \advanced_testcase {
    /**
     * An activity with one trenching scene and an enrolled student.
     *
     * @param array $settings
     * @param int $scenes
     * @return array [course, cm, context, instance, student, scenes, generator]
     */
    protected function setup_activity(array $settings = [], int $scenes = 1): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $plugin = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $plugin->create_instance(['course' => $course->id] + $settings);
        $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id);
        $context = \context_module::instance($cm->id);
        $list = [];
        for ($i = 1; $i <= $scenes; $i++) {
            $list[] = $plugin->create_scene($instance, 'Scene ' . $i);
        }
        $instance = $DB->get_record('aisafetyhazards', ['id' => $instance->id]);
        $this->setUser($student);
        return [$course, $cm, $context, $instance, $student, $list, $plugin];
    }

    /**
     * The scene's hazards: the three real ones by name, and the distractors.
     *
     * @param int $sceneid
     * @return array [trench, truck, fall, distractors]
     */
    protected function hazards(int $sceneid): array {
        $all = manager::scene_hazards($sceneid);
        return [$all[0], $all[1], $all[2], array_slice($all, 3)];
    }

    /**
     * The id of a control by its position in a row.
     *
     * @param array $h
     * @param string $kind
     * @param bool $correct the first right (or first wrong) label
     * @return int
     */
    protected function control(array $h, string $kind, bool $correct = true): int {
        foreach ($h['controls'][$kind] as $c) {
            if ((bool)$c['correct'] === $correct) {
                return (int)$c['id'];
            }
        }
        $this->fail('No such label');
    }

    /**
     * Practice and Test send no answer key; Study sends every answer and the best risk.
     */
    public function test_answer_key(): void {
        $this->resetAfterTest();
        [, , $context, $instance, $student] = $this->setup_activity(['testmode' => 1]);
        foreach (['practice', 'test'] as $mode) {
            $data = learning::start_attempt($instance, $context, (int)$student->id, $mode);
            $scene = $data['scenes'][0];
            $this->assertCount(3, $scene['spots']);
            $this->assertCount(5, $scene['chips']);
            foreach ($scene['spots'] as $spot) {
                $this->assertArrayNotHasKey('name', $spot);
                foreach ($spot['rows'] as $row) {
                    foreach ($row as $label) {
                        $this->assertArrayNotHasKey('correct', $label);
                        $this->assertArrayNotHasKey('reason', $label);
                    }
                }
            }
            $this->assertSame($mode === 'practice', isset($scene['spots'][0]['now']), 'Only Practice shows the risk now.');
        }
        $data = learning::start_attempt($instance, $context, (int)$student->id, 'study');
        $spot = $data['scenes'][0]['spots'][0];
        $this->assertSame('Trench wall collapse', $spot['name']);
        $this->assertSame(1, $spot['rows']['eliminate'][0]['correct'] + $spot['rows']['eliminate'][1]['correct']
            + $spot['rows']['eliminate'][2]['correct']);
        $this->assertSame(['likelihood' => 1, 'consequence' => 3, 'eliminated' => 0, 'rating' => 'low'], $spot['now']);
        $this->assertSame('eliminated', $data['scenes'][0]['spots'][1]['now']['rating']);
    }

    /**
     * Practice: wrong labels are marked and cost marks; right controls lower the likelihood or the consequence.
     */
    public function test_practice(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $context, $instance, $student, $scenes] = $this->setup_activity();
        $sceneid = (int)$scenes[0]->id;
        [$trench, $truck, , $distractors] = $this->hazards($sceneid);
        $data = learning::start_attempt($instance, $context, (int)$student->id, 'practice');
        $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $data['attemptid']]);
        $tid = (int)$trench['id'];

        // Controls wait until the hazard is found; a distractor or another hazard's label does not find it.
        $this->expect_error('findhazardfirst', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            $sceneid,
            'eliminate',
            $tid,
            $this->control($trench, 'eliminate')
        ));
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $tid, (int)$distractors[0]['id']);
        $this->assertSame([1, 0, 0], [$r['marked'], $r['correct'], $r['found']]);
        $this->assertStringContainsString('Hot work sparks', $r['reason']);
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $tid, (int)$truck['id']);
        $this->assertSame(0, $r['found']);
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $tid, $tid);
        $this->assertSame([1, 1, 'Trench wall collapse'], [$r['correct'], $r['found'], $r['name']]);
        $this->assertSame('extreme', $r['now']['rating']);
        $this->expect_error('alreadyplaced', fn() => learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $tid, $tid));

        // Rows in order: eliminate first.
        $this->expect_error('rowsinorder', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            $sceneid,
            'reduce',
            $tid,
            $this->control($trench, 'reduce')
        ));
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'eliminate', $tid, $this->control($trench, 'eliminate'));
        $this->assertSame([1, 0, 1], [$r['correct'], $r['eliminates'], $r['done']['eliminate']]);
        $this->assertSame(4, $r['now']['likelihood'], '"Cannot be eliminated" removes nothing.');

        // Shoring lowers the likelihood by 3; a wrong label lowers nothing; the hard hat lowers the consequence.
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'reduce', $tid, $this->control($trench, 'reduce'));
        $this->assertSame(['engineering', 3, 0, 1, 5], [$r['level'], $r['dl'], $r['dc'], $r['now']['likelihood'],
            $r['now']['consequence']]);
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'reduce', $tid, $this->control($trench, 'reduce', false));
        $this->assertSame([0, 1, 5, 0], [$r['correct'], $r['now']['likelihood'], $r['now']['consequence'], $r['done']['reduce']]);
        $this->expect_error('rowsinorder', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            $sceneid,
            'ppe',
            $tid,
            $this->control($trench, 'ppe')
        ));

        // The truck: the right elimination removes the hazard and its other rows.
        $hid = (int)$truck['id'];
        learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $hid, $hid);
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'eliminate', $hid, $this->control(
            $truck,
            'eliminate',
            false
        ));
        $this->assertSame([0, 0], [$r['correct'], $r['done']['eliminate']], 'Practice: try again until it is right.');
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'eliminate', $hid, $this->control($truck, 'eliminate'));
        $this->assertSame([1, 'eliminated', 1], [$r['eliminates'], $r['now']['rating'], $r['complete']]);

        $this->expect_error('scenesleft', fn() => learning::finish_attempt($instance, $cm, $course, $context, $attempt));
        // The generator plays the rest right.
        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards')->play_attempt(
            $instance,
            $context,
            (int)$attempt->id
        );
        $summary = learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        // Needed 13 (find and eliminate each hazard, 4 trench controls, 3 fall controls); 4 wrong.
        $this->assertSame([13, 13, 4], [$summary['needed'], $summary['right'], $summary['wrong']]);
        $this->assertSame(76, $summary['score']);
        $this->assertSame(100, $summary['safety']);
        $recap = $summary['recap'][0]['hazards'];
        $this->assertSame(['extreme', 'low'], [$recap[0]['start'], $recap[0]['end']]);
        $this->assertSame('eliminated', $recap[1]['end']);
        $this->assertSame(['high', 'medium'], [$recap[2]['start'], $recap[2]['end']]);
        $this->assertCount(1, $recap[0]['wrong']);
        $this->assertSame(0, $recap[0]['perfect']);
        $this->assertSame(1, $recap[2]['perfect']);
        $this->assertEquals(76.47, (float)$DB->get_field('aisafetyhazards_attempt', 'score', ['id' => $attempt->id]));
        $this->expect_error('attemptfinished', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            $sceneid,
            'hazard',
            $tid,
            $tid
        ));
    }

    /**
     * Test: nothing is marked while playing; any label on a spot lets the learner go on, and is marked at the end.
     */
    public function test_test_mode(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $context, $instance, $student, $scenes] = $this->setup_activity(['testmode' => 1, 'passmark' => 80]);
        $sceneid = (int)$scenes[0]->id;
        [$trench, $truck, $fall, $distractors] = $this->hazards($sceneid);
        $data = learning::start_attempt($instance, $context, (int)$student->id, 'test');
        $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $data['attemptid']]);
        $tid = (int)$trench['id'];
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $tid, (int)$distractors[0]['id']);
        $this->assertSame([0, 0, '', 1, 'Hot work sparks'], [$r['marked'], $r['correct'], $r['reason'], $r['found'], $r['name']]);
        $this->assertNull($r['now']);
        // The distractor label is used.
        $this->expect_error('alreadyplaced', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            $sceneid,
            'hazard',
            (int)$fall['id'],
            (int)$distractors[0]['id']
        ));
        // One elimination only, even a wrong one; the reduce row ends when the learner says so.
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'eliminate', $tid, $this->control($trench, 'eliminate'));
        $this->assertSame(1, $r['done']['eliminate']);
        $this->expect_error('alreadyplaced', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            $sceneid,
            'eliminate',
            $tid,
            $this->control($trench, 'eliminate', false)
        ));
        learning::drop($instance, $context, $attempt, $sceneid, 'reduce', $tid, $this->control($trench, 'reduce'));
        $r = learning::done_row($instance, $context, $attempt, $sceneid, $tid, 'reduce');
        $this->assertSame(1, $r['done']['reduce']);
        $this->expect_error('rowsinorder', fn() => learning::done_row($instance, $context, $attempt, $sceneid, $tid, 'reduce'));
        $r = learning::done_row($instance, $context, $attempt, $sceneid, $tid, 'ppe');
        $this->assertSame(1, $r['complete']);
        // The truck: a wrong elimination ends its rows too (it could have been removed).
        $hid = (int)$truck['id'];
        learning::drop($instance, $context, $attempt, $sceneid, 'hazard', $hid, $hid);
        $r = learning::drop($instance, $context, $attempt, $sceneid, 'eliminate', $hid, $this->control(
            $truck,
            'eliminate',
            false
        ));
        $this->assertSame(1, $r['complete']);
        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards')->play_attempt(
            $instance,
            $context,
            (int)$attempt->id
        );
        $summary = learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        // Right: trench elimination and shoring, fall 5 = 7 (plus the truck found); wrong: distractor, truck elimination.
        $this->assertSame([13, 8, 2], [$summary['needed'], $summary['right'], $summary['wrong']]);
        $this->assertSame(53, $summary['score']);
        $this->assertSame(0, $summary['passed']);
        $recap = $summary['recap'][0]['hazards'];
        $this->assertSame('Hot work sparks', $recap[0]['labelled']);
        $this->assertCount(3, $recap[0]['missed']);
        $this->assertSame('extreme', $recap[1]['end']);
        // Safety: trench 20 → 5 (of 17 possible), truck 15 → 15 (of 15), fall 12 → 4 (of 8): 23 of 40.
        $this->assertSame(58, $summary['safety']);
        $this->assertSame('', $summary['next']);
    }

    /**
     * A perfect test passes; Study counts in full and suggests Practice next.
     */
    public function test_study_and_perfect_test(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm, $context, $instance, $student, , $plugin] = $this->setup_activity(['testmode' => 1], 2);
        $data = learning::start_attempt($instance, $context, (int)$student->id, 'study');
        $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $data['attemptid']]);
        $this->expect_error('invalidlabel', fn() => learning::drop(
            $instance,
            $context,
            $attempt,
            (int)$data['scenes'][0]['sceneid'],
            'hazard',
            (int)$data['scenes'][0]['spots'][0]['id'],
            (int)$data['scenes'][0]['spots'][0]['id']
        ));
        $summary = learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        $this->assertSame([100, 'practice'], [$summary['score'], $summary['next']]);
        $this->assertSame([], $summary['recap'][0]['hazards'][0]['missed']);

        $data = learning::start_attempt($instance, $context, (int)$student->id, 'test');
        $attempt = $plugin->play_attempt($instance, $context, (int)$data['attemptid']);
        $summary = learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        $this->assertSame([100, 100, 1, 26], [$summary['score'], $summary['safety'], $summary['passed'], $summary['needed']]);
    }

    /**
     * Attempts allowed counts the graded mode; grading methods use each attempt's score.
     */
    public function test_attempts_and_grades(): void {
        $this->resetAfterTest();
        [$course, $cm, $context, $instance, $student, , $plugin] = $this->setup_activity(['maxattempts' => 2]);
        foreach ([true, false] as $mistakes) {
            $this->assertTrue(learning::can_start($instance, (int)$student->id));
            $data = learning::start_attempt($instance, $context, (int)$student->id, 'practice');
            $attempt = $plugin->play_attempt($instance, $context, (int)$data['attemptid'], $mistakes);
            learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        }
        $this->assertFalse(learning::can_start($instance, (int)$student->id));
        $this->assertTrue(learning::can_start($instance, (int)$student->id, 'study'));
        $this->expect_error('nomoreattempts', fn() => learning::start_attempt(
            $instance,
            $context,
            (int)$student->id,
            'practice'
        ));
        // With mistakes: 13 right of 13 needed, 3 wrong (a distractor and two wrong reduce labels).
        $expected = [manager::GRADE_HIGHEST => 100, manager::GRADE_AVERAGE => 90.625, manager::GRADE_FIRST => 81.25,
            manager::GRADE_LAST => 100];
        foreach ($expected as $method => $grade) {
            $instance->grademethod = $method;
            $grades = manager::get_user_grades($instance, (int)$student->id);
            $this->assertEquals($grade, (float)$grades[$student->id]->rawgrade, 'Method ' . $method, 0.01);
        }
    }

    /**
     * Only scenes with a picture and complete hazards are played.
     */
    public function test_only_ready_scenes(): void {
        $this->resetAfterTest();
        [, , $context, $instance, $student, , $plugin] = $this->setup_activity([], 0);
        $this->expect_error('noscenes', fn() => learning::start_attempt($instance, $context, (int)$student->id));
        $plugin->create_scene($instance, 'No picture', null, '');
        $ready = $plugin->create_scene($instance, 'Ready');
        $data = learning::start_attempt($instance, $context, (int)$student->id);
        $this->assertSame([(int)$ready->id], array_column($data['scenes'], 'sceneid'));
        $this->assertSame(['study', 'practice'], learning::modes($instance));
        $this->assertSame('practice', learning::graded_mode($instance));
    }

    /**
     * Runs a call that must fail with a plugin error.
     *
     * @param string $code
     * @param callable $call
     */
    protected function expect_error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }
}
