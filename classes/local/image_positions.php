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
 * Positions of the hazards on a new AI picture, found by LMS Labs when it advertises "anchorPositions". The hazard
 * spots are placed there automatically (placed = 2); a spot the teacher placed is never moved, and the teacher can
 * still move every spot in the editor.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class image_positions {
    /** @var int Most anchors LMS Labs accepts. */
    public const MAX_ANCHORS = 6;

    /** @var int Longest anchor LMS Labs accepts, in code points. */
    public const MAX_ANCHOR = 120;

    /**
     * The descriptions sent with a picture request: what in the picture shows each hazard, in hazard order, at most 6,
     * each at most 120 code points, without control characters, and unique.
     *
     * @param \stdClass $scene
     * @return array hazard id => anchor
     */
    public static function anchors(\stdClass $scene): array {
        $out = [];
        foreach (manager::scene_hazards((int)$scene->id) as $h) {
            if (!empty($h['distractor'])) {
                continue;
            }
            $text = trim((string)$h['anchor']) !== '' ? (string)$h['anchor'] : (string)$h['name'];
            $text = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text));
            if ($text === '' || \core_text::strlen($text) > self::MAX_ANCHOR || in_array($text, $out, true)) {
                continue;
            }
            $out[(int)$h['id']] = $text;
        }
        return array_slice($out, 0, self::MAX_ANCHORS, true);
    }

    /**
     * Validates the optional header without coercing numeric strings.
     *
     * @param string $header compact JSON
     * @param int $count requested anchors
     * @return array|null points or no usable header
     */
    public static function decode(string $header, int $count): ?array {
        if ($count < 1 || $count > 6 || strlen($header) > 2048) {
            return null;
        }
        $points = json_decode($header, true);
        if (!is_array($points) || array_keys($points) !== range(0, $count - 1)) {
            return null;
        }
        foreach ($points as $point) {
            if ($point === null) {
                continue;
            }
            if (!is_array($point) || count($point) !== 2) {
                return null;
            }
            foreach (['x', 'y'] as $axis) {
                $value = $point[$axis] ?? null;
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 100) {
                    return null;
                }
            }
        }
        return $points;
    }

    /**
     * Places the hazards of a scene at the positions LMS Labs found, after a picture was delivered.
     *
     * @param \stdClass $scene
     * @param array $anchors the anchors sent, in order
     * @param string $header the X-Anchor-Positions header
     * @return int how many hazards were placed
     */
    public static function apply(\stdClass $scene, array $anchors, string $header): int {
        global $DB;
        $points = self::decode($header, count($anchors));
        if ($points === null) {
            return 0;
        }
        $positions = [];
        $byanchor = array_flip(self::anchors($scene));
        foreach (array_values($anchors) as $i => $anchor) {
            $hid = $byanchor[$anchor] ?? null;
            if ($hid === null || $points[$i] === null) {
                continue;
            }
            // Never move a spot the teacher placed.
            if ((int)$DB->get_field('aisafetyhazards_hazard', 'placed', ['id' => $hid]) === 1) {
                continue;
            }
            $positions[$hid] = [$points[$i]['x'], $points[$i]['y']];
        }
        if ($positions) {
            manager::place_hazards($scene, $positions, 2);
        }
        return count($positions);
    }
}
