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

use moodle_exception;
use stdClass;

/**
 * Lesson drafts: the AI prompt, reading pasted AI replies, importing scenes, picture prompts and AI rate limits.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson {
    /** @var int Most scenes one import may create. */
    public const MAX_SCENES = 40;

    /** @var int Most scenes the builder asks for at once. */
    public const MAX_SCENES_PER_LESSON = 20;

    /**
     * The instructions given to an AI assistant to draft the lesson.
     *
     * @param stdClass $instance
     * @return string
     */
    public static function prompt(stdClass $instance): string {
        [$industry, $sub, $task] = catalogue::names_english($instance);
        $sm = get_string_manager();
        $a = (object)[
            'industry' => $industry,
            'subindustry' => $sub,
            'task' => $task,
            'language' => catalogue::language_english((string)$instance->contentlang),
            'scenes' => max(1, min(self::MAX_SCENES_PER_LESSON, (int)($instance->scenecount ?? 5))),
            'style' => $sm->get_string('imagestyle_' . $instance->imagestyle, 'mod_aisafetyhazards', null, 'en'),
            'levels' => implode(', ', catalogue::LEVELS),
        ];
        return $sm->get_string('lessonprompt', 'mod_aisafetyhazards', $a, 'en');
    }

    /**
     * A scene's picture description with any words for the other picture style replaced, so it never asks for a
     * photograph when the activity uses illustrations (or the reverse). Descriptions written while the activity had
     * another style often say "a realistic photograph of ...".
     *
     * @param string $text
     * @param string $style photo or illustration
     * @return string
     */
    public static function style_words(string $text, string $style): string {
        if ($style === 'photo') {
            $drawn = '(?:flat |cartoon |vector |bright, friendly flat )*(?:illustration|cartoon|drawing)';
            $text = preg_replace('/\b' . $drawn . '\b/iu', 'photograph', $text);
            return preg_replace('/\b(?:illustrated|cartoon-?style|hand-?drawn)\s*/iu', '', $text);
        }
        $kinds = '(?:ultra-?|hyper-?)?(?:photo-?realistic|realistic|photographic|cinematic)';
        $text = preg_replace('/\b' . $kinds . '\s+(?:photo(?:graph)?|image|picture|shot)\b/iu', 'illustration', $text);
        $text = preg_replace('/\b(?:photo(?:graph)?|DSLR shot)\b/iu', 'illustration', $text);
        $text = preg_replace('/\b(?:ultra-?|hyper-?)?(?:photo-?realistic|photographic|cinematic|realistic)\s*/iu', '', $text);
        $text = preg_replace(['/\b([Aa]) (illustration)\b/u', '/,\s*(?=[,.])/u', '/\s{2,}/u'], ['$1n $2', '', ' '], $text);
        $text = trim($text, " \t\n\r\0\x0B,");
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * The full picture prompt for a scene: the task, the description, and every hazard to show clearly.
     *
     * @param stdClass $instance
     * @param stdClass $scene
     * @param array|null $hazards from {@see manager::scene_hazards()}
     * @return string
     */
    public static function image_prompt(stdClass $instance, stdClass $scene, ?array $hazards = null): string {
        $hazards = $hazards ?? manager::scene_hazards((int)$scene->id);
        $description = trim((string)$scene->imageprompt) !== '' ? trim((string)$scene->imageprompt) : trim((string)$scene->title);
        $description = self::style_words($description, (string)$instance->imagestyle);
        [$industry, $sub, $task] = catalogue::names_english($instance);
        $show = [];
        foreach ($hazards as $h) {
            if (empty($h['distractor'])) {
                $what = trim((string)($h['anchor'] ?? '')) !== '' ? trim((string)$h['anchor']) : trim((string)$h['name']);
                $show[] = '- ' . $what;
            }
        }
        $sm = get_string_manager();
        $build = fn($d) => $sm->get_string('imageprompt_full', 'mod_aisafetyhazards', (object)[
            'description' => $d,
            'hazards' => $show ? implode("\n", $show) : '- the hazards the scene describes',
            'industry' => $industry . ' (' . $sub . ')',
            'task' => $task,
            'style' => $sm->get_string('imagestyle_' . $instance->imagestyle, 'mod_aisafetyhazards', null, 'en'),
            'stylerule' => $sm->get_string('imagestyle_rule_' . $instance->imagestyle, 'mod_aisafetyhazards', null, 'en'),
        ], 'en');
        $prompt = $build($description);
        // The picture route accepts at most 2,000 characters: shorten the description, never the hazards or rules.
        $over = \core_text::strlen($prompt) - \mod_aisafetyhazards\local\ai\lmslabs::MAX_PROMPT;
        if ($over > 0) {
            $keep = max(0, \core_text::strlen($description) - $over - 1);
            $prompt = $build(rtrim(\core_text::substr($description, 0, $keep)) . '…');
        }
        return \core_text::substr($prompt, 0, \mod_aisafetyhazards\local\ai\lmslabs::MAX_PROMPT);
    }

    /**
     * Reads a lesson draft from AI output, tolerating code fences and text around the JSON.
     *
     * @param string $raw
     * @return array cleaned draft
     */
    public static function parse(string $raw): array {
        $text = trim($raw);
        // Remove a Markdown code fence (three backticks, written as \x60) around the JSON.
        $text = preg_replace('/^\x60{3}[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\x60{3}\s*$/', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new moodle_exception('lessoninvalid', 'mod_aisafetyhazards');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new moodle_exception('lessoninvalid', 'mod_aisafetyhazards');
        }
        return self::clean($data);
    }

    /**
     * Reads one control label from an AI draft (several spellings of the effect are accepted).
     *
     * @param mixed $item
     * @return array|null
     */
    protected static function draft_control($item): ?array {
        if (!is_array($item)) {
            return null;
        }
        $truthy = fn($v) => $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
        $steps = fn($v) => abs((int)round((float)$v));
        return [
            'text' => $item['text'] ?? ($item['label'] ?? ''),
            'correct' => $truthy($item['correct'] ?? false),
            'eliminates' => $truthy($item['eliminates'] ?? false),
            'level' => strtolower((string)($item['level'] ?? '')),
            'dl' => $steps($item['lowersLikelihood'] ?? ($item['likelihood'] ?? ($item['dl'] ?? 0))),
            'dc' => $steps($item['lowersConsequence'] ?? ($item['consequence'] ?? ($item['dc'] ?? 0))),
            'reason' => $item['reason'] ?? '',
        ];
    }

    /**
     * Validates and cleans a decoded draft. A scene is kept only with complete hazards.
     *
     * @param array $data
     * @return array scenes: list of {title, context, imageprompt, teachingnote, hazards}
     */
    public static function clean(array $data): array {
        $scenes = [];
        foreach (array_slice((array)($data['scenes'] ?? []), 0, self::MAX_SCENES) as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $hazards = [];
            foreach (array_slice((array)($scene['hazards'] ?? []), 0, catalogue::HAZARDS + manager::MAX_DISTRACTORS) as $h) {
                if (!is_array($h)) {
                    continue;
                }
                $controls = [];
                foreach (catalogue::KINDS as $kind) {
                    $controls[$kind] = array_values(array_filter(array_map(
                        fn($c) => self::draft_control($c),
                        (array)($h[$kind] ?? ($h['controls'][$kind] ?? []))
                    )));
                }
                $hazards[] = [
                    'name' => $h['name'] ?? '',
                    'anchor' => $h['anchor'] ?? '',
                    'why' => $h['why'] ?? '',
                    'likelihood' => $h['likelihood'] ?? 3,
                    'consequence' => $h['consequence'] ?? 3,
                    'distractor' => !empty($h['distractor']),
                    'controls' => $controls,
                ];
            }
            foreach (array_slice((array)($scene['distractors'] ?? []), 0, manager::MAX_DISTRACTORS) as $d) {
                $name = is_array($d) ? ($d['name'] ?? '') : (string)$d;
                $hazards[] = ['name' => $name, 'why' => is_array($d) ? ($d['why'] ?? '') : '', 'distractor' => 1];
            }
            $hazards = manager::clean_hazards($hazards);
            $title = manager::clean_line($scene['title'] ?? '');
            if ($title === '' || !manager::hazards_ok($hazards)) {
                continue;
            }
            $scenes[] = [
                'title' => $title,
                'context' => manager::clean_text($scene['context'] ?? '', 2000),
                'imageprompt' => manager::clean_text($scene['imageprompt'] ?? '', 2000),
                'teachingnote' => manager::clean_text($scene['teachingnote'] ?? '', 2000),
                'hazards' => $hazards,
            ];
        }
        if (!$scenes) {
            throw new moodle_exception('lessonempty', 'mod_aisafetyhazards');
        }
        return ['scenes' => $scenes];
    }

    /**
     * Creates scenes from a cleaned draft.
     *
     * @param stdClass $instance
     * @param array $draft
     * @return array counts: scenes
     */
    public static function import(stdClass $instance, array $draft): array {
        global $DB;
        $count = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach (self::clean($draft)['scenes'] as $scene) {
            $sceneid = manager::add_scene($instance, $scene);
            manager::save_scene(
                $DB->get_record('aisafetyhazards_scene', ['id' => $sceneid], '*', MUST_EXIST),
                [],
                $scene['hazards']
            );
            $count++;
        }
        $transaction->allow_commit();
        return ['scenes' => $count];
    }

    /**
     * Records an AI request.
     *
     * @param int $aid
     * @param int $userid
     * @param string $action
     * @param string $status
     */
    public static function log_ai(int $aid, int $userid, string $action, string $status): void {
        global $DB;
        $DB->insert_record('aisafetyhazards_ailog', (object)['aisafetyhazardsid' => $aid, 'userid' => $userid,
            'action' => $action, 'status' => $status, 'timecreated' => time()]);
    }

    /**
     * Stops a teacher making too many AI requests.
     *
     * @param int $userid
     */
    public static function check_ai_rate(int $userid): void {
        global $DB;
        $limit = (int)(get_config('mod_aisafetyhazards', 'airate') ?: 30);
        $count = $DB->count_records_select(
            'aisafetyhazards_ailog',
            // Voiceover clips have their own limit.
            "userid = :userid AND timecreated > :since AND action <> 'voice'",
            ['userid' => $userid, 'since' => time() - HOURSECS]
        );
        if ($count >= $limit) {
            throw new moodle_exception('airatelimit', 'mod_aisafetyhazards');
        }
    }
}
