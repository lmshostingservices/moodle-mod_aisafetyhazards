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
 * Activity home and the scene player.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aisafetyhazards\local\catalogue;
use mod_aisafetyhazards\local\learning;
use mod_aisafetyhazards\local\manager;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aisafetyhazards');
$instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aisafetyhazards:view', $context);

$PAGE->set_url('/mod/aisafetyhazards/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

$canmanage = has_capability('mod/aisafetyhazards:manage', $context);

// The whole plugin needs this site to be unlocked with LMS Labs (50 credits or a recognised Marketplace purchase).
if (!\mod_aisafetyhazards\local\unlock::active()) {
    echo $OUTPUT->header();
    echo \mod_aisafetyhazards\local\unlock::locked_notice($canmanage);
    echo $OUTPUT->footer();
    exit;
}

$ready = manager::ready_scenes($instance, $context);

// A new activity with nothing in it: take the teacher straight to the lesson builder.
if (!$ready && $canmanage && !manager::get_scenes((int)$instance->id)) {
    redirect(new moodle_url('/mod/aisafetyhazards/builder.php', ['id' => $cm->id]));
}

manager::view($instance, $course, $cm, $context);

$userid = (int)$USER->id;
$canattempt = has_capability('mod/aisafetyhazards:attempt', $context) && !isguestuser();
$finished = $canattempt ? learning::finished_attempts($instance, $userid) : [];
$modes = learning::modes($instance);
$graded = learning::graded_mode($instance);
$best = null;
foreach ($finished as $attempt) {
    if ($attempt->playmode === $graded && $graded !== learning::MODE_STUDY) {
        $best = $best === null ? (float)$attempt->score : max($best, (float)$attempt->score);
    }
}
// One start card per way to play: study, practice, test.
$starts = [];
foreach ($modes as $mode) {
    $inprogress = $canattempt && $DB->record_exists('aisafetyhazards_attempt', ['aisafetyhazardsid' => $instance->id,
        'userid' => $userid, 'state' => learning::STATE_INPROGRESS, 'playmode' => $mode]);
    if (!$canattempt || !$ready || (!$inprogress && !learning::can_start($instance, $userid, $mode))) {
        continue;
    }
    $starts[] = [
        'mode' => $mode,
        'name' => get_string('mode_' . $mode, 'mod_aisafetyhazards'),
        'label' => get_string(($inprogress ? 'resume_' : 'start_') . $mode, 'mod_aisafetyhazards'),
        'intro' => get_string($mode . '_intro', 'mod_aisafetyhazards'),
        'primary' => $mode === $graded,
    ];
}
$canstart = (bool)$starts;
$used = count(array_filter($finished, fn($a) => $a->playmode === $graded));
$history = [];
foreach ($finished as $attempt) {
    if ($attempt->playmode === learning::MODE_STUDY) {
        continue;
    }
    $history[] = ['number' => (int)$attempt->attempt, 'score' => (int)round((float)$attempt->score),
        'safety' => (int)round((float)$attempt->safety),
        'mode' => get_string('mode_' . $attempt->playmode, 'mod_aisafetyhazards'),
        'date' => userdate($attempt->timefinish, get_string('strftimedatetimeshort', 'langconfig'))];
}
[$industry, $sub, $task] = catalogue::names($instance);
$config = [
    'cmid' => (int)$cm->id,
    'sounds' => (int)$instance->sounds,
    'mustlisten' => (int)$instance->mustlisten,
    'contentlang' => (string)$instance->contentlang,
    'rtl' => catalogue::is_rtl((string)$instance->contentlang),
    'passmark' => in_array(learning::MODE_TEST, $modes, true) ? (int)$instance->passmark : 0,
    'task' => $task,
];

$templatedata = [
    'uniqid' => 'sh-' . $cm->id,
    'config' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'ready' => (bool)$ready,
    'canmanage' => $canmanage,
    'canattempt' => $canattempt,
    'canstart' => $canstart,
    'starts' => $starts,
    'nomore' => $canattempt && $ready && !$canstart,
    'industry' => $industry,
    'subindustry' => $sub,
    'task' => $task,
    'language' => catalogue::language_name((string)$instance->contentlang),
    'scenes' => count($ready),
    'bestscore' => $best === null ? null : (int)round($best),
    'hasbest' => $best !== null,
    'history' => array_reverse($history),
    'hashistory' => (bool)$history,
    'attemptsinfo' => $instance->maxattempts
        ? get_string('attemptsused', 'mod_aisafetyhazards', ['used' => $used, 'max' => $instance->maxattempts])
        : '',
    'setupurl' => (new moodle_url('/mod/aisafetyhazards/builder.php', ['id' => $cm->id, 'step' => 'resume']))->out(false),
    'reporturl' => has_capability('mod/aisafetyhazards:viewreports', $context)
        ? (new moodle_url('/mod/aisafetyhazards/report.php', ['id' => $cm->id]))->out(false) : null,
    'dir' => catalogue::is_rtl((string)$instance->contentlang) ? 'rtl' : 'ltr',
    'contentlang' => (string)$instance->contentlang,
];

if ($canstart) {
    $PAGE->requires->js_call_amd('mod_aisafetyhazards/player', 'init', ['#sh-' . $cm->id]);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aisafetyhazards/view', $templatedata);
echo $OUTPUT->footer();
