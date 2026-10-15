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
 * Set-up steps 4 (pictures and hazard spots), 5 (voiceover) and 6 (check each scene: hazards, order, remove).
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aisafetyhazards\local\ai\factory;
use mod_aisafetyhazards\local\ai\requests;
use mod_aisafetyhazards\local\catalogue;
use mod_aisafetyhazards\local\manager;
use mod_aisafetyhazards\local\setuppath;
use mod_aisafetyhazards\local\voiceover;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$sceneid = optional_param('sceneid', 0, PARAM_INT);
$steps = ['pictures' => setuppath::PICTURES, 'voices' => setuppath::VOICES];
$step = $steps[optional_param('step', 'check', PARAM_ALPHA)] ?? setuppath::CHECK;

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aisafetyhazards');
$instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aisafetyhazards:manage', $context);

$baseurl = setuppath::url((int)$cm->id, $step);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($instance->name) . ': ' .
    get_string('setupstep_' . setuppath::STEPS[$step - 1], 'mod_aisafetyhazards'));
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

$scene = null;
if ($sceneid) {
    $scene = $DB->get_record(
        'aisafetyhazards_scene',
        ['id' => $sceneid, 'aisafetyhazardsid' => $instance->id],
        '*',
        MUST_EXIST
    );
}

if (($action === 'up' || $action === 'down') && $scene) {
    require_sesskey();
    manager::move_scene($scene, $action === 'up' ? -1 : 1);
    redirect($baseurl);
}
if ($action === 'delete' && $scene) {
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        manager::delete_scene($context, $scene);
        redirect(
            $baseurl,
            get_string('scenedeleted', 'mod_aisafetyhazards'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletescene', 'mod_aisafetyhazards', format_string($scene->title)),
        new moodle_url($baseurl, ['action' => 'delete', 'sceneid' => $scene->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}
if ($action === 'replace' && $scene) {
    $form = new \mod_aisafetyhazards\form\replace_image_form($baseurl);
    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        $ok = manager::replace_scene_image($context, $scene, (int)$data->image);
        redirect(
            $baseurl,
            get_string($ok ? 'imagereplaced' : 'noimagefound', 'mod_aisafetyhazards'),
            null,
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
    $draftid = file_get_submitted_draft_itemid('image');
    file_prepare_draft_area(
        $draftid,
        $context->id,
        'mod_aisafetyhazards',
        'sceneimage',
        $scene->id,
        manager::image_filemanager_options(1)
    );
    $form->set_data(['id' => $cm->id, 'sceneid' => $scene->id, 'image' => $draftid]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('replaceimage', 'mod_aisafetyhazards') . ': ' . format_string($scene->title), 3);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Scenes are created only in step 3, where every way of creating one is charged.
$scenes = manager::get_scenes($instance->id);
$provider = factory::get();
$canai = $provider->can_generate() && has_capability('mod/aisafetyhazards:useai', $context);
$cards = [];
$i = 0;
$total = count($scenes);
$sesskey = sesskey();
$missing = [];
$nohazards = 0;
// Voiceover: what is still to make, scene by scene (only worked out on the voiceover step).
$voicestep = $step === setuppath::VOICES;
$canvoice = $voicestep && voiceover::enabled() && has_capability('mod/aisafetyhazards:useai', $context);
$voiceconfig = $canvoice ? voiceover::config($instance) : null;
$clips = [];
foreach ($scenes as $s) {
    $i++;
    [$url] = manager::get_scene_image($context, (int)$s->id);
    $hazards = manager::scene_hazards((int)$s->id);
    $hazardsok = manager::hazards_ok($hazards);
    if (!$url) {
        $missing[] = (int)$s->id;
    }
    $nohazards += $hazardsok ? 0 : 1;
    $toclip = [];
    if ($voiceconfig && $voiceconfig['locale'] !== '') {
        foreach (voiceover::segments($s, $voiceconfig, $hazards, voiceover::parts($instance)) as $segment) {
            if (voiceover::url($context, (int)$s->id, $segment) === '') {
                $toclip[] = $segment['index'];
                $clips[] = $s->id . ':' . $segment['index'];
            }
        }
    }
    $spots = [];
    $auto = 0;
    foreach ($hazards as $h) {
        if (empty($h['distractor'])) {
            $spots[] = ['number' => count($spots) + 1, 'x' => (float)$h['x'], 'y' => (float)$h['y'],
                'name' => format_string($h['name'], true, ['context' => $context]), 'auto' => (int)$h['placed'] !== 1];
            $auto += (int)$h['placed'] !== 1 ? 1 : 0;
        }
    }
    $editurl = new moodle_url('/mod/aisafetyhazards/editor.php', ['id' => $cm->id, 'sceneid' => $s->id]);
    $cards[] = [
        'id' => (int)$s->id,
        'number' => $i,
        'title' => format_string($s->title, true, ['context' => $context]),
        'image' => $url,
        'noimage' => !$url,
        'hazardsok' => $hazardsok,
        'canai' => $canai,
        'imagecredits' => \mod_aisafetyhazards\local\ai\lmslabs::IMAGE_CREDITS,
        'editurl' => $editurl->out(false),
        'spotsurl' => (new moodle_url($editurl, ['focus' => 'spots']))->out(false),
        'spots' => $spots,
        'autospots' => $auto,
        'upurl' => $i > 1 ? (new moodle_url($baseurl, ['action' => 'up', 'sceneid' => $s->id, 'sesskey' => $sesskey]))
            ->out(false) : null,
        'downurl' => $i < $total ? (new moodle_url($baseurl, ['action' => 'down', 'sceneid' => $s->id,
            'sesskey' => $sesskey]))->out(false) : null,
        'replaceurl' => (new moodle_url($baseurl, ['action' => 'replace', 'sceneid' => $s->id]))->out(false),
        'deleteurl' => (new moodle_url($baseurl, ['action' => 'delete', 'sceneid' => $s->id]))->out(false),
        'toolong' => (bool)manager::too_long($s, $hazards),
        'voiceready' => $voiceconfig && !$toclip,
        'voicemissing' => $toclip ? get_string('voice_scene_missing', 'mod_aisafetyhazards', count($toclip)) : '',
    ];
}

$str = fn($k, $a = null) => get_string($k, 'mod_aisafetyhazards', $a);
if ($step === setuppath::PICTURES) {
    $blocked = $missing ? $str('setup_needpictures', count($missing)) : '';
    $back = setuppath::CREATE;
} else if ($step === setuppath::VOICES) {
    // Voiceover is optional: it never blocks the next step.
    $blocked = '';
    $back = setuppath::PICTURES;
} else {
    $blocked = $nohazards ? $str('setup_needhazards', $nohazards)
        : ($missing ? $str('setup_needpictures', count($missing)) : '');
    $back = setuppath::VOICES;
}
if (!$total) {
    $blocked = $str('setup_needscene');
}
$next = $step + 1;

$PAGE->requires->js_call_amd('mod_aisafetyhazards/scenes', 'init', ['#sh-scenes']);

$typename = function (string $voice): string {
    $type = strtolower(preg_replace('/^.*-Chirp3-HD-/', '', $voice));
    return get_string_manager()->string_exists('voicetype_' . $type, 'mod_aisafetyhazards')
        ? get_string('voicetype_' . $type, 'mod_aisafetyhazards') : $voice;
};
$voices = $voiceconfig && $voiceconfig['locale'] !== '' ? [['text' => $str('voice_narrator', $typename($voiceconfig['narrator']))]]
    : [];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aisafetyhazards/scenes', [
    'bar' => setuppath::bar($step),
    'pictures' => $step === setuppath::PICTURES,
    'voicestep' => $voicestep,
    'voiceon' => $canvoice && $voiceconfig && $voiceconfig['locale'] !== '',
    'voicenolocale' => $canvoice && $voiceconfig && $voiceconfig['locale'] === '',
    'voiceoff' => $voicestep && !$canvoice,
    'voices' => $voices,
    'voicepartsline' => $str('voice_parts', implode('; ', array_map(
        fn($p) => core_text::strtolower(core_text::substr($str('voicepart_' . $p), 0, 1)) .
            core_text::substr($str('voicepart_' . $p), 1),
        voiceover::parts($instance)
    ))),
    'settingsurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false) . '#id_playhdr',
    'clipcount' => count($clips),
    'clipids' => implode(',', $clips),
    'clipcredits' => count($clips) * \mod_aisafetyhazards\local\ai\lmslabs::VOICE_CREDITS,
    'voicecredits' => \mod_aisafetyhazards\local\ai\lmslabs::VOICE_CREDITS,
    // LMS Labs still publishes another price per clip: nothing new is made until it publishes the approved one.
    'pricehold' => voiceover::price_hold() !== null ? get_string(voiceover::price_hold() ? 'voice_pricehold'
        : 'voice_priceunknown', 'mod_aisafetyhazards', [
        'published' => voiceover::price_hold(), 'approved' => \mod_aisafetyhazards\local\ai\lmslabs::VOICE_CREDITS]) : '',
    // Free remakes (when LMS Labs supports them): each clip is priced before the teacher confirms.
    'remakes' => voiceover::remakes() ? 1 : 0,
    'remakesline' => voiceover::remakes() ? get_string('voice_remakes', 'mod_aisafetyhazards', (object)voiceover::remakes()) : '',
    'check' => $step === setuppath::CHECK,
    'cards' => $cards,
    'hasscenes' => $total > 0,
    'industry' => implode(' › ', catalogue::names($instance)),
    'count' => $total,
    'cmid' => (int)$cm->id,
    'canai' => $canai,
    'aioff' => !$canai,
    'missingcount' => count($missing),
    'missingids' => implode(',', $missing),
    'missingcredits' => count($missing) * \mod_aisafetyhazards\local\ai\lmslabs::IMAGE_CREDITS,
    'imagecredits' => \mod_aisafetyhazards\local\ai\lmslabs::IMAGE_CREDITS,
    'requests' => $step !== setuppath::CHECK && has_capability('mod/aisafetyhazards:useai', $context) ? array_map(
        fn($r) => requests::export($r, $context),
        requests::open((int)$instance->id, $voicestep ? requests::VOICE : requests::IMAGE)
    ) : [],
    'nav' => [
        'backurl' => setuppath::url((int)$cm->id, $back)->out(false),
        'backlabel' => $str('setup_back'),
        'nexturl' => setuppath::url((int)$cm->id, $next)->out(false),
        'nextlabel' => $str('setup_next', $str('setupstep_' . setuppath::STEPS[$next - 1])),
        'blocked' => $blocked,
    ],
]);
echo $OUTPUT->footer();
