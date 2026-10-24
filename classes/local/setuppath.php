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

use moodle_url;
use stdClass;

/**
 * The teacher's set-up path: seven steps in a fixed order, with Back and Next only.
 *
 * 1 work task (industry, sub-industry, task), 2 language, scenes and picture style, 3 create the scenes, 4 pictures and
 * hazard spots, 5 voiceover (optional: never blocks Next), 6 check each scene, 7 finish.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setuppath {
    /** @var string[] step names, in order (string keys setupstep_<name>). */
    public const STEPS = ['task', 'language', 'create', 'pictures', 'voices', 'check', 'finish'];

    /** @var int The last step of the builder's choices. */
    public const CHOICES = 2;

    /** @var int The create step. */
    public const CREATE = 3;

    /** @var int The pictures step. */
    public const PICTURES = 4;

    /** @var int The voiceover step. */
    public const VOICES = 5;

    /** @var int The check step. */
    public const CHECK = 6;

    /** @var int The finish step. */
    public const FINISH = 7;

    /**
     * The page for a step.
     *
     * @param int $cmid
     * @param int $step 1 to 7
     * @return moodle_url
     */
    public static function url(int $cmid, int $step): moodle_url {
        $builder = '/mod/aisafetyhazards/builder.php';
        $scenes = '/mod/aisafetyhazards/scenes.php';
        if ($step <= self::CHOICES) {
            return new moodle_url($builder, ['id' => $cmid, 'start' => max(1, $step)]);
        }
        return match ($step) {
            self::CREATE => new moodle_url($builder, ['id' => $cmid, 'step' => 'build']),
            self::PICTURES => new moodle_url($scenes, ['id' => $cmid, 'step' => 'pictures']),
            self::VOICES => new moodle_url($scenes, ['id' => $cmid, 'step' => 'voices']),
            self::CHECK => new moodle_url($scenes, ['id' => $cmid, 'step' => 'check']),
            default => new moodle_url($builder, ['id' => $cmid, 'step' => 'finish']),
        };
    }

    /**
     * Template data for the step bar. The bar only shows progress: steps are reached with Back and Next.
     *
     * @param int $current 1 to 7
     * @return array
     */
    public static function bar(int $current): array {
        $steps = [];
        foreach (self::STEPS as $i => $name) {
            $n = $i + 1;
            $steps[] = [
                'number' => $n,
                'name' => get_string('setupstep_' . $name, 'mod_aisafetyhazards'),
                'done' => $n < $current,
                'current' => $n === $current,
                'dot' => $n <= self::CHOICES ? $n : 0,
            ];
        }
        return ['steps' => $steps, 'current' => $current, 'total' => count(self::STEPS)];
    }

    /**
     * Where the set-up path is up to.
     *
     * @param array $state from {@see self::state()}
     * @param bool $choicessaved whether the builder choices have been saved at least once
     * @return int step 1 to 7
     */
    public static function resume_step(array $state, bool $choicessaved): int {
        if (!$state['scenes']) {
            return $choicessaved ? self::CREATE : 1;
        }
        if ($state['nopicture']) {
            return self::PICTURES;
        }
        if ($state['nohazards'] || !empty($state['needrows'])) {
            return self::CHECK;
        }
        return self::FINISH;
    }

    /**
     * Counts what is still missing.
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array scenes, nopicture (scene ids without a picture), nohazards (scene ids whose hazards are not complete),
     *     needrows (older scene ids that play but lack Reduce or PPE answers for a hazard that can be eliminated),
     *     unplaced (scene ids with hazard spots never placed), ready (number playable, as learners see it)
     */
    public static function state(stdClass $instance, \context $context): array {
        $nopicture = [];
        $nohazards = [];
        $needrows = [];
        $unplaced = [];
        $ready = 0;
        $scenes = manager::get_scenes((int)$instance->id);
        foreach ($scenes as $scene) {
            $haspicture = manager::get_scene_image($context, (int)$scene->id)[0] !== null;
            $hazards = manager::scene_hazards((int)$scene->id);
            // The same check as the learner's page (manager::scene_ready()): older scenes play, and are listed apart.
            $hstate = manager::hazards_state($hazards);
            $ok = $hstate !== 'incomplete';
            if (!$haspicture) {
                $nopicture[] = (int)$scene->id;
            }
            if (!$ok) {
                $nohazards[] = (int)$scene->id;
            } else if ($hstate === 'older') {
                $needrows[] = (int)$scene->id;
            }
            if (array_filter($hazards, fn($h) => empty($h['distractor']) && (int)$h['placed'] === 0)) {
                $unplaced[] = (int)$scene->id;
            }
            $ready += $haspicture && $ok ? 1 : 0;
        }
        return ['scenes' => count($scenes), 'nopicture' => $nopicture, 'nohazards' => $nohazards, 'needrows' => $needrows,
            'unplaced' => $unplaced, 'ready' => $ready];
    }
}
