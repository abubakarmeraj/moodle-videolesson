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
use mod_videolesson\local\services\video_list_service;
use context_system;

/**
 * AJAX endpoint for fetching video listings.
 *
 * @package     mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_videos extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'folderid' => new external_value(PARAM_TEXT, 'Folder identifier (all|uncategorized|ID)', VALUE_DEFAULT, 'all'),
            'search' => new external_value(PARAM_TEXT, 'Search term', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page number (0 based)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 10),
        ]);
    }

    /**
     * Returns structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'videos' => new \core_external\external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Video ID'),
                    'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content hash'),
                    'namehtml' => new external_value(PARAM_RAW, 'Rendered video name HTML'),
                    'title' => new external_value(PARAM_TEXT, 'Video title/name'),
                    'thumbnail' => new external_value(PARAM_URL, 'Thumbnail URL'),
                    'placeholder' => new external_value(PARAM_URL, 'Placeholder image URL for fallback'),
                    'duration' => new external_value(PARAM_TEXT, 'Duration'),
                    'foldername' => new external_value(PARAM_TEXT, 'Folder label'),
                    'folderid' => new external_value(PARAM_TEXT, 'Folder identifier', VALUE_DEFAULT, null),
                    'status' => new external_value(PARAM_TEXT, 'Combined status (uploaded + transcoder)'),
                    'statusbadge' => new external_value(PARAM_ALPHANUMEXT, 'Status badge class', VALUE_DEFAULT, ''),
                    'instances' => new external_value(PARAM_INT, 'Instances'),
                    'instancesurl' => new external_value(PARAM_URL, 'Instances URL (if instances > 0)', VALUE_OPTIONAL),
                    'size' => new external_value(PARAM_TEXT, 'Converted size'),
                    'sourcesize' => new external_value(PARAM_TEXT, 'Source size'),
                    'timecreated' => new external_value(PARAM_TEXT, 'Created time'),
                    'timecreatedtimestamp' => new external_value(PARAM_INT, 'Created time timestamp'),
                    'viewaction' => new external_single_structure([
                        'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content hash'),
                        'src' => new external_value(PARAM_URL, 'Video source'),
                        'title' => new external_value(PARAM_TEXT, 'Title'),
                    ], 'View action', VALUE_OPTIONAL),
                    'mp4action' => new external_single_structure([
                        'url' => new external_value(PARAM_URL, 'MP4 URL'),
                        'label' => new external_value(PARAM_TEXT, 'Label'),
                    ], 'MP4 action', VALUE_OPTIONAL),
                    'reporturl' => new external_value(PARAM_URL, 'Report URL'),
                    'retryurl' => new external_value(PARAM_URL, 'Retry URL', VALUE_OPTIONAL),
                    'deleteurl' => new external_value(PARAM_URL, 'Delete URL'),
                    'assignable' => new external_value(PARAM_BOOL, 'Can assign folder'),
                    'cansubtitle' => new external_value(PARAM_BOOL, 'Can generate subtitles'),
                ])
            ),
            'hasvideos' => new external_value(PARAM_BOOL, 'Whether there are videos'),
            'pagination' => new external_single_structure([
                'total' => new external_value(PARAM_INT, 'Total videos'),
                'page' => new external_value(PARAM_INT, 'Current page'),
                'perpage' => new external_value(PARAM_INT, 'Per page'),
                'totalpages' => new external_value(PARAM_INT, 'Total pages'),
                'haspages' => new external_value(PARAM_BOOL, 'Has multiple pages'),
                'hasprev' => new external_value(PARAM_BOOL, 'Has previous page'),
                'hasnext' => new external_value(PARAM_BOOL, 'Has next page'),
                'prevpage' => new external_value(PARAM_INT, 'Previous page'),
                'nextpage' => new external_value(PARAM_INT, 'Next page'),
                'pages' => new \core_external\external_multiple_structure(
                    new \core_external\external_single_structure([
                        'number' => new external_value(PARAM_INT, 'Page number'),
                        'display' => new external_value(PARAM_TEXT, 'Page display'),
                        'current' => new external_value(PARAM_BOOL, 'Whether current page'),
                    ])
                ),
            ]),
            'filters' => new external_single_structure([
                'search' => new external_value(PARAM_TEXT, 'Search term'),
                'folderid' => new external_value(PARAM_TEXT, 'Normalised folder identifier'),
                'foldername' => new external_value(PARAM_TEXT, 'Folder name', VALUE_OPTIONAL),
                'active' => new external_value(PARAM_BOOL, 'Whether filters are active'),
            ]),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $folderid
     * @param string $search
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function execute(string $folderid = 'all', string $search = '', int $page = 0, int $perpage = 10): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('mod/videolesson:manage', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'folderid' => $folderid,
            'search' => $search,
            'page' => $page,
            'perpage' => $perpage,
        ]);

        return video_list_service::build_listing($params);
    }
}
