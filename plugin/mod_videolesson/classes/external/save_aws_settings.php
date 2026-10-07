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
 * AJAX endpoint for saving AWS settings from setup wizard.
 *
 * @package     mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_aws_settings extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'api_key' => new external_value(PARAM_TEXT, 'AWS API Key', VALUE_DEFAULT, ''),
            'api_secret' => new external_value(PARAM_TEXT, 'AWS API Secret', VALUE_DEFAULT, ''),
            's3_input_bucket' => new external_value(PARAM_TEXT, 'S3 Input Bucket', VALUE_DEFAULT, ''),
            's3_output_bucket' => new external_value(PARAM_TEXT, 'S3 Output Bucket', VALUE_DEFAULT, ''),
            'api_region' => new external_value(PARAM_TEXT, 'API Region', VALUE_DEFAULT, 'ap-southeast-2'),
            'dynamodb_table_name' => new external_value(
                PARAM_TEXT,
                'DynamoDB Table Name',
                VALUE_DEFAULT,
                'videolesson-transcoding-status'
            ),
            'sns_topic_arn' => new external_value(PARAM_TEXT, 'SNS Topic ARN', VALUE_DEFAULT, ''),
            'cloudfrontdomain' => new external_value(PARAM_URL, 'CloudFront Domain', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Returns structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation was successful'),
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
     * @param string $dynamodbtable
     * @param string $snstopicarn
     * @param string $cloudfrontdomain
     * @return array
     */
    public static function execute(
        string $apikey = '',
        string $apisecret = '',
        string $s3inputbucket = '',
        string $s3outputbucket = '',
        string $apiregion = 'ap-southeast-2',
        string $dynamodbtable = 'videolesson-transcoding-status',
        string $snstopicarn = '',
        string $cloudfrontdomain = ''
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'api_key' => $apikey,
            'api_secret' => $apisecret,
            's3_input_bucket' => $s3inputbucket,
            's3_output_bucket' => $s3outputbucket,
            'api_region' => $apiregion,
            'dynamodb_table_name' => $dynamodbtable,
            'sns_topic_arn' => $snstopicarn,
            'cloudfrontdomain' => $cloudfrontdomain,
        ]);

        // Save each setting.
        if ($params['api_key'] !== '') {
            set_config('api_key', $params['api_key'], 'mod_videolesson');
        }
        if ($params['api_secret'] !== '') {
            set_config('api_secret', $params['api_secret'], 'mod_videolesson');
        }
        if ($params['s3_input_bucket'] !== '') {
            set_config('s3_input_bucket', $params['s3_input_bucket'], 'mod_videolesson');
        }
        if ($params['s3_output_bucket'] !== '') {
            set_config('s3_output_bucket', $params['s3_output_bucket'], 'mod_videolesson');
        }
        if ($params['api_region'] !== '') {
            set_config('api_region', $params['api_region'], 'mod_videolesson');
        }
        if ($params['dynamodb_table_name'] !== '') {
            set_config('dynamodb_table_name', $params['dynamodb_table_name'], 'mod_videolesson');
        }
        if ($params['sns_topic_arn'] !== '') {
            set_config('sns_topic_arn', $params['sns_topic_arn'], 'mod_videolesson');
        }
        if ($params['cloudfrontdomain'] !== '') {
            set_config('cloudfrontdomain', $params['cloudfrontdomain'], 'mod_videolesson');
        }

        return [
            'success' => true,
            'message' => get_string('setup:step2:settings:saved', 'mod_videolesson'),
        ];
    }
}
