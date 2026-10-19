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

use mod_aisafetyhazards\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for scenes, hazards and their control rows.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\manager
 */
#[CoversClass(manager::class)]
final class manager_test extends \advanced_testcase {
    /**
     * A wrong label never lowers the risk; PPE is level PPE; a right label lowers something; the effect is limited.
     */
    public function test_clean_control(): void {
        $c = manager::clean_control(['text' => 'Sign', 'correct' => 0, 'dl' => 3, 'dc' => 2, 'level' => 'engineering'], 'reduce');
        $this->assertSame([0, 0, 0], [$c['correct'], $c['dl'], $c['dc']]);
        $c = manager::clean_control(['text' => 'Gloves', 'correct' => 1, 'level' => 'engineering'], 'ppe');
        $this->assertSame(['ppe', 0, 1], [$c['level'], $c['dl'], $c['dc']]);
        $c = manager::clean_control(['text' => 'Guard', 'correct' => 1, 'level' => 'nonsense'], 'reduce');
        $this->assertSame(['administrative', 1, 0], [$c['level'], $c['dl'], $c['dc']]);
        $c = manager::clean_control(
            ['text' => 'Guard', 'correct' => 1, 'level' => 'isolation', 'dl' => 9, 'dc' => -3],
            'reduce'
        );
        $this->assertSame(['isolation', 4, 0], [$c['level'], $c['dl'], $c['dc']]);
        $c = manager::clean_control(['text' => 'Remove it', 'correct' => 1, 'eliminates' => 1, 'dl' => 2], 'eliminate');
        $this->assertSame(['elimination', 1, 0, 0], [$c['level'], $c['eliminates'], $c['dl'], $c['dc']]);
        $c = manager::clean_control(['text' => 'Faster', 'correct' => 0, 'eliminates' => 1], 'eliminate');
        $this->assertSame(0, $c['eliminates'], 'Only a right answer can remove the hazard.');
        $this->assertSame('reduce', manager::clean_control(['text' => 'x'], 'bogus')['kind']);
    }

    /**
     * A scene needs exactly three hazards, one right elimination each, and right and wrong labels in each row.
     */
    public function test_hazards_ok(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards');
        $hazards = $gen->fixture_scene()['hazards'];
        $this->assertTrue(manager::hazards_ok($hazards));
        $this->assertFalse(manager::hazards_ok(array_slice($hazards, 1)), 'Only two real hazards.');
        $bad = $hazards;
        $bad[0]['controls']['reduce'] = array_values(array_filter($bad[0]['controls']['reduce'], fn($c) => $c['correct']));
        $this->assertFalse(manager::hazards_ok($bad), 'A row needs a wrong label to choose from.');
        $bad = $hazards;
        $bad[0]['controls']['ppe'] = [];
        $this->assertFalse(manager::hazards_ok($bad), 'A hazard that stays needs a PPE row.');
        $bad = $hazards;
        $bad[1]['controls']['reduce'] = [];
        $bad[1]['controls']['ppe'] = [];
        $this->assertFalse(manager::hazards_ok($bad), 'An eliminated hazard still needs Reduce and PPE (it may not be approved).');
        $this->assertTrue(manager::hazards_ok($bad, true), 'Older scenes like this still play.');
        $bad = $hazards;
        $bad[2]['controls']['eliminate'][1]['correct'] = 1;
        $this->assertFalse(manager::hazards_ok($bad), 'One right elimination only.');
        $bad = $hazards;
        $bad[] = ['name' => 'Third distractor', 'distractor' => 1, 'controls' => []];
        $this->assertFalse(manager::hazards_ok($bad), 'At most two distractors.');
    }

    /**
     * Saving keeps the ids of hazards and labels that are kept (so learners' labels still point at them), adds new
     * ones and deletes those left out. Incomplete hazards are refused.
     */
    public function test_save_scene_keeps_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards');
        $instance = $gen->create_instance(['course' => $course->id]);
        $scene = $gen->create_scene($instance, 'Trench');
        $hazards = manager::scene_hazards((int)$scene->id);
        $ids = array_map('intval', array_column($hazards, 'id'));
        $keepcontrol = (int)$hazards[0]['controls']['reduce'][0]['id'];
        $dropcontrol = (int)$hazards[0]['controls']['reduce'][3]['id'];
        $hazards[0]['name'] = 'Wall collapse';
        array_pop($hazards[0]['controls']['reduce']);
        $hazards[0]['controls']['reduce'][] = ['text' => 'Bench the trench walls', 'correct' => 0];
        array_pop($hazards);
        manager::save_scene($scene, ['title' => 'Trench work'], $hazards);
        $after = manager::scene_hazards((int)$scene->id);
        $this->assertSame(array_slice($ids, 0, 4), array_map('intval', array_column($after, 'id')));
        $this->assertSame('Wall collapse', $after[0]['name']);
        $this->assertSame($keepcontrol, (int)$after[0]['controls']['reduce'][0]['id']);
        $this->assertFalse($DB->record_exists('aisafetyhazards_control', ['id' => $dropcontrol]));
        $this->assertSame('Bench the trench walls', end($after[0]['controls']['reduce'])['text']);
        $this->assertFalse($DB->record_exists('aisafetyhazards_hazard', ['id' => $ids[4]]));
        $this->assertSame('Trench work', $DB->get_field('aisafetyhazards_scene', 'title', ['id' => $scene->id]));
        try {
            manager::save_scene($scene, [], array_slice($after, 1));
            $this->fail('Saved two hazards');
        } catch (\moodle_exception $e) {
            $this->assertSame('hazardsincomplete', $e->errorcode);
        }
        $this->assertCount(4, manager::scene_hazards((int)$scene->id));
    }

    /**
     * Hazard spots: guessed positions are spread out and marked; the teacher's moves are kept as placed.
     */
    public function test_positions(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards');
        $instance = $gen->create_instance(['course' => $course->id]);
        $scene = $gen->create_scene($instance, 'Trench');
        $hazards = manager::scene_hazards((int)$scene->id);
        $this->assertEquals([20, 50, 80], array_map('floatval', array_column(array_slice($hazards, 0, 3), 'x')));
        manager::place_hazards($scene, [(int)$hazards[0]['id'] => [12.5, 140], (int)$hazards[3]['id'] => [5, 5]]);
        $after = manager::scene_hazards((int)$scene->id);
        $this->assertEquals([12.5, 100, 1], [(float)$after[0]['x'], (float)$after[0]['y'], (int)$after[0]['placed']]);
        $this->assertEquals([0, 0], [(float)$after[3]['x'], (float)$after[3]['y']], 'Distractors have no spot.');
        // Saving again keeps the placed spot.
        manager::save_scene($scene, [], $after);
        $this->assertEquals(12.5, (float)manager::scene_hazards((int)$scene->id)[0]['x']);
    }

    /**
     * A scene is ready with a picture and complete hazards; deleting it removes its hazards, labels and picture.
     */
    public function test_ready_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards');
        $instance = $gen->create_instance(['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $with = $gen->create_scene($instance, 'With picture');
        $gen->create_scene($instance, 'Without picture', null, '');
        $ready = manager::ready_scenes($instance, $context);
        $this->assertSame([(int)$with->id], array_map(fn($s) => (int)$s->id, $ready));
        manager::delete_scene($context, $with);
        $this->assertFalse($DB->record_exists('aisafetyhazards_scene', ['id' => $with->id]));
        $this->assertSame(0, $DB->count_records('aisafetyhazards_hazard', ['sceneid' => $with->id]));
        $this->assertNull(manager::get_scene_file($context, (int)$with->id));
        $this->assertSame(1, (int)$DB->get_field('aisafetyhazards_scene', 'sortorder', ['aisafetyhazardsid' => $instance->id]));
    }

    /**
     * Fields over the length limits are reported once each.
     */
    public function test_too_long(): void {
        $this->resetAfterTest();
        $hazards = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards')->fixture_scene()['hazards'];
        $scene = (object)['context' => 'Short'];
        $this->assertSame([], manager::too_long($scene, $hazards));
        $hazards[0]['name'] = str_repeat('n', 61);
        $hazards[1]['name'] = str_repeat('n', 70);
        $hazards[0]['controls']['ppe'][0]['text'] = str_repeat('t', 91);
        $scene->context = str_repeat('c', 401);
        $this->assertSame(['context', 'name', 'text'], manager::too_long($scene, $hazards));
    }

    /**
     * Settings are normalised: an unknown task becomes custom, at least one mode, limits on numbers.
     */
    public function test_prepare_instance_data(): void {
        $data = manager::prepare_instance_data(
            (object)['industry' => 'construction', 'subindustry' => 'civil',
            'task' => 'space', 'customtask' => " Laying\nkerbs ", 'contentlang' => 'xx', 'imagestyle' => 'oil',
            'maxattempts' => 500, 'grademethod' => 9, 'scenecount' => 99, 'studymode' => 0, 'practicemode' => 0,
            'testmode' => 0, 'passmark' => 140, 'voiceparts' => ['situation' => 1, 'hazards' => 0, 'bogus' => 1]]
        );
        $this->assertSame(['construction', 'civil', 'custom'], [$data->industry, $data->subindustry, $data->task]);
        $this->assertSame('Laying kerbs', $data->customtask);
        $this->assertSame('en', $data->contentlang);
        $this->assertSame('photo', $data->imagestyle);
        $this->assertSame(100, $data->maxattempts);
        $this->assertSame(manager::GRADE_HIGHEST, $data->grademethod);
        $this->assertSame(20, $data->scenecount);
        $this->assertSame([0, 1, 0], [$data->studymode, $data->practicemode, $data->testmode]);
        $this->assertSame(100, $data->passmark);
        $this->assertSame('situation', $data->voiceparts);
    }
}
