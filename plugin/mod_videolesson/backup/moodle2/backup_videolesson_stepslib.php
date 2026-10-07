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
 * Defines the complete structure for backup.
 *
 * @package    mod_videolesson
 * @subpackage backup-moodle2
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete videolesson structure for backup, with file and id annotations.
 */
class backup_videolesson_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the activity structure for backup.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {

        // Define each element separated.
        $videolesson = new backup_nested_element(
            'videolesson',
            ['id'],
            [
                'course',
                'name',
                'source',
                'videouuid',
                'tenantid',
                'mediarevision',
                'mediakind',
                'sourcedata',
                'options',
                'intro',
                'introformat',
                'completionprogress',
                'timemodified',
            ]
        );

        // Define sources.
        $videolesson->set_source_table('videolesson', ['id' => backup::VAR_ACTIVITYID]);

        // Define file annotations.
        $videolesson->annotate_files('mod_videolesson', 'intro', null); // Intro has no itemid.
        $videolesson->annotate_files('mod_videolesson', 'thumbnail', null); // Custom thumbnail (itemid 0 in storage).

        return $this->prepare_activity_structure($videolesson);
    }
}
