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

use mod_aisafetyhazards\local\ai\lmslabs;
use moodle_url;
use stdClass;

/**
 * Voiceover: each scene read aloud by LMS Labs (Google Chirp 3 HD) in one narrator voice, created once by the teacher
 * in the Voiceover step, stored in Moodle and played to learners.
 *
 * What can be read (each activity chooses): the work task step ("situation"), each hazard and why it is dangerous
 * ("hazards"), each right control and why it works ("controls"), and why each wrong label does not work ("feedback").
 * The narrator is the voice type chosen in the plugin settings. Each clip is at most 200 characters (longer text is
 * split at sentence ends), and each clip LMS Labs makes costs 2 LMS Labs credits. A clip is identified by its locale,
 * voice and exact text, so it is made once and reused, and changing a line only makes that line again.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voiceover {
    /** @var string File area for voiceover clips (item id: scene id). */
    public const FILEAREA = 'voiceover';

    /** @var int Longest clip LMS Labs bills as one, in characters. */
    public const MAX_CHARS = 200;

    /** @var int How long the voice catalogue is kept, in seconds. */
    public const CATALOG_TTL = 3600;

    /** @var int How long a failed catalogue fetch is remembered, in seconds. */
    public const CATALOG_RETRY = 300;

    /** @var string[] What the voiceover can read; each activity chooses. */
    public const PARTS = ['situation', 'hazards', 'controls', 'feedback'];

    /** @var string[] The eight Google Chirp 3 HD voice types. */
    public const VOICETYPES = ['Kore', 'Charon', 'Aoede', 'Puck', 'Leda', 'Fenrir', 'Zephyr', 'Orus'];

    /** @var string Narrator voice type when the setting is not set. */
    public const DEFAULT_NARRATOR = 'Charon';

    /** @var string[] Preferred locale per content language, when LMS Labs offers several. */
    public const PREFERRED = ['en' => 'en-AU', 'es' => 'es-ES', 'fr' => 'fr-FR', 'pt' => 'pt-BR', 'zh' => 'cmn-CN',
        'ar' => 'ar-XA', 'de' => 'de-DE'];

    /**
     * Whether voiceover can be made on this site: switched on, and connected to LMS Labs.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool)get_config('mod_aisafetyhazards', 'aivoice') && credentials::find() !== null;
    }

    /**
     * Whether learners hear voiceover: switched on. Playing clips already made costs nothing and never calls LMS Labs.
     *
     * @return bool
     */
    public static function enabled_for_learners(): bool {
        return (bool)get_config('mod_aisafetyhazards', 'aivoice');
    }

    /**
     * Why no new clip may be asked for: the price LMS Labs publishes per clip when it differs from the approved price,
     * 0 when LMS Labs publishes no price (it is then unknown, and a clip sent without a ceiling could cost more), or
     * null when it publishes the approved price. The teacher is never charged a price they were not shown.
     *
     * @return int|null
     */
    public static function price_hold(): ?int {
        $price = (string)get_config('mod_aisafetyhazards', 'voiceprice');
        if ($price === '' || !ctype_digit($price)) {
            return 0;
        }
        return (int)$price !== lmslabs::VOICE_CREDITS ? (int)$price : null;
    }

    /** @var bool Whether {@see self::catalog()} may ask LMS Labs (false while building a learner's page). */
    protected static $fetch = true;

    /**
     * The LMS Labs voice catalogue, kept in Moodle's cache for an hour (a failure for five minutes).
     *
     * The last good catalogue is also kept in the plugin settings, so a learner's page never waits for LMS Labs.
     *
     * @return array locales
     */
    public static function catalog(): array {
        $cache = \cache::make('mod_aisafetyhazards', 'voicecatalog');
        // The key names the catalogue format, so a new format is read again, never a stale copy (1.4.4: the tariff;
        // 1.4.9: its price per clip).
        $key = sha1('tariff2|' . (string)(credentials::find()['siteid'] ?? ''));
        $cached = $cache->get($key);
        $kept = json_decode((string)get_config('mod_aisafetyhazards', 'voicecatalog'), true);
        $kept = is_array($kept) ? $kept : [];
        if (!self::$fetch) {
            return is_array($cached) && $cached['locales'] ? $cached['locales'] : $kept;
        }
        if (is_array($cached) && ($cached['time'] ?? 0) > time() - ($cached['locales'] ? self::CATALOG_TTL : self::CATALOG_RETRY)) {
            return $cached['locales'] ?: $kept;
        }
        lmslabs::$lasttariff = null;
        $locales = lmslabs::voice_catalog() ?? [];
        $cache->set($key, ['time' => time(), 'locales' => $locales]);
        if ($locales && $locales !== $kept) {
            set_config('voicecatalog', json_encode($locales, JSON_UNESCAPED_SLASHES), 'mod_aisafetyhazards');
        }
        if ($locales && lmslabs::$lasttariff !== null) {
            // The price LMS Labs publishes per clip: voiceover waits while it differs from the approved price.
            $price = lmslabs::$lasttariff['tts'] ?? null;
            set_config('voiceprice', is_int($price) ? (string)$price : '', 'mod_aisafetyhazards');
            // Free remakes are used only once LMS Labs says it takes the new fields (until then it refuses them).
            $remakes = !empty(lmslabs::$lasttariff['clipRefSupported']) && !empty(lmslabs::$lasttariff['maxCreditsRequired']);
            set_config('voiceremakes', $remakes ? json_encode([
                'on' => 1,
                'limit' => (int)(lmslabs::$lasttariff['ttsRemakeLimit'] ?? 10),
                'days' => (int)(lmslabs::$lasttariff['ttsRemakeWindowDays'] ?? 30),
            ]) : '', 'mod_aisafetyhazards');
        }
        return $locales ?: $kept;
    }

    /**
     * The free remake rule when LMS Labs supports it (clips are then sent with clipRef and maxCredits).
     *
     * @return array|null {limit, days}, or null when LMS Labs has not said it supports remakes
     */
    public static function remakes(): ?array {
        $rule = json_decode((string)get_config('mod_aisafetyhazards', 'voiceremakes'), true);
        return is_array($rule) && !empty($rule['on'])
            ? ['limit' => (int)($rule['limit'] ?? 0), 'days' => (int)($rule['days'] ?? 0)] : null;
    }

    /**
     * The stable reference of one place in a scene, sent with each clip so LMS Labs can make a clip again for free
     * after an edit. It never contains text or voice, and its serialisation must never change: a JSON array of the
     * component, this site, the activity, the scene, the part, the line (the dialogue line's stable id, the response
     * id, or -1 for none) and the clip number within that text.
     *
     * @param stdClass $instance
     * @param int $sceneid
     * @param array $segment from {@see self::segments()}
     * @return string 64 lower-case hex characters
     */
    public static function clipref(stdClass $instance, int $sceneid, array $segment): string {
        // A shared heading belongs to the activity (item 0), not to one scene.
        return hash('sha256', json_encode(['mod_aisafetyhazards', (string)get_site_identifier(), (int)$instance->id, $sceneid,
            (string)$segment['part'], (int)($segment['ref'] ?? $segment['line']), (int)($segment['clip'] ?? 0)]));
    }

    /**
     * The voices for a learner's page: from the kept catalogue only, never asking LMS Labs.
     *
     * @param stdClass $instance
     * @return array as {@see self::config()}
     */
    public static function learner_config(stdClass $instance): array {
        self::$fetch = false;
        try {
            return self::config($instance);
        } finally {
            self::$fetch = true;
        }
    }

    /**
     * The locales LMS Labs offers for a content language.
     *
     * @param string $lang content language code, such as en or zh
     * @return string[]
     */
    public static function locales(string $lang): array {
        $codes = $lang === 'zh' ? ['zh', 'cmn', 'yue'] : [$lang];
        $out = [];
        foreach (self::catalog() as $entry) {
            $locale = (string)($entry['locale'] ?? '');
            if (in_array(strtok($locale, '-'), $codes, true) && self::voices_in($entry)) {
                $out[] = $locale;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Chirp 3 HD voice names of a catalogue entry.
     *
     * @param array $entry
     * @return string[]
     */
    protected static function voices_in(array $entry): array {
        $code = (string)($entry['ttsLanguageCode'] ?? $entry['locale'] ?? '');
        $names = [];
        foreach ((array)($entry['voices'] ?? []) as $voice) {
            $name = is_array($voice) ? (string)($voice['name'] ?? '') : '';
            if (str_starts_with($name, $code . '-Chirp3-HD-') && preg_match('/^[a-zA-Z-]+-Chirp3-HD-[a-zA-Z]+$/', $name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Voice names for one locale.
     *
     * @param string $locale
     * @return string[]
     */
    public static function voices(string $locale): array {
        foreach (self::catalog() as $entry) {
            if (($entry['locale'] ?? '') === $locale) {
                return self::voices_in($entry);
            }
        }
        return [];
    }

    /**
     * The activity's voice: the locale LMS Labs offers for the content language and the narrator voice.
     *
     * @param stdClass $instance
     * @return array {locale, narrator, types: type => voice name, lang}; locale '' when LMS Labs has no voices for the
     *     content language
     */
    public static function config(stdClass $instance): array {
        $none = ['locale' => '', 'narrator' => '', 'types' => [], 'lang' => (string)$instance->contentlang];
        $locales = self::locales((string)$instance->contentlang);
        $preferred = self::PREFERRED[(string)$instance->contentlang] ?? '';
        $locale = in_array($preferred, $locales, true) ? $preferred : ($locales[0] ?? '');
        $types = [];
        foreach ($locale !== '' ? self::voices($locale) : [] as $name) {
            $types[preg_replace('/^.*-Chirp3-HD-/', '', $name)] = $name;
        }
        $types = array_intersect_key($types, array_flip(self::VOICETYPES));
        if (!$types) {
            return $none;
        }
        $setting = (string)get_config('mod_aisafetyhazards', 'narratorvoice') ?: self::DEFAULT_NARRATOR;
        $narrator = isset($types[$setting]) ? $setting : array_key_first($types);
        return ['locale' => $locale, 'narrator' => $types[$narrator], 'types' => $types,
            'lang' => (string)$instance->contentlang];
    }

    /**
     * Splits text into clips of at most 200 characters, at sentence ends where possible, then at spaces.
     *
     * @param string $text
     * @return string[]
     */
    public static function chunks(string $text): array {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        if (\core_text::strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }
        $out = [];
        $current = '';
        $pieces = preg_split('/(?<=[.!?…。！？])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($pieces as $piece) {
            while (\core_text::strlen($piece) > self::MAX_CHARS) {
                // One very long sentence: cut at the last space that fits.
                $cut = \core_text::substr($piece, 0, self::MAX_CHARS);
                $space = \core_text::strrpos($cut, ' ');
                $cut = $space ? \core_text::substr($cut, 0, $space) : $cut;
                if ($current !== '') {
                    $out[] = $current;
                    $current = '';
                }
                $out[] = trim($cut);
                $piece = trim(\core_text::substr($piece, \core_text::strlen($cut)));
            }
            $joined = $current === '' ? $piece : $current . ' ' . $piece;
            if (\core_text::strlen($joined) <= self::MAX_CHARS) {
                $current = $joined;
            } else {
                $out[] = $current;
                $current = $piece;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }
        return array_values(array_filter($out, fn($c) => $c !== ''));
    }

    /**
     * What an activity's voiceover reads.
     *
     * @param stdClass $instance
     * @return string[] of {@see self::PARTS}
     */
    public static function parts(stdClass $instance): array {
        $stored = isset($instance->voiceparts) ? explode(',', (string)$instance->voiceparts) : self::PARTS;
        return array_values(array_intersect(self::PARTS, $stored));
    }

    /**
     * The clips of one scene, in playing order.
     *
     * @param stdClass $scene
     * @param array $config from {@see self::config()}
     * @param array|null $hazards from {@see manager::scene_hazards()}
     * @param string[]|null $parts what to read (default: all)
     * @return array list of {index, text, voice, locale, part (situation, hazard, control or feedback), line (hazard or
     *     control id, or 0), clip (number of the clip within that text, from 0), group}
     */
    public static function segments(stdClass $scene, array $config, ?array $hazards = null, ?array $parts = null): array {
        if ($config['locale'] === '') {
            return [];
        }
        $parts = $parts ?? self::PARTS;
        $hazards = $hazards ?? manager::scene_hazards((int)$scene->id);
        $out = [];
        $add = function (string $text, string $part, int $line, string $group) use (&$out, $config): void {
            foreach (self::chunks($text) as $clip => $chunk) {
                $out[] = ['text' => $chunk, 'voice' => $config['narrator'], 'part' => $part, 'line' => $line,
                    'clip' => $clip, 'ref' => $line, 'group' => $group];
            }
        };
        if (in_array('situation', $parts, true)) {
            $add(trim($scene->title . '. ' . $scene->context), 'situation', 0, 'scene');
        }
        foreach ($hazards as $h) {
            if (!empty($h['distractor'])) {
                continue;
            }
            $hid = (int)$h['id'];
            if (in_array('hazards', $parts, true)) {
                $add(trim($h['name'] . '. ' . $h['why']), 'hazard', $hid, 'hazard:' . $hid);
            }
            foreach (catalogue::KINDS as $kind) {
                foreach ($h['controls'][$kind] as $c) {
                    $right = !empty($c['correct']);
                    if (trim((string)$c['reason']) === '' || !in_array($right ? 'controls' : 'feedback', $parts, true)) {
                        continue;
                    }
                    $add(
                        trim($c['text'] . '. ' . $c['reason']),
                        $right ? 'control' : 'feedback',
                        (int)$c['id'],
                        'control:' . (int)$c['id']
                    );
                }
            }
        }
        foreach (array_keys($out) as $i) {
            $out[$i]['index'] = $i;
            $out[$i]['locale'] = $config['locale'];
        }
        return $out;
    }

    /**
     * The request body for one clip, exactly as sent.
     *
     * @param array $segment
     * @param string|null $clipref the clip's place ({@see self::clipref()}), when LMS Labs supports free remakes
     * @param int|null $maxcredits the most the teacher confirmed for this clip: 0 or the price per clip
     * @return array
     */
    public static function body(array $segment, ?string $clipref = null, ?int $maxcredits = null): array {
        $body = ['text' => $segment['text'], 'locale' => $segment['locale'], 'speed' => 'normal', 'voice' => $segment['voice']];
        if ($clipref !== null && $maxcredits !== null) {
            // Both or neither: LMS Labs refuses one without the other.
            $body += ['clipRef' => $clipref, 'maxCredits' => $maxcredits];
        }
        return $body;
    }

    /**
     * The file name of a clip: a hash of everything that changes the audio.
     *
     * @param array $segment
     * @return string
     */
    public static function filename(array $segment): string {
        return 'clip-' . sha1($segment['locale'] . '|' . $segment['voice'] . '|' . $segment['text']) . '.mp3';
    }

    /**
     * The URL of a clip that has been made, or ''.
     *
     * @param \context $context
     * @param int $sceneid
     * @param array $segment
     * @return string
     */
    public static function url(\context $context, int $sceneid, array $segment): string {
        $filename = self::filename($segment);
        if (!get_file_storage()->file_exists($context->id, 'mod_aisafetyhazards', self::FILEAREA, $sceneid, '/', $filename)) {
            return '';
        }
        return moodle_url::make_pluginfile_url($context->id, 'mod_aisafetyhazards', self::FILEAREA, $sceneid, '/', $filename)
            ->out(false);
    }

    /**
     * The clips still to make for an activity, as "sceneid:index".
     *
     * @param stdClass $instance
     * @param \context $context
     * @return string[]
     */
    public static function missing(stdClass $instance, \context $context): array {
        $config = self::config($instance);
        $out = [];
        foreach (manager::get_scenes((int)$instance->id) as $scene) {
            foreach (self::segments($scene, $config, null, self::parts($instance)) as $segment) {
                if (self::url($context, (int)$scene->id, $segment) === '') {
                    $out[] = $scene->id . ':' . $segment['index'];
                }
            }
        }
        return $out;
    }

    /**
     * Why a scene's voiceover does not play, for the teacher trying the activity, or '' when all of it plays.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $scene
     * @param array|null $config the learner voice settings, null when voiceover is turned off
     * @return string
     */
    public static function silent_reason(stdClass $instance, \context $context, stdClass $scene, ?array $config): string {
        if ($config === null) {
            return get_string('voicenote_off', 'mod_aisafetyhazards');
        }
        if ($config['locale'] === '') {
            return get_string('voicenote_nocatalogue', 'mod_aisafetyhazards');
        }
        $missing = 0;
        foreach (self::segments($scene, $config, null, self::parts($instance)) as $segment) {
            if (self::url($context, (int)$scene->id, $segment) === '') {
                $missing++;
            }
        }
        return $missing ? get_string('voicenote_missing', 'mod_aisafetyhazards', $missing) : '';
    }

    /**
     * What learners hear for a scene. A part plays only when all of its clips have been made: the situation, each
     * hazard, and each control.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $scene
     * @param array|null $config
     * @return array {scene: urls, hazard: hazard id => urls, control: control id => urls}
     */
    public static function playlist(stdClass $instance, \context $context, stdClass $scene, ?array $config = null): array {
        $config = $config ?? self::config($instance);
        $groups = [];
        foreach (self::segments($scene, $config, null, self::parts($instance)) as $segment) {
            $groups[$segment['group']][] = self::url($context, (int)$scene->id, $segment);
        }
        $out = ['scene' => [], 'hazard' => [], 'control' => []];
        foreach ($groups as $key => $urls) {
            if (in_array('', $urls, true)) {
                // Not every clip has been made yet: this part stays silent rather than half read.
                continue;
            }
            [$kind, $id] = array_pad(explode(':', $key), 2, 0);
            if ($kind === 'scene') {
                $out['scene'] = $urls;
            } else {
                $out[$kind][(int)$id] = $urls;
            }
        }
        return $out;
    }

    /**
     * Saves a delivered clip. Paid clips are never deleted here (a clip not used now is used again for free); they are
     * deleted with the scene.
     *
     * @param \context $context
     * @param stdClass $scene
     * @param array $segment
     * @param string $mp3
     */
    public static function save(\context $context, stdClass $scene, array $segment, string $mp3): void {
        $fs = get_file_storage();
        $name = self::filename($segment);
        if (!$fs->file_exists($context->id, 'mod_aisafetyhazards', self::FILEAREA, (int)$scene->id, '/', $name)) {
            $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_aisafetyhazards',
                'filearea' => self::FILEAREA, 'itemid' => (int)$scene->id, 'filepath' => '/',
                'filename' => self::filename($segment), 'mimetype' => 'audio/mpeg'], $mp3);
        }
    }

    /**
     * Whether bytes are MP3 audio (an ID3 tag or an MPEG audio frame) of at most 2 MB.
     *
     * @param string $bytes
     * @return bool
     */
    public static function is_mp3(string $bytes): bool {
        if ($bytes === '' || strlen($bytes) > 2 * 1048576) {
            return false;
        }
        $frame = strlen($bytes) > 1 && ord($bytes[0]) === 255 && (ord($bytes[1]) & 0xe0) === 0xe0;
        return substr($bytes, 0, 3) === 'ID3' || $frame;
    }
}
