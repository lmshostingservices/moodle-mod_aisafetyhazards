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
use mod_aisafetyhazards\local\manager;
use mod_aisafetyhazards\local\voiceover;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for voiceover: the narrator voice, what is read, clips and the stored, charged request.
 * No network: the LMS Labs catalogue and speech route are faked.
 *
 * @package    mod_aisafetyhazards
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aisafetyhazards\local\voiceover
 */
#[CoversClass(voiceover::class)]
final class voiceover_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $instance;
    /** @var \stdClass */
    protected $scene;
    /** @var \context_module */
    protected $context;
    /** @var array Answers of the fake speech route. */
    protected $answers = [];
    /** @var array Bodies sent to the speech route. */
    protected $sent = [];

    /**
     * One English activity with the trenching scene; LMS Labs offers the eight en-AU voices.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $sh = $gen->get_plugin_generator('mod_aisafetyhazards');
        $this->instance = $sh->create_instance(['course' => $course->id, 'contentlang' => 'en']);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->scene = $sh->create_scene($this->instance, 'Trench');
        set_config('aivoice', 1, 'mod_aisafetyhazards');
        set_config('lmslabssiteid', 'My Site', 'mod_aisafetyhazards');
        set_config('lmslabsapikey', 'secret-key', 'mod_aisafetyhazards');
        $voices = array_map(fn($t) => ['name' => 'en-AU-Chirp3-HD-' . $t], voiceover::VOICETYPES);
        lmslabs::$transport = fn() => [200, json_encode(
            ['locales' => [
            ['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU', 'voices' => $voices]], 'tariff' => ['tts' => 2]]
        )];
        $this->answers = [];
        $this->sent = [];
        lmslabs::$posttransport = function ($url, $headers, $body) {
            $this->assertStringEndsWith(lmslabs::VOICE_ROUTE, $url);
            $this->assertContains('Accept: audio/mpeg, application/json', $headers);
            $this->sent[] = json_decode($body, true);
            return array_shift($this->answers);
        };
        $this->setUser($teacher);
    }

    /**
     * Resets the transports.
     */
    protected function tearDown(): void {
        lmslabs::$transport = null;
        lmslabs::$posttransport = null;
        parent::tearDown();
    }

    /**
     * One narrator reads everything: the setting, or Charon.
     */
    public function test_config(): void {
        $config = voiceover::config($this->instance);
        $this->assertSame('en-AU', $config['locale']);
        $this->assertSame('en-AU-Chirp3-HD-Charon', $config['narrator']);
        set_config('narratorvoice', 'Kore', 'mod_aisafetyhazards');
        $this->assertSame('en-AU-Chirp3-HD-Kore', voiceover::config($this->instance)['narrator']);
        set_config('narratorvoice', 'Nobody', 'mod_aisafetyhazards');
        $this->assertSame('en-AU-Chirp3-HD-Kore', voiceover::config($this->instance)['narrator'], 'The first voice.');
        $this->instance->contentlang = 'ja';
        $this->assertSame('', voiceover::config($this->instance)['locale']);
    }

    /**
     * A clip that has been made is never bought again.
     */
    public function test_made_clip_not_bought_again(): void {
        global $USER;
        $this->answers = [[200, ['content-type' => 'audio/mpeg'], "ID3\x03\x00" . str_repeat("\x00", 64)]];
        requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0);
        try {
            requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0);
            $this->fail('The same clip was bought twice');
        } catch (\moodle_exception $e) {
            $this->assertSame('voice_alreadymade', $e->errorcode);
        }
        $this->assertCount(1, $this->sent);
    }

    /**
     * The situation, each real hazard, and each label with a reason: right labels as "controls", wrong ones as
     * "feedback". Distractors are never read.
     */
    public function test_segments_and_parts(): void {
        $config = voiceover::config($this->instance);
        $hazards = manager::scene_hazards((int)$this->scene->id);
        $all = voiceover::segments($this->scene, $config, $hazards);
        $this->assertSame('situation', $all[0]['part']);
        $this->assertStringStartsWith('Trench. The excavator is digging', $all[0]['text']);
        $this->assertSame('scene', $all[0]['group']);
        $parts = array_count_values(array_column($all, 'part'));
        $this->assertSame(3, $parts['hazard']);
        $right = 0;
        $wrong = 0;
        foreach (array_slice($hazards, 0, 3) as $h) {
            foreach ($h['controls'] as $row) {
                foreach ($row as $c) {
                    if (trim($c['reason']) !== '') {
                        $c['correct'] ? $right++ : $wrong++;
                    }
                }
            }
        }
        $this->assertSame([$right, $wrong], [$parts['control'], $parts['feedback']]);
        $this->assertNotContains(
            'Hot work sparks. No welding or cutting is happening in this picture.',
            array_column($all, 'text')
        );
        $hazard = array_values(array_filter($all, fn($g) => $g['part'] === 'hazard'))[0];
        $this->assertSame('hazard:' . $hazards[0]['id'], $hazard['group']);
        $this->assertStringStartsWith('Trench wall collapse. ', $hazard['text']);
        $this->assertSame([$config['narrator']], array_values(array_unique(array_column($all, 'voice'))));
        $this->assertSame(range(0, count($all) - 1), array_column($all, 'index'));
        $some = voiceover::segments($this->scene, $config, $hazards, ['hazards']);
        $this->assertSame(['hazard', 'hazard', 'hazard'], array_column($some, 'part'));
        // By default the situation, the hazards and the right labels are read; the wrong labels' feedback is not.
        $this->assertSame(['situation', 'hazards', 'controls'], voiceover::parts($this->instance));
        $this->instance->voiceparts = 'hazards,bogus';
        $this->assertSame(['hazards'], voiceover::parts($this->instance));
    }

    /**
     * Clips are at most 200 characters, split at sentence ends.
     */
    public function test_chunks(): void {
        $this->assertSame([], voiceover::chunks('  '));
        $long = str_repeat('This is a sentence of words. ', 12);
        $chunks = voiceover::chunks($long);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(voiceover::MAX_CHARS, \core_text::strlen($chunk));
            $this->assertStringEndsWith('.', $chunk);
        }
        $this->assertSame(trim(preg_replace('/\s+/', ' ', $long)), implode(' ', $chunks));
    }

    /**
     * A delivered clip is saved and reused; a part plays only once all of its clips exist; nothing is charged twice.
     */
    public function test_request_flow(): void {
        global $DB, $USER;
        $mp3 = "ID3\x03\x00" . str_repeat("\x00", 64);
        $config = voiceover::config($this->instance);
        $segments = voiceover::segments($this->scene, $config, null, voiceover::parts($this->instance));
        $this->answers = [[200, ['content-type' => 'audio/mpeg', 'x-request-id' => 'v1'], $mp3]];
        $row = requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0);
        $this->assertSame('completed', $row->status);
        // No x-credits-charged header: the charge is not known, and is never assumed.
        $this->assertNull($row->charged);
        $this->assertStringContainsString(
            'did not say what it charged: at most 2 credits',
            requests::export($row, $this->context)['message']
        );
        $this->assertSame(['text', 'locale', 'speed', 'voice'], array_keys($this->sent[0]));
        $this->assertSame($segments[0]['text'], $this->sent[0]['text']);
        $this->assertCount(count($segments) - 1, voiceover::missing($this->instance, $this->context));
        $playlist = voiceover::playlist($this->instance, $this->context, $this->scene);
        $this->assertSame(
            array_sum(array_map(fn($g) => $g['group'] === 'scene' ? 1 : 0, $segments)),
            count($playlist['scene'])
        );
        // A hazard plays once its clips are made.
        $hazard = array_values(array_filter($segments, fn($g) => $g['part'] === 'hazard'))[0];
        $this->assertSame([], $playlist['hazard']);
        $this->answers = [[200, ['content-type' => 'audio/mpeg'], $mp3]];
        requests::start_voice($this->instance, (int)$USER->id, $this->scene, $hazard['index']);
        $playlist = voiceover::playlist($this->instance, $this->context, $this->scene);
        $this->assertSame([$hazard['line']], array_keys($playlist['hazard']));
        // Not audio: lost, nothing saved.
        $this->answers = [[200, ['content-type' => 'application/json'], '{"ok":true}']];
        $row = requests::start_voice($this->instance, (int)$USER->id, $this->scene, $hazard['index'] + 1);
        $this->assertSame('lost', $row->status);
        $this->assertSame('unusable_audio', $row->errorcode);
        // The route is not live: nothing charged, a clear message.
        $this->answers = [[404, ['content-type' => 'application/json'], '{"error":{"code":"NOT_FOUND"}}']];
        $row = requests::start_voice($this->instance, (int)$USER->id, $this->scene, $hazard['index'] + 2);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('not available yet', requests::export($row, $this->context)['message']);
        $this->assertSame(
            2,
            $DB->count_records_select(
                'files',
                "component = 'mod_aisafetyhazards' AND filearea = 'voiceover'
            AND itemid = :id AND filename <> '.'",
                ['id' => $this->scene->id]
            )
        );
    }

    /**
     * LMS Labs answers that say "not made" end the request; unknown outcomes keep the key for "Check again".
     */
    public function test_error_codes(): void {
        global $DB, $USER;
        $json = ['content-type' => 'application/json'];
        $cases = [
            [503, 'VOICEOVER_NOT_ENABLED', 'failed', 'not switched on'],
            [503, 'TARIFF_NOT_APPROVED', 'failed', 'not switched on'],
            [503, 'PROVIDER_NOT_CONFIGURED', 'failed', 'configuration problem'],
            [502, 'UNUSABLE_AUDIO', 'failed', 'not been charged'],
            [503, 'SETTLEMENT_UNCONFIRMED', 'uncertain', ''],
            [503, 'TTS_UNAVAILABLE', 'uncertain', ''],
            [500, '', 'uncertain', ''],
            [410, 'GONE', 'lost', 'contact LMS Labs support before'],
        ];
        foreach ($cases as $i => [$status, $code, $want, $text]) {
            // An unresolved request blocks the next one for the scene: clear it, as "Dismiss" would.
            $DB->delete_records_select('aisafetyhazards_aireq', "status IN ('uncertain', 'lost')");
            $body = json_encode(['requestId' => 'r' . $i, 'error' => ['code' => $code, 'message' => 'x']]);
            $this->answers = [[$status, $json, $body]];
            $row = requests::start_voice($this->instance, (int)$USER->id, $this->scene, $i);
            $this->assertSame($want, $row->status, $code);
            if ($text !== '') {
                $this->assertStringContainsString($text, requests::export($row, $this->context)['message'], $code);
            }
        }
    }

    /**
     * Free remakes: once LMS Labs says it supports them, every clip carries its place (clipRef) and the price the
     * teacher confirmed (maxCredits); the place stays the same when the text changes; a price that went up is refused.
     */
    public function test_free_remakes(): void {
        global $DB, $USER;
        $mp3 = "ID3\x03\x00" . str_repeat("\x00", 64);
        // Not supported yet: the body is as before.
        $this->answers = [[200, ['content-type' => 'audio/mpeg'], $mp3]];
        requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0, 0);
        $this->assertSame(['text', 'locale', 'speed', 'voice'], array_keys($this->sent[0]));
        // The catalogue says LMS Labs takes clipRef and maxCredits.
        $voices = array_map(fn($t) => ['name' => 'en-AU-Chirp3-HD-' . $t], voiceover::VOICETYPES);
        lmslabs::$transport = fn() => [200, json_encode(
            ['locales' => [['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU',
            'voices' => $voices]], 'tariff' => ['tts' => 2, 'ttsRemake' => 0, 'ttsRemakeLimit' => 10,
            'ttsRemakeWindowDays' => 30, 'clipRefSupported' => true, 'maxCreditsRequired' => true]]
        )];
        \cache::make('mod_aisafetyhazards', 'voicecatalog')->purge();
        voiceover::catalog();
        $this->assertSame(['limit' => 10, 'days' => 30], voiceover::remakes());
        $this->answers = [[200, ['content-type' => 'audio/mpeg', 'x-credits-charged' => '0'], $mp3]];
        $row = requests::start_voice($this->instance, (int)$USER->id, $this->scene, 1, 0);
        $this->assertSame('completed', $row->status);
        $this->assertSame(0, (int)$row->charged);
        $body = $this->sent[1];
        $this->assertSame(['text', 'locale', 'speed', 'voice', 'clipRef', 'maxCredits'], array_keys($body));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['clipRef']);
        $this->assertSame(0, $body['maxCredits']);
        // The same place keeps its reference when its text changes; other places differ.
        $config = voiceover::config($this->instance);
        $before = voiceover::segments($this->scene, $config);
        $DB->set_field('aisafetyhazards_scene', 'context', 'A new situation.', ['id' => $this->scene->id]);
        $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $this->scene->id]);
        $after = voiceover::segments($scene, $config);
        $this->assertNotSame($before[0]['text'], $after[0]['text']);
        $this->assertSame(
            voiceover::clipref($this->instance, (int)$scene->id, $before[0]),
            voiceover::clipref($this->instance, (int)$scene->id, $after[0])
        );
        $this->assertNotSame(
            voiceover::clipref($this->instance, (int)$scene->id, $after[0]),
            voiceover::clipref($this->instance, (int)$scene->id, $after[1])
        );
        // A free clip that became paid: refused before anything was made; the teacher is asked again.
        $this->answers = [[409, ['content-type' => 'application/json'],
            json_encode(['requestId' => 'r9', 'error' => ['code' => 'PRICE_CHANGED', 'message' => 'x']])]];
        $row = requests::start_voice($this->instance, (int)$USER->id, $scene, 3, 0);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('no longer free', requests::export($row, $this->context)['message']);
        // Without a confirmed price the ceiling is the full price.
        $this->answers = [[200, ['content-type' => 'audio/mpeg', 'x-credits-charged' => '2'], $mp3]];
        requests::start_voice($this->instance, (int)$USER->id, $scene, 2);
        $this->assertSame(2, end($this->sent)['maxCredits']);
    }

    /**
     * While LMS Labs publishes another price per clip than the approved 2 credits, no new clip is asked for, and the
     * Voiceover step says why; once it publishes 2, clips are made again.
     */
    public function test_price_hold(): void {
        global $USER;
        $this->assertSame(2, lmslabs::VOICE_CREDITS);
        $voices = array_map(fn($t) => ['name' => 'en-AU-Chirp3-HD-' . $t], voiceover::VOICETYPES);
        $catalogue = fn($tts) => fn() => [200, json_encode(
            ['locales' => [['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU',
            'voices' => $voices]], 'tariff' => ['tts' => $tts]]
        )];
        lmslabs::$transport = $catalogue(5);
        \cache::make('mod_aisafetyhazards', 'voicecatalog')->purge();
        voiceover::catalog();
        $this->assertSame(5, voiceover::price_hold());
        $this->sent = [];
        try {
            requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0);
            $this->fail('No clip may be made at a price the teacher was not shown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('voice_pricehold', $e->errorcode);
        }
        $this->assertSame([], $this->sent);
        lmslabs::$transport = $catalogue(2);
        \cache::make('mod_aisafetyhazards', 'voicecatalog')->purge();
        voiceover::catalog();
        $this->assertNull(voiceover::price_hold());
        // A clip with no charge reported is shown with the approved price.
        $row = (object)['id' => 0, 'operation' => requests::VOICE, 'status' => 'completed', 'charged' => null,
            'balance' => null, 'requestid' => 'r-old', 'timecreated' => time() - 60, 'timemodified' => time() - 60,
            'targetid' => (int)$this->scene->id, 'retryafter' => null, 'error' => null,
            'body' => json_encode(['text' => 'x', 'locale' => 'en-AU', 'speed' => 'normal', 'voice' => 'v'])];
        $this->assertStringContainsString('at most 2 credits', requests::export($row, $this->context)['message']);
        // No published price: the price is unknown, so nothing new is made either (a clip sent without a ceiling to an
        // older LMS Labs could cost more than the teacher was shown).
        lmslabs::$transport = fn() => [200, json_encode(
            ['locales' => [['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU',
            'voices' => $voices]], 'tariff' => []]
        )];
        \cache::make('mod_aisafetyhazards', 'voicecatalog')->purge();
        voiceover::catalog();
        $this->assertSame(0, voiceover::price_hold());
        try {
            requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0);
            $this->fail('No clip may be made while its price is unknown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('voice_priceunknown', $e->errorcode);
        }
        $this->assertSame([], $this->sent);
    }

    /**
     * Prices come from the free quote route; no price means the full price.
     */
    public function test_quote(): void {
        $voices = array_map(fn($t) => ['name' => 'en-AU-Chirp3-HD-' . $t], voiceover::VOICETYPES);
        lmslabs::$transport = fn() => [200, json_encode(
            ['locales' => [['locale' => 'en-AU', 'ttsLanguageCode' => 'en-AU',
            'voices' => $voices]], 'tariff' => ['tts' => 2, 'clipRefSupported' => true, 'maxCreditsRequired' => true]]
        )];
        \cache::make('mod_aisafetyhazards', 'voicecatalog')->purge();
        voiceover::catalog();
        $quotes = [];
        lmslabs::$posttransport = function ($url, $headers, $body) use (&$quotes) {
            $this->assertStringEndsWith(lmslabs::VOICE_QUOTE_ROUTE, $url);
            $quotes[] = json_decode($body, true);
            return count($quotes) === 1
                ? [200, ['content-type' => 'application/json'], json_encode(
                    ['requestId' => 'q', 'credits' => 0,
                    'firstClip' => false, 'freeRemakesRemaining' => 7, 'windowDays' => 30, 'freeRemakeLimit' => 10]
                )]
                : [503, ['content-type' => 'application/json'], '{}'];
        };
        $this->assertSame(0, requests::quote_voice($this->instance, $this->scene, 0));
        $this->assertSame(2, requests::quote_voice($this->instance, $this->scene, 1));
        $this->assertSame(['clipRef'], array_keys($quotes[0]));
        set_config('voiceremakes', '', 'mod_aisafetyhazards');
        $this->assertSame(2, requests::quote_voice($this->instance, $this->scene, 0));
        $this->assertCount(2, $quotes, 'No quote is asked for while LMS Labs does not support remakes.');
    }

    /**
     * Voiceover off: no request can be made.
     */
    public function test_off(): void {
        global $USER;
        set_config('aivoice', 0, 'mod_aisafetyhazards');
        $this->expectException(\moodle_exception::class);
        requests::start_voice($this->instance, (int)$USER->id, $this->scene, 0);
    }

    /**
     * A learner's page never asks LMS Labs: it uses the catalogue kept from the last fetch.
     */
    public function test_learner_config_uses_kept_catalogue(): void {
        voiceover::config($this->instance);
        \cache::make('mod_aisafetyhazards', 'voicecatalog')->purge();
        lmslabs::$transport = function () {
            $this->fail('A learner page asked LMS Labs.');
        };
        $this->assertSame('en-AU', voiceover::learner_config($this->instance)['locale']);
    }
}
