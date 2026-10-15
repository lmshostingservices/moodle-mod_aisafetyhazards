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
 * Reports: learners' results, how each scene went, and attempts.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use mod_aisafetyhazards\local\learning;
use mod_aisafetyhazards\local\manager;

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'learners', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aisafetyhazards');
$instance = $DB->get_record('aisafetyhazards', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aisafetyhazards:viewreports', $context);
if (!in_array($tab, ['learners', 'scenes', 'attempts'], true)) {
    $tab = 'learners';
}
$c = 'mod_aisafetyhazards';
$str = fn($k, $a = null) => get_string($k, $c, $a);

$baseurl = new moodle_url('/mod/aisafetyhazards/report.php', ['id' => $cm->id, 'tab' => $tab]);
$PAGE->set_url($baseurl, ['page' => $page]);
$PAGE->set_title(format_string($instance->name) . ': ' . $str('reports'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
if ($node = $PAGE->settingsnav->find('aisafetyhazards_reports', navigation_node::TYPE_SETTING)) {
    $node->make_active();
}

// Which learners this teacher may see: the chosen group, and only their own groups in separate-groups mode.
$groupmode = groups_get_activity_groupmode($cm);
$currentgroup = groups_get_activity_group($cm, true);
$allgroups = has_capability('moodle/site:accessallgroups', $context);
$visibleusers = null;
if ($groupmode && $currentgroup) {
    $visibleusers = array_keys(groups_get_members($currentgroup, 'u.id'));
} else if ($groupmode == SEPARATEGROUPS && !$allgroups) {
    $visibleusers = [];
    foreach (groups_get_all_groups($course->id, $USER->id, $cm->groupingid) as $group) {
        $visibleusers = array_merge($visibleusers, array_keys(groups_get_members($group->id, 'u.id')));
    }
    $visibleusers = array_values(array_unique($visibleusers));
}
$userfilter = function (string $column) use ($DB, $visibleusers): array {
    if ($visibleusers === null) {
        return ['', []];
    }
    if (!$visibleusers) {
        return [" AND 1 = 0", []];
    }
    [$insql, $params] = $DB->get_in_or_equal($visibleusers, SQL_PARAMS_NAMED, 'vu');
    return [" AND $column $insql", $params];
};
$canmanage = has_capability('mod/aisafetyhazards:manage', $context);

// Attempt management.
if ($action === 'delete' && $canmanage) {
    require_sesskey();
    $ids = optional_param_array('attemptids', [], PARAM_INT);
    if ($ids) {
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'at');
        $userids = $DB->get_fieldset_select(
            'aisafetyhazards_attempt',
            'DISTINCT userid',
            "aisafetyhazardsid = :aid AND id $insql",
            $params + ['aid' => $instance->id]
        );
        $count = learning::delete_attempts($instance, $ids);
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            foreach ($userids as $uid) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$uid);
            }
        }
        redirect($baseurl, $str('attemptsdeleted', $count), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($baseurl);
}
if ($action === 'regrade' && $canmanage) {
    require_sesskey();
    aisafetyhazards_update_grades($instance);
    redirect($baseurl, $str('regraded'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$identity = \core_user\fields::for_identity($context, false)->with_name()->excluding('id');
$userselect = $identity->get_sql('u', true);
$identityfields = \core_user\fields::for_identity($context, false)->get_required_fields();
$fmtpct = fn($v) => $v === null ? '–' : round((float)$v) . '%';
$fmtdate = fn($t) => $t ? userdate($t, get_string('strftimedatetimeshort', 'langconfig')) : '–';

$data = [
    'tabs' => [],
    'learners' => $tab === 'learners',
    'scenes' => $tab === 'scenes',
    'attempts' => $tab === 'attempts',
    'groupselector' => groups_print_activity_menu($cm, $baseurl, true),
    'identityheaders' => array_map(fn($f) => ['name' => \core_user\fields::get_display_name($f)], $identityfields),
];
foreach (['learners', 'scenes', 'attempts'] as $t) {
    $data['tabs'][] = ['name' => $str('report_' . $t), 'url' => (new moodle_url($baseurl, ['tab' => $t]))->out(false),
        'active' => $t === $tab];
}

if ($tab === 'learners') {
    [$esql, $eparams] = get_enrolled_sql($context, 'mod/aisafetyhazards:attempt', $currentgroup ?: 0, true);
    [$ufsql, $ufparams] = $userfilter('u.id');
    $from = "FROM {user} u JOIN ($esql) e ON e.id = u.id {$userselect->joins} WHERE u.deleted = 0 $ufsql";
    $params = $eparams + $ufparams + $userselect->params;
    $count = $DB->count_records_sql("SELECT COUNT(1) $from", $params);
    $sql = "SELECT u.id {$userselect->selects} $from ORDER BY u.lastname, u.firstname, u.id";
    $grades = function (array $userids) use ($instance): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $out = [];
        $info = grade_get_grades($instance->course, 'mod', 'aisafetyhazards', $instance->id, $userids);
        foreach ($info->items[0]->grades ?? [] as $uid => $g) {
            $out[$uid] = $g->grade === null ? null : (float)$g->grade / max(1, (float)$instance->grade) * 100;
        }
        return $out;
    };

    // Report rows for one page of learners.
    $learnerrows = function (
        int $offset,
        int $limit
    ) use (
        $DB,
        $sql,
        $params,
        $instance,
        $identityfields,
        $fmtpct,
        $fmtdate,
        $course,
        $grades
): array {
        $users = $DB->get_records_sql($sql, $params, $offset, $limit);
        if (!$users) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'lu');
        $inparams['aid'] = $instance->id;
        $stats = $DB->get_records_sql(
            "SELECT userid, COUNT(1) AS attempts, MAX(score) AS best, MAX(timefinish) AS lastfinish
               FROM {aisafetyhazards_attempt}
              WHERE aisafetyhazardsid = :aid AND userid $insql AND state = :state AND playmode <> :study
           GROUP BY userid",
            $inparams + ['state' => 'finished', 'study' => 'study']
        );
        $inprogress = $DB->get_records_sql_menu(
            "SELECT userid, COUNT(1) FROM {aisafetyhazards_attempt}
              WHERE aisafetyhazardsid = :aid AND userid $insql AND state = :state GROUP BY userid",
            $inparams + ['state' => 'inprogress']
        );
        $grade = $grades(array_keys($users));
        $rows = [];
        foreach ($users as $u) {
            $st = $stats[$u->id] ?? null;
            $rows[] = [
                'user' => $u,
                'fullname' => fullname($u),
                'profileurl' => (new moodle_url('/user/view.php', ['id' => $u->id, 'course' => $course->id]))->out(false),
                'identity' => array_map(fn($f) => ['value' => $u->$f ?? ''], $identityfields),
                'attempts' => $st ? (int)$st->attempts : 0,
                'inprogress' => !empty($inprogress[$u->id]),
                'best' => $fmtpct($st->best ?? null),
                'grade' => $fmtpct($grade[$u->id] ?? null),
                'lastfinish' => $fmtdate($st->lastfinish ?? 0),
            ];
        }
        return $rows;
    };
    if ($download) {
        $columns = ['fullname' => get_string('fullname')];
        foreach ($identityfields as $f) {
            $columns[$f] = \core_user\fields::get_display_name($f);
        }
        $columns += ['attempts' => $str('col_attempts'), 'best' => $str('col_bestscore'),
            'grade' => $str('col_grade'), 'lastfinish' => $str('col_lastfinish')];
        // Streams learners in chunks so large courses are never loaded at once.
        $generator = (function () use ($learnerrows, $identityfields) {
            for ($offset = 0; ($chunk = $learnerrows($offset, 500)); $offset += 500) {
                foreach ($chunk as $r) {
                    $line = ['fullname' => $r['fullname']];
                    foreach ($identityfields as $f) {
                        $line[$f] = $r['user']->$f ?? '';
                    }
                    yield $line + ['attempts' => $r['attempts'], 'best' => $r['best'], 'grade' => $r['grade'],
                        'lastfinish' => $r['lastfinish']];
                }
            }
        })();
        \core\dataformat::download_data('aisafetyhazards-learners', $download, $columns, $generator);
        exit;
    }
    $rows = array_map(
        function ($r) {
            unset($r['user']);
            return $r;
        },
        $learnerrows($page * $perpage, $perpage)
    );
    $data['rows'] = $rows;
    $data['hasrows'] = !empty($rows);
    $data['paging'] = $OUTPUT->paging_bar($count, $page, $perpage, $baseurl);
    $data['downloads'] = $OUTPUT->download_dataformat_selector(
        get_string('downloadas', 'table'),
        $baseurl->out_omit_querystring(),
        'download',
        ['id' => $cm->id, 'tab' => $tab]
    );
}

if ($tab === 'scenes') {
    $rows = [];
    $scenes = manager::get_scenes((int)$instance->id);
    if ($scenes) {
        [$usql, $uparams] = $userfilter('a.userid');
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($scenes), SQL_PARAMS_NAMED, 'sc');
        $stats = $DB->get_records_sql(
            "SELECT ch.sceneid, COUNT(DISTINCT ch.attemptid) AS attempts, SUM(ch.correct) AS rights,
                    SUM(CASE WHEN ch.correct = 0 THEN 1 ELSE 0 END) AS wrongs
               FROM {aisafetyhazards_choice} ch
               JOIN {aisafetyhazards_attempt} a ON a.id = ch.attemptid
              WHERE a.aisafetyhazardsid = :aid AND ch.sceneid $insql AND ch.kind <> :done $usql
           GROUP BY ch.sceneid",
            $inparams + $uparams + ['aid' => $instance->id, 'done' => 'done']
        );
        $n = 0;
        foreach ($scenes as $scene) {
            $n++;
            $st = $stats[$scene->id] ?? null;
            $total = $st ? (int)$st->rights + (int)$st->wrongs : 0;
            $pct = $total ? round((int)$st->rights / $total * 100) : null;
            $rows[] = [
                'number' => $n,
                'title' => format_string($scene->title, true, ['context' => $context]),
                'attempts' => $st ? (int)$st->attempts : 0,
                'right' => $st ? (int)$st->rights : 0,
                'wrong' => $st ? (int)$st->wrongs : 0,
                'rightpct' => $pct === null ? '–' : $pct . '%',
                'hard' => $pct !== null && $pct < 60,
            ];
        }
    }
    $data['rows'] = $rows;
    $data['hasrows'] = !empty($rows);
}

if ($tab === 'attempts') {
    [$ufsql, $ufparams] = $userfilter('a.userid');
    $where = "a.aisafetyhazardsid = :aid AND u.deleted = 0 $ufsql";
    $params = ['aid' => $instance->id] + $ufparams;
    $count = $DB->count_records_sql(
        "SELECT COUNT(1) FROM {aisafetyhazards_attempt} a JOIN {user} u ON u.id = a.userid
        WHERE $where",
        $params
    );
    $params += $userselect->params;
    $sql = "SELECT a.id, a.userid, a.attempt, a.state, a.playmode, a.timestart, a.timefinish, a.score, a.safety
                   {$userselect->selects}
              FROM {aisafetyhazards_attempt} a
              JOIN {user} u ON u.id = a.userid
                   {$userselect->joins}
             WHERE $where
          ORDER BY u.lastname, u.firstname, a.attempt, a.id";
    $format = fn($a) => [
        'id' => (int)$a->id,
        'fullname' => fullname($a),
        'identity' => array_map(fn($f) => ['value' => $a->$f ?? ''], $identityfields),
        'attempt' => (int)$a->attempt,
        'state' => $str('state_' . $a->state),
        'mode' => $str('mode_' . (in_array($a->playmode, learning::MODES, true) ? $a->playmode : 'practice')),
        'started' => $fmtdate($a->timestart),
        'duration' => $a->timefinish ? format_time((int)$a->timefinish - (int)$a->timestart) : '–',
        'score' => $a->playmode === learning::MODE_STUDY ? '–' : $fmtpct($a->score),
        'safety' => $a->playmode === learning::MODE_STUDY ? '–' : $fmtpct($a->safety),
    ];
    if ($download) {
        $columns = ['fullname' => get_string('fullname')];
        foreach ($identityfields as $f) {
            $columns[$f] = \core_user\fields::get_display_name($f);
        }
        $columns += ['attempt' => $str('col_attempt'), 'mode' => $str('col_mode'), 'state' => $str('col_state'),
            'started' => $str('col_started'),
            'duration' => $str('col_duration'), 'score' => $str('col_score'), 'safety' => $str('col_safety')];
        $rs = $DB->get_recordset_sql($sql, $params);
        \core\dataformat::download_data(
            'aisafetyhazards-attempts',
            $download,
            $columns,
            $rs,
            function ($a) use ($format, $identityfields) {
                $r = $format($a);
                $line = ['fullname' => $r['fullname']];
                foreach ($identityfields as $f) {
                    $line[$f] = $a->$f ?? '';
                }
                return $line + ['attempt' => $r['attempt'], 'mode' => $r['mode'], 'state' => $r['state'],
                    'started' => $r['started'],
                    'duration' => $r['duration'], 'score' => $r['score'], 'safety' => $r['safety']];
            }
        );
        $rs->close();
        exit;
    }
    $rows = array_map($format, array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage)));
    $data['rows'] = $rows;
    $data['hasrows'] = !empty($rows);
    $data['paging'] = $OUTPUT->paging_bar($count, $page, $perpage, $baseurl);
    $data['canmanage'] = $canmanage;
    $data['actionurl'] = $baseurl->out(false);
    $data['sesskey'] = sesskey();
    $data['downloads'] = $OUTPUT->download_dataformat_selector(
        get_string('downloadas', 'table'),
        $baseurl->out_omit_querystring(),
        'download',
        ['id' => $cm->id, 'tab' => $tab]
    );
}

$PAGE->requires->js_call_amd('mod_aisafetyhazards/report', 'init', ['#sh-report']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aisafetyhazards/report', $data);
echo $OUTPUT->footer();
