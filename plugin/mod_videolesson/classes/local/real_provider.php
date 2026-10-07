<?php
// CodeFortex additions, GPL v3 or later. No R2 credentials or bucket provisioning in Moodle.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

final class real_provider implements provider, service_lifecycle, gateway_provider, upload_provider {
    private array $config;

    public function __construct() {
        global $CFG;
        $this->config = service_setup::configuration();
        foreach (['endpoint', 'gateway', 'keyid', 'key', 'subjectkey', 'tenant'] as $name) {
            if (empty($this->config[$name]) || !is_string($this->config[$name])) {
                throw new \moodle_exception('cfproviderunavailable', 'mod_videolesson');
            }
        }
        foreach (['endpoint', 'gateway'] as $field) {
            $url = parse_url($this->config[$field]);
            if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) ||
                    isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
                    !empty($url['path'])) {
                throw new \moodle_exception('cfproviderunavailable', 'mod_videolesson');
            }
        }
        if (strlen($this->config['key']) < 32 || strlen($this->config['subjectkey']) < 32 ||
                !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $this->config['keyid'])) {
            throw new \moodle_exception('cfproviderunavailable', 'mod_videolesson');
        }
    }

    private function subject(string $user): string {
        // Identity pseudonymization key is independent of rotatable request-authentication keys.
        return hash_hmac('sha256', $this->config['tenant'] . ':' . $user, $this->config['subjectkey']);
    }

    private function request(string $op, string $tenant, array $args = [], bool $diagnostic = false): array {
        if ($tenant !== $this->config['tenant']) {
            throw new \moodle_exception('cfrelink', 'mod_videolesson');
        }
        $body = json_encode(['tenant' => $tenant, 'op' => $op] + $args, JSON_THROW_ON_ERROR);
        $time = (string)time(); $nonce = bin2hex(random_bytes(32));
        $canonical = implode("\n", ['POST', '/v1/rpc', $this->config['keyid'], $time, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $this->config['key']);
        $curl = curl_init($this->config['endpoint'] . '/v1/rpc');
        $response = '';
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CF-Key: ' . $this->config['keyid'],
                'X-CF-Time: ' . $time, 'X-CF-Nonce: ' . $nonce, 'X-CF-Signature: ' . $signature],
            CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 1048576) { return 0; }
                $response .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $data = json_decode($response, true);
        if ($diagnostic && ($status === 401 || $status === 403)) {
            return ['connection_status' => 'AUTHENTICATION_FAILED'];
        }
        if ($diagnostic && $status === 400 && ($data['error'] ?? '') === 'unknown_operation') {
            return ['connection_status' => 'SERVICE_TOO_OLD'];
        }
        if ($diagnostic && ($ok === false || $status !== 200 || !is_array($data) ||
                empty($data['ok']) || !is_array($data['data'] ?? null))) {
            return ['connection_status' => 'SERVICE_UNREACHABLE'];
        }
        if ($status === 409 && ($data['error'] ?? '') === 'upload_capacity') {
            throw new \moodle_exception('cfuploadcapacity', 'mod_videolesson');
        }
        if ($status === 409 && in_array($data['error'] ?? '', ['storage_full', 'storage_reconciliation_required'], true)) {
            throw new \moodle_exception('cfstoragefull', 'mod_videolesson');
        }
        if ($ok === false || $status !== 200 || !is_array($data) || empty($data['ok']) || !is_array($data['data'] ?? null)) {
            // Never surface curl errors, response bodies, signed URLs, headers or request credentials.
            throw new \moodle_exception('cfrequestfailed', 'mod_videolesson');
        }
        return $data['data'];
    }

    public function create_video(string $tenant, string $owner, string $key, string $kind): array {
        return $this->request('create_video', $tenant, ['owner' => $this->subject($owner), 'key' => $key, 'kind' => $kind]);
    }

    /** Site administrator setup only; transport errors never expose response bodies or keys. */
    public function test_connection(): array {
        $data = $this->request('capabilities', $this->config['tenant'], [], true);
        $data['connection_status'] = $data['connection_status'] ?? service_setup::compatibility($data);
        $data['service_reachable'] = $data['connection_status'] !== 'SERVICE_UNREACHABLE';
        $data['authenticated'] = !in_array($data['connection_status'], ['SERVICE_UNREACHABLE', 'AUTHENTICATION_FAILED'], true);
        return $data;
    }
    public function create_upload_session(string $tenant, string $uuid, string $owner, string $key): array {
        throw new \moodle_exception('cfselectsource', 'mod_videolesson');
    }
    public function get_video(string $tenant, string $uuid): array {
        return $this->request('get_video', $tenant, ['uuid' => $uuid]);
    }
    public function retry_processing(string $tenant, string $uuid, string $owner, string $key): array {
        return $this->request('retry_processing', $tenant, ['uuid' => $uuid, 'owner' => $this->subject($owner), 'key' => $key]);
    }
    public function archive(string $tenant, string $uuid, string $owner): void {
        $this->request('archive', $tenant, ['uuid' => $uuid, 'owner' => $this->subject($owner)]);
    }
    public function request_playback_grant(string $tenant, string $uuid, int $revision, int $userid): array {
        // Player grants must use the current Moodle watch-session relationship.
        throw new \moodle_exception('cfrequestfailed', 'mod_videolesson');
    }
    public function scoped_grant(string $tenant, string $uuid, int $revision, int $userid, string $session): array {
        return $this->request('grant', $tenant, ['uuid' => $uuid, 'revision' => $revision,
            'subject' => $this->subject((string)$userid), 'session' => hash('sha256', $session)]);
    }
    public function grant_url(array $grant): string {
        if (!in_array($grant['file'] ?? '', ['master.m3u8', 'video.mp4'], true) ||
                !is_string($grant['token'] ?? null) || strlen($grant['token']) > 2048) {
            throw new \moodle_exception('cfrequestfailed', 'mod_videolesson');
        }
        return (new \moodle_url($this->config['gateway'] . '/media',
            ['grant' => $grant['token'], 'file' => $grant['file']]))->out(false);
    }
    public function sync_references(string $tenant, string $uuid, array $references, int $generation): void {
        global $DB;
        $title = $DB->get_field('videolesson', 'name', ['tenantid' => $tenant, 'videouuid' => $uuid], IGNORE_MULTIPLE) ?: '';
        $this->request('sync_references', $tenant, ['uuid' => $uuid, 'references' => $references, 'generation' => $generation,
            'title' => \core_text::substr(strip_tags($title), 0, 200), 'recovery_days' => storage_admin::policy()['recovery_days']]);
    }
    public function storage_request(string $op, array $args = []): array {
        if (!in_array($op, ['storage_policy', 'storage_status', 'recycle_restore', 'recycle_delete'], true)) {
            throw new \invalid_parameter_exception('Invalid storage operation');
        }
        return $this->request($op, providers::tenant(), $args);
    }
    public function export_owner(string $tenant, string $owner): array {
        return $this->request('export_owner', $tenant, ['owner' => $this->subject($owner)]);
    }
    public function request_deletion(string $tenant, string $uuid, string $owner, string $key): array {
        return $this->request('request_deletion', $tenant, ['uuid' => $uuid, 'owner' => $this->subject($owner), 'key' => $key]);
    }
    public function erase_owner(string $tenant, string $owner, string $key): array {
        return $this->request('erase_owner', $tenant, ['owner' => $this->subject($owner), 'key' => $key]);
    }
    public function upload(string $action, \stdClass $instance, int $userid, array $input): array {
        $args = ['uuid' => $instance->videouuid, 'owner' => $this->subject((string)$userid)];
        if ($action === 'video') { return $this->request('video_status', $instance->tenantid, $args); }
        if (in_array($action, ['start', 'preflight'], true)) {
            $this->storage_request('storage_policy', storage_admin::policy());
            references::sync($instance->videouuid);
            // The service applies the current maximum to new sessions, retaining an existing session's snapshot.
            if (!is_int($input['size'] ?? null) || $input['size'] < 1 || $input['size'] > 1099511627776 ||
                    ($action === 'preflight' && $input['size'] > upload_limit::bytes()) ||
                    (!is_int($input['duration'] ?? null) && !is_float($input['duration'] ?? null)) ||
                    !is_finite((float)$input['duration']) || $input['duration'] <= 0 || $input['duration'] > 86400 ||
                    ($action === 'start' && (!preg_match('/^[a-f0-9]{64}$/D', $input['sha256'] ?? '') ||
                    !preg_match('/^[a-f0-9]{64}$/D', $input['key'] ?? '')))) {
                throw new \invalid_parameter_exception('Invalid upload');
            }
            $profile = get_config('mod_videolesson', 'encodingprofile') ?: 'standard';
            $days = 1;
            $segments = (int)(get_config('mod_videolesson', 'hlssegmentseconds') ?: 4);
            if (!in_array($segments, [2, 4, 6], true)) { $segments = 4; }
            $args += ['size' => $input['size'], 'duration' => $input['duration'], 'maxbytes' => upload_limit::bytes(),
                'sha256' => $input['sha256'] ?? '', 'key' => $input['key'] ?? str_repeat('0', 64),
                'policy' => ['profile' => $profile, 'never_upscale' => true, 'raw_retention_days' => $days,
                    'hls_segment_seconds' => $segments]];
            return $this->request($action === 'start' ? 'start_upload' : 'check_upload', $instance->tenantid, $args);
        }
        $map = ['status' => 'upload_status', 'part' => 'sign_part', 'complete' => 'complete_upload', 'abort' => 'abort_upload'];
        if (!isset($map[$action]) || !preg_match('/^[a-f0-9-]{36}$/D', $input['upload'] ?? '')) {
            throw new \invalid_parameter_exception('Invalid upload operation');
        }
        $args['upload'] = $input['upload'];
        if ($action === 'part') {
            if (!is_int($input['part'] ?? null) || $input['part'] < 1 || $input['part'] > 10000) {
                throw new \invalid_parameter_exception('Invalid part');
            }
            $args['part'] = $input['part'];
        }
        return $this->request($map[$action], $instance->tenantid, $args);
    }
}
