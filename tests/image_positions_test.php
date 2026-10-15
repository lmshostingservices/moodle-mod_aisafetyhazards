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

use mod_aisafetyhazards\local\ai\lmslabs;
use mod_aisafetyhazards\local\ai\requests;
use mod_aisafetyhazards\local\image_positions;
use mod_aisafetyhazards\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for placing hazard spots where LMS Labs found them in a new picture.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\image_positions
 */
#[CoversClass(image_positions::class)]
final class image_positions_test extends \advanced_testcase {
    /**
     * The anchors are the real hazards' picture descriptions (the name when there is none), never the distractors.
     */
    public function test_anchors(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards');
        $instance = $gen->create_instance(['course' => $course->id]);
        $hazards = $gen->fixture_scene()['hazards'];
        $hazards[1]['anchor'] = '';
        $scene = $gen->create_scene($instance, 'Trench', $hazards);
        $anchors = image_positions::anchors($scene);
        $this->assertCount(3, $anchors);
        $this->assertSame('the unsupported trench wall on the left', array_values($anchors)[0]);
        $this->assertSame('Struck by reversing truck', array_values($anchors)[1]);
    }

    /**
     * Only a well-formed header with one point (or null) per anchor is used.
     */
    public function test_decode(): void {
        $this->assertSame([['x' => 10, 'y' => 20.5], null], image_positions::decode('[{"x":10,"y":20.5},null]', 2));
        foreach (
            ['', 'nope', '[{"x":10,"y":20}]', '[{"x":"10","y":20},null]', '[{"x":101,"y":20},null]',
                '{"1":{"x":1,"y":1},"0":null}', '[{"x":1,"y":1,"z":1},null]'] as $bad
        ) {
            $this->assertNull(image_positions::decode($bad, 2), $bad);
        }
        $this->assertNull(image_positions::decode('[]', 0));
    }

    /**
     * A delivered picture places the hazards it found (placed 2) and never moves one the teacher placed.
     */
    public function test_picture_places_hazards(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_aisafetyhazards');
        $instance = $gen->create_instance(['course' => $course->id]);
        $scene = $gen->create_scene($instance, 'Trench');
        [$wall, $truck, $fall] = manager::scene_hazards((int)$scene->id);
        manager::place_hazards($scene, [(int)$fall['id'] => [90, 90]]);
        set_config('aiimages', 1, 'mod_aisafetyhazards');
        set_config('lmslabssiteid', 'My Site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'secret-key', 'mod_aisafetyhazards');
        set_config('imageanchors', 1, 'mod_aisafetyhazards');
        set_config('imageanchorstime', time(), 'mod_aisafetyhazards');
        $sent = [];
        lmslabs::$posttransport = function ($url, $headers, $body) use (&$sent) {
            $sent[] = json_decode($body, true);
            return [200, ['content-type' => 'image/png', 'x-anchor-positions' => '[{"x":12.5,"y":40},null,{"x":50,"y":50}]'],
                file_get_contents(__DIR__ . '/fixtures/scene.png')];
        };
        $this->setUser($teacher);
        try {
            $row = requests::start_image($instance, (int)$teacher->id, $scene);
        } finally {
            lmslabs::$posttransport = null;
        }
        $this->assertSame('completed', $row->status);
        $this->assertSame(['the unsupported trench wall on the left', $truck['anchor'], $fall['anchor']], $sent[0]['anchors']);
        $after = $DB->get_records('aisafetyhazards_hazard', ['sceneid' => $scene->id], '', 'id, x, y, placed');
        $this->assertEquals(
            [12.5, 40, 2],
            [(float)$after[$wall['id']]->x, (float)$after[$wall['id']]->y,
            (int)$after[$wall['id']]->placed]
        );
        $this->assertEquals(
            [(float)$truck['x'], 2],
            [(float)$after[$truck['id']]->x, (int)$after[$truck['id']]->placed],
            'Not found: left where it was guessed.'
        );
        $this->assertEquals(
            [90, 1],
            [(float)$after[$fall['id']]->x, (int)$after[$fall['id']]->placed],
            'The teacher\'s spot is kept.'
        );
        // A bad header changes nothing.
        $this->assertSame(0, image_positions::apply($scene, $sent[0]['anchors'], 'garbage'));
    }
}
