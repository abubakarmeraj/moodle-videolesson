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
 * External API for updating video sort order
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
use core_external\external_multiple_structure;
use context_system;

/**
 * Update sort order external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_sortorder extends external_api {
    /**
     * Returns the description of the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'videoids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Video ID'),
                'Array of video IDs in desired order',
                VALUE_REQUIRED
            ),
            'folderid' => new external_value(PARAM_INT, 'Folder ID (0 or null for root)', VALUE_DEFAULT, null),
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
            'error' => new external_value(PARAM_TEXT, 'Error message if failed', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Execute the external function
     *
     * @param array $videoids Array of video IDs
     * @param int|null $folderid Folder ID
     * @return array Result
     */
    public static function execute(array $videoids, $folderid = null): array {
        // Validate context and permissions.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('mod/videolesson:manage', $context);

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'videoids' => $videoids,
            'folderid' => $folderid,
        ]);

        // Normalize folderid (0 becomes null).
        if ($params['folderid'] == 0) {
            $params['folderid'] = null;
        }

        $success = \mod_videolesson\folder_manager::update_video_sortorder($params['videoids'], $params['folderid']);

        if ($success) {
            return ['success' => true];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to update sort order.',
            ];
        }
    }
}
