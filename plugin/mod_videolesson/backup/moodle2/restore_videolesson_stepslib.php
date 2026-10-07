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
 * Structure step to restore one videolesson activity.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the restore steps that will be used by the restore_videolesson_activity_task.
 */
class restore_videolesson_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the activity structure for restore.
     *
     * @return restore_path_element
     */
    protected function define_structure() {

        $paths = [];
        $paths[] = new restore_path_element('videolesson', '/activity/videolesson');

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the videolesson instance.
     *
     * @param array $data The data from the XML node.
     */
    protected function process_videolesson($data) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        // A UUID in backup XML is not a grant. Revalidate against a source reference this
        // restorer can manage in the current tenant. Otherwise retain identity only for relink UX.
        $data->trustedref = 0;
        if (!empty($data->videouuid) && ($data->tenantid ?? '') === \mod_videolesson\local\providers::tenant()) {
            foreach ($DB->get_records('videolesson', ['videouuid' => $data->videouuid,
                    'tenantid' => $data->tenantid, 'trustedref' => 1], 'id', '*', 0, 100) as $source) {
                try {
                    \mod_videolesson\local\library::select((int)$source->course, (int)$source->id);
                    $data->trustedref = 1;
                    break;
                } catch (moodle_exception $e) {
                    // No authorized ready source: explicit local relink required.
                }
            }
        }

        // Insert the videolesson db record.
        $newitemid = $DB->insert_record('videolesson', $data);
        $this->apply_activity_instance($newitemid);
        $transaction->allow_commit();
        } catch (Throwable $error) {
            $transaction->rollback($error);
        }
    }

    /**
     * After execute.
     */
    protected function after_execute() {
        // Add videolesson related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_videolesson', 'intro', null);
        $this->add_related_files('mod_videolesson', 'thumbnail', null);
    }
}
