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
 * Delete action handler
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\library\action;

/**
 * Handles video delete action
 */
class delete extends base {
    /**
     * Execute delete action
     */
    public function execute() {
        $contenthash = required_param('contenthash', PARAM_TEXT);
        $viewurl = new \moodle_url('/mod/videolesson/library.php', [
            'action' => 'view',
            'contenthash' => $contenthash,
        ]);

        $this->require_capability();
        $this->require_sesskey($viewurl);

        $videosource = new \mod_videolesson\videosource();
        $result = $videosource->output_delete($contenthash);

        if ($result['success']) {
            redirect(
                $this->baseurl,
                get_string('success:delete', 'mod_videolesson'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            $errors = \html_writer::alist($result['errors'], null, 'ul');
            redirect($viewurl, $errors, null, \core\output\notification::NOTIFY_WARNING);
        }
    }
}
