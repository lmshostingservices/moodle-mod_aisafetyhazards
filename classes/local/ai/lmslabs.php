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

use moodle_exception;

/**
 * LMS Labs account connection.
 *
 * AI Safety Hazards uses two dedicated LMS Labs routes (owner-approved tariffs, charged by LMS Labs only):
 * POST /api/moodle/ai-safetyhazards/scenes/draft (5 credits per scene LMS Labs settles) and
 * POST /api/moodle/ai-safetyhazards/images (5 credits per picture LMS Labs settles); also the scene import charge and
 * speech routes. Credentials go in headers only. Each
 * intentional request gets a new Idempotency-Key that is stored with its exact body before sending (see requests), and
 * nothing is retried automatically. The balance check (GET /api/credits) is read-only and advisory.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs implements provider {
    /** @var string Fixed LMS Labs host. */
    public const BASE_URL = 'https://lms-labs.com';

    /** @var string Dedicated AI Safety Hazards scene draft route. */
    public const TEXT_ROUTE = '/api/moodle/ai-safetyhazards/scenes/draft';

    /** @var int Credits LMS Labs charges per scene draft it settles (owner-approved tariff). */
    public const TEXT_CREDITS = 5;

    /**
     * @var string AI Safety Hazards charge for scenes made outside LMS Labs AI (an AI assistant of the teacher's choice):
     * the same tariff as an LMS Labs scene draft, per scene. Requested from LMS Labs on 8 Oct 2026; until it is live,
     * LMS Labs answers 404 and no scene is created.
     */
    public const IMPORT_ROUTE = '/api/moodle/ai-safetyhazards/scenes/import';

    /** @var string AI Safety Hazards voiceover catalogue (free): Google Chirp 3 HD voices per locale. */
    public const VOICE_CATALOG_ROUTE = '/api/moodle/ai-safetyhazards/speech/capabilities';

    /** @var string AI Safety Hazards voiceover route: one MP3 clip of at most 200 characters per request. */
    public const VOICE_ROUTE = '/api/moodle/ai-safetyhazards/speech/tts';

    /** @var string Free price check for one clip (remakes, 9 Oct 2026): {clipRef} -> {credits, firstClip, ...}. */
    public const VOICE_QUOTE_ROUTE = '/api/moodle/ai-safetyhazards/speech/quote';

    /** @var array|null The tariff of the last voice catalogue read, kept for {@see self::voice_catalog()} callers. */
    public static $lasttariff = null;

    /** @var int Credits per voiceover clip (owner-approved, as AI Soft Skills). LMS Labs must publish the same price. */
    public const VOICE_CREDITS = 2;

    /** @var string Dedicated AI Safety Hazards picture route. */
    public const IMAGE_ROUTE = '/api/moodle/ai-safetyhazards/images';

    /** @var int Credits LMS Labs charges per successful picture (owner-approved tariff). */
    public const IMAGE_CREDITS = 5;

    /** @var int Longest picture prompt the route accepts, in Unicode code points. */
    public const MAX_PROMPT = 2000;

    /** @var string[] Picture styles the route accepts. */
    public const STYLES = ['illustration', 'photo'];

    /** @var callable|null Replacement GET transport used by unit tests: fn($url, $headers) => [status, body]. */
    public static $transport = null;

    /**
     * @var callable|null Replacement POST transport used by unit tests:
     *      fn($url, $headers, $body) => [status, lower-case response headers, body].
     */
    public static $posttransport = null;

    /**
     * Free capability check for optional image positions. Failure preserves the legacy request.
     *
     * @return bool whether the server supports positions
     */
    public static function image_anchors(): bool {
        global $CFG;
        $time = (int)get_config('mod_aisafetyhazards', 'imageanchorstime');
        if ($time > time() - 3600) {
            return (bool)get_config('mod_aisafetyhazards', 'imageanchors');
        }
        $enabled = false;
        try {
            $credentials = \mod_aisafetyhazards\local\credentials::find();
            if ($credentials === null) {
                return false;
            }
            $url = self::BASE_URL . self::IMAGE_ROUTE . '/capabilities';
            $headers = ['X-Site-ID: ' . $credentials['siteid'], 'X-API-Key: ' . $credentials['apikey'],
                'Accept: application/json'];
            if (self::$transport) {
                [$status, $body] = (self::$transport)($url, $headers);
            } else {
                require_once($CFG->libdir . '/filelib.php');
                $curl = new \curl();
                $curl->setHeader($headers);
                $body = $curl->get($url, [], ['CURLOPT_CONNECTTIMEOUT' => 3, 'CURLOPT_TIMEOUT' => 8,
                    'CURLOPT_FOLLOWLOCATION' => false]);
                $status = $curl->get_errno() ? 0 : (int)($curl->get_info()['http_code'] ?? 0);
            }
            $data = json_decode((string)$body, true);
            $enabled = (int)$status === 200 && ($data['anchorPositions'] ?? false) === true
                && ($data['tariff']['image'] ?? null) === self::IMAGE_CREDITS;
        } catch (\Throwable $e) {
            $enabled = false;
        }
        set_config('imageanchors', $enabled ? 1 : 0, 'mod_aisafetyhazards');
        set_config('imageanchorstime', time(), 'mod_aisafetyhazards');
        return $enabled;
    }

    /**
     * Whether a complete LMS Labs credential pair is available (Central Config first, then this plugin's settings).
     *
     * @return bool
     */
    public function is_connected(): bool {
        return \mod_aisafetyhazards\local\credentials::find() !== null;
    }

    /**
     * Whether pictures can be created: AI pictures switched on for the site and a complete LMS Labs credential pair.
     *
     * @return bool
     */
    public function can_generate(): bool {
        return (bool)get_config('mod_aisafetyhazards', 'aiimages') && $this->is_connected();
    }

    /**
     * Whether scene drafts can be requested: AI scene drafts switched on for the site and a complete credential pair.
     *
     * @return bool
     */
    public function can_draft(): bool {
        return (bool)get_config('mod_aisafetyhazards', 'aidrafts') && $this->is_connected();
    }

    /**
     * Sends one POST to an AI Safety Hazards route with header-only credentials and the given Idempotency-Key.
     *
     * The caller persists the key and body before calling, and reuses both for any later check of the same request.
     * Nothing is retried here.
     *
     * @param string $route one of TEXT_ROUTE, IMPORT_ROUTE, IMAGE_ROUTE or VOICE_ROUTE
     * @param string $body exact JSON body
     * @param string $key Idempotency-Key
     * @param string $accept Accept header value
     * @param int $timeout seconds to wait for the answer
     * @return array [status (0 when LMS Labs could not be reached or did not answer), lower-case headers, body]
     */
    public static function post(string $route, string $body, string $key, string $accept, int $timeout = 100): array {
        global $CFG;
        $credentials = \mod_aisafetyhazards\local\credentials::find();
        if ($credentials === null) {
            throw new moodle_exception('ainotavailable', 'mod_aisafetyhazards');
        }
        $routes = [self::TEXT_ROUTE, self::IMPORT_ROUTE, self::IMAGE_ROUTE, self::VOICE_ROUTE, self::VOICE_QUOTE_ROUTE];
        if (!in_array($route, $routes, true)) {
            throw new \coding_exception('Unknown LMS Labs route');
        }
        $url = self::BASE_URL . $route;
        $headers = [
            'Content-Type: application/json',
            'Accept: ' . $accept,
            'X-Site-ID: ' . $credentials['siteid'],
            'X-API-Key: ' . $credentials['apikey'],
            'Idempotency-Key: ' . $key,
        ];
        if (self::$posttransport) {
            [$status, $responseheaders, $response] = (self::$posttransport)($url, $headers, $body);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl(['ignoresecurity' => false]);
            $curl->setHeader($headers);
            $response = $curl->post($url, $body, [
                'CURLOPT_CONNECTTIMEOUT' => 10,
                // Both routes finish within 85 to 90 seconds.
                'CURLOPT_TIMEOUT' => $timeout,
                'CURLOPT_FOLLOWLOCATION' => false,
            ]);
            if ($curl->get_errno()) {
                return [0, [], ''];
            }
            $status = (int)($curl->get_info()['http_code'] ?? 0);
            $responseheaders = [];
            foreach ((array)$curl->getResponse() as $name => $value) {
                $responseheaders[strtolower((string)$name)] = is_array($value) ? end($value) : (string)$value;
            }
        }
        return [(int)$status, array_change_key_case((array)$responseheaders, CASE_LOWER), (string)$response];
    }

    /**
     * Reads the voiceover catalogue (free; header-only credentials; redirects never followed).
     *
     * @return array|null locales [{locale, ttsLanguageCode, voices [{name}]}], or null when it cannot be read
     */
    public static function voice_catalog(): ?array {
        global $CFG;
        $credentials = \mod_aisafetyhazards\local\credentials::find();
        if ($credentials === null) {
            return null;
        }
        $url = self::BASE_URL . self::VOICE_CATALOG_ROUTE;
        $headers = ['X-Site-ID: ' . $credentials['siteid'], 'X-API-Key: ' . $credentials['apikey'],
            'Accept: application/json'];
        if (self::$transport) {
            [$status, $body] = (self::$transport)($url, $headers);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader($headers);
            $body = $curl->get($url, [], ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 15,
                'CURLOPT_FOLLOWLOCATION' => false]);
            if ($curl->get_errno()) {
                return null;
            }
            $status = (int)($curl->get_info()['http_code'] ?? 0);
        }
        $data = json_decode((string)$body, true);
        if ((int)$status !== 200 || !is_array($data) || empty($data['locales']) || !is_array($data['locales'])) {
            return null;
        }
        self::$lasttariff = is_array($data['tariff'] ?? null) ? $data['tariff'] : [];
        return array_values(array_filter($data['locales'], 'is_array'));
    }

    /**
     * The current price of one clip (free; nothing is made, charged or reserved). The price can change before the
     * clip is made, so the clip is then sent with the price the teacher confirmed as its ceiling.
     *
     * @param string $clipref 64 lower-case hex characters
     * @return array|null {credits (0 or 5), first (bool), remaining (int|null)}, or null when LMS Labs gave no price
     */
    public static function voice_quote(string $clipref): ?array {
        try {
            // A price check is quick and free: a slow one is not waited for (the clip then counts as full price).
            [$status, , $body] = self::post(
                self::VOICE_QUOTE_ROUTE,
                json_encode(['clipRef' => $clipref]),
                \core\uuid::generate(),
                'application/json',
                8
            );
        } catch (\moodle_exception $e) {
            return null;
        }
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !in_array($data['credits'] ?? null, [0, self::VOICE_CREDITS], true)) {
            return null;
        }
        return ['credits' => (int)$data['credits'], 'first' => !empty($data['firstClip']),
            'remaining' => is_int($data['freeRemakesRemaining'] ?? null) ? $data['freeRemakesRemaining'] : null];
    }

    /**
     * Reads the site's balance (GET /api/credits, key in the X-API-Key header).
     *
     * @return array|null
     */
    public function balance(): ?array {
        global $CFG;
        $credentials = \mod_aisafetyhazards\local\credentials::find();
        if ($credentials === null) {
            return null;
        }
        $url = self::BASE_URL . '/api/credits?siteId=' . rawurlencode($credentials['siteid']);
        $headers = ['X-API-Key: ' . $credentials['apikey'], 'Accept: application/json'];
        if (self::$transport) {
            $transport = self::$transport;
            [$status, $body] = $transport($url, $headers);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader($headers);
            // Never follow a redirect: the API key header would be sent on to the new address.
            $body = $curl->get($url, [], ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 10,
                'CURLOPT_FOLLOWLOCATION' => false]);
            if ($curl->get_errno()) {
                return null;
            }
            $status = (int)($curl->get_info()['http_code'] ?? 0);
        }
        if ($status !== 200) {
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return null;
        }
        if (!empty($data['isUnlimited'])) {
            return ['unlimited' => true, 'credits' => -1];
        }
        if (isset($data['creditsRaw']) && is_numeric($data['creditsRaw'])) {
            return ['unlimited' => false, 'credits' => (int)$data['creditsRaw']];
        }
        return null;
    }
}
