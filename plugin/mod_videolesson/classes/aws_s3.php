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
 * AWS S3 class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use Aws\AwsClient;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use core\aws\aws_helper;
/**
 * AWS S3 class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aws_s3 {
    /**
     * Load bundled AWS SDK functions.php (e.g. Aws\manifest) when core autoload omits Composer "files".
     */
    public static function ensure_aws_sdk_functions_loaded(): void {
        if (\function_exists('Aws\\manifest')) {
            return;
        }
        global $CFG;
        $path = $CFG->dirroot . '/lib/aws-sdk/src/functions.php';
        if (is_readable($path)) {
            require_once($path);
        }
    }

    /**
     * Instantiate an AWS SDK client with Moodle HTTP defaults and proxy middleware.
     *
     * Replaces deprecated {@see \core\aws\client_factory::get_client()}.
     *
     * @param string $classname Fully qualified client class e.g. S3Client::class
     * @param array $opts Client constructor options
     * @return AwsClient
     */
    public static function create_aws_client(string $classname, array $opts): AwsClient {
        self::ensure_aws_sdk_functions_loaded();
        if (empty($opts['http'])) {
            $opts['http'] = ['connect_timeout' => HOURSECS];
        } else if (!array_key_exists('connect_timeout', $opts['http'])) {
            $opts['http']['connect_timeout'] = HOURSECS;
        }
        $client = new $classname($opts);
        if (!$client instanceof AwsClient) {
            throw new \moodle_exception('clientnotfound', 'factor_sms');
        }
        return aws_helper::configure_client_proxy($client);
    }

    /**
     *
     * @var object Plugin confiuration.
     */
    private $config;

    /**
     *
     * @var \Aws\S3\S3Client S3 client.
     */
    private $client;


    /**
     * Class constructor
     *
     * @param \stdClass|null $config Optional configuarion object to use.
     */
    public function __construct($config = null) {
        if ($config) {
            $this->config = $config;
        } else {
            $this->config = get_config('mod_videolesson');
        }
    }

    /**
     * Create AWS S3 API client.
     *
     * @return \Aws\S3\S3Client
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

        // Only create client if it hasn't already been done.
        if ($this->client == null) {
            $this->client = self::create_aws_client(S3Client::class, $connectionoptions);
        }

        return $this->client;
    }

    /**
     * When an exception occurs get and return
     * the exception details.
     *
     * @param object $exception The thrown exception.
     * @return string $details The details of the exception.
     */
    private function get_exception_details($exception) {
        $message = $exception->getMessage();

        if (get_class($exception) !== 'S3Exception') {
            return "Not a S3 exception : $message";
        }

        $errorcode = $exception->getAwsErrorCode();

        $details = ' ';

        if ($message) {
            $details .= "ERROR MSG: " . $message . "\n";
        }

        if ($errorcode) {
            $details .= "ERROR CODE: " . $errorcode . "\n";
        }

        return $details;
    }

    /**
     * Tests connection to S3 and bucket.
     * There is no check connection in the AWS API.
     * We use list buckets instead and check the bucket is in the list.
     *
     * @param string $bucket Name of buket to check.
     * @return \stdClass $connection Object with success and message properties.
     */
    public function is_bucket_accessible($bucket) {
        $connection = new \stdClass();
        $connection->success = true;
        $connection->message = '';

        try {
            $result = $this->client->headBucket([
                'Bucket' => $bucket,
            ]);

            $connection->message = get_string('settings:connectionsuccess', 'mod_videolesson');
        } catch (S3Exception $e) {
            $connection->success = false;
            $details = $this->get_exception_details($e);
            $connection->message = get_string('settings:connectionfailure', 'mod_videolesson') . $details;
        }
        return $connection;
    }

    /**
     * Tests connection to S3 and bucket.
     * There is no check connection in the AWS API.
     * We use list buckets instead and check the bucket is in the list.
     *
     * @param string $bucket The bucket to check.
     * @return \stdClass $permissions Object with success and messages properties.
     */
    private function have_bucket_permissions($bucket) {
        $permissions = new \stdClass();
        $permissions->success = true;
        $permissions->messages = [];

        try {
            $result = $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => 'permissions_check_file',
                'Body' => 'test content',
            ]);
        } catch (S3Exception $e) {
            $details = $this->get_exception_details($e);
            $permissions->messages[] = get_string('settings:writefailure', 'mod_videolesson') . $details;
            $permissions->success = false;
        }

        try {
            $result = $this->client->getObject(
                [
                    'Bucket' => $bucket,
                    'Key' => 'permissions_check_file',
                ]
            );
        } catch (S3Exception $e) {
            $errorcode = $e->getAwsErrorCode();
            // Write could have failed.
            if ($errorcode !== 'NoSuchKey') {
                $details = $this->get_exception_details($e);
                $permissions->messages[] = get_string('settings:readfailure', 'mod_videolesson') . $details;
                $permissions->success = false;
            }
        }

        try {
            $result = $this->client->deleteObject(
                [
                    'Bucket' => $bucket,
                    'Key' => 'permissions_check_file',
                ]
            );
            $permissions->messages[] = get_string('settings:deletesuccess', 'mod_videolesson');
        } catch (S3Exception $e) {
            $errorcode = $e->getAwsErrorCode();
            // Something else went wrong.
            if ($errorcode !== 'AccessDenied') {
                $details = $this->get_exception_details($e);
                $permissions->messages[] = get_string('settings:deleteerror', 'mod_videolesson') . $details;
            }
        }

        if ($permissions->success) {
            $permissions->messages[] = get_string('settings:permissioncheckpassed', 'mod_videolesson');
        }
        return $permissions;
    }

    /**
     * Whether the plugin is configured and requirements are met.
     *
     * @return  bool
     */
    public function are_requirements_met() {

        // Check that we can access the input S3 Bucket.
        $connection = $this->is_bucket_accessible($this->config->s3_input_bucket);
        if (!$connection->success) {
            debugging('mod_videolesson: cannot connect to input bucket');
            return false;
        }

        // Check that we can access the output S3 Bucket.
        $connection = $this->is_bucket_accessible($this->config->s3_output_bucket);
        if (!$connection->success) {
            debugging('mod_videolesson: cannot connect to output bucket');
            return false;
        }

        // Check input bucket permissions.
        $bucket = $this->config->s3_input_bucket;
        $permissions = $this->have_bucket_permissions($bucket);
        if (!$permissions->success) {
            debugging('mod_videolesson: permissions failure on input bucket');
            return false;
        }

        // Check output bucket permissions.
        $bucket = $this->config->s3_output_bucket;
        $permissions = $this->have_bucket_permissions($bucket);
        if (!$permissions->success) {
            debugging('mod_videolesson: permissions failure on output bucket');
            return false;
        }

        return true;
    }
}
