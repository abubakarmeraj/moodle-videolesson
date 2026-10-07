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
 * AWS DynamoDB operations via MOOPLUGINS API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * DynamoDB hosted handler class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamodb_hosted_handler {
    /** @var string $apiurl The WordPress REST API endpoint URL */
    private $apiurl;

    /** @var string $licensekey The license key for validation */
    private $licensekey;

    /** @var string $tablename The DynamoDB table name */
    private $tablename;

    /** @var string $bucket_key The bucket key */
    private $bucketkey;

    /**
     * Constructor for the dynamodb_hosted_handler class.
     */
    public function __construct() {
        $config = get_config('mod_videolesson');
        $this->apiurl = $config->apiurl;
        $this->licensekey = $config->license_key;
        $this->bucketkey = $config->bucket_key;
    }

    /**
     * Get transcoding status for a contenthash.
     *
     * @param string $contenthash The video content hash
     * @return array|null Status data or null if not found
     */
    public function get_status(string $contenthash): ?array {
        if (!$this->apiurl || !$this->licensekey) {
            return null;
        }

        $postdata = [
            'license_key' => $this->licensekey,
            'action' => 'dynamodb_get_status',
            'contenthash' => $contenthash,
            'plugin_version' => util::get_plugin_version(),
        ];

        $data = util::execute_hosted_api_request($this->apiurl, $postdata, [
            'return_null_on_error' => true,
        ]);

        // Check for error in response.
        if (isset($data['error'])) {
            return null;
        }

        return $data ?? null;
    }
}
