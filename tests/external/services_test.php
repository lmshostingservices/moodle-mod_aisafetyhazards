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

namespace mod_aisafetyhazards\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Web service permission and behaviour tests.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\external\start_attempt
 * @covers     \mod_aisafetyhazards\external\drop_label
 * @covers     \mod_aisafetyhazards\external\done_row
 * @covers     \mod_aisafetyhazards\external\save_scene
 * @covers     \mod_aisafetyhazards\external\preview_lesson
 * @covers     \mod_aisafetyhazards\external\finish_attempt
 * @covers     \mod_aisafetyhazards\external\import_lesson
 * @covers     \mod_aisafetyhazards\external\generate_image
 * @covers     \mod_aisafetyhazards\external\draft_scene
 * @covers     \mod_aisafetyhazards\external\check_request
 * @covers     \mod_aisafetyhazards\external\dismiss_request
 */
#[CoversClass(start_attempt::class)]
#[CoversClass(drop_label::class)]
#[CoversClass(done_row::class)]
#[CoversClass(save_scene::class)]
#[CoversClass(preview_lesson::class)]
#[CoversClass(finish_attempt::class)]
#[CoversClass(import_lesson::class)]
#[CoversClass(generate_image::class)]
#[CoversClass(draft_scene::class)]
#[CoversClass(check_request::class)]
#[CoversClass(dismiss_request::class)]
final class services_test extends \advanced_testcase {
    /** @var array */
    protected $u = [];
    /** @var \stdClass */
    protected $cm;
    /** @var \stdClass */
    protected $scene;

    /**
     * A course with a teacher, a non-editing teacher, two learners and an outsider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        foreach (['editingteacher', 'teacher', 'student', 'student2'] as $role) {
            $this->u[$role] = $gen->create_and_enrol($course, $role === 'student2' ? 'student' : $role);
        }
        $this->u['outsider'] = $gen->create_user();
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $instance = $sh->create_instance(['course' => $course->id]);
        $this->scene = $sh->create_scene($instance, 'Trench');
        $this->cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id);
        // The plugin is unlocked on this site; test_locked_site covers the opposite.
        set_config('unlockstate', json_encode(['status' => 'unlocked', 'checkedat' => time()]), 'mod_aisafetyhazards');
        // LMS Labs' picture capabilities are known (no positions), so no test asks the real LMS Labs.
        set_config('imageanchors', 0, 'mod_aisafetyhazards');
        set_config('imageanchorstime', time(), 'mod_aisafetyhazards');
    }

    /**
     * Resets the fake LMS Labs transport.
     */
    protected function tearDown(): void {
        \mod_aisafetyhazards\local\ai\lmslabs::$posttransport = null;
        parent::tearDown();
    }

    /**
     * Calls a service as a user and returns the cleaned result.
     *
     * @param string $user
     * @param string $class
     * @param array $args
     * @return mixed
     */
    protected function call(string $user, string $class, array $args) {
        $this->setUser($this->u[$user]);
        $class = '\\mod_aisafetyhazards\\external\\' . $class;
        $result = $class::execute(...$args);
        return external_api::clean_returnvalue($class::execute_returns(), $result);
    }

    /**
     * The scene's hazards.
     *
     * @return array
     */
    protected function hazards(): array {
        return \mod_aisafetyhazards\local\manager::scene_hazards((int)$this->scene->id);
    }

    /**
     * A learner plays a whole test through the services; the player data never carries the answers.
     */
    public function test_play(): void {
        global $DB;
        $DB->set_field('aisafetyhazards', 'testmode', 1, ['id' => $this->cm->instance]);
        $start = $this->call('student', 'start_attempt', [(int)$this->cm->id, 'test']);
        $data = json_decode($start['data'], true);
        $this->assertSame('test', $data['mode']);
        $this->assertCount(3, $data['scenes'][0]['spots']);
        $this->assertStringNotContainsString('"correct"', $start['data']);
        $this->assertStringNotContainsString('Soil can collapse', $start['data']);
        $sceneid = (int)$this->scene->id;
        foreach ($this->hazards() as $h) {
            if ($h['distractor']) {
                continue;
            }
            $hid = (int)$h['id'];
            $r = $this->call('student', 'drop_label', [$start['attemptid'], $sceneid, 'hazard', $hid, $hid]);
            $this->assertEquals([0, 1], [$r['marked'], $r['found']]);
            $this->assertArrayNotHasKey('now', $r);
            foreach (['eliminate', 'reduce', 'ppe'] as $kind) {
                foreach ($h['controls'][$kind] as $c) {
                    if ($c['correct'] && empty($r['done'][$kind])) {
                        $r = $this->call('student', 'drop_label', [$start['attemptid'], $sceneid, $kind, $hid, (int)$c['id']]);
                    }
                }
                if ($kind !== 'eliminate' && empty($r['done'][$kind])) {
                    $r = $this->call('student', 'done_row', [$start['attemptid'], $sceneid, $hid, $kind]);
                }
            }
        }
        $this->assertEquals(1, $r['allcomplete']);
        $summary = json_decode($this->call('student', 'finish_attempt', [$start['attemptid']])['data'], true);
        $this->assertSame(100, $summary['score']);
        $this->assertSame(100, $summary['safety']);
        $this->assertSame('Trench', $summary['recap'][0]['title']);
    }

    /**
     * Teachers save a scene's hazards and spots (free); learners and other users cannot; voiceover needs the setting.
     */
    public function test_save_scene_and_voice(): void {
        $hazards = $this->hazards();
        $hazards[0]['name'] = 'Wall collapse';
        $hazards[0]['x'] = 33;
        $hazards[0]['y'] = 44;
        $hazards[0]['placed'] = 1;
        $data = json_encode(['title' => 'Trench work', 'hazards' => $hazards]);
        $saved = $this->call('editingteacher', 'save_scene', [(int)$this->scene->id, $data]);
        $this->assertSame(['saved' => true, 'ready' => true], $saved);
        $after = $this->hazards();
        $this->assertSame('Wall collapse', $after[0]['name']);
        $this->assertEquals([33, 44, 1], [(float)$after[0]['x'], (float)$after[0]['y'], (int)$after[0]['placed']]);
        try {
            $this->call('editingteacher', 'save_scene', [(int)$this->scene->id, json_encode(['hazards' => [$hazards[0]]])]);
            $this->fail('Saved one hazard');
        } catch (\moodle_exception $e) {
            $this->assertSame('hazardsincomplete', $e->errorcode);
        }
        foreach (['student', 'outsider', 'teacher'] as $user) {
            try {
                $this->call($user, 'save_scene', [(int)$this->scene->id, $data]);
                $this->fail($user . ' saved a scene');
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
        // No voiceover while it is off.
        $start = $this->call('student', 'start_attempt', [(int)$this->cm->id, 'practice']);
        $this->assertSame([], json_decode($start['data'], true)['scenes'][0]['voice']);
        try {
            $this->call('editingteacher', 'create_voice', [(int)$this->scene->id, 0]);
            $this->fail('A clip was created while voiceover is off');
        } catch (\moodle_exception $e) {
            $this->assertSame('ainotavailable', $e->errorcode);
        }
    }

    /**
     * Pasted text is checked for free: the cleaned scenes come back; nothing is created or charged.
     */
    public function test_preview_lesson(): void {
        global $DB;
        $before = $DB->count_records('aisafetyhazards_scene');
        $draft = "\x60\x60\x60json\n" . file_get_contents(__DIR__ . '/../fixtures/draft.json') . "\n\x60\x60\x60";
        $result = json_decode($this->call('editingteacher', 'preview_lesson', [(int)$this->cm->id, $draft])['data'], true);
        $this->assertSame(['Digging the trench'], array_column($result['scenes'], 'title'));
        $this->assertCount(5, $result['scenes'][0]['hazards']);
        $this->assertSame($before, $DB->count_records('aisafetyhazards_scene'));
        try {
            $this->call('student', 'preview_lesson', [(int)$this->cm->id, $draft]);
            $this->fail('A learner previewed a lesson');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * Nobody else can play or finish a learner's attempt, and outsiders and teachers cannot start one.
     */
    public function test_permissions(): void {
        $start = $this->call('student', 'start_attempt', [(int)$this->cm->id, 'practice']);
        $hid = (int)$this->hazards()[0]['id'];
        foreach (['student2', 'teacher', 'outsider'] as $user) {
            try {
                $this->call($user, 'drop_label', [$start['attemptid'], (int)$this->scene->id, 'hazard', $hid, $hid]);
                $this->fail($user . ' dropped a label in another learner\'s attempt');
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
        foreach (['outsider', 'teacher'] as $user) {
            try {
                $this->call($user, 'start_attempt', [(int)$this->cm->id, '']);
                $this->fail($user . ' started an attempt');
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
    }

    /**
     * Teachers create scenes from an AI assistant's draft, charged per scene like LMS Labs drafts; learners and
     * non-editing teachers cannot; pictures by AI are not available while switched off.
     */
    public function test_teacher_services(): void {
        global $DB;
        $scene = json_decode(file_get_contents(__DIR__ . '/../fixtures/draft.json'), true)['scenes'][0];
        $draft = json_encode(['scenes' => [['title' => 'New'] + $scene]]);
        // Without an LMS Labs connection nothing is created.
        try {
            $this->call('editingteacher', 'import_lesson', [(int)$this->cm->id, $draft]);
            $this->fail('Scenes were created without LMS Labs');
        } catch (\moodle_exception $e) {
            $this->assertSame('ainotavailable', $e->errorcode);
        }
        set_config('lmslabssiteid', 'site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'key', 'mod_aisafetyhazards');
        $sent = [];
        \mod_aisafetyhazards\local\ai\lmslabs::$posttransport = function ($url, $headers, $body) use (&$sent) {
            $sent[] = [$url, json_decode($body, true)];
            return [200, ['content-type' => 'application/json'], json_encode(['requestId' => 'imp-1',
                'creditsCharged' => 5, 'creditsBalance' => 47])];
        };
        $before = $DB->count_records('aisafetyhazards_scene');
        $result = $this->call('editingteacher', 'import_lesson', [(int)$this->cm->id, $draft]);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('import', $result['operation']);
        $this->assertSame($before + 1, $DB->count_records('aisafetyhazards_scene'));
        $this->assertSame('https://lms-labs.com/api/moodle/ai-safetyhazards/scenes/import', $sent[0][0]);
        $this->assertSame(['sceneCount' => 1, 'titles' => ['New']], $sent[0][1]);
        foreach (['student', 'teacher'] as $user) {
            try {
                $this->call($user, 'import_lesson', [(int)$this->cm->id, $draft]);
                $this->fail($user . ' imported scenes');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
        set_config('aiimages', 0, 'mod_aisafetyhazards');
        try {
            $this->call('editingteacher', 'generate_image', [(int)$this->scene->id]);
            $this->fail('A picture was generated');
        } catch (\moodle_exception $e) {
            $this->assertSame('ainotavailable', $e->errorcode);
        }
    }

    /**
     * Until LMS Labs has unlocked the plugin, nothing can be set up or played; requests already made can still be
     * looked at and dismissed.
     */
    public function test_locked_site(): void {
        unset_config('unlockstate', 'mod_aisafetyhazards');
        $draft = file_get_contents(__DIR__ . '/../fixtures/draft.json');
        foreach (
            [['editingteacher', 'import_lesson', [(int)$this->cm->id, $draft]],
                ['editingteacher', 'draft_scene', [(int)$this->cm->id, 'A brief']],
                ['editingteacher', 'generate_image', [(int)$this->scene->id]],
                ['student', 'start_attempt', [(int)$this->cm->id, '']]] as [$user, $class, $args]
        ) {
            try {
                $this->call($user, $class, $args);
                $this->fail($class . ' worked on a locked site');
            } catch (\moodle_exception $e) {
                $this->assertSame('notactivated', $e->errorcode, $class);
            }
        }
        // A check that got no answer keeps an unlocked site usable; a definite "locked" does not.
        set_config('unlockstate', json_encode(['status' => 'unknown', 'wasunlocked' => true]), 'mod_aisafetyhazards');
        $this->assertTrue(\mod_aisafetyhazards\local\unlock::active());
        set_config('unlockstate', json_encode(['status' => 'locked']), 'mod_aisafetyhazards');
        $this->assertFalse(\mod_aisafetyhazards\local\unlock::active());
    }

    /**
     * A teacher creates a scene picture through LMS Labs: it is stored, and the charge and balance are reported.
     */
    public function test_generate_image_success(): void {
        global $DB;
        set_config('aiimages', 1, 'mod_aisafetyhazards');
        set_config('lmslabssiteid', 'site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'key', 'mod_aisafetyhazards');
        $png = file_get_contents(__DIR__ . '/../fixtures/scene.png');
        $sent = [];
        \mod_aisafetyhazards\local\ai\lmslabs::$posttransport = function ($url, $headers, $body) use ($png, &$sent) {
            $sent[] = json_decode($body, true);
            return [200, ['content-type' => 'image/png', 'x-request-id' => 'abc-1', 'x-credits-charged' => '5',
                'x-credits-balance' => '42'], $png];
        };
        try {
            $long = str_repeat('A long description. ', 200);
            $DB->set_field('aisafetyhazards_scene', 'imageprompt', $long, ['id' => $this->scene->id]);
            $result = $this->call('editingteacher', 'generate_image', [(int)$this->scene->id]);
        } finally {
            \mod_aisafetyhazards\local\ai\lmslabs::$posttransport = null;
        }
        $this->assertSame('completed', $result['status']);
        $this->assertSame('abc-1', $result['requestid']);
        $this->assertStringContainsString('5 LMS Labs credits used; 42 left', $result['message']);
        [$url] = \mod_aisafetyhazards\local\manager::get_scene_image(
            \context_module::instance($this->cm->id),
            (int)$this->scene->id
        );
        $this->assertStringContainsString('pluginfile.php', $url);
        $this->assertCount(1, $sent);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($sent[0]['prompt']), 'The prompt is shortened to the route limit.');
        $this->assertStringContainsString('No text', $sent[0]['prompt']);
        $this->assertSame('photo', $sent[0]['style']);
        $this->assertStringContainsString('Trenching', $sent[0]['prompt']);
        $this->assertSame(1, $DB->count_records('aisafetyhazards_ailog', ['action' => 'image', 'status' => 'completed']));
        try {
            $this->call('teacher', 'generate_image', [(int)$this->scene->id]);
            $this->fail('A non-editing teacher created a picture');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * Scene drafts: only teachers who manage the activity and may use AI; checking again reuses the stored request.
     */
    public function test_draft_scene_services(): void {
        global $DB;
        set_config('aidrafts', 1, 'mod_aisafetyhazards');
        set_config('lmslabssiteid', 'site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'key', 'mod_aisafetyhazards');
        $keys = [];
        $answers = [
            [202, ['retry-after' => '5'], json_encode(['requestId' => 'r1', 'error' => ['code' => 'PENDING',
                'message' => 'PENDING']])],
            [200, [], json_encode(['requestId' => 'r1', 'model' => 'gpt-4o-2024-08-06', 'creditsCharged' => 5,
                'creditsBalance' => 97, 'draft' => ['title' => 'A clash'] + json_decode(
                    file_get_contents(__DIR__ . '/../fixtures/draft.json'),
                    true
                )['scenes'][0]])],
        ];
        \mod_aisafetyhazards\local\ai\lmslabs::$posttransport = function ($url, $headers, $body) use (&$keys, &$answers) {
            $keys[] = array_values(preg_grep('/^Idempotency-Key: /', $headers))[0] . '|' . $body;
            return array_shift($answers);
        };
        try {
            foreach (['student', 'teacher'] as $user) {
                try {
                    $this->call($user, 'draft_scene', [(int)$this->cm->id, 'A trench beside a footpath']);
                    $this->fail($user . ' drafted a scene');
                } catch (\required_capability_exception $e) {
                    $this->assertSame('nopermissions', $e->errorcode);
                }
            }
            $first = $this->call('editingteacher', 'draft_scene', [(int)$this->cm->id, 'A trench beside a footpath']);
            $this->assertSame('pending', $first['status']);
            $this->assertTrue($first['poll']);
            $this->assertSame(5, $first['retryafter']);
            try {
                $this->call('teacher', 'check_request', [(int)$this->cm->id, $first['id']]);
                $this->fail('A non-editing teacher checked a request');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
            $done = $this->call('editingteacher', 'check_request', [(int)$this->cm->id, $first['id']]);
        } finally {
            \mod_aisafetyhazards\local\ai\lmslabs::$posttransport = null;
        }
        $this->assertSame('completed', $done['status']);
        $this->assertTrue($done['openscene']);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'Checking again sends the same key and body.');
        $this->assertSame(1, $DB->count_records('aisafetyhazards_scene', ['title' => 'A clash']));
        $dismissed = $this->call('editingteacher', 'dismiss_request', [(int)$this->cm->id, $first['id']]);
        $this->assertSame('completed', $dismissed['status'], 'A completed request is simply no longer listed.');
    }
}
