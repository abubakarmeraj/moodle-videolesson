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
 * License class to manage the license keys for mod_videolesson.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;
defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/filelib.php");
/**
 * License class to manage the license keys for mod_videolesson.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class license {
    /** @var string $action_check The action to check the license. */
    const ACTION_CHECK = 'slm_check';
    /** @var string $action_activate The action to activate the license. */
    const ACTION_ACTIVATE = 'slm_activate';
    /** @var string $action_deactivate The action to deactivate the license. */
    const ACTION_DEACTIVATE = 'slm_deactivate';
    /** @var string $action_provision The action to provision the license. */
    const ACTION_PROVISION = 'videolesson_provision';
    /** @var string $api_site_url The API site URL. */
    const APISITEURL = 'https://mooplugins.com/';
    /** @var string $secret_key The secret key. */
    const SECRETKEY = '66c6cdcb2ccc2980290939';
    /** @var string $result_error The result error. */
    const RESULT_ERROR = 'error';
    /** @var string $result_success The result success. */
    const RESULT_SUCCESS = 'success';

    /** @var string $key The license key. */
    private $key;
    /** @var string $type The type of license. */
    public $type;

    /**
     * Constructor. Initializes the license key from configuration.
     */
    public function __construct() {
        $this->key = get_config('mod_videolesson', 'license_key');
        $this->type = get_config('mod_videolesson', 'hosting_type');
    }

    /**
     * Activates the current license.
     *
     * @param string $licensekey The license key to activate.
     * @return array
     */
    public function activate(string $licensekey): array {

        $response = $this->callapi(self::ACTION_ACTIVATE, $licensekey);
        if ($response['result'] == self::RESULT_SUCCESS) {
            $this->save($response);
        }

        return $response;
    }

    /**
     * Deactivates the current license by clearing the key and related configurations.
     *
     * @return array
     */
    public function deactivate(): array {

        $response = $this->callapi(self::ACTION_DEACTIVATE, $this->key);

        if ($response['result'] == self::RESULT_SUCCESS) {
            $this->key = '';
            unset_config('license_key', 'mod_videolesson');
            unset_config('license_details', 'mod_videolesson');
            unset_config('hosting_type', 'mod_videolesson');
            unset_config('api_url', 'mod_videolesson');
            unset_config('cloudfrontdomainhosted', 'mod_videolesson');
        }

        return $response;
    }

    /**
     * Checks if a license key exists.
     *
     * @return bool True if a license key exists, false otherwise.
     */
    public function has_license(): bool {
        return !empty($this->key);
    }

    /**
     * Checks if a license key exists.
     *
     * @return bool True if a license key exists, false otherwise.
     */
    public function has_valid_license(): bool {
        return !empty($this->key) && $this->is_valid();
    }

    /**
     * Checks if a license is expired.
     *
     * @return bool True if a license key exists, false otherwise.
     */
    public function has_expired_license(): bool {
        return $this->has_license() && !$this->is_valid();
    }

    /**
     * Validates the license key.
     * This is just local validation, license will be validated on the api during any request
     * @return bool True if the license is valid, false otherwise.
     */
    public function is_valid(): bool {

        $details = get_config('mod_videolesson', 'license_details');
        $details = json_decode($details);
        $currentdate = time();

        if (isset($details->date_expiry) && strtotime($details->date_expiry) > $currentdate) {
            return true;
        }

        return false;
    }

    /**
     * Retrieves the current license type from configuration.
     *
     * @return bool True if the license type is hosted, false otherwise.
     */
    public function is_hosted(): bool {
        return $this->type == 'hosted';
    }

    /**
     * Retrieves the current license key from configuration.
     *
     * @return string|null The license key, or null if not set.
     */
    public function get_key(): ?string {
        return $this->key;
    }

    /**
     * Retrieves the license details.
     *
     * @return array|bool The license details or false if no license exists.
     */
    public function get_license_details() {
        if (!$this->has_license()) {
            return false;
        }

        $details = get_config('mod_videolesson', 'license_details');

        if (empty($details)) {
            return false;
        }

        $details = json_decode($details, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('Failed to decode license details: ' . json_last_error_msg(), DEBUG_DEVELOPER);
            return false;
        }

        $currentdate = time();
        if (strtotime($details['date_expiry']) < $currentdate) {
            $details['expired'] = true;
        } else {
            $details['expired'] = false;
        }

        if ($details['status'] != 'active') {
            $details['notactive'] = true;
        }

        return $details;
    }

    /**
     * Validates the given license key by contacting the license server.
     *
     * @param string $licensekey The license key to validate.
     * @return array The response from the license server.
     */
    public function validate(string $licensekey): array {
        return $this->callapi(self::ACTION_CHECK, $licensekey);
    }

    /**
     * Saves the license key and details to the Moodle configuration.
     * This is used for hosting licenses (Mooplugins hosting).
     *
     * @param array $data The license data to save.
     * @return bool True if saved successfully, false otherwise.
     */
    public function save(array $data): bool {
        if ($data['result'] === 'success') {
            $this->key = $data['license_key'];
            set_config('license_details', json_encode($data), 'mod_videolesson');
            set_config('license_key', $data['license_key'], 'mod_videolesson');
            set_config('hosting_type', $data['type'], 'mod_videolesson');
            set_config('apiurl', $data['apiurl'] ?? '', 'mod_videolesson');
            set_config('cloudfrontdomainhosted', $data['cloudfrontdomain'] ?? '', 'mod_videolesson');
            set_config('bucket_key', $data['bucket_key'] ?? '', 'mod_videolesson');
            return true;
        }

        return false;
    }

    /**
     * Retrieves the license details from the api and saves it.
     * Basically updating the data.
     * @return void
     */
    public function license_check() {
        $licensekey = get_config('mod_videolesson', 'license_key');
        if (!$licensekey) {
            return;
        }

        if ($licensedata = $this->callapi(self::ACTION_CHECK, $licensekey)) {
            $this->save($licensedata);
        }
    }

    /**
     * Calls an external API with specified action and parameters.
     *
     * This function performs an HTTP request to an external API based on the provided action and license key.
     * It handles errors related to cURL and JSON parsing, and returns the API response data.
     *
     * @param string $action The action to be performed by the API.
     * Valid actions are defined in `self::ACTION_CHECK`, `self::ACTION_ACTIVATE`, and `self::ACTION_DEACTIVATE`.
     * @param string $licensekey The license key used for authentication with the API.
     * @param array $params Optional parameters to be included in the API request. Default is an empty array.
     *
     * @return array An associative array containing the API response data or an error message. The array includes:
     *               - 'result': The result of the API call ('success' or 'error').
     *               - 'error_code': An error code in case of an error (e.g., 'invalidaction', 'curlerror', 'jsonerror').
     *               - 'message': A message providing details about the error or the API response.
     */
    public function callapi(string $action, string $licensekey, array $params = []): array {
        global $CFG;
        $actions = [self::ACTION_CHECK, self::ACTION_ACTIVATE, self::ACTION_DEACTIVATE];

        if (!in_array($action, $actions)) {
            debugging('Invalid action -' . $action, DEBUG_DEVELOPER);
            return ['result' => 'error', 'error_code' => 'invalidaction', 'message' => 'Invalid action.'];
        }

        $url = new \moodle_url(self::APISITEURL, [
            'slm_action' => $action,
            'secret_key' => self::SECRETKEY,
            'license_key' => $licensekey,
        ]);

        if ($action == self::ACTION_ACTIVATE || $action == self::ACTION_DEACTIVATE) {
            $url->param('registered_domain', $CFG->wwwroot);
        }

        $result = $this->execute_curl_request($url->out(false), 'GET');

        if (!$result['success']) {
            debugging('cURL error: ' . $result['error'], DEBUG_DEVELOPER);
            return [
                'result' => 'error',
                'error_code' => 'curlerror',
                'message' => 'Error occurred while fetching data. Please try again later.',
            ];
        }

        $responsedata = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('Invalid JSON response: ' . json_last_error_msg(), DEBUG_DEVELOPER);
            return [
                'result' => 'error',
                'error_code' => 'jsonerror',
                'message' => 'Error occurred while processing data. Please try again later.',
            ];
        }

        return $responsedata;
    }

    /**
     * Executes a cURL request with common settings.
     *
     * @param string $url The URL to request
     * @param string $method HTTP method ('GET' or 'POST')
     * @param array $postdata Optional POST data
     * @param array $headers Optional HTTP headers
     * @param int $timeout Connection timeout in seconds (default 30)
     * @return array ['success' => bool, 'response' => string|false, 'error' => string|null]
     */
    private function execute_curl_request(
        string $url,
        string $method = 'GET',
        array $postdata = [],
        array $headers = [],
        int $timeout = 30
    ): array {
        $curl = new \curl();
        $curl->setopt([
            'connecttimeout' => $timeout,
            'timeout' => $timeout,
        ]);

        // SSL verification settings.
        $disablessl = get_config('mod_videolesson', 'disable_ssl_verification');
        if ($disablessl) {
            debugging('SSL verification disabled via config - NOT RECOMMENDED FOR PRODUCTION', DEBUG_DEVELOPER);
            $curl->setopt([
                'ssl_verifypeer' => false,
                'ssl_verifyhost' => 0,
            ]);
        } else {
            $curl->setopt([
                'ssl_verifypeer' => true,
                'ssl_verifyhost' => 2,
            ]);
        }

        if (!empty($headers)) {
            foreach ($headers as $header) {
                $curl->setHeader($header);
            }
        }

        if ($method === 'POST') {
            $response = $curl->post($url, $postdata);
        } else {
            $response = $curl->get($url);
        }

        $success = ($curl->get_errno() === 0);
        $error = $success ? null : ($curl->error ?: 'cURL error');

        return [
            'success' => $success,
            'response' => $response,
            'error' => $error,
        ];
    }

    /**
     * Retrieves the provision license key from the configuration.
     *
     * @return string The provision license key.
     */
    public function get_provision_licensekey() {
        return get_config('mod_videolesson', 'provision_license_key');
    }

    /**
     * Sets the provision license key in the configuration.
     *
     * @param string $licensekey The provision license key to set.
     * @return bool True if set successfully, false otherwise.
     */
    public function set_provision_licensekey($licensekey) {
        return set_config('provision_license_key', $licensekey, 'mod_videolesson');
    }

    /**
     * Provisions a stack for the given license key, key id, secret key, and region.
     *
     * @param string $licensekey The license key to provision.
     * @param string $keyid The key id to provision.
     * @param string $secretkey The secret key to provision.
     * @param string $region The region to provision.
     * @return array The result of the provision.
     */
    public function provision($licensekey, $keyid, $secretkey, $region) {
        $postdata = [
            'licensekey' => $licensekey,
            'keyid' => $keyid,
            'secretkey' => $secretkey,
            'region' => $region,
        ];

        $result = $this->execute_curl_request(
            self::APISITEURL . '/wp-json/videolesson/v1/provision',
            'POST',
            $postdata
        );

        if (!$result['success']) {
            throw new \Exception('cURL Error: ' . $result['error']);
        }

        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to decode JSON response');
        }

        return $data;
    }

    /**
     * Generates a free license key and registers the user to mooplugins.com
     * Gets admin details and plugin version automatically
     *
     * @return array Response array with result, message, and license_key
     */
    public function generate_free_license(): array {
        global $CFG, $USER;

        // Get admin details.
        $adminemail = $USER->email;
        $adminname = fullname($USER);

        $pluginversion = util::get_plugin_version();

        // Step 1: Request token.
        $tokenresult = $this->request_token();
        if ($tokenresult['result'] !== self::RESULT_SUCCESS || empty($tokenresult['token'])) {
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'tokenerror',
                'message' => $tokenresult['message'] ?? get_string('setup:step1:error:token', 'mod_videolesson'),
            ];
        }

        $token = $tokenresult['token'];

        // Step 2: Create license using the token.
        $postdata = [
            'email' => $adminemail,
            'name' => $adminname,
            'plugin' => 'mod_videolesson',
            'version' => $pluginversion,
            'domain' => $CFG->wwwroot,
        ];

        $headers = ['X-Token: ' . $token];

        $result = $this->execute_curl_request(
            self::APISITEURL . '/wp-json/slms/v1/free_hosting',
            'POST',
            $postdata,
            $headers
        );

        if (!$result['success']) {
            debugging('cURL error creating license: ' . $result['error'], DEBUG_DEVELOPER);
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'curlerror',
                'message' => get_string('setup:step1:error:network', 'mod_videolesson'),
            ];
        }

        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('Invalid JSON response: ' . json_last_error_msg(), DEBUG_DEVELOPER);
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'jsonerror',
                'message' => get_string('setup:step1:error:invalidresponse', 'mod_videolesson'),
            ];
        }

        // Check if the API returned success.
        if (isset($data['success']) && $data['success'] === true && !empty($data['license_key'])) {
            $licensekey = $data['license_key'];
            $type = 'hosted'; // Free tier uses 'hosted' type.
            $dateexpiry = $data['date_expiry'] ?? '';

            // Save as hosting license (license_key), not registration license.
            $licensedata = [
                'result' => 'success',
                'license_key' => $licensekey,
                'status' => 'active',
                'type' => $type,
                'date_expiry' => $dateexpiry,
                'apiurl' => $data['apiurl'] ?? '',
                'cloudfrontdomain' => $data['cloudfrontdomain'] ?? '',
                'bucket_key' => $data['bucket_key'] ?? '',
            ];

            // Save as hosting license.
            $this->save($licensedata);

            return [
                'result' => self::RESULT_SUCCESS,
                'license_key' => $licensekey,
                'date_expiry' => $dateexpiry,
                'message' => $data['message'] ?? get_string('setup:step2:hosted:activation:success', 'mod_videolesson'),
            ];
        } else {
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'apierror',
                'message' => $data['message'] ?? get_string('setup:step2:hosted:activation:error', 'mod_videolesson'),
            ];
        }
    }

    /**
     * Requests a token from the API for license creation
     *
     * @return array Response array with result, token, and message
     */
    private function request_token(): array {
        global $CFG;
        $postdata = [
            'domain' => $CFG->wwwroot,
        ];

        $result = $this->execute_curl_request(
            self::APISITEURL . '/wp-json/slms/v1/request-token',
            'POST',
            $postdata
        );

        if (!$result['success']) {
            debugging('cURL error requesting token: ' . $result['error'], DEBUG_DEVELOPER);
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'curlerror',
                'message' => get_string('setup:step1:error:network', 'mod_videolesson'),
            ];
        }

        $data = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('Invalid JSON response: ' . json_last_error_msg(), DEBUG_DEVELOPER);
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'jsonerror',
                'message' => get_string('setup:step1:error:invalidresponse', 'mod_videolesson'),
            ];
        }

        // Check if the API returned success.
        if (isset($data['success']) && $data['success'] === true && !empty($data['token'])) {
            return [
                'result' => self::RESULT_SUCCESS,
                'token' => $data['token'],
                'expires' => $data['expires'] ?? 0,
                'expires_in' => $data['expires_in'] ?? 600,
                'message' => $data['message'] ?? 'Token generated successfully',
            ];
        } else {
            return [
                'result' => self::RESULT_ERROR,
                'error_code' => 'tokenerror',
                'message' => $data['message'] ?? 'Failed to generate token',
            ];
        }
    }
}
