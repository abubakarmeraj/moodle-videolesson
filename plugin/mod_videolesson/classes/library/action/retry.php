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
 * Retry action handler
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\library\action;

/**
 * Handles video retry upload action
 */
class retry extends base {
    /**
     * Execute retry action
     */
    public function execute() {
        global $DB;

        $contenthash = required_param('contenthash', PARAM_TEXT);
        $viewurl = new \moodle_url('/mod/videolesson/library.php', [
            'action' => 'view',
            'contenthash' => $contenthash,
        ]);

        $this->require_capability();
        $this->require_sesskey($viewurl);

        // Initialize file storage.
        $fs = get_file_storage();

        // Query the file record from the database by contenthash and component.
        $filerecord = $DB->get_record('files', [
            'contenthash' => $contenthash,
            'component' => 'mod_videolesson',
        ], '*', IGNORE_MISSING);

        if (!$filerecord) {
            redirect(
                $viewurl,
                get_string('error:file_not_found', 'mod_videolesson'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        // Get the file object using the file record.
        $file = $fs->get_file(
            $filerecord->contextid,
            $filerecord->component,
            $filerecord->filearea,
            $filerecord->itemid,
            $filerecord->filepath,
            $filerecord->filename
        );

        if (!$file) {
            redirect(
                $viewurl,
                get_string('error:file_not_found', 'mod_videolesson'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        videolesson_sendfiletoaws($file->get_pathnamehash());

        // Mark failed for retry.
        $classconversion = new \mod_videolesson\conversion();
        $conversionrecord = $DB->get_record('videolesson_conv', [
            'contenthash' => $file->get_contenthash(),
        ]);

        if ($conversionrecord) {
            $conversionrecord->uploaded = $classconversion::CONVERSION_ACCEPTED;
            $DB->update_record('videolesson_conv', $conversionrecord);
        }

        redirect(
            $viewurl,
            get_string('retry:scheduled', 'mod_videolesson'),
            null,
            \core\output\notification::NOTIFY_SUCCESSf
        );
    }
}
