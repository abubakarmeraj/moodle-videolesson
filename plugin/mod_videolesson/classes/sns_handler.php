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
 * AWS SNS handler for the Video Lesson module.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * Main SNS handler that delegates to SDK or hosted handler based on hosting type.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sns_handler {
    /** @var string $hostingtype The license type, determining hosted or SDK-based operations */
    private $hostingtype;

    /** @var sns_hosted_handler|sns_sdk_handler $handler The handler instance */
    private $handler;

    /**
     * Constructor for the sns_handler class.
     */
    public function __construct() {
        $this->hostingtype = get_config('mod_videolesson', 'hosting_type');
        $this->handler = $this->hostingtype === 'hosted'
            ? new sns_hosted_handler()
            : new sns_sdk_handler();
    }

    /**
     * Publishes a message to an SNS topic (generic method).
     *
     * @param string $topicarn The SNS topic ARN
     * @param string|array $message The message to publish
     * @return array Result of the publish operation
     * @throws \Exception If publish fails
     */
    public function publish_message($topicarn, $message) {
        // Ensure the handler has the publish_message method before calling it.
        if (!method_exists($this->handler, 'publish_message')) {
            throw new \Exception('The handler does not implement the publish_message method.');
        }

        // Delegate the publish operation to the handler.
        return $this->handler->publish_message($topicarn, $message);
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
        // Ensure the handler has the trigger_subtitle_generation method before calling it.
        if (!method_exists($this->handler, 'trigger_subtitle_generation')) {
            throw new \Exception('The handler does not implement the trigger_subtitle_generation method.');
        }

        // Delegate the subtitle generation trigger to the handler.
        return $this->handler->trigger_subtitle_generation($objectkey, $targetlang, $filename, $s3uri);
    }
}
