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
 * External API for triggering subtitle generation
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
use core_external\external_single_structure;
use context_system;
use mod_videolesson\local\services\subtitle_service;

/**
 * Trigger subtitle external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trigger_subtitle extends external_api {
    /**
     * Returns the description of the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contenthash' => new external_value(PARAM_TEXT, 'Content hash of the video', VALUE_REQUIRED),
            'lang' => new external_value(
                PARAM_TEXT,
                'Language code(s) for subtitle generation (comma-separated, e.g., "en,original,zh-TW")',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Returns the description of the method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation was successful'),
            'message' => new external_value(PARAM_TEXT, 'Success or error message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Execute the external function
     *
     * @param string $contenthash Content hash of the video
     * @param string $lang Language code(s) for subtitle generation
     * @return array Result
     */
    public static function execute(string $contenthash, string $lang): array {
        global $DB;

        // Validate context and permissions.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('mod/videolesson:manage', $context);

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'contenthash' => $contenthash,
            'lang' => $lang,
        ]);

        // Parse language codes.
        $langcodes = explode(',', $params['lang']);
        $langcodes = array_map('trim', $langcodes);
        $langcodes = array_filter($langcodes); // Remove empty values.

        if (empty($langcodes)) {
            return [
                'success' => false,
                'message' => get_string('error:subtitle:no_languages', 'mod_videolesson'),
            ];
        }

        // Use subtitle service to request subtitles (service handles validation).
        try {
            $result = subtitle_service::request_subtitles($params['contenthash'], $langcodes);

            if ($result['success'] && !empty($result['requested'])) {
                $message = get_string('success:subtitle:triggered', 'mod_videolesson');
                if (!empty($result['skipped'])) {
                    $message .= ' ' . get_string('subtitle:some_skipped', 'mod_videolesson', implode(', ', $result['skipped']));
                }
                return [
                    'success' => true,
                    'message' => $message,
                ];
            } else if (!empty($result['requested'])) {
                // Partial success.
                $errorlist = implode(', ', $result['errors']);
                $message = get_string('success:subtitle:triggered', 'mod_videolesson') . ' ' .
                          get_string('error:subtitle:partial_failure', 'mod_videolesson', $errorlist);
                return [
                    'success' => false,
                    'message' => $message,
                ];
            } else if (!empty($result['skipped']) && empty($result['requested'])) {
                // All were skipped (already requested/completed).
                return [
                    'success' => false,
                    'message' => get_string('error:subtitle:already_requested', 'mod_videolesson'),
                ];
            } else {
                // All failed.
                $errorlist = !empty($result['errors']) ? ': ' . implode(', ', $result['errors']) : '';
                return [
                    'success' => false,
                    'message' => get_string('error:subtitle:trigger_failed', 'mod_videolesson') . $errorlist,
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => get_string('error:subtitle:exception', 'mod_videolesson', $e->getMessage()),
            ];
        }
    }
}
