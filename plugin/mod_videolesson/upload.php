<?php
// CodeFortex additions, GPL v3 or later. Small control messages only; media goes browser -> R2.
define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new invalid_parameter_exception('POST required'); }
    require_sesskey();
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (strlen($raw) > 4096) { throw new invalid_parameter_exception('Control message too large'); }
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_int($input['cmid'] ?? null) || !is_string($input['action'] ?? null)) {
        throw new invalid_parameter_exception('Invalid envelope');
    }
    [, , , $instance] = \mod_videolesson\local\access::activity($input['cmid'], true);
    if (empty($instance->trustedref) || $instance->tenantid !== \mod_videolesson\local\providers::tenant()) {
        throw new invalid_parameter_exception('Reference denied');
    }
    $provider = \mod_videolesson\local\providers::get();
    if (!$provider instanceof \mod_videolesson\local\upload_provider) { throw new invalid_parameter_exception('Upload unavailable'); }
    \core\session\manager::write_close();
    if ($input['action'] === 'prepare') {
        $intent = \mod_videolesson\local\replacement::current($instance->id);
        if (!$intent && empty($instance->videouuid)) {
            $intent = $DB->get_record('videolesson_intent', ['instanceid' => $instance->id, 'purpose' => 'create'], '*', MUST_EXIST);
        }
        if ($intent) { \mod_videolesson\local\provisioning::reconcile((int)$intent->id); }
        echo json_encode(['ok' => true, 'data' => ['prepared' => true]]);
        exit;
    }
    $replacement = \mod_videolesson\local\replacement::current($instance->id);
    if ($replacement) {
        if ($replacement->state !== 'ready' || !$replacement->videouuid ||
                $replacement->baseuuid !== $instance->videouuid ||
                (int)$replacement->baserevision !== (int)$instance->mediarevision) {
            throw new invalid_parameter_exception('Replacement not prepared');
        }
        if ($input['action'] === 'video' && \mod_videolesson\local\replacement::publish($replacement->id)) {
            echo json_encode(['ok' => true, 'data' => ['state' => 'ready', 'stage' => 'ready', 'progress' => 100,
                'qualities' => [], 'can_upload' => false, 'published' => true]]);
            exit;
        }
        $instance = clone $instance;
        $instance->videouuid = $replacement->videouuid;
    }
    $result = $provider->upload($input['action'], $instance, (int)$USER->id, $input);
    echo json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400); echo json_encode(['ok' => false,
        'error' => $e instanceof moodle_exception && $e->errorcode === 'cfstoragefull' ? 'storage_full' :
            ($e instanceof moodle_exception && $e->errorcode === 'cfuploadcapacity' ? 'upload_capacity' : 'request_rejected')]);
}
