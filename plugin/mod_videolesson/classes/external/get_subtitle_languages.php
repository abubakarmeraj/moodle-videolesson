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
 * External API for getting subtitle languages
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\external;

// Quarantined V1 API: unregistered in services.php; cannot invoke vendor or legacy tracking code.
throw new \moodle_exception('cflegacydisabled', 'mod_videolesson');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use context_system;
use mod_videolesson\subtitle_languages;
use mod_videolesson\local\services\subtitle_service;

/**
 * Get subtitle languages external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_subtitle_languages extends external_api {
    /**
     * Returns the description of the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content hash of the video', VALUE_OPTIONAL, null),
        ]);
    }

    /**
     * Returns the description of the method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'languages' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                    'name' => new external_value(PARAM_TEXT, 'Language display name'),
                ])
            ),
            'existing' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                    'name' => new external_value(PARAM_TEXT, 'Language display name'),
                ]),
                'Existing subtitle languages',
                VALUE_OPTIONAL
            ),
            'pending' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                    'name' => new external_value(PARAM_TEXT, 'Language display name'),
                ]),
                'Pending subtitle languages',
                VALUE_OPTIONAL
            ),
            'processing' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                    'name' => new external_value(PARAM_TEXT, 'Language display name'),
                ]),
                'Processing subtitle languages',
                VALUE_OPTIONAL
            ),
            'failed' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'Language code'),
                    'name' => new external_value(PARAM_TEXT, 'Language display name'),
                ]),
                'Failed subtitle languages',
                VALUE_OPTIONAL
            ),
        ]);
    }

    /**
     * Execute the external function
     *
     * @param string|null $contenthash Content hash of the video (optional)
     * @return array List of languages and existing subtitles
     */
    public static function execute(?string $contenthash = null): array {
        global $DB;

        // Validate context and permissions.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('mod/videolesson:manage', $context);

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'contenthash' => $contenthash,
        ]);

        // Get subtitle status if contenthash provided.
        $status = [
            'completed' => [],
            'pending' => [],
            'processing' => [],
            'failed' => [],
        ];

        if (!empty($params['contenthash'])) {
            $status = subtitle_service::get_subtitle_status($params['contenthash']);
        }

        // Get all supported languages.
        $supportedlanguages = subtitle_languages::get_supported_languages();
        $languages = [];

        // Filter out all statuses (completed, pending, processing) - only show available languages.
        $allstatuses = array_merge($status['completed'], $status['pending'], $status['processing']);

        foreach ($supportedlanguages as $code => $name) {
            if (!in_array($code, $allstatuses)) {
                $languages[] = [
                    'code' => $code,
                    'name' => $name,
                ];
            }
        }

        // Map existing completed languages to objects with code and name.
        $existing = array_map(function ($code) use ($supportedlanguages) {
            return [
                'code' => $code,
                'name' => $supportedlanguages[$code] ?? $code,
            ];
        }, $status['completed']);

        // Map pending languages to objects with code and name.
        $pending = array_map(function ($code) use ($supportedlanguages) {
            return [
                'code' => $code,
                'name' => $supportedlanguages[$code] ?? $code,
            ];
        }, $status['pending']);

        // Map processing languages to objects with code and name.
        $processing = array_map(function ($code) use ($supportedlanguages) {
            return [
                'code' => $code,
                'name' => $supportedlanguages[$code] ?? $code,
            ];
        }, $status['processing']);

        // Map failed languages to objects with code and name.
        $failed = array_map(function ($code) use ($supportedlanguages) {
            return [
                'code' => $code,
                'name' => $supportedlanguages[$code] ?? $code,
            ];
        }, $status['failed']);

        return [
            'languages' => $languages,
            'existing' => array_values($existing),
            'pending' => array_values($pending),
            'processing' => array_values($processing),
            'failed' => array_values($failed),
        ];
    }
}
