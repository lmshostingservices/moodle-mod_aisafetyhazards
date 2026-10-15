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

namespace mod_aisafetyhazards\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_aisafetyhazards\local\learning;

/**
 * Privacy provider for mod_aisafetyhazards.
 *
 * Learners' choices stay in Moodle. Drafting a scene or creating a scene picture with LMS Labs AI sends only teacher-written
 * text (and the site's LMS Labs credentials) to LMS Labs; no learner data leaves Moodle.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables holding learner data, each with aisafetyhazardsid and userid. */
    protected const USER_TABLES = ['aisafetyhazards_attempt', 'aisafetyhazards_ailog', 'aisafetyhazards_aireq'];

    /**
     * Describes stored and transferred personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'aisafetyhazards_attempt',
            [
            'userid' => 'privacy:metadata:attempt:userid',
            'attempt' => 'privacy:metadata:attempt:attempt',
            'state' => 'privacy:metadata:attempt:state',
            'playmode' => 'privacy:metadata:attempt:mode',
            'sceneorder' => 'privacy:metadata:attempt:sceneorder',
            'score' => 'privacy:metadata:attempt:score',
            'safety' => 'privacy:metadata:attempt:safety',
            'timestart' => 'privacy:metadata:attempt:timestart',
            'timefinish' => 'privacy:metadata:attempt:timefinish',
            ],
            'privacy:metadata:attempt'
        );
        $collection->add_database_table(
            'aisafetyhazards_choice',
            [
            'attemptid' => 'privacy:metadata:choice:attemptid',
            'sceneid' => 'privacy:metadata:choice:sceneid',
            'hazardid' => 'privacy:metadata:choice:hazardid',
            'kind' => 'privacy:metadata:choice:kind',
            'itemid' => 'privacy:metadata:choice:itemid',
            'correct' => 'privacy:metadata:choice:correct',
            'timecreated' => 'privacy:metadata:choice:timecreated',
            ],
            'privacy:metadata:choice'
        );
        $collection->add_database_table(
            'aisafetyhazards_ailog',
            [
            'userid' => 'privacy:metadata:ailog:userid',
            'action' => 'privacy:metadata:ailog:action',
            'status' => 'privacy:metadata:ailog:status',
            'timecreated' => 'privacy:metadata:ailog:timecreated',
            ],
            'privacy:metadata:ailog'
        );
        $collection->add_database_table(
            'aisafetyhazards_aireq',
            [
            'userid' => 'privacy:metadata:aireq:userid',
            'body' => 'privacy:metadata:aireq:body',
            'status' => 'privacy:metadata:aireq:status',
            'requestid' => 'privacy:metadata:aireq:requestid',
            'timecreated' => 'privacy:metadata:aireq:timecreated',
            ],
            'privacy:metadata:aireq'
        );
        $collection->add_external_location_link(
            'lmslabs',
            [
            'brief' => 'privacy:metadata:lmslabs:brief',
            'prompt' => 'privacy:metadata:lmslabs:prompt',
            'voicetext' => 'privacy:metadata:lmslabs:voicetext',
            'siteid' => 'privacy:metadata:lmslabs:siteid',
            ],
            'privacy:metadata:lmslabs'
        );
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');
        return $collection;
    }

    /**
     * Contexts containing user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        foreach (self::USER_TABLES as $table) {
            $sql = "SELECT ctx.id
                      FROM {context} ctx
                      JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {{$table}} t ON t.aisafetyhazardsid = cm.instance
                     WHERE t.userid = :userid";
            $contextlist->add_from_sql(
                $sql,
                ['ctxlevel' => CONTEXT_MODULE, 'modname' => 'aisafetyhazards',
                'userid' => $userid]
            );
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        foreach (self::USER_TABLES as $table) {
            $sql = "SELECT t.userid
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {{$table}} t ON t.aisafetyhazardsid = cm.instance
                     WHERE cm.id = :cmid";
            $userlist->add_from_sql('userid', $sql, ['modname' => 'aisafetyhazards', 'cmid' => $context->instanceid]);
        }
    }

    /**
     * Exports user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        $userid = (int)$user->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aisafetyhazards', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $aid = (int)$cm->instance;
            $attempts = [];
            foreach (
                $DB->get_records(
                    'aisafetyhazards_attempt',
                    ['aisafetyhazardsid' => $aid, 'userid' => $userid],
                    'attempt, id'
                ) as $attempt
            ) {
                $choices = [];
                $sql = 'SELECT ch.id, s.title AS scene, h.name AS hazard, ch.kind, ch.itemid, ch.correct, ch.timecreated
                          FROM {aisafetyhazards_choice} ch
                     LEFT JOIN {aisafetyhazards_scene} s ON s.id = ch.sceneid
                     LEFT JOIN {aisafetyhazards_hazard} h ON h.id = ch.hazardid
                         WHERE ch.attemptid = :attemptid
                      ORDER BY ch.id';
                foreach ($DB->get_records_sql($sql, ['attemptid' => $attempt->id]) as $row) {
                    if ($row->kind === 'done') {
                        continue;
                    }
                    $label = $row->kind === 'hazard'
                        ? $DB->get_field('aisafetyhazards_hazard', 'name', ['id' => $row->itemid])
                        : $DB->get_field('aisafetyhazards_control', 'text', ['id' => $row->itemid]);
                    $choices[] = (object)[
                        'scene' => format_string((string)$row->scene, true, ['context' => $context]),
                        'hazard' => format_string((string)$row->hazard, true, ['context' => $context]),
                        'row' => $row->kind,
                        'label' => format_string((string)$label, true, ['context' => $context]),
                        'right' => transform::yesno($row->correct),
                        'time' => transform::datetime($row->timecreated),
                    ];
                }
                $attempts[] = (object)[
                    'attempt' => (int)$attempt->attempt,
                    'state' => $attempt->state,
                    'mode' => $attempt->playmode,
                    'score' => $attempt->score === null ? null : (float)$attempt->score,
                    'riskremoved' => $attempt->safety === null ? null : (float)$attempt->safety,
                    'timestart' => transform::datetime($attempt->timestart),
                    'timefinish' => $attempt->timefinish ? transform::datetime($attempt->timefinish) : null,
                    'choices' => $choices,
                ];
            }
            $ailog = [];
            foreach (
                $DB->get_records(
                    'aisafetyhazards_ailog',
                    ['aisafetyhazardsid' => $aid, 'userid' => $userid],
                    'id'
                ) as $row
            ) {
                $ailog[] = (object)['action' => $row->action, 'status' => $row->status,
                    'time' => transform::datetime($row->timecreated)];
            }
            $aireq = [];
            foreach ($DB->get_records('aisafetyhazards_aireq', ['aisafetyhazardsid' => $aid, 'userid' => $userid], 'id') as $row) {
                $aireq[] = (object)['operation' => $row->operation, 'sent' => json_decode((string)$row->body, true),
                    'status' => $row->status, 'reference' => $row->requestid,
                    'time' => transform::datetime($row->timecreated)];
            }
            if (!$attempts && !$ailog && !$aireq) {
                continue;
            }
            $contextdata = helper::get_context_data($context, $user);
            $contextdata->attempts = $attempts;
            $contextdata->aigeneration = $ailog;
            $contextdata->airequests = $aireq;
            writer::with_context($context)->export_data([], $contextdata);
            helper::export_context_files($context, $user);
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('aisafetyhazards', $context->instanceid);
        if ($cm) {
            learning::delete_all_user_data((int)$cm->instance);
            $DB->delete_records('aisafetyhazards_ailog', ['aisafetyhazardsid' => $cm->instance]);
            $DB->delete_records('aisafetyhazards_aireq', ['aisafetyhazardsid' => $cm->instance]);
        }
    }

    /**
     * Deletes one user's data in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aisafetyhazards', $context->instanceid);
            if ($cm) {
                learning::delete_user_data((int)$cm->instance, $userid);
            }
        }
    }

    /**
     * Deletes data for several users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('aisafetyhazards', $context->instanceid);
        if (!$cm) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            learning::delete_user_data((int)$cm->instance, (int)$userid);
        }
    }
}
