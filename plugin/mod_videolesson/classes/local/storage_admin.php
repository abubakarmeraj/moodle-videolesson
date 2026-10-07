<?php
// GPL v3 or later. Site administration only; service owns bytes, Moodle owns reference authority.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
final class storage_admin {
    public static function policy(): array {
        $value = get_config('mod_videolesson', 'storagequotamib');
        if ($value === false) { $value = '0'; }
        if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string)$value) || (int)$value > 104857600) {
            throw new \moodle_exception('cfstoragepolicyinvalid', 'mod_videolesson');
        }
        $days = get_config('mod_videolesson', 'recoverydays');
        $days = $days === false ? 7 : (int)$days;
        if (!in_array($days, [0, 1, 3, 7, 14, 30], true)) {
            throw new \moodle_exception('cfstoragepolicyinvalid', 'mod_videolesson');
        }
        return ['quota_bytes' => (int)$value * 1048576, 'recovery_days' => $days];
    }
    public static function provider(): real_provider {
        $provider = providers::get();
        if (!$provider instanceof real_provider) { throw new \moodle_exception('cfproviderunavailable', 'mod_videolesson'); }
        return $provider;
    }
    public static function require_admin(): void {
        require_login();
        require_capability('moodle/site:config', \context_system::instance());
    }
    public static function intent(int $id): \stdClass {
        global $DB;
        self::require_admin();
        return $DB->get_record('videolesson_intent', ['id' => $id, 'tenantid' => providers::tenant(),
            'state' => 'ready'], '*', MUST_EXIST);
    }
    public static function action(int $id, string $action): array {
        $intent = self::intent($id);
        if (!in_array($action, ['recycle_restore', 'recycle_delete'], true)) {
            throw new \invalid_parameter_exception('Invalid action');
        }
        // Requery actual committed Moodle references NOW, not a stale browser count.
        $generation = references::sync($intent->videouuid);
        return self::provider()->storage_request($action, ['uuid' => $intent->videouuid, 'generation' => $generation]);
    }
}
