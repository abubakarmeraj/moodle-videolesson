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
 * Handles AWS SNS operations for mod_videolesson via hosted API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * SNS hosted handler class.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sns_hosted_handler {
    /** @var string $apiurl The WordPress REST API endpoint URL */
    private $apiurl;

    /** @var string $licensekey The license key for validation */
    private $licensekey;

    /**
     * Constructor for sns_hosted_handler.
     */
    public function __construct() {
        $config = get_config('mod_videolesson');
        $this->apiurl = $config->apiurl;
        $this->licensekey = $config->license_key;
    }

    /**
     * Publishes a message to an SNS topic via hosted API (generic method).
     *
     * @param string $action The action type (e.g., 'subtitle', 'sns_publish') - determines which topic ARN to use
     * @param string|array $message The message to publish (will be JSON encoded if array)
     * @return array Result of the publish operation
     * @throws \Exception If API call fails
     */
    public function publish_message($action, $message) {
        if (!$this->apiurl || !$this->licensekey) {
            throw new \Exception('API URL or License Key not set');
        }

        $postdata = array_merge(
            [
                'license_key' => $this->licensekey,
                'action'      => $action,
            ],
            $message
        );

        $data = util::execute_hosted_api_request($this->apiurl, $postdata, [
            'check_http_code' => true,
        ]);

        // Check for error in response.
        if (isset($data['result']) && $data['result'] === 'error') {
            throw new \Exception($data['message'] ?? 'SNS publish failed');
        }

        return [
            'success' => true,
            'MessageId' => $data['MessageId'] ?? null,
        ];
    }

    /**
     * Triggers subtitle generation by publishing a message to SNS via hosted API.
     *
     * @param string $objectkey The object key with bucket_key prefix (e.g., "bucket_key/contenthash")
     * @param string $targetlang Single language code for subtitle generation (e.g., "en" or "original")
     * @param string $filename The video filename/contenthash
     * @param string $s3uri The S3 URI of the video file
     * @return array Result of the publish operation
     * @throws \Exception If publish fails
     */
    public function trigger_subtitle_generation($objectkey, $targetlang, $filename, $s3uri) {
        // Build the message payload matching Lambda function expectations.
        $message = [
            'object_key' => $filename,
            'target_lang' => $targetlang,
            'file_name' => $filename,
        ];

        // Pass 'subtitle' as action - WordPress endpoint will determine the topic ARN.
        return $this->publish_message('subtitle', $message);
    }
}
