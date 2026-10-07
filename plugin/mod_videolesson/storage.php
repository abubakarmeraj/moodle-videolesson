<?php
// GPL v3 or later. Site-admin storage/recycle view; no R2 calls or prefixes in browser.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
use mod_videolesson\local\storage_admin;
use mod_videolesson\local\providers;
storage_admin::require_admin();
admin_externalpage_setup('videolessonstorage');
$PAGE->set_url(new moodle_url('/mod/videolesson/storage.php'));
$PAGE->set_title(get_string('cfstorage', 'mod_videolesson'));
$action = optional_param('action', '', PARAM_ALPHAEXT);
$id = optional_param('intent', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$after = optional_param('after', '', PARAM_ALPHANUMEXT);
$provider = storage_admin::provider();
$provider->storage_request('storage_policy', storage_admin::policy());
$notice = '';
if ($action && data_submitted()) {
    require_sesskey();
    storage_admin::intent($id);
    if (!in_array($action, ['recycle_restore', 'recycle_delete'], true)) { throw new invalid_parameter_exception('Invalid action'); }
    if ($confirm) {
        try {
            storage_admin::action($id, $action);
            $notice = get_string($action === 'recycle_restore' ? 'cfstoragerestored' : 'cfstoragepending', 'mod_videolesson');
        } catch (moodle_exception $e) { $notice = get_string('cfstoragedenied', 'mod_videolesson'); }
    }
}
$state = $provider->storage_request('storage_status', ['after' => $after]);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cfstorage', 'mod_videolesson'));
if ($notice) { echo $OUTPUT->notification($notice, 'info'); }
if ($action && data_submitted() && !$confirm) {
    $intent = storage_admin::intent($id);
    $bytes = 0;
    foreach ($state['videos'] as $video) { if ($video['uuid'] === $intent->videouuid) { $bytes = $video['bytes']; } }
    $url = new moodle_url('/mod/videolesson/storage.php', ['intent' => $id, 'action' => $action,
        'confirm' => 1, 'sesskey' => sesskey(), 'after' => $after]);
    echo $OUTPUT->confirm(get_string($action === 'recycle_delete' ? 'cfstorageconfirm' : 'cfrestoreconfirm',
        'mod_videolesson', display_size($bytes)), new single_button($url,
            get_string($action === 'recycle_delete' ? 'cfpermanentdelete' : 'cfrestorekeep', 'mod_videolesson'), 'post'),
        new moodle_url('/mod/videolesson/storage.php'));
    echo $OUTPUT->footer();
    exit;
}
$quota = (int)$state['quota_bytes'];
if ($state['unchecked_videos']) {
    echo $OUTPUT->notification(get_string('cfstorageunchecked', 'mod_videolesson'), 'warning');
}
if ($quota && $state['total_physical_bytes'] + $state['reserved_upload_bytes'] >= $quota * .9) {
    echo $OUTPUT->notification(get_string('cfstoragenearfull', 'mod_videolesson'), 'warning');
}
$metrics = new html_table();
$metrics->head = [get_string('cfstoragemetric', 'mod_videolesson'), get_string('cfstoragesize', 'mod_videolesson')];
$metrics->data[] = [get_string('cfstorageplan', 'mod_videolesson'), $quota ? display_size($quota) : get_string('unlimited')];
foreach (['active_processed_bytes', 'recycle_processed_bytes', 'raw_bytes', 'reserved_upload_bytes',
        'total_physical_bytes', 'available_bytes', 'reclaimable_bytes'] as $field) {
    $metrics->data[] = [get_string('cfstorage_' . $field, 'mod_videolesson'),
        $state[$field] === null ? get_string('unlimited') : display_size((int)$state[$field])];
}
echo html_writer::table($metrics);
echo html_writer::tag('p', get_string('cfstorageaccountingnote', 'mod_videolesson'));
echo $OUTPUT->heading(get_string('cfrecyclebin', 'mod_videolesson'), 3);
$table = new html_table();
$table->head = array_map(fn($key) => get_string($key, 'mod_videolesson'),
    ['cfvideotitle', 'cfstoragesize', 'cfdeleteddate', 'cfcleanupdate', 'cfreferences', 'cfstate', 'cfstorageactions']);
foreach ($state['videos'] as $video) {
    $intent = $DB->get_record('videolesson_intent', ['tenantid' => providers::tenant(),
        'videouuid' => $video['uuid'], 'state' => 'ready'], '*', IGNORE_MULTIPLE);
    $actions = '';
    if ($intent && !$video['references'] && !$video['hold_flag'] && in_array($video['state'], ['recycle', 'ready'], true)) {
        foreach (['recycle_restore' => 'cfrestorekeep', 'recycle_delete' => 'cfpermanentdelete'] as $op => $label) {
            if ($op === 'recycle_restore' && $video['state'] !== 'recycle') { continue; }
            $url = new moodle_url('/mod/videolesson/storage.php', ['intent' => $intent->id, 'action' => $op,
                'sesskey' => sesskey(), 'after' => $after]);
            $actions .= $OUTPUT->single_button($url, get_string($label, 'mod_videolesson'), 'post');
        }
    }
    $table->data[] = [s($video['title'] ?: get_string('cfkeptvideo', 'mod_videolesson', $intent->id ?? '')),
        display_size((int)$video['bytes']), $video['recycle_at'] ? userdate($video['recycle_at']) : '-',
        $video['delete_after'] ? userdate($video['delete_after']) : '-', (int)$video['references'],
        get_string('cfstorage_state_' . $video['state'], 'mod_videolesson'), $actions];
}
echo html_writer::div(html_writer::table($table), 'table-responsive', ['role' => 'region',
    'aria-label' => get_string('cfrecyclebin', 'mod_videolesson'), 'tabindex' => 0]);
if ($state['next']) { echo html_writer::link(new moodle_url('/mod/videolesson/storage.php', ['after' => $state['next']]), get_string('next')); }
echo $OUTPUT->footer();
