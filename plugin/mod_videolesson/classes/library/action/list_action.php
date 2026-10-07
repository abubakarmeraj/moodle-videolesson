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
 * List action handler
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\library\action;

/**
 * Handles video list action (default)
 */
class list_action extends base {
    /**
     * Setup navigation for list action
     */
    public function setup_navigation() {
        $this->setup_base_navigation();

        // Add "Video Library" (no link, it's the current page).
        $this->add_breadcrumb(get_string('header_manage_videos', 'mod_videolesson'));
    }

    /**
     * Execute list action.
     */
    public function execute() {
        global $OUTPUT, $PAGE;

        $text = optional_param('_text', '', PARAM_TEXT);
        $status = optional_param('_status', 'all', PARAM_ALPHA);
        $filter = optional_param('_action', '', PARAM_TEXT);
        $type = optional_param('type', 'all', PARAM_TEXT);

        if ($filter == 'reset') {
            $type = 'all';
            $status = -1;
            $text = '';
        }

        $folderparam = optional_param('folder', optional_param('folderid', null, PARAM_TEXT), PARAM_TEXT);
        $folderidentifier = \mod_videolesson\local\services\video_list_service::normalise_folder_identifier($folderparam);

        $listingcontext = \mod_videolesson\local\services\video_list_service::build_listing([
            'folderid' => $folderidentifier,
            'search' => $text,
            'page' => optional_param('vpage', 0, PARAM_INT),
            'perpage' => optional_param('vperpage', 10, PARAM_INT),
        ]);
        $listingcontext['canmanage'] = has_capability('mod/videolesson:manage', $this->systemcontext);

        $uploadpageurl = new \moodle_url('/mod/videolesson/library.php', [
            'action' => 'upload',
            'folder' => $folderidentifier,
        ]);

        $heading = get_string('header_manage_videos', 'mod_videolesson');
        $PAGE->set_title($heading);
        $PAGE->set_heading($heading);
        videolesson_register_player_page_requires($PAGE);
        echo $OUTPUT->header();
        echo $this->render_breadcrumb();

        // Get folder tree data.
        $foldertree = \mod_videolesson\folder_manager::get_folder_tree();
        $uncategorizedcount = \mod_videolesson\folder_manager::get_uncategorized_count();
        $activefolderid = is_numeric($folderidentifier) ? (int)$folderidentifier : null;
        $foldertree = \mod_videolesson\folder_manager::mark_selected($foldertree, $activefolderid);
        $foldertreecontext = [
            'folders' => $foldertree,
            'selected_folder' => [
                'is_all' => ($folderidentifier === 'all'),
                'is_uncategorized' => ($folderidentifier === 'uncategorized'),
                'value' => $folderidentifier,
            ],
            'can_manage' => has_capability('mod/videolesson:manage', $this->systemcontext),
            'uncategorized_count' => $uncategorizedcount,
        ];

        // Two-column layout: folder tree sidebar + main content.
        echo \html_writer::start_div('row');
        echo \html_writer::start_div('col-md-3 videolesson-folder-sidebar');
        echo $OUTPUT->render_from_template('mod_videolesson/folder_tree', $foldertreecontext);
        echo \html_writer::end_div();

        $tableattrs = [
            'id' => 'videolesson-table-container',
            'class' => 'col-md-9 videolesson-video-content',
            'data-initial-folder' => $folderidentifier,
            'data-initial-search' => $listingcontext['filters']['search'],
            'data-initial-page' => $listingcontext['pagination']['page'],
            'data-per-page' => $listingcontext['pagination']['perpage'],
        ];
        echo \html_writer::start_div('', $tableattrs);
        $filterparam = [
            'uploadpageurl' => $uploadpageurl,
            'action' => new \moodle_url('/mod/videolesson/library.php'),
            'filtered' => ($filter == 'search'),
            'text' => $text,
        ];
        echo $OUTPUT->render_from_template('mod_videolesson/loading_modal', []);
        echo $OUTPUT->render_from_template('mod_videolesson/manage_filterbar', $filterparam);
        // Table will be loaded via AJAX - container left empty for initial load.
        echo \html_writer::end_div();
        echo \html_writer::end_div();

        $PAGE->requires->js_call_amd('mod_videolesson/manage', 'init', []);
        echo $OUTPUT->footer();
    }
}
