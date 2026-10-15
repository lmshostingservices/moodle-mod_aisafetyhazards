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

use mod_aisafetyhazards\local\lesson;
use mod_aisafetyhazards\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the lesson prompt, reading AI drafts and importing them.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\lesson
 */
#[CoversClass(lesson::class)]
final class lesson_test extends \advanced_testcase {
    /**
     * The fixture draft as decoded JSON.
     *
     * @return array
     */
    protected function draft(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/draft.json'), true);
    }

    /**
     * A fenced draft with text around it is read; hazards, distractors and the tags of each control are kept.
     */
    public function test_parse(): void {
        $this->resetAfterTest();
        $raw = "Here you go:\n\x60\x60\x60json\n" . json_encode($this->draft()) . "\n\x60\x60\x60\nStay safe!";
        $scenes = lesson::parse($raw)['scenes'];
        $this->assertCount(1, $scenes);
        $hazards = $scenes[0]['hazards'];
        $this->assertCount(5, $hazards);
        $this->assertSame([0, 0, 0, 1, 1], array_column($hazards, 'distractor'));
        $this->assertSame('Hot work sparks', $hazards[3]['name']);
        [$trench, $truck] = $hazards;
        $this->assertSame([4, 5], [$trench['likelihood'], $trench['consequence']]);
        $this->assertSame('the unsupported trench wall on the left', $trench['anchor']);
        $shoring = $trench['controls']['reduce'][0];
        $this->assertSame(['engineering', 3, 0, 1], [$shoring['level'], $shoring['dl'], $shoring['dc'], $shoring['correct']]);
        $this->assertSame(['ppe', 0, 1], [$trench['controls']['ppe'][0]['level'], $trench['controls']['ppe'][0]['dl'],
            $trench['controls']['ppe'][0]['dc']]);
        // A wrong label never lowers the risk.
        $sign = $trench['controls']['reduce'][3];
        $this->assertSame([0, 0, 0], [$sign['correct'], $sign['dl'], $sign['dc']]);
        // The right answer "Cannot be eliminated" removes nothing; the drive-through route removes the hazard.
        $cannot = $trench['controls']['eliminate'][0];
        $this->assertSame([1, 0], [$cannot['correct'], $cannot['eliminates']]);
        $this->assertSame([1, 1, 'elimination'], [$truck['controls']['eliminate'][0]['correct'],
            $truck['controls']['eliminate'][0]['eliminates'], $truck['controls']['eliminate'][0]['level']]);
        $this->assertSame([], $truck['controls']['reduce']);
        foreach (['nothing', '{"scenes": []}'] as $bad) {
            try {
                lesson::parse($bad);
                $this->fail('Accepted ' . $bad);
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['lessoninvalid', 'lessonempty']);
            }
        }
    }

    /**
     * Scenes without exactly three hazards, or with a hazard missing its rows, are dropped.
     */
    public function test_incomplete_scenes_are_dropped(): void {
        $draft = $this->draft();
        $good = $draft['scenes'][0];
        $two = $good;
        $two['title'] = 'Two hazards';
        array_pop($two['hazards']);
        $norows = $good;
        $norows['title'] = 'No PPE row';
        $norows['hazards'][0]['ppe'] = [];
        $tworight = $good;
        $tworight['title'] = 'Two right eliminations';
        $tworight['hazards'][0]['eliminate'][1]['correct'] = true;
        $notitle = $good;
        $notitle['title'] = '';
        $scenes = lesson::clean(['scenes' => [$two, $norows, $good, $tworight, $notitle]])['scenes'];
        $this->assertSame([$good['title']], array_column($scenes, 'title'));
    }

    /**
     * Importing creates the scene, its hazards and every control row.
     */
    public function test_import(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('aisafetyhazards', ['course' => $course->id]);
        $this->assertSame(['scenes' => 1], lesson::import($instance, $this->draft()));
        $scene = $DB->get_record('aisafetyhazards_scene', ['aisafetyhazardsid' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('Digging the trench', $scene->title);
        $this->assertSame(5, $DB->count_records('aisafetyhazards_hazard', ['sceneid' => $scene->id]));
        $hazards = manager::scene_hazards((int)$scene->id);
        $this->assertTrue(manager::hazards_ok($hazards));
        // Hazards with no position get a guessed one the teacher still has to check.
        $this->assertSame([2, 2, 2, 0, 0], array_map('intval', array_column($hazards, 'placed')));
        $this->assertCount(4, $hazards[0]['controls']['reduce']);
    }

    /**
     * The prompt names the task, the language, the number of scenes and the control levels.
     */
    public function test_prompt(): void {
        $this->resetAfterTest();
        $instance = (object)['industry' => 'construction', 'subindustry' => 'civil', 'task' => 'trenching',
            'customindustry' => '', 'customsubindustry' => '', 'customtask' => '', 'contentlang' => 'es',
            'scenecount' => 4, 'imagestyle' => 'photo'];
        $prompt = lesson::prompt($instance);
        $this->assertStringContainsString('Industry: Construction', $prompt);
        $this->assertStringContainsString('Sub-industry: Civil construction', $prompt);
        $this->assertStringContainsString('Trenching', $prompt);
        $this->assertStringContainsString('Spanish', $prompt);
        $this->assertStringContainsString('engineering', $prompt);
        $this->assertStringContainsString('lowersLikelihood', $prompt);
    }

    /**
     * The picture prompt keeps the activity's style and lists every real hazard (not the distractors).
     */
    public function test_image_prompt(): void {
        $this->resetAfterTest();
        $instance = (object)['imagestyle' => 'illustration', 'industry' => 'construction', 'subindustry' => 'civil',
            'task' => 'trenching', 'customindustry' => '', 'customsubindustry' => '', 'customtask' => ''];
        $scene = (object)['imageprompt' => 'A realistic photograph of a trench.', 'title' => 'T'];
        $hazards = lesson::clean($this->draft())['scenes'][0]['hazards'];
        $prompt = lesson::image_prompt($instance, $scene, $hazards);
        $this->assertStringContainsString('Scene: An illustration of a trench.', $prompt);
        $this->assertStringContainsString('- the unsupported trench wall on the left', $prompt);
        $this->assertStringNotContainsString('Hot work sparks', $prompt);
        $this->assertStringNotContainsString('photograph of', $prompt);
        $this->assertSame('A photograph of a cafe.', lesson::style_words(
            'A bright, friendly flat illustration of a cafe.',
            'photo'
        ));
    }
}
