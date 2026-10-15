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
 * Set-up steps 1 to 3 and 7: the work task, language and size, create the scenes; finish. Steps 4 to 6 are scenes.php.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aisafetyhazards\local\ai\factory;
use mod_aisafetyhazards\local\ai\requests;
use mod_aisafetyhazards\local\catalogue;
use mod_aisafetyhazards\local\lesson;
use mod_aisafetyhazards\local\manager;
use mod_aisafetyhazards\local\setuppath;

$id = required_param('id', PARAM_INT);
$step = optional_param('step', '', PARAM_ALPHA);
$start = optional_param('start', 1, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aisafetyhazards');
$instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aisafetyhazards:manage', $context);

$baseurl = new moodle_url('/mod/aisafetyhazards/builder.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('setup_title', 'mod_aisafetyhazards'));
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

// Save the choices from steps 1 and 2.
if (optional_param('savechoices', 0, PARAM_BOOL)) {
    require_sesskey();
    $data = (object)[
        'id' => $instance->id,
        'industry' => required_param('industry', PARAM_ALPHA),
        'subindustry' => optional_param('subindustry', '', PARAM_ALPHA),
        'task' => optional_param('task', '', PARAM_ALPHA),
        'customindustry' => optional_param('customindustry', '', PARAM_TEXT),
        'customsubindustry' => optional_param('customsubindustry', '', PARAM_TEXT),
        'customtask' => optional_param('customtask', '', PARAM_TEXT),
        'contentlang' => required_param('contentlang', PARAM_ALPHA),
        'imagestyle' => optional_param('imagestyle', 'photo', PARAM_ALPHA),
        'scenecount' => optional_param('scenecount', 5, PARAM_INT),
        'timemodified' => time(),
    ];
    $DB->update_record('aisafetyhazards', manager::prepare_instance_data($data));
    redirect(new moodle_url($baseurl, ['step' => 'build']));
}

$instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
$str = fn($k, $a = null) => get_string($k, 'mod_aisafetyhazards', $a);
$nav = fn(int $back, ?int $next, string $blocked = '') => ['nav' => [
    'backurl' => setuppath::url((int)$cm->id, $back)->out(false),
    'backlabel' => $str('setup_back'),
    'nexturl' => $next ? setuppath::url((int)$cm->id, $next)->out(false) : null,
    'nextlabel' => $next ? $str('setup_next', $str('setupstep_' . setuppath::STEPS[$next - 1])) : '',
    'blocked' => $blocked,
]];

if ($step === 'resume') {
    $state = setuppath::state($instance, $context);
    redirect(setuppath::url((int)$cm->id, setuppath::resume_step($state, (string)$instance->task !== '')));
}

if ($step === 'finish') {
    $state = setuppath::state($instance, $context);
    $todo = [];
    if (!$state['scenes']) {
        $todo[] = $str('finish_noscenes');
    }
    if ($state['nopicture']) {
        $todo[] = $str('finish_nopicture', count($state['nopicture']));
    }
    if ($state['nohazards']) {
        $todo[] = $str('finish_nohazards', count($state['nohazards']));
    }
    $data = [
        'bar' => setuppath::bar(setuppath::FINISH),
        'title' => $str('setupstep_finish'),
        'ready' => $state['ready'],
        'scenes' => $state['scenes'],
        'allready' => !$todo,
        'todo' => $todo,
        'viewurl' => (new moodle_url('/mod/aisafetyhazards/view.php', ['id' => $cm->id]))->out(false),
        'courseurl' => course_get_url($course, $cm->sectionnum)->out(false),
    ] + $nav(setuppath::CHECK, null);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_aisafetyhazards/setup_finish', $data);
    echo $OUTPUT->footer();
    exit;
}

if ($step === 'build') {
    $provider = factory::get();
    $balance = $provider->balance();
    [$industry, $sub, $task] = catalogue::names($instance);
    $templatedata = [
        'cmid' => (int)$cm->id,
        'summary' => [
            'industry' => $industry,
            'subindustry' => $sub,
            'task' => $task,
            'language' => catalogue::language_name($instance->contentlang),
            'scenes' => (int)$instance->scenecount,
            'style' => $str('imagestyle_' . $instance->imagestyle . '_name'),
        ],
        'hasbalance' => $balance !== null,
        'balance' => $balance === null ? '' : ($balance['unlimited'] ? $str('balance_unlimited')
            : $str('balance_credits', $balance['credits'])),
        'prompt' => lesson::prompt($instance),
        'existing' => count(manager::get_scenes($instance->id)),
        'bar' => setuppath::bar(setuppath::CREATE),
        'nexturl' => setuppath::url((int)$cm->id, setuppath::PICTURES)->out(false),
    ];
    $templatedata += $nav(setuppath::CHOICES, setuppath::PICTURES, $templatedata['existing'] ? '' : $str('setup_needscene'));
    if ($provider->can_draft() && has_capability('mod/aisafetyhazards:useai', $context)) {
        [$eind, $esub, $etask] = catalogue::names_english($instance);
        $templatedata += [
            'canai' => true,
            'draftcredits' => \mod_aisafetyhazards\local\ai\lmslabs::TEXT_CREDITS,
            'brief' => get_string_manager()->get_string(
                'aidraft_briefdefault',
                'mod_aisafetyhazards',
                (object)[
                'task' => $etask, 'subindustry' => $esub, 'industry' => $eind,
                'language' => catalogue::language_english((string)$instance->contentlang),
                ],
                'en'
            ),
            'requests' => array_map(
                fn($r) => requests::export($r, $context),
                requests::open((int)$instance->id, requests::SCENE)
            ),
        ];
    }
    // Scenes from the teacher's own AI assistant are charged like LMS Labs drafts, so they need LMS Labs too.
    $canimport = $provider instanceof \mod_aisafetyhazards\local\ai\lmslabs && $provider->is_connected()
        && has_capability('mod/aisafetyhazards:useai', $context);
    $templatedata += [
        'canimport' => $canimport,
        'importcredits' => \mod_aisafetyhazards\local\ai\lmslabs::TEXT_CREDITS,
        'importblock' => [
            'cmid' => (int)$cm->id,
            'requests' => $canimport ? array_map(
                fn($r) => requests::export($r, $context),
                requests::open((int)$instance->id, requests::IMPORT)
            ) : [],
        ],
    ];
    $PAGE->requires->js_call_amd('mod_aisafetyhazards/builder', 'initBuild', ['#sh-build']);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_aisafetyhazards/builder_build', $templatedata);
    echo $OUTPUT->footer();
    exit;
}

// Steps 1 and 2.
$languages = [];
foreach (catalogue::LANGUAGES as $code => [$endonym, $rtl]) {
    $name = catalogue::language_name($code);
    $languages[] = ['code' => $code, 'name' => $name, 'endonym' => $endonym, 'showendonym' => $name !== $endonym,
        'rtl' => $rtl, 'selected' => $code === $instance->contentlang];
}
$tree = catalogue::picker();
$templatedata = [
    'cmid' => (int)$cm->id,
    'action' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'industries' => array_map(
        fn($i) => ['key' => $i['key'], 'name' => $i['name'],
        'selected' => $i['key'] === $instance->industry],
        $tree
    ),
    'customindustry' => (string)$instance->customindustry,
    'customsubindustry' => (string)$instance->customsubindustry,
    'customtask' => (string)$instance->customtask,
    'languages' => $languages,
    'scenecounts' => array_map(
        fn($n) => ['value' => $n, 'selected' => $n === (int)$instance->scenecount],
        range(1, lesson::MAX_SCENES_PER_LESSON)
    ),
    'illustration' => $instance->imagestyle === 'illustration',
    'photo' => $instance->imagestyle !== 'illustration',
    'bar' => setuppath::bar(max(1, min(setuppath::CHOICES, $start))),
    // The whole tree, so the sub-industry and task lists follow the choice above them without reloading.
    'treejson' => json_encode(
        ['tree' => $tree, 'industry' => (string)$instance->industry,
        'subindustry' => (string)$instance->subindustry, 'task' => (string)$instance->task],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
];
$PAGE->requires->js_call_amd('mod_aisafetyhazards/builder', 'initWizard', ['#sh-wizard', max(1, min(setuppath::CHOICES, $start))]);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aisafetyhazards/builder', $templatedata);
echo $OUTPUT->footer();
