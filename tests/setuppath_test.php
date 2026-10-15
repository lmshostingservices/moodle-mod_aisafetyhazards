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

use mod_aisafetyhazards\local\setuppath;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the set-up path.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\setuppath
 */
#[CoversClass(setuppath::class)]
final class setuppath_test extends \advanced_testcase {
    /**
     * Each step has one page; steps 1 and 2 share the builder page.
     */
    public function test_urls_and_bar(): void {
        $this->assertSame('/mod/aisafetyhazards/builder.php?id=3&start=2', setuppath::url(3, 2)->out_as_local_url(false));
        $this->assertSame('/mod/aisafetyhazards/builder.php?id=3&step=build', setuppath::url(3, 3)->out_as_local_url(false));
        $this->assertSame('/mod/aisafetyhazards/scenes.php?id=3&step=pictures', setuppath::url(3, 4)->out_as_local_url(false));
        $this->assertSame('/mod/aisafetyhazards/scenes.php?id=3&step=voices', setuppath::url(3, 5)->out_as_local_url(false));
        $this->assertSame('/mod/aisafetyhazards/scenes.php?id=3&step=check', setuppath::url(3, 6)->out_as_local_url(false));
        $this->assertSame('/mod/aisafetyhazards/builder.php?id=3&step=finish', setuppath::url(3, 7)->out_as_local_url(false));
        $bar = setuppath::bar(4);
        $this->assertCount(7, $bar['steps']);
        $this->assertSame([true, true, true, false, false, false, false], array_column($bar['steps'], 'done'));
        $this->assertTrue($bar['steps'][3]['current']);
        $this->assertSame('Pictures and hazard spots', $bar['steps'][3]['name']);
    }

    /**
     * Set-up resumes at the first step that is not done: pictures, then the hazards check.
     */
    public function test_state_and_resume(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $plugin = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $plugin->create_instance(['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);

        $state = setuppath::state($instance, $context);
        $this->assertSame(0, $state['scenes']);
        $this->assertSame(1, setuppath::resume_step($state, false));
        $this->assertSame(setuppath::CREATE, setuppath::resume_step($state, true));

        $ready = $plugin->create_scene($instance, 'Ready');
        $nopicture = $plugin->create_scene($instance, 'No picture', null, '');
        $state = setuppath::state($instance, $context);
        $this->assertSame(2, $state['scenes']);
        $this->assertSame([(int)$nopicture->id], $state['nopicture']);
        $this->assertSame([], $state['unplaced'], 'Guessed spots count as placed.');
        $this->assertSame(1, $state['ready']);
        $this->assertSame(setuppath::PICTURES, setuppath::resume_step($state, true));

        local\manager::save_scene_image_bytes($context, $nopicture, file_get_contents(__DIR__ . '/fixtures/scene.png'));
        $emptyid = local\manager::add_scene($instance, ['title' => 'Not written yet']);
        $empty = $DB->get_record('aisafetyhazards_scene', ['id' => $emptyid]);
        local\manager::save_scene_image_bytes($context, $empty, file_get_contents(__DIR__ . '/fixtures/scene.png'));
        $state = setuppath::state($instance, $context);
        $this->assertSame([], $state['nopicture']);
        $this->assertSame([(int)$empty->id], $state['nohazards']);
        $this->assertSame(setuppath::CHECK, setuppath::resume_step($state, true));
        $this->assertSame(2, $state['ready']);
        $this->assertNotEmpty($ready);
    }
}
