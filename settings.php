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
 * Site settings for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_aisafetyhazards\local\catalogue;

if ($ADMIN->fulltree) {
    $component = 'mod_aisafetyhazards';

    // Activation is part of this page: status, "Check access" and "Unlock" (confirmed on the next step).
    $settings->add(new \mod_aisafetyhazards\admin\setting_activation("$component/activation"));

    $settings->add(new admin_setting_heading(
        "$component/aiheading",
        get_string('settings_ai', $component),
        get_string('settings_ai_desc', $component) . '<p><strong>' .
            get_string(\mod_aisafetyhazards\local\credentials::status(), $component) . '</strong></p>'
    ));
    $settings->add(new admin_setting_configcheckbox(
        "$component/aidrafts",
        get_string('aidrafts', $component),
        get_string('aidrafts_desc', $component),
        // Off until LMS Labs publishes the AI Safety Hazards routes.
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        "$component/aiimages",
        get_string('aiimages', $component),
        get_string('aiimages_desc', $component),
        0
    ));
    // Off by default: LMS Labs has not published the AI Safety Hazards speech routes yet (tariff: 2 credits per clip).
    $settings->add(new admin_setting_configcheckbox(
        "$component/aivoice",
        get_string('aivoice', $component),
        get_string('aivoice_desc', $component, \mod_aisafetyhazards\local\ai\lmslabs::VOICE_CREDITS),
        0
    ));
    $voicetypes = [];
    foreach (\mod_aisafetyhazards\local\voiceover::VOICETYPES as $type) {
        $voicetypes[$type] = get_string('voicetype_' . strtolower($type), $component);
    }
    $settings->add(new admin_setting_configselect(
        "$component/narratorvoice",
        get_string('narratorvoice', $component),
        get_string('narratorvoice_desc', $component),
        \mod_aisafetyhazards\local\voiceover::DEFAULT_NARRATOR,
        $voicetypes
    ));
    $settings->add(new admin_setting_configtext(
        "$component/lmslabssiteid",
        get_string('lmslabssiteid', $component),
        get_string('lmslabssiteid_desc', $component),
        '',
        PARAM_ALPHANUMEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        "$component/lmslabsapikey",
        get_string('lmslabsapikey', $component),
        get_string('lmslabsapikey_desc', $component),
        ''
    ));
    $settings->add(new admin_setting_configtextarea(
        "$component/extratasks",
        get_string('extratasks', $component),
        get_string('extratasks_desc', $component),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        "$component/airate",
        get_string('airate', $component),
        get_string('airate_desc', $component),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        "$component/defaultsheading",
        get_string('settings_defaults', $component),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        "$component/defaultcontentlang",
        get_string('contentlang', $component),
        '',
        'en',
        catalogue::language_options()
    ));
    $settings->add(new admin_setting_configcheckbox(
        "$component/defaultsounds",
        get_string('sounds', $component),
        '',
        1
    ));
}
