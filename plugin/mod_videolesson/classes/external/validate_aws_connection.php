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

namespace mod_videolesson\external;

// Quarantined V1 API: unregistered in services.php; cannot invoke vendor or legacy tracking code.
throw new \moodle_exception('cflegacydisabled', 'mod_videolesson');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

/**
 * AJAX endpoint for validating AWS connection.
 *
 * @package     mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate_aws_connection extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'api_key' => new external_value(PARAM_TEXT, 'AWS API Key', VALUE_REQUIRED),
            'api_secret' => new external_value(PARAM_TEXT, 'AWS API Secret', VALUE_REQUIRED),
            's3_input_bucket' => new external_value(PARAM_TEXT, 'S3 Input Bucket', VALUE_REQUIRED),
            's3_output_bucket' => new external_value(PARAM_TEXT, 'S3 Output Bucket', VALUE_REQUIRED),
            'api_region' => new external_value(PARAM_TEXT, 'API Region', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the connection was successful'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $apikey
     * @param string $apisecret
     * @param string $s3inputbucket
     * @param string $s3outputbucket
     * @param string $apiregion
     * @return array
     */
    public static function execute(
        string $apikey,
        string $apisecret,
        string $s3inputbucket,
        string $s3outputbucket,
        string $apiregion
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'apikey' => $apikey,
            'apisecret' => $apisecret,
            's3inputbucket' => $s3inputbucket,
            's3outputbucket' => $s3outputbucket,
            'apiregion' => $apiregion,
        ]);

        // Create a temporary config object for testing.
        $testconfig = new \stdClass();
        $testconfig->apikey = $params['apikey'];
        $testconfig->apisecret = $params['apisecret'];
        $testconfig->apiregion = $params['apiregion'];
        $testconfig->s3inputbucket = $params['s3inputbucket'];
        $testconfig->s3outputbucket = $params['s3outputbucket'];

        try {
            // Test AWS S3 connection.
            $awss3 = new \mod_videolesson\aws_s3($testconfig);
            $awss3->create_client();

            // Test input bucket access.
            $inputresult = $awss3->is_bucket_accessible($params['s3_input_bucket']);
            if (!$inputresult->success) {
                $errormessage = get_string('setup:step2:self:validation:input_bucket_failed', 'mod_videolesson');
                $errormessage .= ' ' . $inputresult->message;
                $errormessage .= ' ' . get_string('setup:step2:self:validation:check_info', 'mod_videolesson');
                return [
                    'success' => false,
                    'message' => $errormessage,
                ];
            }

            // Test output bucket access.
            $outputresult = $awss3->is_bucket_accessible($params['s3_output_bucket']);
            if (!$outputresult->success) {
                $errormessage = get_string('setup:step2:self:validation:output_bucket_failed', 'mod_videolesson');
                $errormessage = ' ' . $outputresult->message;
                $errormessage .= ' ' . get_string('setup:step2:self:validation:check_info', 'mod_videolesson');
                return [
                    'success' => false,
                    'message' => $errormessage,
                ];
            }

            return [
                'success' => true,
                'message' => get_string('setup:step2:self:validation:success', 'mod_videolesson'),
            ];
        } catch (\Exception $e) {
            $errormessage = get_string('setup:step2:self:validation:error', 'mod_videolesson') . ' ' . $e->getMessage();
            $errormessage .= ' ' . get_string('setup:step2:self:validation:check_info', 'mod_videolesson');
            return [
                'success' => false,
                'message' => $errormessage,
            ];
        }
    }
}
