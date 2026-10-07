<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

final class providers {
    public static function get(): provider {
        global $CFG;
        if (service_setup::configuration()) { return new real_provider(); }
        // Test implementation is outside deployable plugin source. Production has no fake fallback.
        if (empty($CFG->videolesson_test_provider_file) || empty($CFG->videolesson_test_mode)) {
            throw new \moodle_exception('cfproviderunavailable', 'mod_videolesson');
        }
        require_once($CFG->videolesson_test_provider_file);
        return new \codefortex_test_provider();
    }

    public static function tenant(): string {
        global $CFG;
        $tenant = service_setup::configuration()['tenant'] ?? $CFG->videolesson_tenant ?? '';
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $tenant)) {
            throw new \moodle_exception('cfproviderunavailable', 'mod_videolesson');
        }
        return $tenant;
    }

    public static function media(\stdClass $instance): array {
        if (empty($instance->trustedref) || empty($instance->videouuid) || $instance->tenantid !== self::tenant()) {
            throw new \moodle_exception('cfrelink', 'mod_videolesson');
        }
        $media = self::get()->get_video($instance->tenantid, $instance->videouuid);
        if ($media['state'] !== 'ready' || $media['revision'] !== (int)$instance->mediarevision ||
                !is_numeric($media['duration']) || $media['duration'] <= 0 || $media['duration'] > service_setup::MAX_DURATION) {
            throw new \moodle_exception('cfnotready', 'mod_videolesson');
        }
        return $media;
    }
}
