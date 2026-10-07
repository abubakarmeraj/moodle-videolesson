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
 * Provision license form.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\local\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/videolesson/lib.php');
require_once($CFG->dirroot . '/mod/videolesson/classes/license.php');

/**
 * Provision form.
 *
 * @package    mod_videolesson
 */
class provision_form extends \moodleform {
    /**
     * Definition for the provision form.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'licensetype', 'self');
        $mform->setType('licensetype', PARAM_ALPHANUM);

        $mform->addElement('text', 'licensekey', get_string('provision:license', 'mod_videolesson'));
        $mform->setType('licensekey', PARAM_ALPHANUMEXT);
        $mform->addRule('licensekey', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('licensekey', 'provision:license', 'mod_videolesson');
        $mform->addElement('static', 'description', '', get_string('license:description', 'mod_videolesson'));

        // Key.
        $mform->addElement('text', 'provisionkey', get_string('provision:key', 'mod_videolesson'));
        $mform->setType('provisionkey', PARAM_TEXT);
        $mform->addHelpButton('provisionkey', 'provision:key', 'mod_videolesson');
        $mform->addRule('provisionkey', get_string('required'), 'required', null, 'client');

        // Secret.
        $mform->addElement('text', 'provisionsecret', get_string('provision:secret', 'mod_videolesson'));
        $mform->setType('provisionsecret', PARAM_TEXT);
        $mform->addHelpButton('provisionsecret', 'provision:secret', 'mod_videolesson');
        $mform->addRule('provisionsecret', get_string('required'), 'required', null, 'client');

        // Region.
        $regionoptions = [
            'us-east-1'      => 'US East (N. Virginia)',
            'us-west-1'      => 'US West (N. California)',
            'us-west-2'      => 'US West (Oregon)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-south-1'     => 'Asia Pacific (Mumbai)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'eu-west-1'      => 'EU (Ireland)',
        ];
        $attributes = [];
        $mform->addElement(
            'select',
            'provisionregion',
            get_string('provision:region', 'mod_videolesson'),
            $regionoptions,
            $attributes
        );
        $mform->setType('provisionregion', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('provisionregion', 'provision:region', 'mod_videolesson');
        $mform->addRule('provisionregion', get_string('required'), 'required', null, 'client');

        // Add submit button.
        $mform->addElement('submit', 'submitbutton', get_string('submit'));
    }

    /**
     * Validation for the provision form.
     *
     * @param array $data The data from the form.
     * @param array $files The files from the form.
     * @return array The errors from the form.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $license = new \mod_videolesson\license();
        $result = $license->provision(
            $data['licensekey'],
            $data['provisionkey'],
            $data['provisionsecret'],
            $data['provisionregion']
        );

        if (!empty($result['errors'])) {
            $errors = array_merge($result['errors'], $errors);
        }
        return $errors;
    }
}
