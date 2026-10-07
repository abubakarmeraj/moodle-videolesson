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
 * Activity custom completion subclass for the videolesson activity.
 *
 * Class for defining mod_videolesson's custom completion rules and fetching the completion statuses
 * of the custom completion rules for a given videolesson instance and a user.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\completion;

defined('MOODLE_INTERNAL') || die;

global $CFG;

use core_completion\activity_custom_completion;

require_once($CFG->dirroot . '/mod/videolesson/locallib.php');
require_once($CFG->dirroot . '/mod/videolesson/classes/util.php');
/**
 * Custom completion class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $userid = $this->userid;
        $cm = $this->cm;

        $instance = $DB->get_record('videolesson', ['id' => $cm->instance], '*', MUST_EXIST);
        $requiredprogress = $this->cm->customdata['customcompletionrules']['completionprogress'];

        if (!$requiredprogress) {
            return COMPLETION_COMPLETE;
        }

        return \mod_videolesson\local\watch::complete($instance, (int)$cm->id, (int)$userid)
            ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Get normalized hash for sourcedata (for embedded videos, uses provider:videoid)
     *
     * @param object $instance The videolesson instance record
     * @return string The hashed sourcedata
     */
    private function get_normalized_sourcedata_hash($instance) {
        return \mod_videolesson\util::normalize_sourcedata_for_usage($instance->source, $instance->sourcedata);
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionprogress'];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $rules = $this->cm->customdata['customcompletionrules'];
        return [
            'completionprogress' => get_string('completiondetail:progressdesc', 'mod_videolesson', $rules['completionprogress']),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionprogress',
        ];
    }
}
