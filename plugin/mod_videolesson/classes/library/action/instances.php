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
 * Instances action handler
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\library\action;

/**
 * Handles video instances listing action
 */
class instances extends base {
    /**
     * Setup navigation for instances action
     */
    public function setup_navigation() {
        $this->setup_base_navigation();

        global $DB;

        $contenthash = required_param('contenthash', PARAM_TEXT);

        // Add "Video Library" link.
        $this->add_breadcrumb(get_string('header_manage_videos', 'mod_videolesson'), $this->listurl);

        // Add "Instances" link.
        $instancesurl = new \moodle_url('/mod/videolesson/library.php', [
            'action' => 'instances',
            'contenthash' => $contenthash,
        ]);
        $this->add_breadcrumb(get_string('header_video_instances', 'mod_videolesson'), $instancesurl);

        // Get video title.
        $videosource = new \mod_videolesson\videosource();
        $videotitle = $videosource->get_video_title($contenthash);
        if (empty($videotitle)) {
            // Fallback: try to get from videolesson_conv directly.
            $record = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash], 'name', IGNORE_MISSING);
            if ($record && !empty($record->name)) {
                $videotitle = format_string($record->name, true);
            } else {
                // Final fallback: use contenthash (truncated).
                $videotitle = substr($contenthash, 0, 20) . '...';
            }
        }

        // Add video title (no link, current page).
        $this->add_breadcrumb($videotitle);
    }

    /**
     * Execute instances action
     */
    public function execute() {
        global $OUTPUT, $PAGE;

        $download = optional_param('download', '', PARAM_ALPHA);
        $contenthash = required_param('contenthash', PARAM_TEXT);

        $pageurl = new \moodle_url('/mod/videolesson/library.php', [
            'action' => 'instances',
            'contenthash' => $contenthash,
        ]);

        $table = new \mod_videolesson\local\table\content_instances('uniqueid');
        $table->is_downloading($download);
        $where = 'sourcedata = :sourcedata';
        $param = ['sourcedata' => $contenthash];
        $table->set_sql(
            '*',
            "{videolesson}",
            $where,
            $param
        );

        $table->define_baseurl($pageurl);
        $heading = get_string('header_video_instances', 'mod_videolesson');
        $PAGE->set_title($heading);
        $PAGE->set_heading($heading);
        echo $OUTPUT->header();
        echo $this->render_breadcrumb();
        $table->out(10, true);
        echo $OUTPUT->footer();
    }
}
