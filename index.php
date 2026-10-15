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
 * Lists all AI Safety Hazards activities in a course.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_aisafetyhazards\local\catalogue;

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);
require_capability('mod/aisafetyhazards:view', $coursecontext);

$event = \mod_aisafetyhazards\event\course_module_instance_list_viewed::create(['context' => $coursecontext]);
$event->add_record_snapshot('course', $course);
$event->trigger();

// Moodle 5.0 and later have a unified activity overview page.
if (class_exists('\core_courseformat\activityoverviewbase')) {
    \core_courseformat\activityoverviewbase::redirect_to_overview_page($course->id, 'aisafetyhazards');
}

$PAGE->set_url('/mod/aisafetyhazards/index.php', ['id' => $course->id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_aisafetyhazards'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$instances = get_all_instances_in_course('aisafetyhazards', $course);
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_aisafetyhazards')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}
$rows = [];
foreach ($instances as $instance) {
    $rows[] = [
        'name' => format_string($instance->name),
        'url' => (new moodle_url('/mod/aisafetyhazards/view.php', ['id' => $instance->coursemodule]))->out(false),
        'dimmed' => !$instance->visible,
        'industry' => implode(' › ', array_slice(catalogue::names($instance), 0, 2)),
        'level' => catalogue::names($instance)[2],
        'intro' => format_module_intro('aisafetyhazards', $instance, $instance->coursemodule),
    ];
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_aisafetyhazards'));
echo $OUTPUT->render_from_template('mod_aisafetyhazards/index', ['rows' => $rows]);
echo $OUTPUT->footer();
