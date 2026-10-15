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

namespace mod_aisafetyhazards\local\ai;

use mod_aisafetyhazards\local\catalogue;
use mod_aisafetyhazards\local\lesson;
use mod_aisafetyhazards\local\manager;
use mod_aisafetyhazards\local\voiceover;
use moodle_exception;
use stdClass;

/**
 * Paid LMS Labs requests (scene drafts, imports, pictures and voiceover clips), stored before they are sent.
 *
 * Every intentional request gets one Idempotency-Key, saved with the exact JSON body in aisafetyhazards_aireq before the
 * first send. "Check again" resends that same key and body, so LMS Labs can never charge twice for it; a new key is
 * only made when a teacher intentionally asks for a new draft or picture. Nothing is retried automatically, except
 * that the page asks again about a request LMS Labs reported as still in progress (HTTP 202, Retry-After).
 *
 * The two operations recover differently, as their contracts do:
 * - scene drafts: a same-key request after success replays the stored draft for 24 hours without a second charge;
 *   410 means the draft expired.
 * - pictures: LMS Labs keeps no picture bytes, so a same-key request after completion returns 410 and the picture
 *   cannot be recovered ("lost"); the teacher decides whether to ask for a new one.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests {
    /** @var string Scene draft operation. */
    public const SCENE = 'scene';

    /** @var string Scene picture operation. */
    public const IMAGE = 'image';

    /** @var string Charge for scenes made with the teacher's own AI assistant (5 credits per scene, like a draft). */
    public const IMPORT = 'import';

    /** @var string One voiceover clip of a scene (2 credits per clip LMS Labs makes). */
    public const VOICE = 'voice';

    /** @var int Most voiceover clips one teacher may ask for in an hour. */
    public const VOICE_RATE = 400;

    /** @var int Most scenes one import may bring in. */
    public const MAX_IMPORT = lesson::MAX_SCENES;

    /** @var int Longest brief, in Unicode code points. */
    public const MAX_BRIEF = 2000;

    /** @var int Longest audience or context, in Unicode code points. */
    public const MAX_DETAIL = 500;

    /** @var int Seconds to wait before asking again about a request in progress, when LMS Labs does not say. */
    public const RETRY_AFTER = 5;

    /** @var string[] States that need the teacher's attention and are listed on the page. */
    public const OPEN = ['pending', 'uncertain', 'conflict', 'expired', 'lost'];

    /** @var string[] States in which the same request may be sent again with the same key and body. */
    public const CHECKABLE = ['pending', 'uncertain'];

    /** @var string[] Text error codes that leave the outcome unknown: the key is kept for "Check again". */
    protected const TEXT_UNCERTAIN = ['settlement_unconfirmed', 'deadline_exceeded'];

    /** @var string[] Documented 5xx codes LMS Labs uses when the provider failed before anything was charged. */
    protected const PROVIDER_REFUSED = ['provider_unavailable', 'provider_failed', 'provider_rate_limited',
        'invalid_provider_result', 'image_failed', 'unusable_image', 'unusable_audio', 'voiceover_not_enabled',
        'tariff_not_approved', 'provider_not_configured'];

    /** @var string[] Voiceover error codes that leave the outcome unknown: the key is kept for "Check again". */
    protected const VOICE_UNCERTAIN = ['settlement_unconfirmed', 'deadline_exceeded', 'tts_unavailable'];

    /** @var string[] Picture error codes that leave the outcome unknown: the key is kept for "Check again". */
    protected const IMAGE_UNCERTAIN = ['settlement_unconfirmed', 'deadline_exceeded', 'image_unavailable'];

    /**
     * Cleans teacher text for a draft request: normalised line breaks, no other control characters.
     *
     * @param string $value
     * @return string
     */
    public static function clean_input(string $value): string {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[^\P{Cc}\t\n]/u', '', $value) ?? '';
        return trim($value);
    }

    /**
     * The draft request body: the activity's industry, sub-industry and work task (in English), the content language
     * and the teacher's brief, checked against the route's rules so a request LMS Labs would reject is never sent.
     *
     * @param stdClass $instance
     * @param string $brief
     * @return array the JSON body
     */
    public static function scene_body(stdClass $instance, string $brief): array {
        [$industry, $sub, $task] = catalogue::names_english($instance);
        $fields = ['brief' => self::clean_input($brief), 'industry' => self::clean_input($industry),
            'subindustry' => self::clean_input($sub), 'task' => self::clean_input($task)];
        if ($fields['brief'] === '') {
            throw new moodle_exception('aidraft_briefrequired', 'mod_aisafetyhazards');
        }
        foreach ($fields as $name => $value) {
            $max = $name === 'brief' ? self::MAX_BRIEF : self::MAX_DETAIL;
            if (\core_text::strlen($value) > $max) {
                throw new moodle_exception(
                    'aidraft_toolong',
                    'mod_aisafetyhazards',
                    '',
                    (object)['field' => get_string('aidraft_' . $name, 'mod_aisafetyhazards'), 'max' => $max]
                );
            }
            if (strpbrk($value, '<>') !== false) {
                throw new moodle_exception('aidraft_nobrackets', 'mod_aisafetyhazards');
            }
        }
        $fields['language'] = catalogue::language_english((string)$instance->contentlang);
        return $fields;
    }

    /**
     * Starts one intentional scene draft request (a new key), stores it, then sends it once.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param string $brief
     * @return stdClass the stored request
     */
    public static function start_scene(stdClass $instance, int $userid, string $brief): stdClass {
        if (!(new lmslabs())->can_draft()) {
            throw new moodle_exception('ainotavailable', 'mod_aisafetyhazards');
        }
        return self::start($instance, $userid, self::SCENE, 0, self::scene_body($instance, $brief));
    }

    /**
     * Starts one intentional picture request for a scene (a new key), stores it, then sends it once.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param stdClass $scene
     * @return stdClass the stored request
     */
    public static function start_image(stdClass $instance, int $userid, stdClass $scene): stdClass {
        if (!(new lmslabs())->can_generate()) {
            throw new moodle_exception('ainotavailable', 'mod_aisafetyhazards');
        }
        $prompt = self::clean_input(lesson::image_prompt($instance, $scene));
        if ($prompt === '' || \core_text::strlen($prompt) > lmslabs::MAX_PROMPT) {
            throw new moodle_exception('aierror_prompt_too_long', 'mod_aisafetyhazards', '', (object)['requestid' => '-']);
        }
        // A picture already asked for and not resolved (still being made, or its answer was lost) is shown again,
        // never sent again with a new key: the teacher uses "Check again" on it, or dismisses it.
        $waiting = self::waiting((int)$instance->id, self::IMAGE, (int)$scene->id);
        if ($waiting !== null) {
            return $waiting;
        }
        $style = in_array((string)$instance->imagestyle, lmslabs::STYLES, true) ? (string)$instance->imagestyle
            : 'illustration';
        $body = ['prompt' => $prompt, 'style' => $style];
        if (lmslabs::image_anchors()) {
            $anchors = array_values(\mod_aisafetyhazards\local\image_positions::anchors($scene));
            if ($anchors) {
                $body['anchors'] = $anchors;
            }
        }
        return self::start($instance, $userid, self::IMAGE, (int)$scene->id, $body);
    }

    /**
     * Starts one intentional voiceover clip request for a scene (a new key), stores it, then sends it once.
     *
     * The body is the clip's text, locale, speed and voice exactly as {@see voiceover::body()} makes it; the clip is
     * saved under a name made from the same values, so a line that changes meanwhile never gets the old audio.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param stdClass $scene
     * @param int $index clip number in the scene's playing order
     * @return stdClass the stored request
     */
    public static function start_voice(
        stdClass $instance,
        int $userid,
        stdClass $scene,
        int $index,
        ?int $maxcredits = null
    ): stdClass {
        if (!voiceover::enabled()) {
            throw new moodle_exception('ainotavailable', 'mod_aisafetyhazards');
        }
        $segments = voiceover::segments($scene, voiceover::config($instance), null, voiceover::parts($instance));
        if (!isset($segments[$index])) {
            throw new moodle_exception('voice_nolocale', 'mod_aisafetyhazards');
        }
        $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id, $instance->course, false, MUST_EXIST);
        if (voiceover::url(\context_module::instance($cm->id), (int)$scene->id, $segments[$index]) !== '') {
            // Already made (another page or tab made it): never buy the same clip twice.
            throw new moodle_exception('voice_alreadymade', 'mod_aisafetyhazards');
        }
        if (($published = voiceover::price_hold()) !== null) {
            throw new moodle_exception(
                $published ? 'voice_pricehold' : 'voice_priceunknown',
                'mod_aisafetyhazards',
                '',
                ['published' => $published, 'approved' => lmslabs::VOICE_CREDITS]
            );
        }
        $body = voiceover::body($segments[$index]);
        if (voiceover::remakes() !== null) {
            // Free remakes: the clip's place, and the most the teacher confirmed (LMS Labs refuses a higher price).
            $max = $maxcredits === 0 ? 0 : lmslabs::VOICE_CREDITS;
            $body = voiceover::body($segments[$index], voiceover::clipref($instance, (int)$scene->id, $segments[$index]), $max);
        }
        return self::start($instance, $userid, self::VOICE, (int)$scene->id, $body);
    }

    /**
     * The current price of clips (free; nothing is made). A clip with no price from LMS Labs counts as a full-price
     * clip, so the teacher never confirms less than a clip can cost.
     *
     * @param stdClass $instance
     * @param stdClass $scene
     * @param int $index
     * @param array|null $segments the scene's clips, when already worked out
     * @return int 0 or the price per clip
     */
    public static function quote_voice(stdClass $instance, stdClass $scene, int $index, ?array $segments = null): int {
        if (voiceover::remakes() === null) {
            return lmslabs::VOICE_CREDITS;
        }
        if ($segments === null) {
            $segments = voiceover::segments($scene, voiceover::config($instance), null, voiceover::parts($instance));
        }
        if (!isset($segments[$index])) {
            throw new moodle_exception('voice_nolocale', 'mod_aisafetyhazards');
        }
        $quote = lmslabs::voice_quote(voiceover::clipref($instance, (int)$scene->id, $segments[$index]));
        return $quote === null ? lmslabs::VOICE_CREDITS : $quote['credits'];
    }

    /**
     * Charges for scenes made with the teacher's own AI assistant, then creates them once LMS Labs confirms.
     *
     * The scenes are kept with the request before anything is sent; LMS Labs receives only how many there are and
     * their titles. They are created only after a 200, so a refused or unconfirmed charge creates nothing, and
     * "Check again" (same key) can never charge twice.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param array $draft parsed lesson draft (scenes)
     * @return stdClass the stored request
     */
    public static function start_import(stdClass $instance, int $userid, array $draft): stdClass {
        if (\mod_aisafetyhazards\local\credentials::find() === null) {
            // Checked before anything is stored, so no request is left waiting for a connection that does not exist.
            throw new moodle_exception('ainotavailable', 'mod_aisafetyhazards');
        }
        $scenes = lesson::clean($draft)['scenes'];
        if (!$scenes) {
            throw new moodle_exception('lessonempty', 'mod_aisafetyhazards');
        }
        if (count($scenes) > self::MAX_IMPORT) {
            throw new moodle_exception('import_toomany', 'mod_aisafetyhazards', '', self::MAX_IMPORT);
        }
        $titles = [];
        foreach (array_values($scenes) as $i => $scene) {
            $title = trim(\core_text::substr(str_replace(['<', '>'], '', self::clean_input((string)$scene['title'])), 0, 200));
            // LMS Labs needs a title with text for every scene.
            $titles[] = $title !== '' ? $title : get_string('scenex', 'mod_aisafetyhazards', $i + 1);
        }
        return self::start(
            $instance,
            $userid,
            self::IMPORT,
            0,
            ['sceneCount' => count($scenes), 'titles' => array_values($titles)],
            json_encode(['scenes' => $scenes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Stores a new request with a new key and its exact body, then sends it once.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param string $operation
     * @param int $targetid
     * @param array $body
     * @param string|null $result kept with the request before sending (an import's scenes, created on delivery)
     * @return stdClass
     */
    protected static function start(
        stdClass $instance,
        int $userid,
        string $operation,
        int $targetid,
        array $body,
        ?string $result = null
    ): stdClass {
        global $DB;
        // Two pages asking at the same moment must not both pass the check below.
        $lock = \core\lock\lock_config::get_lock_factory('mod_aisafetyhazards_aireq')
            ->get_lock('start' . $instance->id . $operation . $targetid, 10);
        if (!$lock) {
            throw new moodle_exception('aireq_busy', 'mod_aisafetyhazards');
        }
        try {
            $row = self::start_locked($instance, $userid, $operation, $targetid, $body, $result);
        } finally {
            $lock->release();
        }
        return self::send($row, $instance);
    }

    /**
     * Checks and stores a new request while holding the start lock.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param string $operation
     * @param int $targetid
     * @param array $body
     * @param string|null $result
     * @return stdClass the stored request, not sent yet
     */
    protected static function start_locked(
        stdClass $instance,
        int $userid,
        string $operation,
        int $targetid,
        array $body,
        ?string $result
    ): stdClass {
        global $DB;
        // One unresolved request at a time for the same thing: check it (same key) or dismiss it first.
        [$insql, $params] = $DB->get_in_or_equal(self::CHECKABLE, SQL_PARAMS_NAMED);
        $params += ['aid' => $instance->id, 'op' => $operation, 'target' => $targetid];
        if (
            $DB->record_exists_select(
                'aisafetyhazards_aireq',
                "aisafetyhazardsid = :aid AND operation = :op AND targetid = :target AND status $insql",
                $params
            )
        ) {
            throw new moodle_exception('aireq_busy', 'mod_aisafetyhazards');
        }
        if ($operation === self::VOICE) {
            self::check_voice_rate($userid);
        } else {
            lesson::check_ai_rate($userid);
        }
        $now = time();
        $row = (object)[
            'aisafetyhazardsid' => (int)$instance->id,
            'userid' => $userid,
            'operation' => $operation,
            'targetid' => $targetid,
            'idemkey' => \core\uuid::generate(),
            'sitehash' => self::sitehash(),
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'result' => $result,
            'tries' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        // Saved (and committed) before anything is sent, so the key survives a lost connection or a closed page.
        $row->id = $DB->insert_record('aisafetyhazards_aireq', $row);
        lesson::log_ai((int)$instance->id, $userid, $operation, 'sent');
        return $row;
    }

    /**
     * Voiceover has its own limit: one activity needs many short clips (each costing 1 credit), so it is not counted
     * against the drafts-and-pictures limit; at most VOICE_RATE clips are asked for per teacher per hour.
     *
     * @param int $userid
     */
    protected static function check_voice_rate(int $userid): void {
        global $DB;
        $count = $DB->count_records_select(
            'aisafetyhazards_ailog',
            'userid = :userid AND action = :action AND status = :status AND timecreated > :since',
            ['userid' => $userid, 'action' => self::VOICE, 'status' => 'sent', 'since' => time() - HOURSECS]
        );
        if ($count >= self::VOICE_RATE) {
            throw new moodle_exception('airatelimit', 'mod_aisafetyhazards');
        }
    }

    /**
     * Gets one request of an activity.
     *
     * @param int $instanceid
     * @param int $requestid
     * @return stdClass
     */
    public static function get(int $instanceid, int $requestid): stdClass {
        global $DB;
        return $DB->get_record(
            'aisafetyhazards_aireq',
            ['id' => $requestid, 'aisafetyhazardsid' => $instanceid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Asks LMS Labs again about a pending or uncertain request, with the same key and body.
     *
     * @param stdClass $row
     * @param stdClass $instance
     * @return stdClass
     */
    public static function check(stdClass $row, stdClass $instance): stdClass {
        if (!in_array($row->status, self::CHECKABLE, true)) {
            return $row;
        }
        return self::send($row, $instance);
    }

    /**
     * Hides a request from the page. A pending or uncertain request keeps its record (and key) for reference.
     *
     * @param stdClass $row
     * @return stdClass
     */
    public static function dismiss(stdClass $row): stdClass {
        global $DB;
        if (in_array($row->status, self::OPEN, true)) {
            $row->errorcode = $row->errorcode ?: $row->status;
            $row->status = 'dismissed';
            $row->timemodified = time();
            $DB->update_record('aisafetyhazards_aireq', $row);
        }
        return $row;
    }

    /**
     * The unresolved request (pending or uncertain) for one thing, if there is one.
     *
     * @param int $instanceid
     * @param string $operation
     * @param int $targetid
     * @return stdClass|null
     */
    public static function waiting(int $instanceid, string $operation, int $targetid): ?stdClass {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::CHECKABLE, SQL_PARAMS_NAMED);
        $params += ['aid' => $instanceid, 'op' => $operation, 'target' => $targetid];
        $rows = $DB->get_records_select(
            'aisafetyhazards_aireq',
            "aisafetyhazardsid = :aid AND operation = :op AND targetid = :target AND status $insql",
            $params,
            'id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Requests of an activity that need attention, newest first.
     *
     * @param int $instanceid
     * @param string $operation
     * @return stdClass[]
     */
    public static function open(int $instanceid, string $operation): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::OPEN, SQL_PARAMS_NAMED);
        $params += ['aid' => $instanceid, 'op' => $operation];
        return array_values(
            $DB->get_records_select(
                'aisafetyhazards_aireq',
                "aisafetyhazardsid = :aid AND operation = :op AND status $insql",
                $params,
                'timecreated DESC, id DESC'
            )
        );
    }

    /**
     * Sends a stored request (same key, same body) and records the outcome.
     *
     * @param stdClass $row
     * @param stdClass $instance
     * @return stdClass
     */
    protected static function send(stdClass $row, stdClass $instance): stdClass {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('mod_aisafetyhazards_aireq');
        $lock = $factory->get_lock('req' . $row->id, 5);
        if (!$lock) {
            // Another page is sending this request right now; it stays pending until that finishes.
            return $row;
        }
        try {
            $row = $DB->get_record('aisafetyhazards_aireq', ['id' => $row->id], '*', MUST_EXIST);
            if (!in_array($row->status, self::CHECKABLE, true)) {
                return $row;
            }
            $image = $row->operation === self::IMAGE;
            if ((string)$row->sitehash !== '' && $row->sitehash !== self::sitehash()) {
                // The key belongs to another LMS Labs site: sending it now could start a second, separately charged
                // request. The teacher dismisses it and asks again intentionally.
                throw new moodle_exception('aireq_sitechanged', 'mod_aisafetyhazards');
            }
            $row->tries++;
            $row->timemodified = time();
            $DB->update_record('aisafetyhazards_aireq', $row);
            $import = $row->operation === self::IMPORT;
            $voice = $row->operation === self::VOICE;
            $routes = [self::IMAGE => lmslabs::IMAGE_ROUTE, self::IMPORT => lmslabs::IMPORT_ROUTE,
                self::VOICE => lmslabs::VOICE_ROUTE, self::SCENE => lmslabs::TEXT_ROUTE];
            $accept = [self::IMAGE => 'image/png, image/webp, image/jpeg, application/json',
                self::VOICE => 'audio/mpeg, application/json'];
            // A picture with hazard positions can take LMS Labs up to two minutes: wait for it, and keep this page
            // running for as long, so the answer is never cut off and lost.
            \core_php_time_limit::raise(240);
            [$status, $headers, $body] = lmslabs::post(
                $routes[$row->operation],
                (string)$row->body,
                (string)$row->idemkey,
                $accept[$row->operation] ?? 'application/json',
                $image ? lmslabs::IMAGE_TIMEOUT : 100
            );
            $row->retryafter = max(1, min(60, (int)($headers['retry-after'] ?? self::RETRY_AFTER)));
            if ($image) {
                self::image_outcome($row, $instance, $status, $headers, $body);
            } else if ($import) {
                self::import_outcome($row, $instance, $status, $headers, $body);
            } else if ($voice) {
                self::voice_outcome($row, $instance, $status, $headers, $body);
            } else {
                self::scene_outcome($row, $instance, $status, $headers, $body);
            }
            $row->timemodified = time();
            $DB->update_record('aisafetyhazards_aireq', $row);
            lesson::log_ai((int)$instance->id, (int)$row->userid, $row->operation, $row->status);
            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * SHA-1 of the Site ID in use now (the Site ID itself is not stored with requests).
     *
     * @return string
     */
    protected static function sitehash(): string {
        $credentials = \mod_aisafetyhazards\local\credentials::find();
        return $credentials === null ? '' : sha1((string)$credentials['siteid']);
    }

    /**
     * Reads the error code, LMS Labs request id and balance from either error envelope.
     *
     * Text routes answer {"requestId", "error": {"code", "message"}, "creditsBalance"?}; picture routes answer
     * {"error": "CODE", "requestId", "balance"?}.
     *
     * @param array $headers
     * @param string $body
     * @return array [code (lower case, '' when none), requestid, balance or null]
     */
    public static function read_error(array $headers, string $body): array {
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];
        $error = $data['error'] ?? '';
        $code = is_array($error) ? (string)($error['code'] ?? '') : (is_string($error) ? $error : '');
        $code = strtolower(clean_param($code, PARAM_ALPHANUMEXT));
        $requestid = clean_param((string)($headers['x-request-id'] ?? ''), PARAM_ALPHANUMEXT);
        if ($requestid === '' && is_scalar($data['requestId'] ?? null)) {
            $requestid = clean_param((string)$data['requestId'], PARAM_ALPHANUMEXT);
        }
        $balance = $data['creditsBalance'] ?? ($data['balance'] ?? null);
        return [$code, substr($requestid, 0, 64), is_numeric($balance) ? (int)$balance : null];
    }

    /**
     * Records the outcome of a scene draft request; a delivered draft becomes a scene in the same transaction.
     *
     * @param stdClass $row
     * @param stdClass $instance
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    protected static function scene_outcome(
        stdClass $row,
        stdClass $instance,
        int $status,
        array $headers,
        string $body
    ): void {
        global $DB;
        [$code, $requestid, $balance] = self::read_error($headers, $body);
        $row->requestid = $requestid !== '' ? $requestid : $row->requestid;
        if ($status === 200) {
            $data = json_decode($body, true);
            // What LMS Labs reports it charged; not reported means not known (never assumed).
            $row->charged = self::reported($data['creditsCharged'] ?? null);
            $row->balance = isset($data['creditsBalance']) && is_numeric($data['creditsBalance'])
                ? (int)$data['creditsBalance'] : null;
            $draft = null;
            try {
                $draft = is_array($data) && is_array($data['draft'] ?? null)
                    ? lesson::clean(['scenes' => [$data['draft']]])['scenes'][0] : null;
            } catch (\moodle_exception $e) {
                $draft = null;
            }
            if ($draft === null) {
                // Delivered and charged, but not usable here. Keep what arrived for LMS Labs support.
                $row->status = 'failed';
                $row->errorcode = 'unusable_draft';
                $row->result = \core_text::substr($body, 0, 65536);
                return;
            }
            $transaction = $DB->start_delegated_transaction();
            $row->targetid = manager::add_scene($instance, $draft);
            $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $row->targetid], '*', MUST_EXIST);
            manager::save_scene($scene, [], $draft['hazards']);
            $row->status = 'completed';
            $row->errorcode = null;
            $row->result = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $DB->update_record('aisafetyhazards_aireq', $row);
            $transaction->allow_commit();
            return;
        }
        self::error_outcome($row, $status, $code, $balance, self::TEXT_UNCERTAIN, 'expired');
    }

    /**
     * Records the outcome of an import charge; on delivery the kept scenes are created in the same transaction.
     *
     * @param stdClass $row
     * @param stdClass $instance
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    protected static function import_outcome(
        stdClass $row,
        stdClass $instance,
        int $status,
        array $headers,
        string $body
    ): void {
        global $DB;
        [$code, $requestid, $balance] = self::read_error($headers, $body);
        $row->requestid = $requestid !== '' ? $requestid : $row->requestid;
        if ($status === 200) {
            $data = json_decode($body, true);
            $kept = json_decode((string)$row->result, true);
            $count = count($kept['scenes'] ?? []);
            $row->charged = self::reported(is_array($data) ? ($data['creditsCharged'] ?? null) : null);
            $row->balance = is_array($data) && isset($data['creditsBalance']) && is_numeric($data['creditsBalance'])
                ? (int)$data['creditsBalance'] : null;
            $transaction = $DB->start_delegated_transaction();
            $made = lesson::import($instance, ['scenes' => $kept['scenes'] ?? []]);
            $row->targetid = (int)$made['scenes'];
            $row->status = 'completed';
            $row->errorcode = null;
            $DB->update_record('aisafetyhazards_aireq', $row);
            $transaction->allow_commit();
            return;
        }
        self::error_outcome($row, $status, $code, $balance, self::TEXT_UNCERTAIN, 'expired');
    }

    /**
     * Records the outcome of a picture request; delivered bytes are saved at once.
     *
     * @param stdClass $row
     * @param stdClass $instance
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    protected static function image_outcome(
        stdClass $row,
        stdClass $instance,
        int $status,
        array $headers,
        string $body
    ): void {
        global $DB;
        [$code, $requestid, $balance] = self::read_error($headers, $body);
        $row->requestid = $requestid !== '' ? $requestid : $row->requestid;
        $type = strtolower(trim(explode(';', (string)($headers['content-type'] ?? ''))[0]));
        if ($status === 200) {
            $row->charged = self::reported($headers['x-credits-charged'] ?? null);
            $row->balance = isset($headers['x-credits-balance']) && is_numeric($headers['x-credits-balance'])
                ? (int)$headers['x-credits-balance'] : null;
            $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $row->targetid, 'aisafetyhazardsid' => $instance->id]);
            $bytes = self::image_bytes($type, $body);
            if ($bytes === null || !$scene) {
                // Charged but not usable (or the scene was deleted meanwhile); LMS Labs keeps no copy.
                // What arrived is kept (type and size only, never the bytes) so LMS Labs support can trace it.
                $row->status = 'lost';
                $row->errorcode = $scene ? 'unusable_image' : 'scene_deleted';
                $row->result = json_encode(['contenttype' => substr($type, 0, 100), 'bytes' => strlen($body)]);
                return;
            }
            $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id, $instance->course, false, MUST_EXIST);
            manager::save_scene_image_bytes(\context_module::instance($cm->id), $scene, $bytes);
            $sent = json_decode((string)$row->body, true);
            if (!empty($sent['anchors'])) {
                try {
                    // The hazard spots go where LMS Labs found them; a bad header never fails the paid picture.
                    \mod_aisafetyhazards\local\image_positions::apply(
                        $scene,
                        (array)$sent['anchors'],
                        (string)($headers['x-anchor-positions'] ?? '')
                    );
                } catch (\Throwable $e) {
                    debugging('Hazard positions not applied: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            $row->status = 'completed';
            $row->errorcode = null;
            return;
        }
        // A 410 means completed or expired at LMS Labs, and the bytes never reached this site.
        self::error_outcome($row, $status, $code, $balance, self::IMAGE_UNCERTAIN, 'lost');
    }

    /**
     * Records the outcome of a voiceover clip request; delivered MP3 bytes are saved at once.
     *
     * @param stdClass $row
     * @param stdClass $instance
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    protected static function voice_outcome(
        stdClass $row,
        stdClass $instance,
        int $status,
        array $headers,
        string $body
    ): void {
        global $DB;
        [$code, $requestid, $balance] = self::read_error($headers, $body);
        $row->requestid = $requestid !== '' ? $requestid : $row->requestid;
        if ($status === 200) {
            // What LMS Labs reports it charged. Without the header it is not known: the confirmed ceiling (maxCredits)
            // stays in the stored body, and is never recorded as a charge.
            $row->charged = self::reported($headers['x-credits-charged'] ?? null);
            $row->balance = isset($headers['x-credits-balance']) && is_numeric($headers['x-credits-balance'])
                ? (int)$headers['x-credits-balance'] : null;
            $scene = $DB->get_record('aisafetyhazards_scene', ['id' => $row->targetid, 'aisafetyhazardsid' => $instance->id]);
            $segment = json_decode((string)$row->body, true);
            if (!$scene || !is_array($segment) || !voiceover::is_mp3($body)) {
                $type = strtolower(trim(explode(';', (string)($headers['content-type'] ?? ''))[0]));
                $row->status = 'lost';
                $row->errorcode = $scene ? 'unusable_audio' : 'scene_deleted';
                $row->result = json_encode(['contenttype' => substr($type, 0, 100), 'bytes' => strlen($body)]);
                return;
            }
            $cm = get_coursemodule_from_instance('aisafetyhazards', $instance->id, $instance->course, false, MUST_EXIST);
            voiceover::save(\context_module::instance($cm->id), $scene, $segment, $body);
            $row->status = 'completed';
            $row->errorcode = null;
            return;
        }
        self::error_outcome($row, $status, $code, $balance, self::VOICE_UNCERTAIN, 'lost');
    }

    /**
     * The picture in a delivered image answer, or null when there is none.
     *
     * Raw PNG, WebP or JPEG bytes are accepted whatever the content type says, as long as the bytes really are a picture.
     * A JSON answer carrying the picture as base64 (field image, imageBase64, b64_json or data, optionally a data: URL)
     * is accepted too: the approved contract says raw bytes, but a charged picture must not be thrown away over its
     * wrapping.
     *
     * @param string $type lower-case content type without parameters
     * @param string $body
     * @return string|null
     */
    public static function image_bytes(string $type, string $body): ?string {
        $accepted = ['png', 'webp', 'jpg'];
        if (in_array(manager::image_signature(substr($body, 0, 16)), $accepted, true)) {
            return $body;
        }
        if ($type !== 'application/json' && !str_ends_with($type, '+json')) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['image', 'imageBase64', 'b64_json', 'data'] as $field) {
            $value = $data[$field] ?? null;
            if (is_array($value)) {
                $value = $value['b64_json'] ?? $value['base64'] ?? $value['data'] ?? null;
            }
            if (!is_string($value) || $value === '' || strlen($value) > 28 * 1048576) {
                continue;
            }
            $value = preg_replace('~^data:image/[a-z]+;base64,~i', '', $value);
            $bytes = base64_decode($value, true);
            if ($bytes !== false && in_array(manager::image_signature(substr($bytes, 0, 16)), $accepted, true)) {
                return $bytes;
            }
        }
        return null;
    }

    /**
     * Records a non-200 answer (or no answer, status 0).
     *
     * 202: still in progress. 409: key used with a different body (never replaced automatically). 410: gone, as
     * $gone. No answer, an unconfirmed settlement or an unexplained server error: the outcome is unknown, so the key
     * is kept for "Check again". Anything else: LMS Labs refused it and charged nothing.
     *
     * @param stdClass $row
     * @param int $status
     * @param string $code lower-case error code, '' when none
     * @param int|null $balance
     * @param string[] $uncertain error codes that leave the outcome unknown
     * @param string $gone status for 410
     */
    protected static function error_outcome(
        stdClass $row,
        int $status,
        string $code,
        ?int $balance,
        array $uncertain,
        string $gone
    ): void {
        $row->balance = $balance ?? $row->balance;
        $row->errorcode = $code !== '' ? $code : ($status === 0 ? 'network' : 'http_' . $status);
        if ($status === 404) {
            // The route is not deployed at LMS Labs (yet): nothing was done, whatever the body says.
            $row->errorcode = 'http_404';
        }
        // An unexpected server error is never taken as "not charged": only documented provider refusals are a no.
        $unknown = $status === 0 || in_array($code, $uncertain, true) ||
            ($status >= 500 && !in_array($code, self::PROVIDER_REFUSED, true));
        $map = [202 => 'pending', 409 => 'conflict', 410 => $gone];
        $row->status = $map[$status] ?? ($unknown ? 'uncertain' : 'failed');
        if ($status === 409 && $code === 'price_changed') {
            // A free clip has become a paid one: refused before anything was made or charged. Ask the teacher again.
            $row->status = 'failed';
        }
    }

    /**
     * A charge as LMS Labs reported it, or null when it did not report one (then it is not known).
     *
     * @param mixed $value header or JSON value
     * @return int|null
     */
    protected static function reported($value): ?int {
        return is_numeric($value) && (int)$value >= 0 ? (int)$value : null;
    }

    /**
     * A request as shown on the page (never the key, the credentials or the raw body).
     *
     * @param stdClass $row
     * @param \context_module $context
     * @return array
     */
    public static function export(stdClass $row, \context_module $context): array {
        global $DB;
        $image = $row->operation === self::IMAGE;
        $voice = $row->operation === self::VOICE;
        $credits = $image ? lmslabs::IMAGE_CREDITS : ($voice ? lmslabs::VOICE_CREDITS : lmslabs::TEXT_CREDITS);
        if ($row->operation === self::IMPORT) {
            $credits *= max(1, (int)((json_decode((string)$row->body, true) ?: [])['sceneCount'] ?? 1));
        }
        $sent = json_decode((string)$row->body, true) ?: [];
        if ($voice && isset($sent['maxCredits'])) {
            // The most the teacher confirmed for this clip.
            $credits = (int)$sent['maxCredits'];
        }
        $known = $row->charged !== null && $row->charged !== '';
        $a = (object)[
            'requestid' => $row->requestid ?: '-',
            'credits' => $credits,
            // Not reported: shown as "up to" the confirmed amount, never as a charge.
            'charged' => $known ? (int)$row->charged : $credits,
            'balance' => $row->balance === null ? '-' : (int)$row->balance,
        ];
        $import = $row->operation === self::IMPORT;
        $prefix = $image ? 'aireq_image_' : ($import ? 'aireq_import_' : ($voice ? 'aireq_voice_' : 'aireq_scene_'));
        $status = (string)$row->status;
        if ($status === 'completed' && !$known) {
            $message = get_string(
                'aireq_completed_unknown',
                'mod_aisafetyhazards',
                (object)[
                'what' => get_string($prefix . 'done', 'mod_aisafetyhazards'), 'credits' => $credits]
            );
        } else if ($status === 'completed') {
            $message = get_string($prefix . 'completed' . ($row->balance === null ? '' : 'balance'), 'mod_aisafetyhazards', $a);
        } else if ($status === 'failed') {
            $message = self::failure_message($row, $a);
        } else {
            $message = get_string($prefix . $status, 'mod_aisafetyhazards', $a);
        }
        $body = json_decode((string)$row->body, true) ?: [];
        if ($image || $voice) {
            $title = (string)$DB->get_field('aisafetyhazards_scene', 'title', ['id' => $row->targetid]);
            if ($voice) {
                $title .= ' · ' . \core_text::substr((string)($body['text'] ?? ''), 0, 60);
            }
        } else if ($import) {
            $title = get_string(
                'import_title',
                'mod_aisafetyhazards',
                (object)['count' => (int)($body['sceneCount'] ?? 0),
                'titles' => implode(', ', array_slice((array)($body['titles'] ?? []), 0, 3))]
            );
        } else {
            $title = \core_text::substr((string)($body['brief'] ?? ''), 0, 120);
        }
        $sceneurl = '';
        if (!$import && $row->targetid && $DB->record_exists('aisafetyhazards_scene', ['id' => $row->targetid])) {
            $sceneurl = (new \moodle_url(
                '/mod/aisafetyhazards/editor.php',
                ['id' => $context->instanceid,
                'sceneid' => $row->targetid]
            ))->out(false);
        }
        return [
            'id' => (int)$row->id,
            'operation' => (string)$row->operation,
            'status' => $status,
            'title' => format_string($title, true, ['context' => $context, 'escape' => false]),
            'message' => $message,
            'requestid' => (string)($row->requestid ?? ''),
            'targetid' => (int)$row->targetid,
            'canrecheck' => in_array($status, self::CHECKABLE, true),
            'candismiss' => $status !== 'dismissed',
            'poll' => $status === 'pending',
            'retryafter' => (int)($row->retryafter ?? self::RETRY_AFTER),
            'sceneid' => (int)$row->targetid,
            'sceneurl' => $sceneurl,
            'openscene' => $status === 'completed' && !$image && !$voice && $sceneurl !== '',
            'timecreated' => userdate((int)$row->timecreated),
        ];
    }

    /**
     * Message for a request that failed (LMS Labs answered and nothing more can be done with the same key).
     *
     * @param stdClass $row
     * @param stdClass $a
     * @return string
     */
    protected static function failure_message(stdClass $row, stdClass $a): string {
        $code = (string)$row->errorcode;
        $sm = get_string_manager();
        if ($row->operation === self::IMAGE) {
            $key = $sm->string_exists('aierror_' . $code, 'mod_aisafetyhazards') ? 'aierror_' . $code
                : ($code === 'http_404' ? 'aierror_not_live' : 'aierror_failed');
            $a->balance = $row->balance === null ? 0 : (int)$row->balance;
            return get_string($key, 'mod_aisafetyhazards', $a);
        }
        $map = [
            'invalid_credentials' => 'invalid_credentials', 'no_entitlement' => 'no_entitlement',
            'insufficient_credits' => 'insufficient_credits', 'unusable_draft' => 'unusable_draft',
            'rate_limited' => 'rate_limited', 'provider_rate_limited' => 'rate_limited',
            'provider_failed' => 'provider_failed', 'invalid_provider_result' => 'provider_failed',
            'provider_unavailable' => 'provider_failed', 'invalid_input' => 'rejected', 'invalid_json' => 'rejected',
            'unexpected_fields' => 'rejected', 'body_too_large' => 'rejected', 'invalid_idempotency_key' => 'rejected',
            'http_404' => 'not_live', 'tts_failed' => 'provider_failed', 'unsupported_voice' => 'rejected',
            'tariff_not_approved' => 'voiceover_not_enabled', 'unusable_audio' => 'provider_failed',
            'price_changed' => 'price_changed',
            'unsupported_locale' => 'rejected', 'text_too_long' => 'rejected',
        ];
        $prefixes = [self::IMPORT => 'aiimporterror_', self::VOICE => 'aivoiceerror_'];
        $prefix = $prefixes[$row->operation] ?? 'aidrafterror_';
        $name = $map[$code] ?? $code;
        if ($name === '' || !$sm->string_exists($prefix . $name, 'mod_aisafetyhazards')) {
            $name = 'failed';
        }
        return get_string($prefix . $name, 'mod_aisafetyhazards', $a);
    }
}
