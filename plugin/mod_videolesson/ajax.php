<?php
// CodeFortex additions, GPL v3 or later.
define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new invalid_parameter_exception('POST required');
    }
    require_sesskey();
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (strlen($raw) > 4096) {
        throw new invalid_parameter_exception('Payload too large');
    }
    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_int($data['cmid'] ?? null) || !is_string($data['action'] ?? null)) {
        throw new invalid_parameter_exception('Invalid request');
    }
    $cmid = $data['cmid'];
    if ($data['action'] === 'session') {
        $result = \mod_videolesson\local\watch::session($cmid);
    } else if ($data['action'] === 'event' && is_array($data['event'] ?? null)) {
        $result = \mod_videolesson\local\watch::event($cmid, $data['event']);
    } else if (in_array($data['action'], ['reconnect', 'renew'], true) && is_string($data['session'] ?? null)) {
        $result = \mod_videolesson\local\watch::reconnect($cmid, $data['session'], $data['action'] === 'renew');
    } else {
        throw new invalid_parameter_exception('Unknown operation');
    }
    echo json_encode(['ok' => true, 'data' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(400);
    // No raw exception/config/SQL disclosure to clients.
    echo json_encode(['ok' => false, 'error' => 'request_rejected']);
}
