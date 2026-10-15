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

namespace mod_aisafetyhazards\local;

/**
 * LMS Labs activation (one-time unlock) of AI Safety Hazards for this site.
 *
 * All requests are made from PHP. Credentials go in the JSON body of the two unlock routes (as the LMS Labs
 * contract requires) and are never logged, stored with the state, or sent to the browser.
 *
 *   POST https://lms-labs.com/api/plugin-unlock/verify   free access check
 *        {"pluginId":"aisafetyhazards","siteId":"…","apiKey":"…"}  → {unlocked, credits, unlockedAt, entitlementSource}
 *   GET  https://lms-labs.com/api/plugin-versions         release catalogue (SHA-256, acquisition, price)
 *   POST https://lms-labs.com/api/plugin-unlock           spends credits, only after the admin confirmed
 *        {"pluginId":"aisafetyhazards","pluginComponent":"mod_aisafetyhazards","siteId":"…","apiKey":"…",
 *         "releaseSha256":"<live SHA>","expectedCredits":<live price shown to the admin>}
 *
 * Safety: nothing is bought on page load; the price and release are re-read from the live catalogue immediately
 * before the purchase and must equal what the admin confirmed; a purchase whose outcome is unknown (network error,
 * timeout, 5xx, unreadable reply) leaves a pending marker, and no new purchase can be submitted until a free access
 * check has resolved it.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unlock {
    /** Unlock plugin id at LMS Labs. */
    public const PLUGINID = 'aisafetyhazards';

    /** Frankenstyle component. */
    public const COMPONENT = 'mod_aisafetyhazards';

    /** LMS Labs base URL. */
    public const BASE = 'https://lms-labs.com';

    /** Access check route. */
    public const VERIFY = '/api/plugin-unlock/verify';

    /** Release catalogue route. */
    public const VERSIONS = '/api/plugin-versions';

    /** Purchase route. */
    public const UNLOCK = '/api/plugin-unlock';

    /** Release statuses that allow a purchase. */
    public const AVAILABLE = ['ready', 'available', 'published', 'public'];

    /** The only acquisition mode that may enter the credit-unlock flow. */
    public const CREDITMODE = 'credit-unlock';

    /** @var callable|null test seam: fn(array $request): array [status (0 = network error), body] */
    public static $transport = null;

    /**
     * A route URL on the fixed LMS Labs host.
     *
     * @param string $path
     * @return string
     */
    public static function url(string $path): string {
        // Fixed host: the credentials in the body are only ever sent to lms-labs.com.
        return self::BASE . $path;
    }

    /**
     * Sends a request. Returns [status, decoded JSON or null, raw length]. Status 0 means no response.
     *
     * @param string $method
     * @param string $url
     * @param array|null $body JSON body (may contain credentials: never logged)
     * @param int $timeout
     * @return array
     */
    protected static function request(string $method, string $url, ?array $body, int $timeout): array {
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Accept' => 'application/json'];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $req = ['method' => $method, 'url' => $url, 'body' => $payload, 'headers' => $headers];
        if (self::$transport) {
            [$status, $raw] = (self::$transport)($req);
        } else {
            try {
                $client = new \core\http_client();
                $response = $client->request($method, $url, self::http_options($req['headers'], $payload, $timeout));
                $status = (int)$response->getStatusCode();
                $raw = (string)$response->getBody();
            } catch (\Throwable $e) {
                // The exception text can name the URL, never the body, so no credential reaches the log.
                debugging('LMS Labs activation request failed: ' . get_class($e), DEBUG_DEVELOPER);
                return [0, null];
            }
        }
        $data = json_decode((string)$raw, true);
        return [(int)$status, is_array($data) ? $data : null];
    }

    /**
     * HTTP client options. Redirects are never followed, so a credential-bearing body cannot be re-sent to
     * another host.
     *
     * @param array $headers
     * @param string|null $payload
     * @param int $timeout
     * @return array
     */
    public static function http_options(array $headers, ?string $payload, int $timeout): array {
        $options = ['headers' => $headers, 'timeout' => $timeout, 'connect_timeout' => 10, 'http_errors' => false,
            'allow_redirects' => false];
        if ($payload !== null) {
            $options['body'] = $payload;
        }
        return $options;
    }

    /**
     * The credential pair and its source, or null.
     *
     * @return array|null ['siteid', 'apikey', 'source']
     */
    protected static function creds(): ?array {
        try {
            return credentials::resolve();
        } catch (\moodle_exception $e) {
            return null;
        }
    }

    /**
     * Reads a balance: number, or unlimited.
     *
     * The field depends on the route: verify returns `credits` (-1 = unlimited), a successful unlock returns
     * `remainingCredits` (may be "unlimited"), an insufficient-credit error returns `currentCredits`.
     *
     * @param array $data
     * @return array ['credits' => int|null, 'unlimited' => bool] (credits null when not reported)
     */
    public static function balance(array $data): array {
        $c = null;
        foreach (['remainingCredits', 'currentCredits', 'credits'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $c = $data[$field];
                break;
            }
        }
        $unlimited = (is_string($c) && strtolower(trim($c)) === 'unlimited')
            || (is_numeric($c) && (int)$c === -1);
        $known = !$unlimited && is_numeric($c) && (float)$c >= 0;
        return ['credits' => $known ? (int)$c : null, 'unlimited' => $unlimited];
    }

    /**
     * Whether a reply reports a balance.
     *
     * @param array $bal from {@see balance()}
     * @return bool
     */
    protected static function has_balance(array $bal): bool {
        return $bal['credits'] !== null || $bal['unlimited'];
    }

    /**
     * Last stored access state.
     *
     * @return array status: notchecked|locked|unlocked|unknown (+ credits, unlimited, unlockedat, source, checkedat,
     *               error)
     */
    public static function state(): array {
        $s = json_decode((string)get_config(self::COMPONENT, 'unlockstate'), true);
        return is_array($s) && !empty($s['status']) ? $s : ['status' => 'notchecked'];
    }

    /**
     * Saves the access state.
     *
     * @param array $state
     */
    protected static function save(array $state): void {
        $state['checkedat'] = time();
        set_config('unlockstate', json_encode($state), self::COMPONENT);
    }

    /**
     * Whether the plugin may be used on this site: LMS Labs has unlocked it (50 credits or a recognised Marketplace
     * purchase), and no later check has said "locked". A check that got no definite answer keeps an unlocked site
     * usable.
     *
     * @return bool
     */
    public static function active(): bool {
        $state = self::state();
        return $state['status'] === 'unlocked' || ($state['status'] === 'unknown' && !empty($state['wasunlocked']));
    }

    /**
     * Stops a page or web service call when the plugin is not unlocked on this site.
     *
     * @throws \moodle_exception notactivated
     */
    public static function require_active(): void {
        if (!self::active()) {
            throw new \moodle_exception('notactivated', 'mod_aisafetyhazards', self::settings_url_for_admins());
        }
    }

    /**
     * The settings page (where activation is) for site administrators, '' for everyone else.
     *
     * @return string
     */
    public static function settings_url_for_admins(): string {
        return has_capability('moodle/site:config', \context_system::instance())
            ? (new \moodle_url('/admin/settings.php', ['section' => 'modsettingaisafetyhazards']))->out(false) : '';
    }

    /**
     * The notice shown instead of a page while the plugin is not unlocked.
     *
     * @param bool $teacher whether the viewer manages the activity (learners get a plain "not available yet")
     * @return string HTML
     */
    public static function locked_notice(bool $teacher = true): string {
        global $OUTPUT;
        $url = self::settings_url_for_admins();
        $key = $url !== '' ? 'notactivated_admin' : ($teacher ? 'notactivated_teacher' : 'notactivated_learner');
        $text = get_string($key, 'mod_aisafetyhazards');
        $html = $OUTPUT->notification($text, \core\output\notification::NOTIFY_WARNING, false);
        if ($url !== '') {
            $html .= \html_writer::link($url, get_string('notactivated_open', 'mod_aisafetyhazards'),
                ['class' => 'btn btn-primary']);
        }
        return $html;
    }

    /**
     * An unlock whose outcome is not known yet, or null.
     *
     * @return array|null ['time', 'expected', 'sha']
     */
    public static function pending(): ?array {
        $raw = (string)get_config(self::COMPONENT, 'unlockpending');
        $p = json_decode($raw, true);
        return $raw !== '' ? (is_array($p) && !empty($p['time']) ? $p : ['time' => time()]) : null;
    }

    /**
     * Free access check. Stores and returns the state.
     *
     * @return array state plus 'resolved' => unlocked|locked|null when a pending purchase was resolved
     */
    public static function verify(): array {
        $creds = self::creds();
        if (!$creds) {
            $state = ['status' => 'unknown', 'error' => 'nocredentials'];
            self::save($state);
            return $state;
        }
        [$status, $data] = self::request('POST', self::url(self::VERIFY), [
            'pluginId' => self::PLUGINID, 'siteId' => $creds['siteid'], 'apiKey' => $creds['apikey'],
        ], 20);
        if ($status === 200 && $data !== null && is_bool($data['unlocked'] ?? null)) {
            $state = ['status' => $data['unlocked'] ? 'unlocked' : 'locked'] + self::balance($data) + [
                'unlockedat' => self::time($data['unlockedAt'] ?? null),
                'source' => self::text($data['entitlementSource'] ?? '', 60),
            ];
        } else {
            $state = ['status' => 'unknown', 'error' => self::error($status, $data)];
            // An outage or a bad answer must not lock a site LMS Labs has already unlocked: only a definite
            // "locked" answer clears this.
            $before = self::state();
            if ($before['status'] === 'unlocked' || !empty($before['wasunlocked'])) {
                $state['wasunlocked'] = true;
            }
        }
        $resolved = null;
        if (self::pending() && $state['status'] !== 'unknown') {
            // A definite answer settles an earlier purchase whose outcome was unknown.
            $resolved = $state['status'];
            unset_config('unlockpending', self::COMPONENT);
        }
        self::save($state);
        return $state + ['resolved' => $resolved];
    }

    /**
     * The live release entry for this plugin, checked for purchase.
     *
     * @return array ['ok' => bool, 'reason' => string, 'sha' => string, 'price' => int|null, 'mode' => string,
     *                'availability' => string, 'version' => string]
     */
    public static function release(): array {
        $out = ['ok' => false, 'reason' => 'unreachable', 'sha' => '', 'price' => null, 'mode' => '',
            'availability' => '', 'version' => ''];
        [$status, $data] = self::request('GET', self::url(self::VERSIONS), null, 15);
        if ($status !== 200 || $data === null) {
            return $out;
        }
        $entry = self::find_entry($data);
        if (!$entry) {
            $out['reason'] = 'notlisted';
            return $out;
        }
        $sha = strtolower(trim((string)($entry['sha256'] ?? $entry['releaseSha256'] ?? $entry['sha'] ?? '')));
        $price = $entry['creditsRequired'] ?? null;
        $mode = self::text($entry['acquisitionMode'] ?? '', 40);
        $avail = $entry['status'] ?? $entry['availability'] ?? '';
        $avail = is_bool($avail) ? ($avail ? 'available' : 'unavailable') : self::text($avail, 40);
        // A positive whole number of credits (an int, or a string of digits).
        $validprice = (is_int($price) || (is_string($price) && ctype_digit($price))) && (int)$price > 0;
        $out = ['sha' => preg_match('/^[a-f0-9]{64}$/', $sha) ? $sha : '',
            'price' => $validprice ? (int)$price : null,
            'mode' => $mode, 'availability' => $avail,
            'version' => self::text($entry['version'] ?? $entry['release'] ?? '', 30)] + $out;
        if (($entry['zipExists'] ?? null) !== true) {
            // The release package is not published: never sell it, whatever the status says.
            $out['reason'] = 'nozip';
        } else if ($out['sha'] === '') {
            $out['reason'] = 'nosha';
        } else if ($out['price'] === null) {
            $out['reason'] = 'noprice';
        } else if (!in_array(strtolower($avail), self::AVAILABLE, true)) {
            $out['reason'] = 'notavailable';
        } else if ($mode !== self::CREDITMODE) {
            // Only "credit-unlock" releases are sold for credits (a USD purchase release must never enter this flow).
            $out['reason'] = 'mode';
        } else {
            $out['ok'] = true;
            $out['reason'] = '';
        }
        return $out;
    }

    /**
     * Finds this plugin's entry in the release catalogue (a list, or a list under plugins/versions/data).
     *
     * @param array $data
     * @return array|null
     */
    protected static function find_entry(array $data): ?array {
        $lists = [$data];
        foreach (['plugins', 'versions', 'data', 'items', 'releases'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $lists[] = $data[$k];
            }
        }
        foreach ($lists as $list) {
            foreach ($list as $key => $e) {
                if (!is_array($e)) {
                    continue;
                }
                $names = [$key, $e['component'] ?? '', $e['pluginComponent'] ?? '', $e['name'] ?? '',
                    $e['pluginId'] ?? '', $e['id'] ?? ''];
                if (in_array(self::COMPONENT, $names, true) || ($e['pluginId'] ?? '') === self::PLUGINID) {
                    return $e;
                }
            }
        }
        return null;
    }

    /**
     * Prepares the confirmation: fresh access check and live release/price. No credits are spent.
     *
     * @return array ['state' => array, 'release' => array, 'canbuy' => bool, 'blocked' => string, 'warning' => string]
     */
    public static function review(): array {
        $state = self::verify();
        $release = self::release();
        $blocked = '';
        if (!self::creds()) {
            $blocked = 'nocredentials';
        } else if (self::pending()) {
            $blocked = 'pending';
        } else if ($state['status'] === 'unlocked') {
            $blocked = 'unlocked';
        } else if ($state['status'] !== 'locked') {
            $blocked = 'unverified';
        } else if (!$release['ok']) {
            $blocked = 'release';
        }
        // A balance below the price is a warning, not a block: LMS Labs may recognise an existing purchase and
        // activate at zero credits; otherwise it refuses (402) and nothing is unlocked.
        $low = $release['ok'] && self::low($state, (int)$release['price']);
        return ['state' => $state, 'release' => $release, 'canbuy' => $blocked === '', 'blocked' => $blocked,
            'warning' => $low ? 'insufficient' : ''];
    }

    /**
     * Whether a known, limited balance is below a price.
     *
     * @param array $state
     * @param int $price
     * @return bool
     */
    public static function low(array $state, int $price): bool {
        return empty($state['unlimited']) && isset($state['credits']) && $state['credits'] !== null
            && $state['credits'] < $price;
    }

    /**
     * Buys the unlock after the admin confirmed the price and release shown to them.
     *
     * @param int $expected credits the admin confirmed
     * @param string $sha release SHA-256 the admin confirmed
     * @return array [
     *     'outcome' => unlocked|restored|already|insufficient|stale|ambiguous|conflict|changed|uncertain|refused|blocked,
     *     'consumed' => int|null (creditsConsumed of a new unlock), 'historic' => int|null (creditsConsumed of an
     *     earlier unlock, when alreadyUnlocked), 'source' => entitlementSource, 'message' => server message (plain
     *     text, escape before output), 'balance' => ['credits', 'unlimited'], 'state' => array, 'error' => string]
     */
    public static function buy(int $expected, string $sha): array {
        $result = ['outcome' => 'blocked', 'consumed' => null, 'historic' => null, 'source' => '', 'message' => '',
            'balance' => ['credits' => null, 'unlimited' => false], 'error' => ''];
        if (self::pending()) {
            $result['error'] = 'pending';
            return $result + ['state' => self::state()];
        }
        $creds = self::creds();
        if (!$creds) {
            $result['error'] = 'nocredentials';
            return $result + ['state' => self::state()];
        }
        // Never buy twice: a fresh free check first.
        $state = self::verify();
        if ($state['status'] === 'unlocked') {
            return ['outcome' => 'already'] + $result + ['state' => $state];
        }
        if ($state['status'] !== 'locked') {
            $result['error'] = 'unverified';
            return $result + ['state' => $state];
        }
        // The live price and release must still be exactly what the admin confirmed.
        $release = self::release();
        if (!$release['ok'] || $release['price'] !== $expected || $release['sha'] !== strtolower($sha)) {
            // Checked here before anything is sent: nothing is bought.
            return ['outcome' => 'changed', 'error' => $release['ok'] ? '' : $release['reason']] + $result
                + ['state' => $state, 'release' => $release];
        }
        if (
            !set_config('unlockpending', json_encode(['time' => time(), 'expected' => $expected,
                'sha' => $release['sha']]), self::COMPONENT)
        ) {
            return ['outcome' => 'blocked', 'error' => 'pendingstorage'] + $result + ['state' => $state];
        }
        [$status, $data] = self::request('POST', self::url(self::UNLOCK), [
            'pluginId' => self::PLUGINID, 'pluginComponent' => self::COMPONENT,
            'siteId' => $creds['siteid'], 'apiKey' => $creds['apikey'],
            'releaseSha256' => $release['sha'], 'expectedCredits' => $expected,
        ], 60);
        $code = self::code($data);
        $result['message'] = self::text($data['message'] ?? '', 300);
        $result['balance'] = self::balance($data ?? []);
        $success = $data !== null && ($data['success'] ?? null) === true;
        $already = $data !== null && ($data['alreadyUnlocked'] ?? null) === true;
        $validflags = $data !== null && is_bool($data['success'] ?? null)
            && (!array_key_exists('alreadyUnlocked', $data) || is_bool($data['alreadyUnlocked']));
        if ($status >= 200 && $status < 300 && $validflags && $success) {
            // Access granted. Response fields: success, alreadyUnlocked, creditsConsumed, entitlementSource,
            // remainingCredits, message, downloadUrl.
            unset_config('unlockpending', self::COMPONENT);
            $consumed = is_int($data['creditsConsumed'] ?? null) || ctype_digit((string)($data['creditsConsumed'] ?? ''))
                ? (int)$data['creditsConsumed'] : null;
            $result['source'] = self::text($data['entitlementSource'] ?? '', 60);
            if ($already) {
                // The creditsConsumed value is the original purchase: history, never a new debit.
                $result['outcome'] = 'already';
                $result['historic'] = $consumed;
            } else if ($consumed === 0 && in_array(strtolower($result['source']), ['marketplace', 'purchase'], true)) {
                // Recognised purchase: activated at zero credits.
                $result['outcome'] = 'restored';
                $result['consumed'] = 0;
            } else {
                $result['outcome'] = 'unlocked';
                $result['consumed'] = $consumed;
            }
            $state = self::verify();
            if ($state['status'] !== 'unlocked') {
                // LMS Labs granted access but the check does not agree yet (or is unreachable): keep the answer.
                $state = ['status' => 'unlocked', 'source' => $result['source'], 'unlockedat' => time()]
                    + $result['balance'];
                self::save($state);
                $state = self::state();
            } else if (!self::has_balance(self::balance($state)) && self::has_balance($result['balance'])) {
                $state = array_merge($state, $result['balance']);
                self::save($state);
            }
            return $result + ['state' => $state];
        }
        // A 2xx that explicitly says success:false with an error code is a definite refusal, not an unknown outcome.
        $definiterefusal = $status >= 200 && $status < 300 && $validflags
            && $data['success'] === false && !$already && $code !== '';
        if (
            !$definiterefusal && ($status === 0 || $status === 408 || $status >= 500
                || ($status >= 200 && $status < 300) || $data === null
                || ($status >= 400 && $status < 500 && $code === ''
                    && self::text($data['message'] ?? '', 160) === ''))
        ) {
            // Unknown outcome (no answer, timeout, server error or an unreadable reply): keep the marker.
            return ['outcome' => 'uncertain', 'error' => self::error($status, $data)] + $result
                + ['state' => self::state()];
        }
        // A definite answer: nothing was unlocked by this request.
        unset_config('unlockpending', self::COMPONENT);
        $result['error'] = self::error($status, $data);
        if ($status === 402 || str_contains($code, 'insufficient')) {
            if (self::has_balance($result['balance'])) {
                $state = array_merge($state, $result['balance']);
                self::save($state);
            }
            return ['outcome' => 'insufficient'] + $result + ['state' => $state];
        }
        if ($code === 'stale_credit_price') {
            return ['outcome' => 'stale'] + $result + ['state' => $state];
        }
        if ($code === 'marketplace_entitlement_ambiguous') {
            return ['outcome' => 'ambiguous'] + $result + ['state' => $state];
        }
        if ($status === 409) {
            // Other conflicts (e.g. release unavailable): show the server's message; support may be needed.
            return ['outcome' => 'conflict'] + $result + ['state' => $state];
        }
        return ['outcome' => 'refused'] + $result + ['state' => $state];
    }

    /**
     * The error code of a reply (lower case), from `code` or `error`.
     *
     * @param array|null $data
     * @return string
     */
    protected static function code(?array $data): string {
        return strtolower(self::text($data['code'] ?? $data['error'] ?? '', 60));
    }

    /**
     * A short, safe error description (HTTP status and LMS Labs code/message; never request data).
     *
     * @param int $status
     * @param array|null $data
     * @return string
     */
    public static function error(int $status, ?array $data): string {
        if ($status === 0) {
            return 'network';
        }
        $code = self::text($data['code'] ?? $data['error'] ?? '', 40);
        $msg = self::text($data['message'] ?? '', 160);
        return trim('HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . ($msg !== '' ? ': ' . $msg : ''));
    }

    /**
     * Plain text from a response value.
     *
     * @param mixed $v
     * @param int $max
     * @return string
     */
    protected static function text($v, int $max): string {
        if (!is_scalar($v)) {
            return '';
        }
        return \core_text::substr(trim(clean_param(strip_tags((string)$v), PARAM_TEXT)), 0, $max);
    }

    /**
     * A timestamp from an ISO date or epoch seconds/milliseconds.
     *
     * @param mixed $v
     * @return int
     */
    protected static function time($v): int {
        if (is_numeric($v)) {
            $v = (int)$v;
            return $v > 100000000000 ? intdiv($v, 1000) : $v;
        }
        $t = is_string($v) ? strtotime($v) : false;
        return $t ?: 0;
    }
}
