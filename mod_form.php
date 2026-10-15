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
 * Activity settings form.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_aisafetyhazards\local\catalogue;
use mod_aisafetyhazards\local\manager;

/**
 * Activity settings form for mod_aisafetyhazards.
 *
 * @package    mod_aisafetyhazards
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_aisafetyhazards_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $c = 'mod_aisafetyhazards';
        $config = get_config($c);

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // The work task is chosen in the set-up (industry, sub-industry, task); language and picture style are here too.
        $mform->addElement('header', 'workplacehdr', get_string('task', $c));
        $mform->setExpanded('workplacehdr');
        if (!empty($this->current->instance)) {
            $mform->addElement('static', 'taskinfo', get_string('task', $c), s(implode(' › ', catalogue::names($this->current))));
        }
        $mform->addElement('select', 'contentlang', get_string('contentlang', $c), catalogue::language_options());
        $mform->setDefault('contentlang', $config->defaultcontentlang ?? 'en');
        $mform->addHelpButton('contentlang', 'contentlang', $c);
        $mform->addElement(
            'select',
            'imagestyle',
            get_string('imagestyle', $c),
            [
            'photo' => get_string('imagestyle_photo_name', $c),
            'illustration' => get_string('imagestyle_illustration_name', $c),
            ]
        );

        // Play.
        $mform->addElement('header', 'playhdr', get_string('playsettings', $c));
        $mform->addElement('advcheckbox', 'studymode', get_string('studymode', $c), get_string('studymode_desc', $c));
        $mform->addHelpButton('studymode', 'studymode', $c);
        $mform->setDefault('studymode', 1);
        $mform->addElement('advcheckbox', 'practicemode', get_string('practicemode', $c), get_string('practicemode_desc', $c));
        $mform->addHelpButton('practicemode', 'practicemode', $c);
        $mform->setDefault('practicemode', 1);
        $mform->addElement('advcheckbox', 'testmode', get_string('testmode', $c), get_string('testmode_desc', $c));
        $mform->addHelpButton('testmode', 'testmode', $c);
        $mform->setDefault('testmode', 0);
        $mform->addElement('text', 'passmark', get_string('passmark', $c), ['size' => 4]);
        $mform->setType('passmark', PARAM_INT);
        $mform->setDefault('passmark', 70);
        $mform->addHelpButton('passmark', 'passmark', $c);
        $mform->hideIf('passmark', 'testmode', 'notchecked');
        $mform->addElement('advcheckbox', 'shuffleoptions', get_string('shuffleoptions', $c));
        $mform->setDefault('shuffleoptions', 1);
        $mform->addHelpButton('shuffleoptions', 'shuffleoptions', $c);
        $mform->addElement('advcheckbox', 'sounds', get_string('sounds', $c), get_string('sounds_desc', $c));
        $mform->setDefault('sounds', $config->defaultsounds ?? 1);
        // Voiceover: what is read out (when voiceover is switched on for the site and made in the set-up).
        $parts = [];
        foreach (\mod_aisafetyhazards\local\voiceover::PARTS as $part) {
            $parts[] = $mform->createElement('advcheckbox', $part, '', get_string('voicepart_' . $part, $c));
        }
        $mform->addGroup($parts, 'voiceparts', get_string('voiceparts', $c), '<br>', true);
        $mform->addHelpButton('voiceparts', 'voiceparts', $c);
        foreach (\mod_aisafetyhazards\local\voiceover::PARTS as $part) {
            $mform->setDefault("voiceparts[$part]", 1);
        }
        $mform->addElement('advcheckbox', 'mustlisten', get_string('mustlisten', $c), get_string('mustlisten_desc', $c));
        $mform->addHelpButton('mustlisten', 'mustlisten', $c);
        $mform->setDefault('mustlisten', 0);
        $attemptoptions = [0 => get_string('unlimited')] + array_combine(range(1, 10), range(1, 10));
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', $c), $attemptoptions);
        $mform->addHelpButton('maxattempts', 'maxattempts', $c);

        // Grade.
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', $c),
            [
            manager::GRADE_HIGHEST => get_string('gradehighest', $c),
            manager::GRADE_AVERAGE => get_string('gradeaverage', $c),
            manager::GRADE_FIRST => get_string('gradefirst', $c),
            manager::GRADE_LAST => get_string('gradelast', $c),
            ]
        );
        $mform->addHelpButton('grademethod', 'grademethod', $c);
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Returns the form element name with the completion suffix (Moodle 4.3+).
     *
     * @param string $name
     * @return string
     */
    protected function suffixed(string $name): string {
        return method_exists($this, 'get_suffix') ? $name . $this->get_suffix() : $name;
    }

    /**
     * Adds custom completion rules.
     *
     * @return array element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $name = $this->suffixed('completionallscenes');
        $mform->addElement(
            'advcheckbox',
            $name,
            get_string('completionallscenes', 'mod_aisafetyhazards'),
            get_string('completionallscenes_desc', 'mod_aisafetyhazards')
        );
        $mform->addHelpButton($name, 'completionallscenes', 'mod_aisafetyhazards');
        $pass = $this->suffixed('completionpasstest');
        $mform->addElement(
            'advcheckbox',
            $pass,
            get_string('completionpasstest', 'mod_aisafetyhazards'),
            get_string('completionpasstest_desc', 'mod_aisafetyhazards')
        );
        $mform->addHelpButton($pass, 'completionpasstest', 'mod_aisafetyhazards');
        $mform->hideIf($pass, 'testmode', 'notchecked');
        return [$name, $pass];
    }

    /**
     * Whether a custom completion rule is enabled.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data[$this->suffixed('completionallscenes')]) || !empty($data[$this->suffixed('completionpasstest')]);
    }

    /**
     * "Pass the test" means nothing without a test: it is cleared when Test is switched off.
     *
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $pass = $this->suffixed('completionpasstest');
        if (empty($data->testmode) && !empty($data->$pass)) {
            $data->$pass = 0;
        }
        if (isset($data->completionpasstest) && empty($data->testmode)) {
            $data->completionpasstest = 0;
        }
    }

    /**
     * Shows the stored voiceover parts as ticked boxes.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (isset($defaultvalues['voiceparts']) && is_string($defaultvalues['voiceparts'])) {
            $stored = explode(',', $defaultvalues['voiceparts']);
            $defaultvalues['voiceparts'] = [];
            foreach (\mod_aisafetyhazards\local\voiceover::PARTS as $part) {
                $defaultvalues['voiceparts'][$part] = in_array($part, $stored, true) ? 1 : 0;
            }
        }
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $pass = $this->suffixed('completionpasstest');
        if (!empty($data[$pass]) && !empty($data['testmode']) && (int)($data['passmark'] ?? 0) < 1) {
            $errors[$pass] = get_string('completionpasstest_needtest', 'mod_aisafetyhazards');
        }
        if (empty($data['studymode']) && empty($data['practicemode']) && empty($data['testmode'])) {
            $errors['studymode'] = get_string('modes_needone', 'mod_aisafetyhazards');
        }
        if (!empty($data['testmode']) && ((int)($data['passmark'] ?? 0) < 0 || (int)($data['passmark'] ?? 0) > 100)) {
            $errors['passmark'] = get_string('passmark_range', 'mod_aisafetyhazards');
        }
        return $errors;
    }
}
