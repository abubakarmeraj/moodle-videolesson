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
 * Moodle Vidoe aws
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use moodle_url;

/**
 * CM analytics class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cm_analytics extends analytics_base {
    /** @var string $sourcehash The source hash */
    public $sourcehash;
    /** @var int $cmid The course module ID */
    public $cmid;
    /** @var int $source The source */
    public $source;
    /** @var \core_course_list_element $course The course */
    public $course;

    /**
     * Constructor for cm_analytics.
     *
     * @param object $activity The activity object.
     * @param int $userid The user ID.
     */
    public function __construct($activity, $userid = 0) {
        global $DB;

        $this->sourcehash = $activity->moduleinstance->sourcedata;
        $this->cmid = $activity->cm->id;
        $this->source = $activity->moduleinstance->source;
        $this->user = $userid;

        // Normalize contenthash to match how watchtime.php stores it in videolesson_usage.
        // Embed videos: normalized format directly, External URLs: hash, Gallery: contenthash as-is.
        $this->contenthash = \mod_videolesson\util::normalize_sourcedata_for_usage($this->source, $this->sourcehash);

        if ($userid) {
            $user = $DB->get_record('user', ['id' => $userid]);
            $this->user = (object) [
                'fullname' => fullname($user),
                'email' => $user->email,
                'id' => $user->id,
            ];
        }

        $this->course = new \core_course_list_element($activity->course);

        foreach ($this->course->get_course_contacts() as $userid => $userdata) {
            $this->contacts[] = $userid;
        }

        $this->data = $this->get_records();
    }

    /**
     * Get records for the cm.
     *
     * @param bool $cm The course module.
     * @return array The records.
     */
    public function get_records($cm = false) {
        global $DB;

        $params = [
            'sourcedata' => $this->contenthash, // Use normalized contenthash instead of sourcehash.
            'cm' => $this->cmid,
        ];

        $where = "";
        if ($this->user) {
            $params['userid'] = $this->user->id;
            $where .= "AND userid = :userid";
        } else if ($this->contacts) {
            // Validate and sanitize contact IDs.
            $contactids = array_filter($this->contacts, function ($id) {
                return is_numeric($id) && $id > 0;
            });
            $contactids = array_map('intval', $contactids);

            if (!empty($contactids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($contactids, SQL_PARAMS_NAMED, 'contact', false);
                $where .= "AND userid $insql";
                $params = array_merge($params, $inparams);
            }
        }

        $sql = "SELECT * FROM {" . self::TABLE_USAGE . "} WHERE cm = :cm AND sourcedata = :sourcedata $where";
        $records = $DB->get_records_sql($sql, $params);

        return $records;
    }

    /**
     * Get video duration.
     *
     * @return int The video duration.
     */
    protected function get_video_duration() {
        return \mod_videolesson\util::get_video_duration($this->source, $this->sourcehash);
    }

    /**
     * Get timespent data.
     *
     * @return array The timespent data.
     */
    public function timespent_data() {
        $duration = [];
        foreach ($this->data as $record) {
            $sessiondata = json_decode($record->data);
            if ($sessiondata->watchduration) {
                $duration[] = $sessiondata->watchduration;
            }
        }

        $totaltime = array_sum($duration);
        $sessions = count($duration);

        return [
            'totaltime' => $totaltime,
            'sessions' => $sessions,
        ];
    }

    /**
     * Get average progress.
     *
     * @return array The average progress.
     */
    public function avg_progress() {
        // Todo.
    }

    /**
     * Get completion data.
     *
     * @return array The completion data.
     */
    public function completion_data() {
        global $DB;

        // Validate and sanitize contact IDs.
        $contactids = array_filter($this->contacts, function ($id) {
            return is_numeric($id) && $id > 0;
        });
        $contactids = array_map('intval', $contactids);

        $params = ['coursemoduleid' => $this->cmid, 'completionstate' => 1];
        $where = "coursemoduleid = :coursemoduleid AND completionstate = :completionstate";

        if (!empty($contactids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($contactids, SQL_PARAMS_NAMED, 'contact', false);
            $where .= " AND userid $insql";
            $params = array_merge($params, $inparams);
        }

        $sql = "SELECT COUNT(id) FROM {course_modules_completion} WHERE $where";
        $total = $DB->count_records_sql($sql, $params);

        $enrolledusers = get_enrolled_users(\context_course::instance($this->course->id));
        $cnt = 0;
        foreach ($enrolledusers as $userid => $user) {
            if (!in_array($userid, $this->contacts)) {
                $cnt++;
            }
        }

        return [
            'count' => $total,
            'total' => $cnt,
        ];
    }
}
