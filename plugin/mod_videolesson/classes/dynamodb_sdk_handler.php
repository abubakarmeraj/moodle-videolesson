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
 * AWS DynamoDB operations via SDK.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
/**
 * DynamoDB SDK handler class.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamodb_sdk_handler {
    /** @var DynamoDbClient|null $dynamodbclient The AWS DynamoDB client instance. */
    private $dynamodbclient;

    /** @var string $tablename The DynamoDB table name */
    private $tablename;

    /** @var \stdClass $config Plugin configuration */
    private $config;

    /**
     * Constructor for the dynamodb_sdk_handler class.
     */
    public function __construct() {
        $this->config = get_config('mod_videolesson');
        $this->tablename = $this->config->dynamodb_table_name ?? 'videolesson-transcoding-status';

        $connectionoptions = [
            'version' => 'latest',
            'region' => $this->config->api_region,
            'credentials' => [
                'key' => $this->config->api_key,
                'secret' => $this->config->api_secret,
            ],
        ];

        try {
            $this->dynamodbclient = aws_s3::create_aws_client(DynamoDbClient::class, $connectionoptions);
        } catch (\Exception $e) {
            debugging('mod_videolesson: Failed to create DynamoDB client: ' . $e->getMessage(), DEBUG_NORMAL);
            $this->dynamodbclient = null;
        }
    }

    /**
     * Get transcoding status for a contenthash.
     *
     * @param string $contenthash The video content hash
     * @return array|null Status data or null if not found
     */
    public function get_status(string $contenthash): ?array {
        if (!$this->dynamodbclient) {
            return null;
        }

        // Get domainid from config (required for composite primary key).
        $domainid = $this->config->bucket_key ?? 'videolesson';
        if ($domainid === null) {
            debugging('mod_videolesson: domain_id not found in config, cannot query DynamoDB', DEBUG_NORMAL);
            return null;
        }

        try {
            $result = $this->dynamodbclient->getItem([
                'TableName' => $this->tablename,
                'Key' => [
                    'contenthash' => ['S' => $contenthash],
                    'domainid' => ['S' => (string)$domainid],
                ],
            ]);

            if (!isset($result['Item'])) {
                return null;
            }

            // Convert DynamoDB format to PHP array.
            return $this->unmarshal_item($result['Item']);
        } catch (DynamoDbException $e) {
            debugging('mod_videolesson: DynamoDB getItem error: ' . $e->getMessage(), DEBUG_NORMAL);
            return null;
        } catch (\Exception $e) {
            debugging('mod_videolesson: Error getting DynamoDB status: ' . $e->getMessage(), DEBUG_NORMAL);
            return null;
        }
    }

    /**
     * Convert DynamoDB item format to PHP array.
     *
     * @param array $item DynamoDB item
     * @return array PHP array
     */
    private function unmarshal_item(array $item): array {
        $result = [];
        foreach ($item as $key => $value) {
            if (isset($value['S'])) {
                $result[$key] = $value['S'];
            } else if (isset($value['N'])) {
                $result[$key] = is_numeric($value['N']) ? (int)$value['N'] : (float)$value['N'];
            } else if (isset($value['BOOL'])) {
                $result[$key] = $value['BOOL'];
            } else if (isset($value['NULL'])) {
                $result[$key] = null;
            }
        }
        return $result;
    }

    /**
     * Convert PHP array to DynamoDB item format.
     *
     * @param array $data PHP array
     * @return array DynamoDB item
     */
    private function marshal_item(array $data): array {
        $item = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $item[$key] = ['S' => $value];
            } else if (is_int($value) || is_float($value)) {
                $item[$key] = ['N' => (string)$value];
            } else if (is_bool($value)) {
                $item[$key] = ['BOOL' => $value];
            } else if ($value === null) {
                $item[$key] = ['NULL' => true];
            }
        }
        return $item;
    }
}
