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

use mod_aisafetyhazards\completion\custom_completion;
use mod_aisafetyhazards\local\learning;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Custom completion rule tests.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\completion\custom_completion
 */
#[CoversClass(custom_completion::class)]
final class completion_test extends \advanced_testcase {
    /**
     * The activity is complete once an attempt with every scene is finished.
     */
    public function test_all_scenes(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $sh->create_instance(
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionallscenes' => 1]
        );
        $scene = $sh->create_scene($instance, 'A');
        $cm = get_fast_modinfo($course)->get_cm(get_coursemodule_from_instance('aisafetyhazards', $instance->id)->id);
        $context = \context_module::instance($cm->id);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $instance->id]);
        $check = fn() => (new custom_completion($cm, (int)$student->id))->get_state('completionallscenes');
        $this->assertSame(COMPLETION_INCOMPLETE, $check());
        $data = learning::start_attempt($instance, $context, (int)$student->id, 'practice');
        $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $data['attemptid']]);
        learning::drop(
            $instance,
            $context,
            $attempt,
            (int)$scene->id,
            'hazard',
            (int)$data['scenes'][0]['spots'][0]['id'],
            (int)$data['scenes'][0]['spots'][0]['id']
        );
        $this->assertSame(COMPLETION_INCOMPLETE, $check());
        $attempt = $sh->play_attempt($instance, $context, (int)$attempt->id);
        learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        $this->assertSame(COMPLETION_COMPLETE, $check());
        $this->assertSame(['completionallscenes', 'completionpasstest'], custom_completion::get_defined_custom_rules());
        $this->assertNotEmpty((new custom_completion($cm, (int)$student->id))->get_custom_rule_descriptions());
    }

    /**
     * "Pass the test": complete only when a test attempt reaches the pass mark; practice never counts.
     */
    public function test_pass_test(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $sh->create_instance(
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionpasstest' => 1, 'practicemode' => 1, 'testmode' => 1, 'passmark' => 100]
        );
        $scene = $sh->create_scene($instance, 'A');
        $cm = get_fast_modinfo($course)->get_cm(get_coursemodule_from_instance('aisafetyhazards', $instance->id)->id);
        $context = \context_module::instance($cm->id);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $instance->id]);
        $this->assertEquals(1, $cm->customdata['customcompletionrules']['completionpasstest']);
        $check = fn() => (new custom_completion($cm, (int)$student->id))->get_state('completionpasstest');
        $play = function (string $mode, bool $perfect) use ($instance, $context, $student, $scene, $cm, $course, $DB, $sh) {
            $data = learning::start_attempt($instance, $context, (int)$student->id, $mode);
            if (!$perfect) {
                // A distractor on the first spot.
                $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $data['attemptid']]);
                $distractor = (int)$DB->get_field(
                    'aisafetyhazards_hazard',
                    'id',
                    ['sceneid' => $scene->id, 'distractor' => 1,
                    'sortorder' => 4]
                );
                learning::drop(
                    $instance,
                    $context,
                    $attempt,
                    (int)$scene->id,
                    'hazard',
                    (int)$data['scenes'][0]['spots'][0]['id'],
                    $distractor
                );
            }
            $attempt = $sh->play_attempt($instance, $context, (int)$data['attemptid']);
            learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        };
        $play('practice', true);
        $this->assertSame(COMPLETION_INCOMPLETE, $check());
        $play('test', false);
        $this->assertSame(COMPLETION_INCOMPLETE, $check());
        $play('test', true);
        $this->assertSame(COMPLETION_COMPLETE, $check());
    }
}
