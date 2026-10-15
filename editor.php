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

/**
 * Scene editor: the picture with its hazard spots, the scene text, and each hazard with its control labels.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aisafetyhazards\local\catalogue;
use mod_aisafetyhazards\local\manager;

$id = required_param('id', PARAM_INT);
$sceneid = required_param('sceneid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aisafetyhazards');
$instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aisafetyhazards:manage', $context);
$scene = $DB->get_record('aisafetyhazards_scene', ['id' => $sceneid, 'aisafetyhazardsid' => $instance->id], '*', MUST_EXIST);

$url = new moodle_url('/mod/aisafetyhazards/editor.php', ['id' => $cm->id, 'sceneid' => $scene->id]);
$backurl = \mod_aisafetyhazards\local\setuppath::url((int)$cm->id, \mod_aisafetyhazards\local\setuppath::CHECK);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name) . ': ' . format_string($scene->title));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
if ($node = $PAGE->settingsnav->find('aisafetyhazards_builder', navigation_node::TYPE_SETTING)) {
    $node->make_active();
}

// The whole plugin needs this site to be unlocked with LMS Labs (50 credits or a recognised Marketplace purchase).
if (!\mod_aisafetyhazards\local\unlock::active()) {
    echo $OUTPUT->header();
    echo \mod_aisafetyhazards\local\unlock::locked_notice();
    echo $OUTPUT->footer();
    exit;
}

$focus = optional_param('focus', '', PARAM_ALPHA);
$hazards = manager::scene_hazards((int)$scene->id);
// A hazard never placed gets a guessed spot, so the teacher only drags it into place.
$hazards = manager::guess_positions($hazards);
$plain = fn($t) => format_string((string)$t, true, ['context' => $context, 'escape' => false]);
$data = [
    'sceneid' => (int)$scene->id,
    'title' => $plain($scene->title),
    'context' => (string)$scene->context,
    'imageprompt' => (string)$scene->imageprompt,
    'teachingnote' => (string)$scene->teachingnote,
    'hazards' => array_map(
        function ($h) {
            $h['controls'] = array_map(
                fn($row) => array_map(
                    fn($c) => [
                        'id' => (int)$c['id'], 'text' => (string)$c['text'], 'correct' => (int)$c['correct'],
                        'eliminates' => (int)$c['eliminates'], 'level' => (string)$c['level'], 'dl' => (int)$c['dl'],
                        'dc' => (int)$c['dc'], 'reason' => (string)$c['reason'],
                    ],
                    $row
                ),
                $h['controls']
            );
            return ['id' => (int)($h['id'] ?? 0), 'name' => (string)$h['name'], 'anchor' => (string)$h['anchor'],
            'why' => (string)$h['why'], 'likelihood' => (int)$h['likelihood'], 'consequence' => (int)$h['consequence'],
            'x' => (float)$h['x'], 'y' => (float)$h['y'], 'placed' => (int)$h['placed'],
            'distractor' => (int)$h['distractor'], 'controls' => $h['controls']];
        },
        $hazards
    ),
    'limits' => manager::LIMITS,
    'levels' => catalogue::LEVELS,
    'rowsize' => manager::ROW_SIZE,
    'maxdistractors' => manager::MAX_DISTRACTORS,
    'focus' => $focus,
    'backurl' => $backurl->out(false),
];

$PAGE->requires->js_call_amd('mod_aisafetyhazards/editor', 'init', ['#sh-editor']);
[$image] = manager::get_scene_image($context, (int)$scene->id);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'mod_aisafetyhazards/editor',
    [
    'title' => format_string($scene->title, true, ['context' => $context, 'escape' => false]),
    'bar' => \mod_aisafetyhazards\local\setuppath::bar(\mod_aisafetyhazards\local\setuppath::CHECK),
    'image' => $image,
    'backurl' => $backurl->out(false),
    'replaceurl' => (new moodle_url($backurl, ['action' => 'replace', 'sceneid' => $scene->id]))->out(false),
    'datajson' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'rtl' => catalogue::is_rtl((string)$instance->contentlang),
    ]
);
echo $OUTPUT->footer();
