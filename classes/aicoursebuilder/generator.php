<?php
namespace mod_aisafetyhazards\aicoursebuilder;

defined('MOODLE_INTERNAL') || die();

/**
 * AI Course Builder adapter using Safety Hazards' idempotent draft request and native scene storage.
 */
final class generator {
    private const COMPONENT = 'mod_aisafetyhazards';

    /**
     * Why AI Course Builder cannot use this activity on this site, or null when it can.
     *
     * @return string|null
     */
    public static function native_ready(): ?string {
        return (new \mod_aisafetyhazards\local\ai\lmslabs())->can_draft() ? null : get_string('aicb_needssetup', 'mod_aisafetyhazards');
    }

    public static function is_available(): bool {
        return class_exists('\local_aicoursebuilder\local\generator_runtime')
            && \local_aicoursebuilder\local\generator_runtime::component_available(self::COMPONENT);
    }

    public static function estimate(array $brief): int {
        return \local_aicoursebuilder\local\generator_runtime::estimate(self::COMPONENT, $brief);
    }

    public static function generate(int $cmid, array $brief): string {
        return \local_aicoursebuilder\local\generator_runtime::generate(self::COMPONENT, $cmid, $brief);
    }

    public static function status(int $cmid, string $jobid): array {
        return \local_aicoursebuilder\local\generator_runtime::status(self::COMPONENT, $cmid, $jobid);
    }

    /**
     * Existing safety-scene draft tariff.
     *
     * @param array $brief
     * @return int
     */
    public static function native_estimate(array $brief): int {
        return \mod_aisafetyhazards\local\ai\lmslabs::TEXT_CREDITS;
    }

    /**
     * Start one safety scenario through the plugin's existing stored request.
     *
     * @param int $cmid
     * @param array $brief
     * @return string request-row ID
     */
    public static function native_generate(int $cmid, array $brief): string {
        global $DB;
        $cm = get_coursemodule_from_id('aisafetyhazards', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
        $outcomes = self::clean_outcomes(
            // AI Course Builder sends the lesson outcomes as a list and the lesson
            // source as text; accept either so the adapter never starts empty.
            !empty($brief['outcomes']) ? $brief['outcomes']
                : (!empty($brief['lesson']['outcomes']) ? $brief['lesson']['outcomes']
                : preg_split('/[\r\n]+/', (string)($brief['sourcecontent'] ?? '')))
        );
        if (!$outcomes) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
        }
        $lesson = $brief['lesson'] ?? [];
        $title = str_replace(['<', '>'], '', trim((string)($lesson['title'] ?? $instance->name)));
        $program = $brief['program'] ?? [];
        $brieftext = 'Create exactly one learner-facing workplace safety scene for this lesson: '
            . $title . '. Teach these tagged safety outcomes: '
            . \core_text::substr(implode('; ', $outcomes), 0, 1400)
            . '. Show a realistic hazard, a suitable risk control, and the safe decision. Return one scene only.';
        $userid = (int)$DB->get_field('local_aicoursebuilder_program', 'userid', ['id' => (int)($brief['programid'] ?? 0)]);
        if ($userid < 1) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
        }
        $row = \mod_aisafetyhazards\local\ai\requests::start_scene(
            $instance,
            $userid,
            $brieftext
        );
        return (string)$row->id;
    }

    /**
     * Poll the plugin-owned request record; retries replay its original idempotency key.
     *
     * @param int $cmid
     * @param string $jobid
     * @return array
     */
    public static function native_status(int $cmid, string $jobid): array {
        global $DB;
        if (!ctype_digit($jobid)) {
            return ['status' => 'failed', 'retryable' => false, 'error' => 'The Safety Hazards request reference is invalid.'];
        }
        $cm = get_coursemodule_from_id('aisafetyhazards', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
        try {
            $row = \mod_aisafetyhazards\local\ai\requests::get((int)$instance->id, (int)$jobid);
            if (in_array($row->status, ['pending', 'uncertain'], true)) {
                $row = \mod_aisafetyhazards\local\ai\requests::check($row, $instance);
            }
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'retryable' => false, 'error' => 'The saved Safety Hazards request could not be read.'];
        }
        if ($row->status === 'completed') {
            return [
                'status' => 'complete',
                'result' => ['requestRowId' => (int)$row->id, 'sceneId' => (int)$row->targetid],
            ];
        }
        if (in_array($row->status, ['failed', 'expired', 'conflict', 'dismissed'], true)) {
            return [
                'status' => 'failed',
                'retryable' => false,
                'error' => clean_param((string)($row->errorcode ?? 'Safety Hazards scene drafting failed.'), PARAM_TEXT),
            ];
        }
        return ['status' => 'running'];
    }

    /**
     * Confirm that the request completed and its scenario is present in native storage.
     *
     * @param int $cmid
     * @param array $result
     * @return bool
     */
    public static function native_save_result(int $cmid, array $result): bool {
        global $DB;
        if (empty($result['requestRowId']) || empty($result['sceneId'])) {
            return false;
        }
        $cm = get_coursemodule_from_id('aisafetyhazards', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
        $row = \mod_aisafetyhazards\local\ai\requests::get((int)$instance->id, (int)$result['requestRowId']);
        return $row->status === 'completed' && (int)$row->targetid === (int)$result['sceneId']
            && $DB->record_exists('aisafetyhazards_scene', [
                'id' => (int)$result['sceneId'],
                'aisafetyhazardsid' => (int)$instance->id,
            ]);
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function clean_outcomes($value): array {
        if (!is_array($value)) {
            return [];
        }
        $outcomes = [];
        foreach ($value as $item) {
            $text = is_array($item) ? trim((string)($item['text'] ?? '')) : trim((string)$item);
            if ($text !== '') {
                $outcomes[] = str_replace(['<', '>'], '', clean_param($text, PARAM_TEXT));
            }
        }
        return $outcomes;
    }
}
