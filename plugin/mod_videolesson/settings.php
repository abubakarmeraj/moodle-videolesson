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
 * Settings
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
$ADMIN->add('modsettings', new admin_externalpage('videolessonservice',
    get_string('cfservicesetup', 'mod_videolesson'), new moodle_url('/mod/videolesson/service_setup.php'), 'moodle/site:config'));
$ADMIN->add('modsettings', new admin_externalpage('videolessonstorage',
    get_string('cfstorage', 'mod_videolesson'), new moodle_url('/mod/videolesson/storage.php'), 'moodle/site:config'));
if ($ADMIN->fulltree) {
    $settings->add(new \mod_videolesson\local\quota_setting('mod_videolesson/storagequotamib',
        get_string('cfstoragequota', 'mod_videolesson'), get_string('cfstoragequotadesc', 'mod_videolesson'), 0, PARAM_RAW));
    $settings->add(new admin_setting_configselect('mod_videolesson/recoverydays',
        get_string('cfrecoverydays', 'mod_videolesson'), get_string('cfrecoverydesc', 'mod_videolesson'),
        7, array_combine([0, 1, 3, 7, 14, 30], [0, 1, 3, 7, 14, 30])));
    $settings->add(new \mod_videolesson\local\upload_limit_setting('mod_videolesson/maxuploadmib',
        get_string('cfmaxupload', 'mod_videolesson'), get_string('cfmaxuploaddescription', 'mod_videolesson'),
        '512', PARAM_RAW));
    $settings->add(new admin_setting_heading('mod_videolesson/cfprovider',
        get_string('pluginname', 'mod_videolesson'), get_string('cfproviderdescription', 'mod_videolesson')));
    $settings->add(new admin_setting_configselect('mod_videolesson/encodingprofile',
        get_string('cfencodingprofile', 'mod_videolesson'), get_string('cfencodingdescription', 'mod_videolesson'),
        'standard', ['economy' => 'Economy (480p)', 'standard' => 'Standard (480p + 720p)',
            'full' => 'Full (480p + 720p + 1080p)']));
    $settings->add(new admin_setting_heading('mod_videolesson/neverupscale',
        get_string('cfneverupscale', 'mod_videolesson'), get_string('cfneverupscaledescription', 'mod_videolesson')));
    $settings->add(new admin_setting_configselect('mod_videolesson/hlssegmentseconds',
        get_string('cfhlssegment', 'mod_videolesson'), get_string('cfhlssegmentdescription', 'mod_videolesson'),
        4, [2 => '2', 4 => '4', 6 => '6']));
    $settings->add(new admin_setting_configselect('mod_videolesson/rawretentiondays',
        get_string('cfrawretention', 'mod_videolesson'), get_string('cfrawretentiondescription', 'mod_videolesson'), 1, [1 => '1']));
}
