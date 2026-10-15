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

namespace mod_aisafetyhazards\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_aisafetyhazards\local\learning;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy provider tests.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\privacy\provider
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \context_module */
    protected $context;
    /** @var \stdClass */
    protected $u1;
    /** @var \stdClass */
    protected $u2;

    /**
     * Two learners, each with an attempt and a choice.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $sh->create_instance(['course' => $course->id]);
        $scene = $sh->create_scene($instance, 'Trench');
        $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id);
        $this->context = \context_module::instance($cm->id);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $instance->id]);
        $hazard = (int)$DB->get_field('aisafetyhazards_hazard', 'id', ['sceneid' => $scene->id, 'sortorder' => 1]);
        $distractor = (int)$DB->get_field('aisafetyhazards_hazard', 'id', ['sceneid' => $scene->id, 'sortorder' => 4]);
        foreach (['u1', 'u2'] as $name) {
            $user = $gen->create_and_enrol($course, 'student');
            $this->$name = $user;
            $data = learning::start_attempt($instance, $this->context, (int)$user->id, 'practice');
            $attempt = $DB->get_record('aisafetyhazards_attempt', ['id' => $data['attemptid']]);
            learning::drop($instance, $this->context, $attempt, (int)$scene->id, 'hazard', $hazard, $distractor);
        }
    }

    /**
     * Metadata lists every learner table and the grades link.
     */
    public function test_metadata(): void {
        $items = provider::get_metadata(new collection('mod_aisafetyhazards'))->get_collection();
        $names = array_map(fn($i) => $i->get_name(), $items);
        foreach (['aisafetyhazards_attempt', 'aisafetyhazards_choice', 'aisafetyhazards_ailog', 'core_grades'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    /**
     * Contexts, users and export.
     */
    public function test_export(): void {
        $contexts = provider::get_contexts_for_userid((int)$this->u1->id);
        $this->assertEquals([$this->context->id], $contexts->get_contextids());
        $userlist = new userlist($this->context, 'mod_aisafetyhazards');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->u1->id, $this->u2->id], $userlist->get_userids());
        $this->export_context_data_for_user((int)$this->u1->id, $this->context, 'mod_aisafetyhazards');
        $data = writer::with_context($this->context)->get_data([]);
        $this->assertCount(1, $data->attempts);
        $choice = $data->attempts[0]->choices[0];
        $this->assertSame(
            ['Trench', 'Trench wall collapse', 'hazard', 'Hot work sparks', 'No'],
            [$choice->scene, $choice->hazard, $choice->row, $choice->label, $choice->right]
        );
        $this->assertSame('practice', $data->attempts[0]->mode);
    }

    /**
     * Deleting one user, a list of users and the whole context keeps the scenes.
     */
    public function test_delete(): void {
        global $DB;
        provider::delete_data_for_user(new approved_contextlist($this->u1, 'mod_aisafetyhazards', [$this->context->id]));
        $this->assertSame(0, $DB->count_records('aisafetyhazards_attempt', ['userid' => $this->u1->id]));
        $this->assertSame(1, $DB->count_records('aisafetyhazards_attempt', ['userid' => $this->u2->id]));
        $this->assertSame(1, $DB->count_records('aisafetyhazards_choice'));
        provider::delete_data_for_users(new approved_userlist($this->context, 'mod_aisafetyhazards', [$this->u2->id]));
        $this->assertSame(0, $DB->count_records('aisafetyhazards_choice'));
        $this->assertSame(0, $DB->count_records('aisafetyhazards_attempt'));
        $this->assertSame(1, $DB->count_records('aisafetyhazards_scene'));
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertSame(5, $DB->count_records('aisafetyhazards_hazard'));
    }
}
