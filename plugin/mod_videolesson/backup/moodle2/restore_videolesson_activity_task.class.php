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
 * Restore task definitions for mod_videolesson.
 *
 * @package    mod_videolesson
 * @subpackage backup-moodle2
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/videolesson/backup/moodle2/restore_videolesson_stepslib.php');

/**
 * Videolesson restore task that provides all the settings and steps to perform one
 * complete restore of the activity.
 */
class restore_videolesson_activity_task extends restore_activity_task {
    /**
     * Define (add) particular settings this activity can have.
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have.
     */
    protected function define_my_steps() {
        // Videolesson only has one structure step.
        $this->add_step(new restore_videolesson_activity_structure_step('videolesson_structure', 'videolesson.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder.
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('videolesson', ['intro'], 'videolesson');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder.
     */
    public static function define_decode_rules() {
        $rules = [];
        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the restore_logs_processor when restoring
     * assign logs. It must return one array
     * of restore_log_rule objects.
     *
     * @return array of restore_log_rule
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('videolesson', 'view', 'view.php?id={course_module}', '{videolesson}');

        return $rules;
    }
}
