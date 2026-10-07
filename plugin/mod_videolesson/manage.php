<?php
// CodeFortex additions, GPL v3 or later.
require(__DIR__ . '/../../config.php');
$cmid = required_param('id', PARAM_INT);
[$cm, $course, $context, $instance] = \mod_videolesson\local\access::activity($cmid, true);
$provider = \mod_videolesson\local\providers::get();
$replacement = \mod_videolesson\local\replacement::current($instance->id);
if ((empty($instance->videouuid) && $DB->record_exists('videolesson_intent', ['instanceid' => $instance->id, 'purpose' => 'create'])) ||
        ($replacement && in_array($replacement->state, ['pending', 'creating'], true))) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_sesskey();
        $intentid = $replacement ? $replacement->id : $DB->get_field('videolesson_intent', 'id',
            ['instanceid' => $instance->id, 'purpose' => 'create'], MUST_EXIST);
        try {
            \mod_videolesson\local\provisioning::reconcile((int)$intentid);
        } catch (Throwable $error) {
            $provisionfailed = true; // Do not expose provider/SQL bodies; the durable intent remains retryable.
        }
        if (empty($provisionfailed)) {
            redirect(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid]));
        }
    }
    $PAGE->set_url('/mod/videolesson/manage.php', ['id' => $cmid]);
    $PAGE->set_context($context); $PAGE->set_cm($cm, $course);
    $PAGE->set_title(get_string('cfmanage', 'mod_videolesson')); $PAGE->set_heading(format_string($course->fullname));
    if (empty($provisionfailed)) {
        $PAGE->requires->js_call_amd('mod_videolesson/upload', 'prepare', [['cmid' => $cmid]]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('cfprovisioning', 'mod_videolesson'));
    if (!empty($provisionfailed)) {
        echo $OUTPUT->notification(get_string('cfprovisionretryerror', 'mod_videolesson'), 'warning');
    }
    echo html_writer::div('', '', ['id' => 'cf-prepare-message', 'role' => 'status']);
    echo html_writer::start_div('', ['id' => 'cf-prepare-retry', 'hidden' => empty($provisionfailed)]);
    echo $OUTPUT->single_button(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid]),
        get_string('cfretryprovisioning', 'mod_videolesson'), 'post');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}
if (empty($instance->trustedref) || $instance->tenantid !== \mod_videolesson\local\providers::tenant()) {
    redirect(new moodle_url('/course/modedit.php', ['update' => $cmid]), get_string('cfrelink', 'mod_videolesson'));
}
try {
    $video = $provider->get_video($instance->tenantid, $instance->videouuid);
} catch (moodle_exception $e) {
    // Moodle's edit form contains the validated same-course selector; never ask for a raw UUID.
    redirect(new moodle_url('/course/modedit.php', ['update' => $cmid]),
        get_string('cfrelink', 'mod_videolesson'));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $operation = required_param('operation', PARAM_ALPHA);
    if ($operation === 'replace') {
        if ($video['state'] !== 'ready') { throw new invalid_parameter_exception('Current video is not ready'); }
        \mod_videolesson\local\replacement::begin($instance, required_param('expected', PARAM_ALPHANUM));
        redirect(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid]));
    }
    if ($replacement) { $instance->videouuid = $replacement->videouuid;
        $video = $provider->get_video($instance->tenantid, $instance->videouuid); }
    // Course edit authority is necessary; service still enforces original uploader ownership.
    if ($operation === 'upload') {
        $provider->create_upload_session($instance->tenantid, $instance->videouuid, (string)$USER->id,
            'upload-' . $instance->videouuid);
    } else if ($operation === 'retry') {
        $provider->retry_processing($instance->tenantid, $instance->videouuid, (string)$USER->id,
            'retry-attempt-' . $video['attempt']);
    } else if (in_array($operation, ['next', 'fail'], true) && !empty($CFG->videolesson_test_mode) &&
            $provider instanceof codefortex_test_provider) {
        $provider->advance($instance->tenantid, $instance->videouuid, (string)$USER->id, $operation);
    } else {
        throw new invalid_parameter_exception('Invalid operation');
    }
    redirect(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid]));
}
$PAGE->set_url('/mod/videolesson/manage.php', ['id' => $cmid]);
$PAGE->set_context($context); $PAGE->set_cm($cm, $course);
$attached = $video['state'] === 'ready';
$title = get_string($attached ? 'cfreuploadtitle' : 'cfmanage', 'mod_videolesson');
$PAGE->set_title($title); $PAGE->set_heading(format_string($course->fullname));
echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if ($attached) {
    echo html_writer::tag('p', get_string('cfattached', 'mod_videolesson'));
    if (!$replacement) {
        echo html_writer::tag('p', get_string('cfstate', 'mod_videolesson') . ': ' .
            get_string('cfstatusready', 'mod_videolesson'), ['id' => 'cf-status', 'role' => 'status']);
        echo html_writer::tag('progress', '', ['id' => 'cf-upload-progress', 'max' => 100, 'value' => 100,
            'aria-label' => get_string('cfuploadprogress', 'mod_videolesson')]);
        echo html_writer::tag('p', '100%');
        echo $OUTPUT->single_button(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid,
            'operation' => 'replace', 'expected' => hash('sha256', $instance->videouuid . ':' . $instance->mediarevision)]),
            get_string('cfreplace', 'mod_videolesson'), 'post');
        echo $OUTPUT->footer(); exit;
    }
}
if ($replacement) {
    $instance->videouuid = $replacement->videouuid;
    $video = $provider->get_video($instance->tenantid, $instance->videouuid);
}
echo html_writer::tag('p', s(get_string('cfstate', 'mod_videolesson') . ': ' .
    get_string('cfstatus' . (in_array($video['state'], ['draft','uploading','verifying','queued','processing','ready','failed'])
        ? $video['state'] : 'unavailable'), 'mod_videolesson')), ['id' => 'cf-status', 'role' => 'status']);
if ($provider instanceof \mod_videolesson\local\upload_provider) {
    $maxbytes = \mod_videolesson\local\upload_limit::bytes();
    echo html_writer::tag('label', get_string('cfsourcefile', 'mod_videolesson', $maxbytes / 1048576), ['for' => 'cf-upload-file']);
    echo html_writer::empty_tag('input', ['id' => 'cf-upload-file', 'type' => 'file', 'accept' => 'video/*', 'class' => 'form-control']);
    echo html_writer::tag('button', get_string('cfstartresume', 'mod_videolesson'), ['id' => 'cf-upload-start', 'type' => 'button', 'disabled' => true, 'class' => 'btn btn-primary mt-2']);
    echo html_writer::tag('button', get_string('cfpauseupload', 'mod_videolesson'), ['id' => 'cf-upload-pause', 'type' => 'button', 'class' => 'btn btn-secondary mt-2']);
    echo html_writer::tag('button', get_string('cfabortupload', 'mod_videolesson'), ['id' => 'cf-upload-abort', 'type' => 'button', 'class' => 'btn btn-secondary mt-2']);
    echo html_writer::tag('progress', '', ['id' => 'cf-upload-progress', 'max' => 100, 'value' => 0,
        'aria-label' => get_string('cfuploadprogress', 'mod_videolesson')]);
    echo html_writer::tag('p', '', ['id' => 'cf-upload-message', 'role' => 'status', 'aria-live' => 'polite']);
    echo html_writer::start_div('', ['id' => 'cf-processing-retry', 'hidden' => $video['state'] !== 'failed']);
    echo html_writer::tag('p', get_string('cfprocessingfailed', 'mod_videolesson'));
    echo $OUTPUT->single_button(new moodle_url('/mod/videolesson/manage.php', ['id' => $cmid, 'operation' => 'retry']),
        get_string('cfretry', 'mod_videolesson'), 'post');
    echo html_writer::end_div();
    $PAGE->requires->js_call_amd('mod_videolesson/upload', 'init', [['cmid' => $cmid, 'maxbytes' => $maxbytes]]);
    echo html_writer::link(new moodle_url('/mod/videolesson/view.php', ['id' => $cmid]), get_string('continue'));
    echo $OUTPUT->footer();
    exit;
}
echo html_writer::tag('p', s(get_string('cftestnotice', 'mod_videolesson')));
if ($video['state'] === 'draft') {
    $operation = 'upload';
} else if ($video['state'] === 'failed') {
    $operation = 'retry';
} else if ($video['state'] !== 'ready') {
    $operation = 'next';
}
if (isset($operation)) {
    echo $OUTPUT->single_button(new moodle_url('/mod/videolesson/manage.php',
        ['id' => $cmid, 'operation' => $operation]), get_string('cf' . $operation, 'mod_videolesson'), 'post');
}
echo html_writer::link(new moodle_url('/mod/videolesson/view.php', ['id' => $cmid]), get_string('continue'));
echo $OUTPUT->footer();
