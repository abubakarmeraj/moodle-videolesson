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
 * External API for getting folder tree
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

/**
 * Get folder tree external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_folder_tree extends external_api {
    /**
     * Returns the description of the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the description of the method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        // Define folder structure for depth 2 (max depth is 3, so we need 3 levels).
        // Level 3 structure (deepest).
        $level3structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Folder ID'),
            'name' => new external_value(PARAM_TEXT, 'Folder name'),
            'parent' => new external_value(PARAM_INT, 'Parent folder ID', VALUE_OPTIONAL),
            'depth' => new external_value(PARAM_INT, 'Depth level'),
            'path' => new external_value(PARAM_TEXT, 'Folder path'),
            'sortorder' => new external_value(PARAM_INT, 'Sort order'),
            'video_count' => new external_value(PARAM_INT, 'Number of videos in folder'),
            'children' => new external_multiple_structure(
                new external_single_structure([]),
                'Nested child folders',
                VALUE_OPTIONAL
            ),
        ]);

        // Level 2 structure.
        $level2structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Folder ID'),
            'name' => new external_value(PARAM_TEXT, 'Folder name'),
            'parent' => new external_value(PARAM_INT, 'Parent folder ID', VALUE_OPTIONAL),
            'depth' => new external_value(PARAM_INT, 'Depth level'),
            'path' => new external_value(PARAM_TEXT, 'Folder path'),
            'sortorder' => new external_value(PARAM_INT, 'Sort order'),
            'video_count' => new external_value(PARAM_INT, 'Number of videos in folder'),
            'children' => new external_multiple_structure(
                $level3structure,
                'Nested child folders',
                VALUE_OPTIONAL
            ),
        ]);

        // Level 1 structure (root folders).
        $tree = new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Folder ID'),
                'name' => new external_value(PARAM_TEXT, 'Folder name'),
                'parent' => new external_value(PARAM_INT, 'Parent folder ID', VALUE_OPTIONAL),
                'depth' => new external_value(PARAM_INT, 'Depth level'),
                'path' => new external_value(PARAM_TEXT, 'Folder path'),
                'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                'video_count' => new external_value(PARAM_INT, 'Number of videos in folder'),
                'children' => new external_multiple_structure(
                    $level2structure,
                    'Child folders',
                    VALUE_OPTIONAL
                ),
            ])
        );

        return new external_single_structure([
            'folders' => $tree,
            'uncategorizedcount' => new external_value(PARAM_INT, 'Count of uncategorized videos'),
            'totalcount' => new external_value(PARAM_INT, 'Total count of all videos'),
        ]);
    }

    /**
     * Execute the external function
     *
     * @return array Folder tree
     */
    public static function execute(): array {
        // Validate context and permissions.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('mod/videolesson:manage', $context);

        return [
            'folders' => \mod_videolesson\folder_manager::get_folder_tree(),
            'uncategorizedcount' => \mod_videolesson\folder_manager::get_uncategorized_count(),
            'totalcount' => \mod_videolesson\folder_manager::get_total_video_count(),
        ];
    }
}
