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
 * Reports page.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
$cmid = required_param('id', PARAM_INT);
[$cm, $course, $context, $instance] = \mod_videolesson\local\access::activity($cmid, false, true);
$PAGE->set_url('/mod/videolesson/report.php', ['id' => $cmid]);
$PAGE->set_context($context); $PAGE->set_cm($cm, $course);
$PAGE->set_title(get_string('reports')); $PAGE->set_heading(format_string($course->fullname));
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('reports'));
$table = new html_table();
$table->head = [get_string('fullname'), get_string('cfwatched', 'mod_videolesson'),
    get_string('cfthreshold', 'mod_videolesson')];
$page = max(0, optional_param('page', 0, PARAM_INT));
$conditions = ['cmid' => $cmid, 'videouuid' => $instance->videouuid, 'revision' => $instance->mediarevision];
foreach ($DB->get_records('videolesson_progress', $conditions, 'userid', '*', $page * 50, 50) as $row) {
    $user = $DB->get_record('user', ['id' => $row->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
    if ($user) {
        $table->data[] = [s(fullname($user)), format_float($row->seconds, 2),
            format_float($row->duration > 0 ? min(100, 100 * $row->seconds / $row->duration) : 0, 1)];
    }
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($DB->count_records('videolesson_progress', $conditions), $page, 50, $PAGE->url);
echo $OUTPUT->footer();
