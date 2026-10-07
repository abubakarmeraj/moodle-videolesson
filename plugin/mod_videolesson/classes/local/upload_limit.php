<?php
// GPL v3 or later. Bounded site-admin policy; never accepted from the browser.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
final class upload_limit {
    public static function parse(string $value): int {
        if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,6}))?$/D', $value, $m)) {
            throw new \invalid_parameter_exception('Invalid upload limit');
        }
        // Exact fixed-point decimal, rounded down to whole bytes; never float money/size arithmetic.
        $bytes = (int)$m[1] * 1048576 + intdiv((int)str_pad($m[2] ?? '', 6, '0') * 1048576, 1000000);
        if ($bytes < 1048576 || $bytes > 1099511627776) {
            throw new \invalid_parameter_exception('Upload limit must be between1MiB and1TiB');
        }
        return $bytes;
    }
    public static function bytes(): int {
        $setting = get_config('mod_videolesson', 'maxuploadmib');
        return self::parse($setting === false ? '512' : (string)$setting);
    }
}
