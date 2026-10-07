<?php
// CodeFortex Video Lesson, GPL v3 or later.
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('videolessonservice');
require_capability('moodle/site:config', context_system::instance());
$PAGE->set_title(get_string('cfservicesetup', 'mod_videolesson'));
$PAGE->set_heading(get_string('cfservicesetup', 'mod_videolesson'));
$PAGE->set_cacheable(false);
$locked = !empty($CFG->videolesson_service) || !empty($CFG->videolesson_connection_file);
$result = null;
$notice = '';
if (data_submitted()) {
    require_sesskey();
    try {
        if (optional_param('action', '', PARAM_ALPHA) === 'save' && !$locked) {
            $path = optional_param('connectionfile', '', PARAM_RAW_TRIMMED);
            $next = \mod_videolesson\local\service_setup::read_handoff($path);
            $previous = \mod_videolesson\local\service_setup::configuration();
            if ($previous && ($previous['tenant'] !== $next['tenant'] || $previous['subjectkey'] !== $next['subjectkey'])) {
                throw new moodle_exception('cfsetupidentity', 'mod_videolesson');
            }
            set_config('connectionfile', $path, 'mod_videolesson');
            $notice = get_string('changessaved');
        }
        $result = (new \mod_videolesson\local\real_provider())->test_connection();
    } catch (Throwable $e) {
        // Deliberately do not print exceptions: handoff paths/response text may be sensitive.
        $notice = get_string('cfsetupinvalid', 'mod_videolesson');
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cfservicesetup', 'mod_videolesson'));
echo html_writer::tag('p', get_string('cfsetupinstructions', 'mod_videolesson'));
try {
    $connection = \mod_videolesson\local\service_setup::configuration();
    if ($connection) {
        echo html_writer::tag('p', get_string('cfsetup_service_url', 'mod_videolesson') . ': ' . s($connection['endpoint']));
    }
} catch (Throwable $e) {
    // An invalid protected file is explained by the safe Test Connection message.
}
if ($notice) {
    echo $OUTPUT->notification($notice, 'info');
}
if ($locked) {
    echo html_writer::tag('p', get_string('cfsetuplocked', 'mod_videolesson'));
} else {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
    echo html_writer::tag('label', get_string('cfsetupfile', 'mod_videolesson'), ['for' => 'connectionfile']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'id' => 'connectionfile', 'name' => 'connectionfile',
        'class' => 'form-control', 'autocomplete' => 'off', 'required' => 'required', 'maxlength' => 4096]);
    echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}
echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['action' => 'test']),
    get_string('cfsetuptest', 'mod_videolesson'), 'post');
if ($result) {
    $table = new html_table();
    $table->head = [get_string('cfsetupcheck', 'mod_videolesson'), get_string('cfsetupresult', 'mod_videolesson')];
    // Allowlisted output only: never dump the provider payload or configuration.
    foreach (['service_reachable', 'authenticated', 'connection_status', 'service_version', 'protocol_version', 'schema_ready', 'storage_backend',
            'raw_reachable', 'processed_reachable', 'redis_ready', 'worker_capacity_ready', 'max_duration_seconds'] as $key) {
        if (isset($result[$key]) && is_scalar($result[$key])) {
            $value = is_bool($result[$key]) ? get_string($result[$key] ? 'yes' : 'no') : (string)$result[$key];
            $table->data[] = [get_string('cfsetup_' . $key, 'mod_videolesson'), s(core_text::substr($value, 0, 100))];
        }
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
