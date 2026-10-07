<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Admin license field
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * Admin setting for Mooplugins license.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_moopluginlicense extends \admin_setting_configtext {
    /**
     * Whether the submitted value matches the stored license key (trimmed).
     *
     * @param mixed $data Submitted value from the admin form.
     * @return bool
     */
    private function is_license_key_unchanged($data): bool {
        $current = $this->get_setting();
        $incoming = trim((string) $data);
        if ($current === null || $current === '') {
            return $incoming === '';
        }
        return $incoming === trim((string) $current);
    }

    /**
     * Validates the license data.
     *
     * @param string $data The license data.
     * @return string|true The error message or true if validation is successful.
     */
    public function validate($data) {
        $hostingtype = optional_param('s_mod_videolesson_hosting_type', '', PARAM_ALPHAEXT);
        $usefreehosting = (bool) optional_param('s_mod_videolesson_use_free_hosting', 0, PARAM_BOOL);
        $hasexistinglicense = !empty(get_config('mod_videolesson', 'license_key'));
        $license = new \mod_videolesson\license();

        // Check if the hosting type is set to 'hosted' (via POST data).
        if ($hostingtype === 'hosted') {
            if ($usefreehosting) {
                if ($hasexistinglicense) {
                    return get_string('settings:aws:usefreehosting_blocked', 'mod_videolesson');
                }
                // Free-hosting generation is handled in write_setting().
                // To avoid parent text write overriding generated license_key.
                return true;
            }

            if ($this->is_license_key_unchanged($data)) {
                return true;
            }

            // Attempt to activate the license.
            $result = $license->activate($data);
            $invalidmsg = get_string('license:invalid', 'mod_videolesson');
            // If activation fails or self-managed type needs a message, return it from the license system.
            if ($result['result'] === 'error' || $result['type'] === 'self') {
                return !empty($result['message']) ? $result['message'] : $invalidmsg;
            }
        } else {
            // Deactivate the license if the hosting type is not 'hosted'.
            $license->deactivate();
        }

        return true;
    }

    /**
     * Persist setting value.
     *
     * @param string $data
     * @return string Empty string on success, error string otherwise.
     */
    public function write_setting($data) {
        $hostingtype = optional_param('s_mod_videolesson_hosting_type', '', PARAM_ALPHAEXT);
        $usefreehosting = (bool) optional_param('s_mod_videolesson_use_free_hosting', 0, PARAM_BOOL);
        $hasexistinglicense = !empty(get_config('mod_videolesson', 'license_key'));

        if ($hostingtype === 'hosted' && $usefreehosting) {
            if ($hasexistinglicense) {
                return get_string('settings:aws:usefreehosting_blocked', 'mod_videolesson');
            }

            $license = new \mod_videolesson\license();
            $result = $license->generate_free_license();
            if (($result['result'] ?? 'error') === 'error') {
                $msg = get_string('setup:step2:hosted:activation:error', 'mod_videolesson');
                return !empty($result['message']) ? $result['message'] : $msg;
            }

            // License already persisted by generate_free_license().
            return '';
        }

        if ($hostingtype === 'hosted' && !$usefreehosting && $this->is_license_key_unchanged($data)) {
            return '';
        }

        return parent::write_setting($data);
    }
}
