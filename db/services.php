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
 * External functions for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_aisafetyhazards_start_attempt' => [
        'classname' => \mod_aisafetyhazards\external\start_attempt::class,
        'description' => 'Starts or resumes the learner\'s attempt and returns its scenes.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_drop_label' => [
        'classname' => \mod_aisafetyhazards\external\drop_label::class,
        'description' => 'Marks one label the learner dropped: a hazard on the picture, or a control on a hazard.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_done_row' => [
        'classname' => \mod_aisafetyhazards\external\done_row::class,
        'description' => 'Test: the learner has finished one row of labels for a hazard.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_finish_attempt' => [
        'classname' => \mod_aisafetyhazards\external\finish_attempt::class,
        'description' => 'Finishes and grades an attempt.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_import_lesson' => [
        'classname' => \mod_aisafetyhazards\external\import_lesson::class,
        'description' => 'Charges for scenes written with an AI assistant (5 LMS Labs credits each) and creates them.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_preview_lesson' => [
        'classname' => \mod_aisafetyhazards\external\preview_lesson::class,
        'description' => 'Reads scenes pasted from an AI assistant and returns those that can be created (free).',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_generate_image' => [
        'classname' => \mod_aisafetyhazards\external\generate_image::class,
        'description' => 'Creates a scene picture with LMS Labs AI (when available on the site).',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_create_voice' => [
        'classname' => \mod_aisafetyhazards\external\create_voice::class,
        'description' => 'Creates one voiceover clip of a scene with LMS Labs (when voiceover is switched on).',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_quote_voices' => [
        'classname' => \mod_aisafetyhazards\external\quote_voices::class,
        'description' => 'Prices voiceover clips before the teacher confirms (free; nothing is made).',
        'type' => 'read',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_save_scene' => [
        'classname' => \mod_aisafetyhazards\external\save_scene::class,
        'description' => 'Saves a scene from the editor: its text, hazards, positions and control labels.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_draft_scene' => [
        'classname' => \mod_aisafetyhazards\external\draft_scene::class,
        'description' => 'Drafts one scene with LMS Labs AI (when available on the site).',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_check_request' => [
        'classname' => \mod_aisafetyhazards\external\check_request::class,
        'description' => 'Asks LMS Labs again about a stored AI request, with the same key.',
        'type' => 'write',
        'ajax' => true,
    ],
    'mod_aisafetyhazards_dismiss_request' => [
        'classname' => \mod_aisafetyhazards\external\dismiss_request::class,
        'description' => 'Hides a stored AI request from the page.',
        'type' => 'write',
        'ajax' => true,
    ],
];
