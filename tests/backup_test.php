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
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Backup and restore tests (duplicate, and course backup with user data).
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[CoversNothing]
final class backup_test extends \advanced_testcase {
    /**
     * Backs up a course with users and restores it as a new course.
     *
     * @param int $courseid
     * @return int new course id
     */
    protected function backup_restore(int $courseid): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $file = $bc->get_results()['backup_destination'];
        $file->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            make_backup_temp_directory($backupid)
        );
        $bc->destroy();
        $newcourseid = \restore_dbops::create_new_course('Restored', 'R1', $this->getDataGenerator()->create_category()->id);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    /**
     * Content, pictures, attempts and choices survive, with every id remapped.
     */
    public function test_course_backup_with_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $sh->create_instance(['course' => $course->id, 'task' => 'custom', 'customtask' => 'Laying kerbs',
            'shuffleoptions' => 1]);
        $scene = $sh->create_scene($instance, 'Short-staffed Friday');
        $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id);
        $context = \context_module::instance($cm->id);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $instance->id]);
        $data = learning::start_attempt($instance, $context, (int)$student->id, 'practice');
        $attempt = $sh->play_attempt($instance, $context, (int)$data['attemptid']);
        learning::finish_attempt($instance, $cm, $course, $context, $attempt);
        $wall = (int)$DB->get_field('aisafetyhazards_hazard', 'id', ['sceneid' => $scene->id, 'sortorder' => 1]);

        $newcourseid = $this->backup_restore((int)$course->id);
        $newinstance = $DB->get_record('aisafetyhazards', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame(['construction', 'civil', 'custom', 'Laying kerbs'], [$newinstance->industry,
            $newinstance->subindustry, $newinstance->task, $newinstance->customtask]);
        $newscene = $DB->get_record('aisafetyhazards_scene', ['aisafetyhazardsid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertSame('Short-staffed Friday', $newscene->title);
        $hazards = \mod_aisafetyhazards\local\manager::scene_hazards((int)$newscene->id);
        $this->assertCount(5, $hazards);
        $this->assertTrue(\mod_aisafetyhazards\local\manager::hazards_ok($hazards));
        $newwall = (int)$hazards[0]['id'];
        $this->assertNotSame($wall, $newwall);
        $this->assertSame(3, (int)$hazards[0]['controls']['reduce'][0]['dl']);
        $newcm = get_coursemodule_from_instance('aisafetyhazards', $newinstance->id);
        $newcontext = \context_module::instance($newcm->id);
        $this->assertNotNull(\mod_aisafetyhazards\local\manager::get_scene_file($newcontext, (int)$newscene->id));
        $newattempt = $DB->get_record('aisafetyhazards_attempt', ['aisafetyhazardsid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertEquals(100, (float)$newattempt->score);
        $order = json_decode($newattempt->sceneorder, true);
        $this->assertSame((int)$newscene->id, $order[0]['scene']);
        $this->assertSame($newwall, $order[0]['hazards'][0]);
        $this->assertContains($newwall, $order[0]['chips']);
        $newcontrols = array_map(fn($c) => (int)$c['id'], $hazards[0]['controls']['reduce']);
        $this->assertEqualsCanonicalizing($newcontrols, $order[0]['rows'][$newwall]['reduce']);
        $choices = $DB->get_records('aisafetyhazards_choice', ['attemptid' => $newattempt->id], 'id');
        $first = reset($choices);
        $this->assertSame([$newwall, $newwall, (int)$newscene->id], [(int)$first->hazardid, (int)$first->itemid,
            (int)$first->sceneid]);
        $control = array_values(array_filter($choices, fn($c) => $c->kind === 'reduce'))[0];
        $this->assertContains((int)$control->itemid, $newcontrols);
        $this->assertEquals(100, (float)$newattempt->safety);
        // The restored activity is playable.
        $again = learning::start_attempt($newinstance, $newcontext, (int)$student->id, 'practice');
        $this->assertCount(1, $again['scenes']);
        $this->assertSame(2, $again['attempt']);
    }

    /**
     * Duplicating an activity copies the content but not learner data.
     */
    public function test_duplicate(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $sh->create_instance(['course' => $course->id, 'name' => 'Orig']);
        $sh->create_scene($instance, 'Behind on target');
        $cm = get_fast_modinfo($course)->get_cm(get_coursemodule_from_instance('aisafetyhazards', $instance->id)->id);
        if (method_exists(\core_courseformat\local\cmactions::class, 'duplicate')) {
            // Moodle 5.2+.
            $newcm = \core_courseformat\formatactions::cm($course->id)->duplicate($cm->id);
        } else {
            $newcm = duplicate_module($course, $cm);
        }
        $this->assertSame(2, $DB->count_records('aisafetyhazards'));
        $this->assertSame(10, $DB->count_records('aisafetyhazards_hazard'));
        $this->assertNotNull(\mod_aisafetyhazards\local\manager::get_scene_file(
            \context_module::instance($newcm->id),
            (int)$DB->get_field('aisafetyhazards_scene', 'id', ['aisafetyhazardsid' => $newcm->instance])
        ));
    }
}
