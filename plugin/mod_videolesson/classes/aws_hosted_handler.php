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
 * AWS S3 operations via MOOPLUGINS API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * AWS hosted handler class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aws_hosted_handler {
    /** @var string $action_upload_object Action for uploading a single object to the S3 bucket. */
    const ACTION_UPLOAD_OBJECT = 'upload_object';

    /** @var string $action_can_upload Action for checking if the user can upload to the S3 bucket. */
    const ACTION_CAN_UPLOAD = 'can_upload';

    /** @var string $action_delete_object Action for deleting a single object from the S3 bucket. */
    const ACTION_DELETE_OBJECT = 'delete_object';

    /** @var string $action_delete_multiple_objects Action for deleting multiple objects from the S3 bucket. */
    const ACTION_DELETE_MULTIPLE_OBJECTS = 'delete_objects';

    /** @var string $action_list_objects Action for listing objects within the S3 bucket. */
    const ACTION_LIST_OBJECTS = 'list_objects';


    /** @var string $buckettype Type of bucket (input/output). */
    private $buckettype;

    /** @var string $apiurl The WordPress REST API endpoint URL. */
    private $apiurl;

    /** @var string $licensekey The license key for validation. */
    private $licensekey;

    /** @var string $cloudfrontdomain The cdn domain. */
    private $cloudfrontdomain;

    /**
     * Constructor for the aws_hosted_handler class.
     *
     * @param string $buckettype Input or output.
     */
    public function __construct($buckettype) {
        $config = get_config('mod_videolesson');
        $this->buckettype = $buckettype;
        $this->apiurl = $config->apiurl;
        $this->licensekey = $config->license_key;
        $this->cloudfrontdomain = $config->cloudfrontdomainhosted;
    }

    /**
     * Lists objects in the S3 bucket via a signed URL.
     *
     * @param string $prefix Optional prefix to filter the list of objects.
     * @param string $continuationtoken Continuation token for paginated results.
     * @param bool $delimit Whether to delimit the list of objects.
     * @param bool $return Whether to return the list of objects.
     * @return array|string The response from the list operation.
     */
    public function list_objects($prefix = '', $continuationtoken = '', $delimit = false, $return = false) {
        $params = ['continuationtoken' => $continuationtoken];
        if ($delimit) {
            $params['delimit'] = $delimit;
        }

        $signedurl = $this->generate_signed_url($prefix, self::ACTION_LIST_OBJECTS, $params);

        $curl = new \curl();
        $curl->setopt([
            'connecttimeout' => 30,
            'timeout' => 120,
        ]);
        $response = $curl->get($signedurl);

        if ($curl->get_errno() !== 0) {
            if ($return) {
                return null;
            }
            throw new \Exception('cURL Error: ' . ($curl->error ?: 'Unknown error'));
        }

        $xml = simplexml_load_string($response);
        if ($xml === false) {
            if ($return) {
                return null;
            }

            throw new \Exception('Failed to parse XML');
        }

        $result = $this->xml_to_array($xml);
        return $result;
    }

    /**
     * Puts an object into the S3 bucket via a signed URL.
     *
     * @param string $key The object key.
     * @param object $file file object.
     * @param array $options Optional parameters for the put operation.
     * @param bool $return Whether to return the result of the put operation.
     * @return array|string The response from the put operation.
     */
    public function put_object($key, $file, $options = [], $return = false) {
        $canupload = $this->canupload();

        if (!$canupload['can_upload']) {
            return ['success' => false, 'error_message' => $canupload['message']];
        }

        $signedurl = $this->generate_signed_url($key, self::ACTION_UPLOAD_OBJECT, $options);

        // Metadata to include with the upload.
        $metadata = [];
        if (isset($options['Metadata'])) {
            foreach ($options['Metadata'] as $metakey => $value) {
                $metadata[] = 'x-amz-meta-' . $metakey . ': ' . $value;
            }
        }

        $tempfile = $file->copy_content_to_temp('mod_videolesson', 'vlhostedput_');
        if ($tempfile === false) {
            return ['success' => false, 'status_code' => 0, 'error_message' => 'Could not prepare upload file'];
        }

        $curl = new \curl();
        $curl->setopt([
            'connecttimeout' => 30,
            'timeout' => 3600,
        ]);
        foreach ($metadata as $headerline) {
            $curl->setHeader($headerline);
        }

        $response = $curl->put($signedurl, ['file' => $tempfile], ['CURLOPT_USERPWD' => false]);
        @unlink($tempfile);

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);

        if ($curl->get_errno() !== 0) {
            if ($return) {
                return [
                    'success' => false,
                    'status_code' => $httpcode,
                    'error_message' => $curl->error ?: 'cURL error',
                ];
            }
            throw new \Exception('cURL Error: ' . ($curl->error ?: 'Unknown error'));
        }

        // Check HTTP status code for success (200) or error handling.
        if ($httpcode !== 200) {
            $errormessage = $curl->error;
            if ($errormessage === '' && is_string($response)) {
                $errormessage = substr($response, 0, 500);
            }
            return [
                'success' => false,
                'status_code' => $httpcode,
                'error_message' => $errormessage ?: 'HTTP error',
            ];
        }

        return ['success' => true, 'status_code' => $httpcode];
    }

    /**
     * Deletes an object from the S3 bucket via a signed URL.
     *
     * @param string $key The object key.
     * @return array|string The response from the delete operation.
     */
    public function delete_object($key) {
        // Generate the signed URL for the DELETE operation.
        $signedurl = $this->generate_signed_url($key, self::ACTION_DELETE_OBJECT);

        // Presigned DELETE must not use core curl::delete(); see {@see hosted_presigned_curl}.
        $curl = new hosted_presigned_curl();
        $response = $curl->delete_presigned_url($signedurl);

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);

        if ($curl->get_errno() !== 0) {
            throw new \Exception('cURL Error: ' . ($curl->error ?: 'Unknown error'));
        }

        // Check for HTTP response status.
        if ($httpcode !== 204) { // 204 No Content is expected for successful DELETE.
            // Optionally parse XML if S3 sends back an error message as XML.
            if (!empty($response) && stripos($response, '<?xml') !== false) {
                $xml = simplexml_load_string($response);
                if ($xml !== false) {
                    $responsearray = $this->xml_to_array($xml);
                    throw new \Exception('S3 Error: ' . ($responsearray['Message'] ?? 'Unknown error'));
                }
            }
            throw new \Exception('Unexpected HTTP response code: ' . $httpcode);
        }

        // Return response or success message.
        return true;
    }

    /**
     * Deletes multiple objects from the S3 bucket via a signed URL.
     * Uses single delete in a loop. delete objects signed url is very complicated.
     * @param array $keys List of object keys.
     * @return array|string The response from the delete operation.
     */
    public function delete_objects(array $keys) {

        $prefixes = [];

        foreach ($keys as $key) {
            $parts = explode('/', $key);
            if (!in_array($parts[0], $prefixes)) {
                $prefixes[] = $parts[0];
            }
        }

        $responses  = [];
        foreach ($prefixes as $prefix) {
            $response = $this->generate_signed_url($prefix, self::ACTION_DELETE_MULTIPLE_OBJECTS);
            $responses[$prefix] = $response;
        }

        return $responses;
    }

    /**
     * Generates a signed URL using the WordPress REST API.
     *
     * @param string $key The object key.
     * @param string $operation The operation to be performed ('put', 'delete', 'list_objects').
     * @param array $other Optional parameters for the operation.
     * @return string The signed URL.
     * @throws \Exception If the API URL or license key is not set, or the operation fails.
     */
    private function generate_signed_url($key, $operation, $other = []) {
        if (!$this->apiurl || !$this->licensekey) {
            throw new \Exception('API URL or License Key not set');
        }

        $postdata = [
            'bucket_type' => $this->buckettype,
            'license_key' => $this->licensekey,
            'action' => $operation,
            'key' => $key,
            'plugin_version' => util::get_plugin_version(),
        ];

        if (isset($other['delimit']) && $other['delimit'] && $operation == self::ACTION_LIST_OBJECTS) {
            $postdata['delimit'] = $other['delimit'];
        }

        if (isset($other['continuationtoken']) && $other['continuationtoken'] && $operation == self::ACTION_LIST_OBJECTS) {
            $postdata['continuationtoken'] = $other['continuationtoken'];
        }

        if (isset($other['Metadata']) && $other['Metadata'] && $operation == self::ACTION_UPLOAD_OBJECT) {
            $postdata['metadata'] = json_encode($other['Metadata']);
        }

        $data = util::execute_hosted_api_request($this->apiurl, $postdata, [
            'check_http_code' => true,
        ]);

        // Safety net: Check for invalid_action error in case API bug returns HTTP 200 with error
        // According to API docs, invalid_action should return HTTP 400, which would throw an exception above.
        // This check handles edge cases where API might incorrectly return HTTP 200 with error code.
        if (isset($data['code']) && $data['code'] === 'invalid_action') {
            return 'Error: ' . $data['message']; // E.g., Error: Invalid key.
        }

        // For ACTION_DELETE_MULTIPLE_OBJECTS, it returns the entire API response $data, not a signed URL.
        // Endpoint deletes the objects and returns the response.
        if ($operation == self::ACTION_DELETE_MULTIPLE_OBJECTS) {
            return $data;
        }

        if (!isset($data['signed_url'])) {
            throw new \Exception('Signed URL not found in response' . var_export($data, true));
        }

        return $data['signed_url'];
    }
    /**
     * Converts an XML object to an array.
     *
     * @param object $xmlobject The XML object.
     * @return array The array.
     */
    private function xml_to_array($xmlobject) {
        $json = json_encode($xmlobject);
        return json_decode($json, true);
    }

    /**
     * Get the cloudfront domain.
     *
     * @return string The cloudfront domain.
     */
    public function cloudfrontdomain() {
        return $this->cloudfrontdomain . '/';
    }

    /**
     * Get the cloudfront domain list format.
     *
     * @param string $key The key.
     * @return string The cloudfront domain list format.
     */
    public function cloudfrontdomainlistformat($key) {
        $parsed = parse_url($this->cloudfrontdomain);
        $baseurl = $parsed['scheme'] . '://' . $parsed['host'];
        return $baseurl . '/' . $key;
    }

    /**
     * Check if the user can upload.
     *
     * @return array The result of the check.
     */
    public function canupload() {
        // Check limit.
        $postdata = [
            'license_key' => $this->licensekey,
            'action' => self::ACTION_CAN_UPLOAD,
        ];

        $data = util::execute_hosted_api_request($this->apiurl, $postdata, [
            'check_http_code' => false, // Don't throw exceptions.
            'throw_on_error' => false, // Return data instead of throwing.
        ]);

        // Normalize the response format.
        if ($data === null) {
            // Network/parsing error.
            return [
                'can_upload' => false,
                'code' => 'network_error',
                'message' => 'Failed to check upload limit. Please try again later.',
            ];
        }

        // If response has WordPress error format (code and message), convert to expected format.
        if (isset($data['code']) && isset($data['message'])) {
            return [
                'can_upload' => false,
                'code' => $data['code'],
                'message' => $data['message'],
            ];
        }

        // Success response (HTTP 200 with can_upload: true).
        if (isset($data['can_upload']) && $data['can_upload'] === true) {
            return [
                'can_upload' => true,
                'message' => $data['message'] ?? '',
            ];
        }

        // Fallback: unexpected response format.
        return [
            'can_upload' => false,
            'code' => 'unknown_error',
            'message' => 'Unexpected response from server.',
        ];
    }

    /**
     * Checks if an object exists in the S3 bucket.
     * Uses list_objects with prefix match since hosted API may not support headObject.
     *
     * @param string $key The object key (e.g., "contenthash/subtitles/language.vtt").
     * @return bool True if the object exists, false otherwise.
     */
    public function does_object_exist($key) {
        try {
            // Use list_objects with the key as prefix to check if object exists.
            // The list_objects method will add bucket_key prefix automatically.
            $result = $this->list_objects($key, '', false, true);
            if ($result === null) {
                return false;
            }

            // Check if the exact key exists in the results.
            if (isset($result['Contents'])) {
                // Normalize single object to array.
                $contents = isset($result['Contents']['Key'])
                    ? [ $result['Contents'] ]
                    : $result['Contents'];

                $config = get_config('mod_videolesson');
                $bucketkey = $config->bucket_key ?? 'videolesson';
                $fullkey = "{$bucketkey}/{$key}";

                foreach ($contents as $object) {
                    if (!isset($object['Key'])) {
                        continue;
                    }

                    if (
                        $object['Key'] === $fullkey ||
                        (
                            strpos($object['Key'], $bucketkey . '/') === 0 &&
                            substr($object['Key'], strlen($bucketkey) + 1) === $key
                        )
                    ) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            debugging('Error checking object existence: ' . $e->getMessage(), DEBUG_NORMAL);
            return false;
        }
    }
}
