<?php
// CodeFortex Video Lesson, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** Protected installer handoff and safe setup diagnostics. */
final class service_setup {
    public const PROTOCOL_VERSION = 1;
    public const TESTED_SERVICE = '0.5.0-rc1';
    public const MAX_DURATION = 21600;

    public static function configuration(): array {
        global $CFG;
        if (!empty($CFG->videolesson_service)) {
            return self::validate($CFG->videolesson_service);
        }
        $path = $CFG->videolesson_connection_file ?? get_config('mod_videolesson', 'connectionfile');
        return $path ? self::read_handoff($path) : [];
    }

    public static function read_handoff(string $path): array {
        global $CFG;
        // Only server-owned local JSON; never PHP inclusion, URLs or browser credential output.
        if (str_contains($path, '://') || !is_file($path) || !is_readable($path) ||
                filesize($path) > 16384 || (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0007))) {
            throw new \moodle_exception('cfsetupinvalid', 'mod_videolesson');
        }
        $resolved = realpath($path);
        $webroot = realpath($CFG->dirroot);
        if (!$resolved || ($webroot && str_starts_with($resolved, $webroot . DIRECTORY_SEPARATOR))) {
            throw new \moodle_exception('cfsetupinvalid', 'mod_videolesson');
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \moodle_exception('cfsetupinvalid', 'mod_videolesson');
        }
        return self::validate($data);
    }

    public static function validate(array $data): array {
        $keys = ['endpoint', 'gateway', 'keyid', 'key', 'subjectkey', 'tenant'];
        foreach ($keys as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '' || strlen($data[$key]) > 2048) {
                throw new \moodle_exception('cfsetupinvalid', 'mod_videolesson');
            }
        }
        foreach (['endpoint', 'gateway'] as $key) {
            $url = parse_url($data[$key]);
            if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) ||
                    isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
                    !empty($url['path']) || preg_match('/[\s\\\\]/', $data[$key])) {
                throw new \moodle_exception('cfsetupinvalid', 'mod_videolesson');
            }
        }
        if (strlen($data['key']) < 32 || strlen($data['subjectkey']) < 32 ||
                !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $data['keyid']) ||
                !preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $data['tenant'])) {
            throw new \moodle_exception('cfsetupinvalid', 'mod_videolesson');
        }
        return array_intersect_key($data, array_flip($keys));
    }

    public static function compatibility(array $data): string {
        if (!isset($data['protocol_version']) || !is_int($data['protocol_version']) ||
                !isset($data['service_version']) || !is_string($data['service_version']) ||
                !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?$/D', $data['service_version'])) {
            return 'SERVICE_TOO_OLD';
        }
        if ($data['protocol_version'] < self::PROTOCOL_VERSION ||
                version_compare($data['service_version'], self::TESTED_SERVICE, '<')) {
            return 'SERVICE_TOO_OLD';
        }
        if ($data['protocol_version'] > self::PROTOCOL_VERSION || $data['service_version'] !== self::TESTED_SERVICE) {
            return 'SERVICE_NEWER_UNTESTED';
        }
        if (($data['schema_ready'] ?? false) !== true || ($data['schema_versions'] ?? []) !== [1, 2, 3, 4, 5]) {
            return 'SCHEMA_NOT_READY';
        }
        if (($data['storage_config_valid'] ?? false) !== true || ($data['raw_reachable'] ?? false) !== true ||
                ($data['processed_reachable'] ?? false) !== true) {
            return 'STORAGE_NOT_READY';
        }
        if (($data['redis_ready'] ?? false) !== true || ($data['worker_capacity_ready'] ?? false) !== true) {
            return 'NO_WORKER_CAPACITY';
        }
        if (!isset($data['max_duration_seconds']) || !is_int($data['max_duration_seconds']) ||
                $data['max_duration_seconds'] < 60 || $data['max_duration_seconds'] > self::MAX_DURATION) {
            return 'SERVICE_NEWER_UNTESTED';
        }
        return 'COMPATIBLE';
    }
}
