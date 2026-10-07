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
 * View activity.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
$cmid = required_param('id', PARAM_INT);
[$cm, $course, $context, $instance] = \mod_videolesson\local\access::activity($cmid);
$PAGE->set_url('/mod/videolesson/view.php', ['id' => $cmid]);
$PAGE->set_context($context); $PAGE->set_cm($cm, $course);
$PAGE->set_title(format_string($instance->name)); $PAGE->set_heading(format_string($course->fullname));
videolesson_view($instance, $course, $cm, $context);
$error = null;
$dimensions = ['width' => 1280, 'height' => 720];
try {
    $media = \mod_videolesson\local\providers::media($instance);
    if (is_int($media['width'] ?? null) && is_int($media['height'] ?? null) &&
            $media['width'] >= 16 && $media['width'] <= 4096 && $media['height'] >= 16 && $media['height'] <= 4096) {
        $dimensions = ['width' => $media['width'], 'height' => $media['height']];
    }
    $provider = \mod_videolesson\local\providers::get();
    $url = '';
    if (!$provider instanceof \mod_videolesson\local\gateway_provider) {
        $grant = $provider->request_playback_grant($instance->tenantid,
            $instance->videouuid, (int)$instance->mediarevision, (int)$USER->id);
        $url = (new moodle_url('/mod/videolesson/media.php', ['id' => $cmid, 'grant' => $grant['token'],
            'file' => $grant['file']]))->out(false);
    }
    $PAGE->requires->css('/mod/videolesson/resources/plyr/plyr.css');
    $PAGE->requires->js('/mod/videolesson/resources/plyr/plyr.polyfilled.min.js', true);
    $PAGE->requires->js('/mod/videolesson/resources/hls.min.js', true);
    $PAGE->requires->js_call_amd('mod_videolesson/codefortex', 'init', [[
        'cmid' => $cmid, 'url' => $url, 'kind' => $media['kind'],
        'restrictseek' => !empty(json_decode($instance->options ?? '{}', true)['seek']),
    ]]);
} catch (moodle_exception $exception) {
    if (has_capability('moodle/course:manageactivities', $context)) {
        redirect(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid]));
    }
    $pending = empty($instance->videouuid) && $DB->record_exists('videolesson_intent', ['instanceid' => $instance->id]);
    $error = get_string($pending ? 'cfprovisioning' : 'cfrelink', 'mod_videolesson');
}
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));
echo format_module_intro('videolesson', $instance, $cmid);
if (!$error) {
    echo $OUTPUT->render_from_template('mod_videolesson/codefortex_player', ['name' => $instance->name] + $dimensions);
} else {
    echo $OUTPUT->notification($error, 'info');
}
if (has_capability('moodle/course:manageactivities', $context)) {
    echo html_writer::link(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid]),
        get_string('cfreupload', 'mod_videolesson'));
}
echo $OUTPUT->footer();
