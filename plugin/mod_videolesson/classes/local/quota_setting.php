<?php
// GPL v3 or later. Bounded whole MiB setting; zero means unlimited.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
final class quota_setting extends \admin_setting_configtext {
    public function validate($data) {
        if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string)$data) || (int)$data > 104857600) {
            return get_string('cfstoragepolicyinvalid', 'mod_videolesson');
        }
        return true;
    }
}
