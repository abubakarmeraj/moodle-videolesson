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
 * AWS S3 operations via SDK.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
/**
 * AWS SDK handler class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aws_sdk_handler {
    /** @var \Aws\S3\S3Client|null $s3client The AWS S3 client instance */
    private $s3client;

    /** @var string $buckettype Type of bucket (input/output) */
    private $buckettype;

    /** @var string $bucketname The S3 bucket name */
    private $bucketname;
    /** @var \stdClass $config The configuration object */
    private $config;
    /** @var string $bucketkey The bucket key */
    private $bucketkey = 'videolesson';

    /**
     * Constructor for the aws_sdk_handler class.
     *
     * @param string $buckettype Input or output.
     */
    public function __construct($buckettype) {
        $this->buckettype = $buckettype;
        $this->config = get_config('mod_videolesson');
        if (isset($this->config->bucket_key) && $this->config->bucket_key) {
            $this->bucketkey = $this->config->bucket_key;
        }
        $awss3 = new aws_s3();
        $this->s3client = $awss3->create_client();
        $this->bucketname = $this->buckettype === 'input'
            ? $this->config->s3_input_bucket
            : $this->config->s3_output_bucket;
    }

    /**
     * Lists objects in the S3 bucket via the AWS SDK.
     *
     * @param string $prefix Optional prefix to filter the list of objects.
     * @param string $continuationtoken Continuation token for paginated results.
     * @param bool $delimit Whether to delimit the list of objects.
     * @param bool $return Whether to return the list of objects.
     * @return array The list of objects.
     * @throws \Exception If the AWS SDK is not configured or the operation fails.
     */
    public function list_objects($prefix = '', $continuationtoken = null, $delimit = false, $return = false) {
        if (!$this->s3client) {
            if ($return) {
                return null;
            }

            throw new \Exception('AWS SDK not configured');
        }

        try {
            $params = [
                'Bucket' => $this->bucketname,
                'Prefix' => "{$this->bucketkey}/{$prefix}",
            ];

            if ($delimit) {
                $params['Delimiter'] = '/';
            }

            if ($continuationtoken) {
                $params['ContinuationToken'] = $continuationtoken;
            }

            try {
                $result = $this->s3client->listObjectsV2($params);
                return $result->toArray();
            } catch (\Exception $e) {
                debugging('mod_videolesson: Error listing objects: ' . $e->getMessage(), DEBUG_DEVELOPER);

                return null;
            }
        } catch (S3Exception $e) {
            if ($return) {
                return null;
            }

            throw new \Exception('Error listing objects: ' . $e->getMessage());
        }
    }

    /**
     * Puts an object into the S3 bucket via the AWS SDK.
     *
     * @param string $filekey The object key.
     * @param \stored_file $file The object content.
     * @param array $options Optional parameters for the put operation.
     * @param bool $return Whether to return the result of the put operation.
     * @return array The result of the put operation.
     * @throws \Exception If the AWS SDK is not configured or the operation fails.
     */
    public function put_object($filekey, $file, $options = [], $return = false) {
        if (!$this->s3client) {
            if ($return) {
                return ['success' => false, 'error_message' => 'AWS SDK not configured'];
            }
            throw new \Exception('AWS SDK not configured');
        }

        $body = $file->get_content_file_handle();

        try {
            // Attempt to upload the object.
            $result = $this->s3client->putObject(array_merge([
                'Bucket' => $this->bucketname,
                'Key'    => "{$this->bucketkey}/{$filekey}",
                'Body'   => $body,
            ], $options));

            return [
                'success' => true,
                'status_code' => $result['@metadata']['statusCode'],
                'ObjectURL' => $result['ObjectURL'],
                'ETag' => $result['ETag'],
            ];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($return) {
                return [
                    'success' => false,
                    'status_code' => $e->getStatusCode(),
                    'error_message' => 'Error putting object: ' . $e->getMessage(),
                ];
            }
            throw new \Exception('Error putting object: ' . $e->getMessage());
        }
    }

    /**
     * Deletes an object from the S3 bucket via the AWS SDK.
     *
     * @param string $key The object key.
     * @return array The result of the delete operation.
     * @throws \Exception If the AWS SDK is not configured or the operation fails.
     */
    public function delete_object($key) {
        if (!$this->s3client) {
            throw new \Exception('AWS SDK not configured');
        }

        try {
            return $this->s3client->deleteObject([
                'Bucket' => $this->bucketname,
                'Key'    => "{$this->bucketkey}/{$key}",
            ]);
        } catch (S3Exception $e) {
            throw new \Exception('Error deleting object: ' . $e->getMessage());
        }
    }

    /**
     * Deletes multiple objects from the S3 bucket via the AWS SDK.
     *
     * @param array $keys List of object keys.
     * @return array The result of the delete operation.
     * @throws \Exception If the AWS SDK is not configured or the operation fails.
     */
    public function delete_objects(array $keys) {
        if (!$this->s3client) {
            throw new \Exception('AWS SDK not configured');
        }

        $prefixes = [];

        foreach ($keys as $key) {
            $parts = explode('/', $key);
            if (!in_array($parts[0], $prefixes)) {
                $prefixes[] = $parts[0];
            }
        }

        $responses  = [];
        foreach ($prefixes as $prefix) {
            try {
                $result = $this->s3client->listObjects([
                    'Bucket' => $this->bucketname,
                    'Prefix' => "{$this->bucketkey}/{$prefix}",
                ]);

                if (!$result['Contents']) {
                    // No contents to delete. just proceed.
                    $responses[$prefix] = ['success' => true, 'errors' => []];
                    continue;
                }

                $deleteobjects = array_map(static function ($content) {
                    return ['Key' => $content['Key']];
                }, $result['Contents'] ?? []);

                $response = $this->s3client->deleteObjects([
                    'Bucket'  => $this->bucketname,
                    'Delete' => [
                        'Objects' => $deleteobjects,
                    ],
                ]);
                $errors = [];
                // Check if there were any errors during deletion.
                if (!empty($response['Errors'])) {
                    foreach ($response['Errors'] as $error) {
                        $errors[] = 'Object: ' . $error['Key'] . ', Error: ' . $error['Message'];
                    }
                }

                $responses[$prefix] = ['success' => empty($errors), 'errors' => $errors];
            } catch (S3Exception $e) {
                $responses[$prefix] = ['success' => false, 'errors' => [$e->getMessage()]];
            }
        }

        return $responses;
    }
    /**
     * Get the cloudfront domain.
     *
     * @return string The cloudfront domain.
     */
    public function cloudfrontdomain() {
        return $this->config->cloudfrontdomain . '/' . $this->bucketkey . '/';
    }

    /**
     * Get the cloudfront domain list format.
     *
     * @param string $key The key.
     * @return string The cloudfront domain list format.
     */
    public function cloudfrontdomainlistformat($key) {
        return $this->config->cloudfrontdomain . '/' . $key;
    }

    /**
     * Check if the user can upload.
     *
     * @return array The result of the check.
     */
    public function canupload() {
        return ['can_upload' => true, ''];
    }

    /**
     * Checks if an object exists in the S3 bucket using headObject API.
     *
     * @param string $key The object key.
     * @return bool True if the object exists, false otherwise.
     */
    public function does_object_exist($key) {
        if (!$this->s3client) {
            return false;
        }

        try {
            $this->s3client->headObject([
                'Bucket' => $this->bucketname,
                'Key'    => "{$this->bucketkey}/{$key}",
            ]);
            return true;
        } catch (S3Exception $e) {
            // 404 means object doesn't exist, which is fine.
            if ($e->getStatusCode() === 404) {
                return false;
            }
            // For other errors, log and return false.
            debugging('Error checking object existence: ' . $e->getMessage(), DEBUG_NORMAL);
            return false;
        }
    }
}
