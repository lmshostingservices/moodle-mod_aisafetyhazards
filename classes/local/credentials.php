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
 * Resolves the site's LMS Labs credentials: LMS Labs Central Config (local_aiconfig) first, then this plugin's own
 * settings. Only a complete pair is ever used; a central value is never mixed with a local one. Values are read when
 * needed and never copied, so a change in Central Config takes effect at once.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class credentials {
    /** @var string Credentials came from Central Config. */
    public const SOURCE_CENTRAL = 'central';

    /** @var string Credentials came from this plugin's settings. */
    public const SOURCE_LOCAL = 'local';

    /** @var callable|null Replaces the Central Config lookup in unit tests; it returns an array, or null for "not installed". */
    public static $central = null;

    /**
     * The Central Config pair, or null when Central Config is not installed.
     *
     * @return array|null ['siteid' => string, 'apikey' => string]
     */
    public static function central(): ?array {
        if (self::$central !== null) {
            $values = (self::$central)();
        } else if (class_exists('\\local_aiconfig\\config')) {
            $values = \local_aiconfig\config::get_credentials();
        } else {
            return null;
        }
        if (!is_array($values)) {
            return null;
        }
        return ['siteid' => trim((string)($values['siteid'] ?? '')), 'apikey' => trim((string)($values['apikey'] ?? ''))];
    }

    /**
     * This plugin's own pair (optional standalone configuration).
     *
     * @return array ['siteid' => string, 'apikey' => string]
     */
    public static function local(): array {
        return [
            'siteid' => trim((string)get_config('mod_aisafetyhazards', 'lmslabssiteid')),
            'apikey' => trim((string)get_config('mod_aisafetyhazards', 'lmslabsapikey')),
        ];
    }

    /**
     * Whether a pair has both values.
     *
     * @param array|null $pair
     * @return bool
     */
    protected static function complete(?array $pair): bool {
        return $pair !== null && $pair['siteid'] !== '' && $pair['apikey'] !== '';
    }

    /**
     * The complete pair to use, central first, or null when neither pair is complete.
     *
     * @return array|null ['siteid' => string, 'apikey' => string, 'source' => central|local]
     */
    public static function find(): ?array {
        $central = self::central();
        if (self::complete($central)) {
            return $central + ['source' => self::SOURCE_CENTRAL];
        }
        $local = self::local();
        if (self::complete($local)) {
            return $local + ['source' => self::SOURCE_LOCAL];
        }
        return null;
    }

    /**
     * The complete pair to use, central first.
     *
     * @return array ['siteid' => string, 'apikey' => string, 'source' => central|local]
     * @throws \moodle_exception when neither pair is complete
     */
    public static function resolve(): array {
        $pair = self::find();
        if ($pair === null) {
            throw new \moodle_exception('lmslabscredentialsmissing', 'mod_aisafetyhazards');
        }
        return $pair;
    }

    /**
     * Which credentials are in use, for the settings page (never shows the values).
     *
     * @return string string identifier: credentials_central, credentials_local, credentials_centralincomplete or
     *                credentials_none
     */
    public static function status(): string {
        $pair = self::find();
        if ($pair !== null) {
            return 'credentials_' . $pair['source'];
        }
        return self::central() !== null ? 'credentials_centralincomplete' : 'credentials_none';
    }
}
