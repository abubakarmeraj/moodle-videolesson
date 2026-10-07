<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The main mod_videolesson configuration form.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/moodleform_mod.php');
class mod_videolesson_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'cfsubmission', bin2hex(random_bytes(32)));
        $mform->setType('cfsubmission', PARAM_ALPHANUM);
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $this->standard_intro_elements();
        $options = \mod_videolesson\local\library::options((int)$this->_course->id,
            (int)($this->current->instance ?? 0));
        if ($this->current->instance) {
            $options[0] = get_string('cfkeepreference', 'mod_videolesson');
        }
        $mform->addElement('select', 'cfexisting', get_string('cfselectvideo', 'mod_videolesson'), $options);
        $mform->setType('cfexisting', PARAM_INT);
        $mform->addElement('select', 'mediakind', get_string('cfmediakind', 'mod_videolesson'),
            ['direct' => get_string('cfdirect', 'mod_videolesson'), 'hls' => get_string('cfhls', 'mod_videolesson')]);
        $mform->setDefault('mediakind', 'hls');
        if ($this->current->instance) {
            $mform->hardFreeze('mediakind');
        }
        $mform->disabledIf('mediakind', 'cfexisting', 'neq', 0);
        $mform->addElement('advcheckbox', 'disableseek', get_string('cfdisableseek', 'mod_videolesson'));
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
    public function add_completion_rules() {
        $mform = $this->_form;
        $mform->addElement('advcheckbox', 'completionprogressenabled', get_string('cfcompletion', 'mod_videolesson'));
        $mform->addElement('select', 'completionprogress', get_string('cfthreshold', 'mod_videolesson'),
            array_combine(range(0, 100), range(0, 100)));
        $mform->disabledIf('completionprogress', 'completionprogressenabled', 'notchecked');
        return ['completionprogressenabled'];
    }
    public function completion_rule_enabled($data) {
        return !empty($data['completionprogressenabled']);
    }
    public function data_preprocessing(&$defaults) {
        $defaults['completionprogressenabled'] = !empty($defaults['completionprogress']);
        $defaults['disableseek'] = json_decode($defaults['options'] ?? '{}', true)['seek'] ?? false;
    }
}
