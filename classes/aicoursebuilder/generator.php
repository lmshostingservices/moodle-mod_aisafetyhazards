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

namespace mod_aisafetyhazards\aicoursebuilder;

defined('MOODLE_INTERNAL') || die();

/**
 * AI Course Builder adapter using Safety Hazards' idempotent draft request and native scene storage.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS-Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generator {
    /** @var string The plugin's component name. */
    private const COMPONENT = 'mod_aisafetyhazards';

    /**
     * Why AI Course Builder cannot use this activity on this site, or null when it can.
     *
     * @return string|null
     */
    public static function native_ready(): ?string {
        return (new \mod_aisafetyhazards\local\ai\lmslabs())->can_draft() ? null : get_string('aicb_needssetup', 'mod_aisafetyhazards');
    }

    /**
     * Whether this plugin can build activities for AI Course Builder.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists('\local_aicoursebuilder\local\generator_runtime')
            && \local_aicoursebuilder\local\generator_runtime::component_available(self::COMPONENT);
    }

    /**
     * Credits one activity will use.
     *
     * @param array $brief
     * @return int
     */
    public static function estimate(array $brief): int {
        return \local_aicoursebuilder\local\generator_runtime::estimate(self::COMPONENT, $brief);
    }

    /**
     * Start generating one activity.
     *
     * @param int $cmid
     * @param array $brief
     * @return string
     */
    public static function generate(int $cmid, array $brief): string {
        return \local_aicoursebuilder\local\generator_runtime::generate(self::COMPONENT, $cmid, $brief);
    }

    /**
     * Where a generation job is up to.
     *
     * @param int $cmid
     * @param string $jobid
     * @return array
     */
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
        // The scene's text and its picture: a scene without a picture cannot be played.
        return \mod_aisafetyhazards\local\ai\lmslabs::TEXT_CREDITS + \mod_aisafetyhazards\local\ai\lmslabs::IMAGE_CREDITS;
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
        // Lessons that are not about safety (a teacher can add this activity to any lesson) still
        // get a scene: the hazards that arise while doing this lesson's work in this workplace.
        $brieftext = 'Create exactly one learner-facing workplace safety scene for this lesson: '
            . $title . '. Lesson outcomes: '
            . \core_text::substr(implode('; ', $outcomes), 0, 1400)
            . '. Set the scene in this workplace and show the real safety hazards that arise during this kind of work'
            . ' (for example slips and trips, manual handling, equipment, aggressive behaviour or fatigue),'
            . ' each with a suitable risk control, and the safe decision. Return one scene only.';
        $userid = (int)$DB->get_field('local_aicoursebuilder_program', 'userid', ['id' => (int)($brief['programid'] ?? 0)]);
        if ($userid < 1) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder');
        }
        // A new activity starts on the form's default industry (construction). Describe the
        // course's own workplace instead, so a retail or aged-care lesson is not set on a building site.
        if ((string)$instance->industry === 'construction' && (string)$instance->subindustry === '' && (string)$instance->task === '') {
            $clean = static fn($v) => \core_text::substr(trim(str_replace(['<', '>'], '', clean_param((string)$v, PARAM_TEXT))), 0, 255);
            $industry = $clean($program['subject'] ?? '') ?: $clean($program['sector'] ?? '') ?: $clean($program['name'] ?? '');
            if ($industry !== '') {
                $instance->industry = \mod_aisafetyhazards\local\catalogue::CUSTOM;
                $instance->subindustry = \mod_aisafetyhazards\local\catalogue::CUSTOM;
                $instance->task = \mod_aisafetyhazards\local\catalogue::CUSTOM;
                $instance->customindustry = $industry;
                $instance->customsubindustry = $clean($program['level'] ?? '') ?: $industry;
                $instance->customtask = $clean($title);
                $DB->update_record('aisafetyhazards', (object)[
                    'id' => $instance->id,
                    'industry' => $instance->industry, 'subindustry' => $instance->subindustry, 'task' => $instance->task,
                    'customindustry' => $instance->customindustry, 'customsubindustry' => $instance->customsubindustry,
                    'customtask' => $instance->customtask,
                ]);
            }
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
            return self::picture_status($cmid, $instance, $row);
        }
        if (in_array($row->status, ['failed', 'expired', 'conflict', 'dismissed'], true)) {
            // The service could not make a valid draft, or was briefly unavailable: nothing was
            // charged, so AI Course Builder may try again with a new request.
            $transient = in_array((string)$row->errorcode, ['invalid_provider_result', 'provider_unavailable',
                'provider_failed', 'provider_rate_limited', 'serviceunavailable', 'deadline_exceeded'], true);
            return [
                'status' => 'failed',
                'retryable' => $transient,
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
     * Clean list of outcome texts.
     *
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

    /**
     * A drafted scene is playable only with its picture. Make the picture as the editor does, then report complete.
     *
     * @param int $cmid
     * @param \stdClass $instance
     * @param \stdClass $row the completed scene-draft request
     * @return array
     */
    private static function picture_status(int $cmid, \stdClass $instance, \stdClass $row): array {
        global $DB;
        $requests = '\\mod_aisafetyhazards\\local\\ai\\requests';
        $done = ['status' => 'complete', 'result' => ['requestRowId' => (int)$row->id, 'sceneId' => (int)$row->targetid]];
        $scene = $DB->get_record('aisafetyhazards_scene', ['id' => (int)$row->targetid, 'aisafetyhazardsid' => (int)$instance->id]);
        if (!$scene) {
            return ['status' => 'failed', 'retryable' => false, 'error' => 'The drafted scene was not saved.'];
        }
        $context = \context_module::instance($cmid);
        if (\mod_aisafetyhazards\local\manager::get_scene_file($context, (int)$scene->id) !== null) {
            return $done;
        }
        try {
            $image = $DB->get_records('aisafetyhazards_aireq', ['aisafetyhazardsid' => (int)$instance->id,
                'operation' => $requests::IMAGE, 'targetid' => (int)$scene->id], 'id DESC', '*', 0, 1);
            $image = $image ? reset($image) : null;
            if (!$image || in_array($image->status, ['failed', 'expired', 'conflict', 'dismissed'], true)) {
                $tries = (int)$DB->count_records('aisafetyhazards_aireq', ['aisafetyhazardsid' => (int)$instance->id,
                    'operation' => $requests::IMAGE, 'targetid' => (int)$scene->id]);
                if ($tries >= 3) {
                    return ['status' => 'failed', 'retryable' => true,
                        'error' => 'The scene picture could not be made: ' . clean_param((string)($image->errorcode ?? ''), PARAM_TEXT)];
                }
                $requests::start_image($instance, (int)$row->userid, $scene);
                return ['status' => 'running'];
            }
            if (in_array($image->status, ['pending', 'uncertain'], true)) {
                $image = $requests::check($image, $instance);
            }
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'retryable' => true, 'error' => 'The scene picture could not be made: ' . $e->getMessage()];
        }
        if (\mod_aisafetyhazards\local\manager::get_scene_file($context, (int)$scene->id) !== null) {
            return $done;
        }
        return ['status' => 'running'];
    }
}
