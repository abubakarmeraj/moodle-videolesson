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
 * Content instances table.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\local\table;

use html_writer;
use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Content instances table.
 *
 * @package    mod_videolesson
 */
class content_instances extends table_sql {
    /**
     * Constructor
     * @param string $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);
        // Define the list of columns to show.
        $columns = [
            'course',
            'title',
        ];
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headerstrings = get_strings(
            [
                'col_instance_course',
                'col_instance_title',
            ],
            'mod_videolesson'
        );
        $headers = [
            $headerstrings->col_instance_course,
            $headerstrings->col_instance_title,
        ];
        $this->define_headers($headers);
    }

    /**
     * Get the sort columns
     * @return array The sort columns
     */
    public function get_sort_columns() {
        $sortcolumns = parent::get_sort_columns();
        $sortcolumns['id'] = SORT_DESC;
        return $sortcolumns;
    }

    /**
     * Get the course column
     * @param \stdClass $log The log object
     * @return string The course column
     */
    public function col_course($log) {
        $course = get_course($log->course);

        $url = new moodle_url('/course/view.php', [
            'id' => $course->id,
        ]);

        return html_writer::link($url, $course->shortname, ['target' => '_blank']);
    }

    /**
     * Get the title column
     * @param \stdClass $log The log object
     * @return string The title column
     */
    public function col_title($log) {
        $cm = get_coursemodule_from_instance('videolesson', $log->id);
        $url = new moodle_url('/mod/videolesson/view.php', [
            'id' => $cm->id,
        ]);

        return html_writer::link($url, $log->name, ['target' => '_blank']);
    }
}
