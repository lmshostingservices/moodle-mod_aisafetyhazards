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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for stored LMS Labs requests: scene drafts (24-hour replay) and pictures (no replay). No network.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\ai\requests
 * @covers     \mod_aisafetyhazards\local\ai\lmslabs
 */
#[CoversClass(requests::class)]
#[CoversClass(lmslabs::class)]
final class requests_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $instance;
    /** @var \stdClass */
    protected $scene;
    /** @var \stdClass */
    protected $teacher;
    /** @var array Requests seen by the fake transport: [url, headers, body]. */
    protected $sent = [];
    /** @var array Answers the fake transport gives, in order. */
    protected $answers = [];

    /**
     * An activity with one scene, a teacher and standalone credentials; the transport is faked.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->teacher = $gen->create_and_enrol($course, 'editingteacher');
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $this->instance = $sh->create_instance(['course' => $course->id]);
        $this->scene = $sh->create_scene($this->instance, 'Behind on target');
        set_config('aidrafts', 1, 'mod_aisafetyhazards');
        set_config('aiimages', 1, 'mod_aisafetyhazards');
        set_config('lmslabssiteid', 'My Site', 'mod_aisafetyhazards');
        // LMS Labs' picture capabilities are known (no positions), so no test asks the real LMS Labs.
        set_config('imageanchors', 0, 'mod_aisafetyhazards');
        set_config('imageanchorstime', time(), 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'secret-key', 'mod_aisafetyhazards');
        $this->sent = [];
        $this->answers = [];
        lmslabs::$posttransport = function ($url, $headers, $body) {
            global $DB;
            $key = substr(array_values(preg_grep('/^Idempotency-Key: /', $headers))[0], 17);
            // The request is stored, with this exact key and body, before anything is sent.
            $this->assertTrue($DB->record_exists('aisafetyhazards_aireq', ['idemkey' => $key]));
            $this->assertSame($body, $DB->get_field('aisafetyhazards_aireq', 'body', ['idemkey' => $key]));
            $this->sent[] = [$url, $headers, $body, $key];
            if (!$this->answers) {
                $this->fail('Unexpected request to LMS Labs.');
            }
            return array_shift($this->answers);
        };
        $this->setUser($this->teacher);
    }

    /**
     * Resets the transport.
     */
    protected function tearDown(): void {
        lmslabs::$posttransport = null;
        parent::tearDown();
    }

    /**
     * A valid scene draft response: the fixture trenching scene, with changes.
     *
     * @param array $draft changes to the draft
     * @return array
     */
    protected function draft_ok(array $draft = []): array {
        $scene = json_decode(file_get_contents(__DIR__ . '/fixtures/draft.json'), true)['scenes'][0];
        return [200, ['content-type' => 'application/json', 'x-request-id' => 'req-1', 'x-idempotent-replay' => 'false'],
            json_encode(['requestId' => 'req-1', 'model' => 'gpt-4o-2024-08-06', 'creditsCharged' => 5, 'creditsBalance' => 97,
            'draft' => $draft + $scene])];
    }

    /**
     * A text error response.
     *
     * @param int $status
     * @param string $code
     * @param array $extra
     * @return array
     */
    protected function text_error(int $status, string $code, array $extra = []): array {
        return [$status, ['content-type' => 'application/json', 'x-request-id' => 'req-e'],
            json_encode(['requestId' => 'req-e', 'error' => ['code' => $code, 'message' => $code]] + $extra)];
    }

    /**
     * A picture error response.
     *
     * @param int $status
     * @param string $code
     * @param array $extra
     * @return array
     */
    protected function image_error(int $status, string $code, array $extra = []): array {
        return [$status, ['content-type' => 'application/json', 'x-request-id' => 'img-e'],
            json_encode(['error' => $code, 'requestId' => 'img-e'] + $extra)];
    }

    /**
     * A delivered draft: exact route, header-only credentials, exact body; it becomes a complete scene with its hazards.
     */
    public function test_scene_draft_delivered(): void {
        global $DB;
        $this->answers = [$this->draft_ok()];
        $row = requests::start_scene($this->instance, (int)$this->teacher->id, "Digging a trench\r\nbeside a footpath.");
        $this->assertSame('completed', $row->status);
        $this->assertCount(1, $this->sent);
        [$url, $headers, $body, $key] = $this->sent[0];
        $this->assertSame('https://lms-labs.com/api/moodle/ai-safetyhazards/scenes/draft', $url);
        $this->assertContains('X-Site-ID: My Site', $headers);
        $this->assertContains('X-API-Key: secret-key', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
        $this->assertSame(
            ['brief' => "Digging a trench\nbeside a footpath.", 'industry' => 'Construction',
                'subindustry' => 'Civil construction', 'task' => 'Trenching', 'language' => 'English'],
            json_decode($body, true),
            'Only the approved fields.'
        );
        $this->assertStringNotContainsString('secret-key', $body . $url);
        $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $row->targetid], '*', MUST_EXIST);
        $this->assertSame('Digging the trench', $scene->title);
        $this->assertStringStartsWith('The excavator is digging', $scene->context);
        $hazards = local\manager::scene_hazards((int)$scene->id);
        $this->assertTrue(local\manager::hazards_ok($hazards));
        $this->assertSame('Trench wall collapse', $hazards[0]['name']);
        $this->assertSame(3, (int)$hazards[0]['controls']['reduce'][0]['dl']);
        $this->assertSame(5, (int)$row->charged);
        $this->assertSame(97, (int)$row->balance);
        $this->assertSame('req-1', $row->requestid);
        $context = \context_module::instance(get_coursemodule_from_instance('aisafetyhazards', $this->instance->id)->id);
        $export = requests::export($row, $context);
        $this->assertStringContainsString('5 LMS Labs credits used; 97 left', $export['message']);
        $this->assertTrue($export['openscene']);
        $this->assertArrayNotHasKey('idemkey', $export);
        // A completed request is never sent again.
        $this->assertSame('completed', requests::check($row, $this->instance)->status);
        $this->assertCount(1, $this->sent);
        // A second intentional draft uses a new key.
        $this->answers = [$this->draft_ok()];
        requests::start_scene($this->instance, (int)$this->teacher->id, 'Another scene');
        $this->assertNotSame($this->sent[0][3], $this->sent[1][3]);
    }

    /**
     * 202 is pending: checking again sends the same key and body; the replayed draft creates exactly one scene.
     */
    public function test_scene_pending_then_replay(): void {
        global $DB;
        $this->answers = [[202, ['retry-after' => '5', 'x-request-id' => 'req-1'], json_encode(['requestId' => 'req-1',
            'error' => ['code' => 'PENDING', 'message' => 'PENDING']])]];
        $row = requests::start_scene($this->instance, (int)$this->teacher->id, 'A brief');
        $this->assertSame('pending', $row->status);
        $this->assertSame(5, $row->retryafter);
        $this->assertSame(0, (int)$row->targetid);
        // Another intentional draft is refused while this one is unresolved.
        try {
            requests::start_scene($this->instance, (int)$this->teacher->id, 'Another');
            $this->fail('A second draft was sent while one was pending.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aireq_busy', $e->errorcode);
        }
        $this->answers = [$this->draft_ok()];
        $row = requests::check($row, $this->instance);
        $this->assertSame('completed', $row->status);
        $this->assertCount(2, $this->sent);
        $this->assertSame($this->sent[0][3], $this->sent[1][3], 'Same key.');
        $this->assertSame($this->sent[0][2], $this->sent[1][2], 'Same body.');
        $this->assertSame(1, $DB->count_records('aisafetyhazards_scene', ['aisafetyhazardsid' => $this->instance->id,
            'title' => 'Digging the trench']));
        $this->assertSame(2, (int)$row->tries);
    }

    /**
     * No answer or unconfirmed settlement keeps the key ("uncertain"); 409 and 410 are terminal and never resent.
     */
    public function test_scene_uncertain_conflict_expired(): void {
        $this->answers = [[0, [], '']];
        $row = requests::start_scene($this->instance, (int)$this->teacher->id, 'A brief');
        $this->assertSame('uncertain', $row->status);
        $this->assertSame('network', $row->errorcode);
        $this->answers = [$this->text_error(503, 'SETTLEMENT_UNCONFIRMED')];
        $row = requests::check($row, $this->instance);
        $this->assertSame('uncertain', $row->status);
        $this->answers = [$this->text_error(409, 'IDEMPOTENCY_CONFLICT')];
        $row = requests::check($row, $this->instance);
        $this->assertSame('conflict', $row->status);
        $this->assertSame($this->sent[0][3], $this->sent[2][3], 'The key is never replaced automatically.');
        $this->assertSame('conflict', requests::check($row, $this->instance)->status);
        $this->assertCount(3, $this->sent, 'A conflict is not resent.');

        $this->answers = [[0, [], ''], $this->text_error(410, 'EXPIRED')];
        $row = requests::start_scene($this->instance, (int)$this->teacher->id, 'A new brief');
        $row = requests::check($row, $this->instance);
        $this->assertSame('expired', $row->status);
        $this->assertCount(5, $this->sent);
        $this->assertSame('expired', requests::check($row, $this->instance)->status);
        $this->assertCount(5, $this->sent, 'An expired request needs a new intentional draft.');
        $open = requests::open((int)$this->instance->id, requests::SCENE);
        $this->assertCount(2, $open);
        $this->assertSame('dismissed', requests::dismiss($row)->status);
        $this->assertCount(1, requests::open((int)$this->instance->id, requests::SCENE));
    }

    /**
     * Failures LMS Labs reports are shown with its reference and are never retried.
     */
    public function test_scene_failures(): void {
        global $DB;
        $context = \context_module::instance(get_coursemodule_from_instance('aisafetyhazards', $this->instance->id)->id);
        $cases = [
            [$this->text_error(402, 'INSUFFICIENT_CREDITS', ['creditsBalance' => 2]), 'insufficient_credits', '2 are left'],
            [$this->text_error(401, 'INVALID_CREDENTIALS'), 'invalid_credentials', 'Site ID and API key'],
            [$this->text_error(403, 'NO_ENTITLEMENT'), 'no_entitlement', 'enabled with LMS Labs'],
            [$this->text_error(422, 'UNEXPECTED_FIELDS'), 'unexpected_fields', 'did not accept'],
            [$this->text_error(429, 'RATE_LIMITED'), 'rate_limited', 'Too many drafts'],
            [$this->text_error(502, 'INVALID_PROVIDER_RESULT'), 'invalid_provider_result', 'could not draft'],
            [$this->text_error(503, 'PROVIDER_UNAVAILABLE'), 'provider_unavailable', 'could not draft'],
            [[404, [], 'Not found'], 'http_404', 'not available yet'],
            [$this->draft_ok(['hazards' => []]),
                'unusable_draft', 'may have charged up to 5'],
        ];
        foreach ($cases as [$answer, $code, $text]) {
            $this->answers = [$answer];
            $row = requests::start_scene($this->instance, (int)$this->teacher->id, 'A brief');
            $this->assertSame('failed', $row->status, $code);
            $this->assertSame($code, $row->errorcode);
            $export = requests::export($row, $context);
            $this->assertStringContainsString($text, $export['message'], $code);
            $this->assertFalse($export['canrecheck']);
            $this->assertSame('failed', requests::check($row, $this->instance)->status);
        }
        $this->assertCount(count($cases), $this->sent, 'One request per intentional draft; nothing retried.');
        $this->assertSame(
            1,
            $DB->count_records('aisafetyhazards_scene', ['aisafetyhazardsid' => $this->instance->id]),
            'No scene from any failure.'
        );
    }

    /**
     * Input is checked before anything is stored or sent.
     */
    public function test_scene_input_rules(): void {
        global $DB;
        $bad = [
            ['', 'aidraft_briefrequired'],
            [str_repeat('é', requests::MAX_BRIEF + 1), 'aidraft_toolong'],
            ['Use <b>bold</b>', 'aidraft_nobrackets'],
        ];
        foreach ($bad as [$brief, $code]) {
            try {
                requests::start_scene($this->instance, (int)$this->teacher->id, $brief);
                $this->fail('Accepted ' . $code);
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
        // A custom task name the teacher typed is checked too.
        $custom = clone $this->instance;
        $custom->task = 'custom';
        $custom->customtask = str_repeat('a', requests::MAX_DETAIL + 1);
        try {
            requests::start_scene($custom, (int)$this->teacher->id, 'Brief');
            $this->fail('Accepted a long task name');
        } catch (\moodle_exception $e) {
            $this->assertSame('aidraft_toolong', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('aisafetyhazards_aireq'));
        $this->assertCount(0, $this->sent);
        // Exactly 2,000 code points (including multi-byte ones) is accepted; control characters are removed.
        $this->answers = [$this->draft_ok()];
        requests::start_scene($this->instance, (int)$this->teacher->id, str_repeat('ü', requests::MAX_BRIEF) . "\x07");
        $this->assertSame(requests::MAX_BRIEF, \core_text::strlen(json_decode($this->sent[0][2], true)['brief']));
        // Switched off: nothing is sent.
        set_config('aidrafts', 0, 'mod_aisafetyhazards');
        $this->expectException(\moodle_exception::class);
        requests::start_scene($this->instance, (int)$this->teacher->id, 'Brief');
    }

    /**
     * Scenes from an AI assistant are charged like drafts (5 credits each) and created only once LMS Labs confirms:
     * a refusal creates nothing, an unconfirmed charge is checked with the same key, and a confirmed one creates the
     * scenes exactly once.
     */
    public function test_import_charge(): void {
        global $DB;
        $scene = json_decode(file_get_contents(__DIR__ . '/fixtures/draft.json'), true)['scenes'][0];
        $draft = ['scenes' => [['title' => 'Late again'] + $scene, ['title' => 'The <b>complaint</b>'] + $scene]];
        $scenes = fn() => $DB->count_records('aisafetyhazards_scene', ['aisafetyhazardsid' => $this->instance->id]);
        $start = $scenes();
        $context = \context_module::instance(get_coursemodule_from_instance('aisafetyhazards', $this->instance->id)->id);

        // Not enough credits: nothing is created.
        $this->answers = [[402, ['content-type' => 'application/json'], json_encode(['requestId' => 'imp-0',
            'error' => ['code' => 'INSUFFICIENT_CREDITS', 'message' => 'x'], 'creditsBalance' => 2])]];
        $row = requests::start_import($this->instance, (int)$this->teacher->id, $draft);
        $this->assertSame('failed', $row->status);
        $this->assertSame($start, $scenes());
        $this->assertSame('https://lms-labs.com/api/moodle/ai-safetyhazards/scenes/import', $this->sent[0][0]);
        $this->assertSame(
            ['sceneCount' => 2, 'titles' => ['Late again', 'The complaint']],
            json_decode($this->sent[0][2], true),
            'Only the count and titles are sent.'
        );
        $this->assertStringContainsString('need 10 and 2 are left', requests::export($row, $context)['message']);

        // Not deployed at LMS Labs yet, whatever error code the 404 carries.
        $this->answers = [[404, ['content-type' => 'application/json'], json_encode(['error' => ['code' => 'NOT_FOUND']])]];
        $row = requests::start_import($this->instance, (int)$this->teacher->id, $draft);
        $this->assertStringContainsString('not switched on at LMS Labs yet', requests::export($row, $context)['message']);
        $this->assertSame($start, $scenes());

        // An unexpected server error is never taken as "not charged": the key is kept for "Check again".
        $this->answers = [[500, ['content-type' => 'application/json'], json_encode(['error' => ['code' => 'INTERNAL']])]];
        $row = requests::start_import($this->instance, (int)$this->teacher->id, $draft);
        $this->assertSame('uncertain', $row->status);
        requests::dismiss($row);

        // No answer: nothing is created yet; "Check again" uses the same key and creates the scenes once.
        $this->answers = [[0, [], '']];
        $row = requests::start_import($this->instance, (int)$this->teacher->id, $draft);
        $this->assertSame('uncertain', $row->status);
        $this->assertSame($start, $scenes());
        $this->answers = [[200, ['content-type' => 'application/json'], json_encode(['requestId' => 'imp-2',
            'creditsCharged' => 10, 'creditsBalance' => 44])]];
        $row = requests::check($row, $this->instance);
        $this->assertSame('completed', $row->status);
        $this->assertSame($this->sent[3][3], $this->sent[4][3]);
        $this->assertSame($this->sent[3][2], $this->sent[4][2]);
        $this->assertSame($start + 2, $scenes());
        $this->assertSame(10, (int)$row->charged);
        $this->assertStringContainsString('10 LMS Labs credits used; 44 left', requests::export($row, $context)['message']);
        // Checking a completed import again sends nothing and creates nothing.
        requests::check($row, $this->instance);
        $this->assertCount(5, $this->sent);
        $this->assertSame($start + 2, $scenes());
    }

    /**
     * A delivered picture is saved at once; a same-key check after completion is 410, so an undelivered picture is "lost".
     */
    public function test_image_delivered_and_lost(): void {
        $png = file_get_contents(__DIR__ . '/fixtures/scene.png');
        $context = \context_module::instance(get_coursemodule_from_instance('aisafetyhazards', $this->instance->id)->id);
        $this->answers = [[200, ['content-type' => 'image/png', 'x-request-id' => 'img-1', 'x-credits-charged' => '5',
            'x-credits-balance' => '90'], $png]];
        $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
        $this->assertSame('completed', $row->status);
        [$url, $headers, $body] = $this->sent[0];
        $this->assertSame('https://lms-labs.com/api/moodle/ai-safetyhazards/images', $url);
        $this->assertSame(['prompt', 'style'], array_keys(json_decode($body, true)));
        $this->assertContains('Accept: image/png, image/webp, image/jpeg, application/json', $headers);
        $this->assertNotNull(local\manager::get_scene_file($context, (int)$this->scene->id));
        $this->assertSame(90, (int)$row->balance);

        // Connection lost: the key is kept; checking again gets 410 because LMS Labs keeps no picture.
        $this->answers = [[0, [], ''], $this->image_error(410, 'RESULT_NOT_RETAINED')];
        $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
        $this->assertSame('uncertain', $row->status);
        $row = requests::check($row, $this->instance);
        $this->assertSame('lost', $row->status);
        $this->assertSame($this->sent[1][3], $this->sent[2][3]);
        $export = requests::export($row, $context);
        $this->assertStringContainsString('5 credits may have been charged', $export['message']);
        $this->assertFalse($export['canrecheck']);
        $this->assertSame('lost', requests::check($row, $this->instance)->status);
        $this->assertCount(3, $this->sent, 'A lost picture is never requested again automatically.');
        // A new picture is only a new, intentional request with a new key.
        $this->answers = [[200, ['content-type' => 'image/png'], 'not a png']];
        $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
        $this->assertNotSame($this->sent[2][3], $this->sent[3][3]);
        $this->assertSame('lost', $row->status, 'Charged but unusable bytes cannot be recovered.');
        $this->assertSame('unusable_image', $row->errorcode);
    }

    /**
     * A delivered picture is kept whatever its wrapping: raw PNG, JPEG or WebP (even with a wrong content type), or base64
     * in a JSON answer. Anything else is "lost", with the content type and size noted for LMS Labs support.
     */
    public function test_image_formats(): void {
        $context = \context_module::instance(get_coursemodule_from_instance('aisafetyhazards', $this->instance->id)->id);
        $png = file_get_contents(__DIR__ . '/fixtures/scene.png');
        $gd = imagecreatefrompng(__DIR__ . '/fixtures/scene.png');
        ob_start();
        imagejpeg($gd);
        $jpeg = ob_get_clean();
        $answers = [
            'jpeg' => [200, ['content-type' => 'image/jpeg'], $jpeg],
            'png as octet-stream' => [200, ['content-type' => 'application/octet-stream'], $png],
            'json base64' => [200, ['content-type' => 'application/json'], json_encode(['image' => base64_encode($png)])],
            'json data object' => [200, ['content-type' => 'application/json; charset=utf-8'],
                json_encode(['data' => ['b64_json' => base64_encode($png)]])],
            'json imageBase64 data url' => [200, ['content-type' => 'application/json'],
                json_encode(['imageBase64' => 'data:image/png;base64,' . base64_encode($png)])],
        ];
        if (function_exists('imagewebp')) {
            ob_start();
            imagewebp($gd);
            $answers['webp'] = [200, ['content-type' => 'image/webp'], ob_get_clean()];
        }
        foreach ($answers as $name => $answer) {
            $this->answers = [$answer];
            $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
            $this->assertSame('completed', $row->status, $name);
            $this->assertNotNull(local\manager::get_scene_file($context, (int)$this->scene->id), $name);
        }
        $this->answers = [[200, ['content-type' => 'text/html'], '<html>error</html>']];
        $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
        $this->assertSame('lost', $row->status);
        $this->assertSame(['contenttype' => 'text/html', 'bytes' => 18], json_decode($row->result, true));
        $this->assertNull(requests::image_bytes('application/json', json_encode(['image' => base64_encode('not a picture')])));
    }

    /**
     * Picture error envelope ({"error": "CODE"}), pending, unconfirmed and failure states.
     */
    public function test_image_states(): void {
        $context = \context_module::instance(get_coursemodule_from_instance('aisafetyhazards', $this->instance->id)->id);
        $this->answers = [[202, ['retry-after' => '5'], json_encode(['error' => 'PENDING', 'requestId' => 'img-p'])]];
        $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
        $this->assertSame('pending', $row->status);
        $this->assertSame('img-p', $row->requestid);
        $this->answers = [$this->image_error(503, 'IMAGE_UNAVAILABLE')];
        $row = requests::check($row, $this->instance);
        $this->assertSame('uncertain', $row->status);
        $this->answers = [$this->image_error(409, 'IDEMPOTENCY_CONFLICT')];
        $row = requests::check($row, $this->instance);
        $this->assertSame('conflict', $row->status);
        requests::dismiss($row);
        $cases = [
            [$this->image_error(402, 'INSUFFICIENT_CREDITS', ['balance' => 4]), 'insufficient_credits', '4 are left'],
            [$this->image_error(413, 'PROMPT_TOO_LONG'), 'prompt_too_long', 'too long'],
            [$this->image_error(502, 'UNUSABLE_IMAGE'), 'unusable_image', 'usable picture'],
            [$this->image_error(503, 'PROVIDER_UNAVAILABLE'), 'provider_unavailable', 'not available right now'],
            [[500, [], ''], 'http_500', 'could not create'],
        ];
        foreach ($cases as [$answer, $code, $text]) {
            $this->answers = [$answer];
            $row = requests::start_image($this->instance, (int)$this->teacher->id, $this->scene);
            $this->assertSame($code === 'http_500' ? 'uncertain' : 'failed', $row->status, $code);
            if ($row->status === 'uncertain') {
                requests::dismiss($row);
                continue;
            }
            $this->assertStringContainsString($text, requests::export($row, $context)['message'], $code);
        }
    }

    /**
     * Both error envelopes are read.
     */
    public function test_read_error(): void {
        $this->assertSame(['insufficient_credits', 'r1', 2], requests::read_error([], json_encode(['requestId' => 'r1',
            'error' => ['code' => 'INSUFFICIENT_CREDITS', 'message' => 'x'], 'creditsBalance' => 2])));
        $this->assertSame(['insufficient_credits', 'r2', 4], requests::read_error([], json_encode([
            'error' => 'INSUFFICIENT_CREDITS', 'requestId' => 'r2', 'balance' => 4])));
        $this->assertSame(['', 'h1', null], requests::read_error(['x-request-id' => 'h1'], 'not json'));
    }

    /**
     * Deleting the activity removes its requests.
     */
    public function test_delete_instance(): void {
        global $DB, $CFG;
        $this->answers = [[0, [], '']];
        requests::start_scene($this->instance, (int)$this->teacher->id, 'A brief');
        $this->assertSame(1, $DB->count_records('aisafetyhazards_aireq'));
        require_once($CFG->dirroot . '/mod/aisafetyhazards/lib.php');
        aisafetyhazards_delete_instance((int)$this->instance->id);
        $this->assertSame(0, $DB->count_records('aisafetyhazards_aireq'));
    }
}
