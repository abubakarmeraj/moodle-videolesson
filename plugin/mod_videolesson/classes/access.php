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
 * Class access
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;
/**
 * This class handles the access control logic for the Video Lesson plugin in Moodle.
 * It checks the status of the license and configuration, determining whether users
 * can access various plugin features, and provides messages for missing or invalid
 * configurations or licenses.
 */
class access {
    /** @var \stdClass Plugin configuration object from get_config('mod_videolesson'). */
    private $config;

    /** @var \mod_videolesson\license License helper for key and hosting checks. */
    private $license;

    /** @var bool True if a license key is stored. */
    public $haslicense;

    /** @var bool True if stored license details indicate a non-expired license. */
    public $hasvalidlicense;

    /** @var bool True if required AWS-related settings are present (self-managed). */
    public $hasconfig;

    /** @var bool True if hosting type is Mooplugins hosted. */
    public $ishosted;

    /**
     * Constructor.
     * Initializes configuration and license checks.
     */
    public function __construct() {
        $this->config = get_config('mod_videolesson');
        $this->hasconfig = $this->is_config_set();
        $this->license = new \mod_videolesson\license();
        $this->haslicense = $this->license->has_license();
        $this->hasvalidlicense = $this->license->is_valid();
        $this->ishosted = $this->license->is_hosted();
    }

    /**
     * Checks if the AWS configuration is set.
     *
     * @return bool True if the required configuration settings are present, false otherwise.
     */
    public function is_config_set(): bool {

        $isset = true;
        $config = $this->config;
        if (
            empty($config->api_key) ||
            empty($config->api_secret) ||
            empty($config->s3_input_bucket) ||
            empty($config->s3_output_bucket) ||
            empty($config->api_region) ||
            empty($config->sns_topic_arn) ||
            empty($config->cloudfrontdomain)
        ) {
            $isset = false;
        }

        return $isset;
    }

    /**
     * Checks if the plugin is hosted.
     *
     * @return bool True if the plugin is in hosted mode, false otherwise.
     */
    public function is_hosted(): bool {
        return $this->ishosted;
    }

    /**
     * Checks if Video Library and uploads should be restricted.
     * External hosting type cannot use Video Library or uploads.
     *
     * @return bool True if Video Library/upload should be restricted, false otherwise.
     */
    public function restrict_library(): bool {
        $hostingtype = get_config('mod_videolesson', 'hosting_type');
        return ($hostingtype === 'none');
    }

    /**
     * Restricts access based on license and configuration status.
     *
     * @return bool True if access should be restricted, false otherwise.
     */
    public function restrict(): bool {
        $hostingtype = get_config('mod_videolesson', 'hosting_type');

        // External hosting type (none) doesn't need AWS config or license.
        if ($hostingtype === 'none') {
            return false; // No restrictions for external mode.
        }

        // If hosted, it only requires valid license.
        if ($this->is_hosted()) {
            return !$this->hasvalidlicense;
        }

        // Self-managed requires AWS config.
        return !$this->hasconfig;
    }

    /**
     * Restricts settings access based on license status.
     *
     * @return bool True if access to settings should be restricted, false otherwise.
     */
    public function restrict_settings(): bool {

        // If hosted, it only requires valid license.
        if ($this->is_hosted()) {
            return !$this->hasvalidlicense;
        }

        return !$this->hasconfig;
    }

    /**
     * Restricts settings access based on license status.
     *
     * @return bool True if access to settings should be restricted, false otherwise.
     */
    public function restrict_modform_elements(): bool {
        // If hosted, it only requires valid license.
        if ($this->is_hosted()) {
            return !$this->hasvalidlicense;
        }

        return !$this->hasconfig;
    }

    /**
     * Returns the appropriate message for missing or invalid licenses/configurations.
     *
     * @return string|null The message string or null if no issues are found.
     */
    public function get_message() {

        // Handle cases for non-hosted setup.
        if (!$this->is_hosted()) {
            if (!$this->hasconfig) {
                return $this->get_missing_config_message();
            }
        }

        // Handle cases for hosted setup.
        if ($this->is_hosted()) {
            if ($this->license->has_expired_license()) {
                return $this->get_expired_license_message();
            }

            if (!$this->license->has_valid_license()) {
                return $this->get_missing_license_message();
            }
        } else {
            return $this->get_missing_config_message();
        }

        return null; // Return null if no conditions are met.
    }

    /**
     * Returns a message indicating the configuration is missing.
     *
     * @return string The missing configuration message.
     */
    public function get_missing_config_message() {
        global $OUTPUT;
        $url = new \moodle_url(
            '/admin/settings.php?section=modsettingvideolesson',
            ['section' => 'modsettingvideolesson']
        );

        return $OUTPUT->notification(
            get_string('config:missing', 'mod_videolesson', $url->out()),
            \core\output\notification::NOTIFY_ERROR,
            false
        );
    }

    /**
     * Returns a message indicating the license is missing.
     *
     * @return string The missing license message.
     */
    public function get_missing_license_message() {
        global $OUTPUT;
        $url = new \moodle_url(
            '/mod/videolesson/provision.php',
        );

        return $OUTPUT->notification(
            get_string('access:nolicense', 'mod_videolesson', $url->out()),
            \core\output\notification::NOTIFY_ERROR,
            false
        );
    }

    /**
     * Returns a message indicating the license has expired.
     *
     * @return string The expired license message.
     */
    public function get_expired_license_message() {
        global $OUTPUT;
        $url = new \moodle_url(
            '/mod/videolesson/provision.php',
        );

        return $OUTPUT->notification(
            get_string('access:expiredlicense', 'mod_videolesson', $url->out()),
            \core\output\notification::NOTIFY_ERROR,
            false
        );
    }

    /**
     * Returns a message restricting access to an activity based on license issues.
     *
     * @return string The access restriction message for activities.
     */
    public function get_restrict_activity_message() {
        global $OUTPUT;
        $icon = $OUTPUT->pix_icon('i/error', 'Error', 'mod_videolesson', []);
        $message = $icon . ' ' . get_string('access:nolicense:activity', 'mod_videolesson');
        return $OUTPUT->notification(
            $message,
            \core\output\notification::NOTIFY_ERROR,
            false
        );
        return $message;
    }

    /**
     * Returns a message indicating the Video Library is restricted for external hosting.
     *
     * @return string The library restriction message.
     */
    public function get_library_restriction_message() {
        global $OUTPUT, $CFG;
        $settingsurl = $CFG->wwwroot . '/admin/settings.php?section=modsettingvideolesson';
        return $OUTPUT->notification(
            get_string('error:library:external:not_available', 'mod_videolesson', $settingsurl),
            \core\output\notification::NOTIFY_ERROR,
            false
        );
    }

    /**
     * Returns an array containing the heading and information string for settings access issues.
     *
     * @return array An associative array containing the 'heading' and 'information' strings.
     */
    public function get_no_access_settings_strings() {
        global $CFG;

        $settingsurl = $CFG->wwwroot . '/admin/settings.php?section=modsettingvideolesson';

        if ($this->license->has_expired_license()) {
            $heading = get_string('settings:aws:header:expiredlicense', 'mod_videolesson');
            $description = get_string('settings:aws:header:expiredlicense_desc', 'mod_videolesson', $settingsurl);
        } else {
            $heading = get_string('settings:aws:header:nolicense', 'mod_videolesson');
            $description = get_string('settings:aws:header:nolicense_desc', 'mod_videolesson', $settingsurl);
        }

        return [
            'heading' => $heading,
            'information' => $description,
        ];
    }
}
