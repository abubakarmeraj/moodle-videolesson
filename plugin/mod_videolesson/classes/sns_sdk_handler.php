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
 * SNS SDK handler
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use Aws\Sns\SnsClient;

/**
 * Handles AWS SNS operations for mod_videolesson using AWS SDK.
 *
 * @package     mod_videolesson
 * @copyright   2022 BitKea Technologies LLP
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sns_sdk_handler {
    /** @var object Plugin configuration settings. */
    private $config;

    /** @var \Aws\Sns\SnsClient SNS client instance. */
    private $client;

    /**
     * Constructor for sns_sdk_handler.
     */
    public function __construct() {
        $this->config = get_config('mod_videolesson');
    }

    /**
     * Creates and returns an AWS SNS client.
     *
     * @return \Aws\Sns\SnsClient
     */
    public function create_client() {
        $connectionoptions = [
            'version' => 'latest',
            'region' => $this->config->api_region,
            'credentials' => [
                'key' => $this->config->api_key,
                'secret' => $this->config->api_secret,
            ],
        ];

        // Instantiate the client if not already created.
        if (!isset($this->client)) {
            $this->client = aws_s3::create_aws_client(SnsClient::class, $connectionoptions);
        }

        return $this->client;
    }

    /**
     * Publishes a message to an SNS topic (generic method).
     *
     * @param string $topicarn The SNS topic ARN
     * @param string|array $message The message to publish (will be JSON encoded if array)
     * @return array Result of the publish operation
     * @throws \Exception If publish fails
     */
    public function publish_message($topicarn, $message) {
        $this->create_client();

        // If message is an array, encode it as JSON.
        if (is_array($message)) {
            $message = json_encode($message);
        }

        try {
            $result = $this->client->publish([
                'TopicArn' => $topicarn,
                'Message' => $message,
            ]);

            return [
                'success' => true,
                'MessageId' => $result->get('MessageId'),
            ];
        } catch (\Aws\Sns\Exception\SnsException $e) {
            throw new \Exception('SNS publish failed: ' . $e->getMessage());
        }
    }

    /**
     * Triggers subtitle generation by publishing a message to SNS.
     *
     * @param string $objectkey The object key with bucket_key prefix (e.g., "bucket_key/contenthash")
     * @param string $targetlang Single language code for subtitle generation (e.g., "en" or "original")
     * @param string $filename The video filename/contenthash
     * @param string $s3uri The S3 URI of the video file
     * @return array Result of the publish operation
     * @throws \Exception If publish fails
     */
    public function trigger_subtitle_generation($objectkey, $targetlang, $filename, $s3uri) {
        $topicarn = $this->config->sns_topic_arn ?? null;

        if (empty($topicarn)) {
            throw new \Exception('SNS topic ARN not configured');
        }

        // Build the message payload matching Lambda function expectations.
        $message = [
            'action' => 'subtitle',
            'object_key' => $objectkey,
            'target_lang' => $targetlang,
            'file_name' => $filename,
            's3_uri' => $s3uri,
        ];

        return $this->publish_message($topicarn, $message);
    }
}
