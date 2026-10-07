<?php
// GPL v3 or later. Administrator decimal MiB input; service receives integer bytes only.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
final class upload_limit_setting extends \admin_setting_configtext {
    public function validate($data) {
        try { upload_limit::parse((string)$data); return true; }
        catch (\invalid_parameter_exception $e) { return get_string('cfuploadlimitinvalid', 'mod_videolesson'); }
    }
}
