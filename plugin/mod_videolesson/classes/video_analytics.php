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
 * Video analytics
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use moodle_url;

/**
 * Video analytics class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class video_analytics extends analytics_base {
    /** @var object $video The video object. */
    public $video;

    /** @var array $contacts The contacts array. */
    public $contacts = [];

    /** @var array $data The data array. */
    public $data = [];

    /** @var string $contefcontactsnthash The content hash. */
    public $contenthash;

    /**
     * Constructor for video_analytics.
     *
     * @param string $contenthash The content hash.
     */
    public function __construct($contenthash) {
        global $DB;
        // The contenthash passed here is from videolesson.sourcedata.
        // For embed videos, it's normalized format. For external URLs, it's the full URL.
        // We need to normalize it to match videolesson_usage.sourcedata format.
        // Try to detect source type from videolesson table (get first matching record).
        $instances = $DB->get_records('videolesson', ['sourcedata' => $contenthash], 'id', 'id, source', 0, 1);
        if (!empty($instances)) {
            $instance = reset($instances);
            // Normalize to match videolesson_usage.sourcedata format.
            $this->contenthash = \mod_videolesson\util::normalize_sourcedata_for_usage($instance->source, $contenthash);
        } else {
            // Fallback: try to detect if it's normalized embed format or hash it.
            if (preg_match('/^(youtube|vimeo):([a-zA-Z0-9_-]+)$/i', $contenthash)) {
                // Already normalized embed format.
                $this->contenthash = $contenthash;
            } else {
                // Assume external URL or unknown - hash it.
                $this->contenthash = md5($contenthash);
            }
        }

        // Try to get video from AWS data first.
        $this->video = $DB->get_record('videolesson_data', ['contenthash' => $this->contenthash]);

        // If not found, try external sources.
        if (!$this->video) {
            // For external videos, contenthash might be the sourcehash.
            $external = $DB->get_record('videolesson_data_external', ['sourcehash' => $this->contenthash]);
            if ($external) {
                // Create a compatible object structure.
                $this->video = (object) [
                    'contenthash' => $external->sourcehash,
                    'duration' => $external->duration,
                    'externaltype' => $external->externaltype,
                    'externalvideoid' => $external->externalvideoid,
                    'sourceurl' => $external->sourceurl,
                ];
            }
        }

        // Get all course contacts from all courses that use this video.
        // Support all source types, not just 'aws'.
        $instances = $DB->get_records('videolesson', ['sourcedata' => $this->contenthash]);
        foreach ($instances as $instance) {
            $cm = get_coursemodule_from_instance('videolesson', $instance->id);
            if ($cm) {
                $courserecord = get_course($cm->course);
                if ($courserecord) {
                    $course = new \core_course_list_element($courserecord);
                    foreach ($course->get_course_contacts() as $userid => $userdata) {
                        if (!in_array($userid, $this->contacts)) {
                            $this->contacts[] = $userid;
                        }
                    }
                }
            }
        }

        $this->data = $this->get_records();
    }

    /**
     * Get records for the video.
     *
     * @param int $userid The user ID.
     * @return array The records.
     */
    public function get_records($userid = 0) {
        global $DB;

        $params = [
            'sourcedata' => $this->contenthash,
        ];

        $where = "";
        if ($userid) {
            $params['userid'] = $userid;
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

        $sql = "SELECT * FROM {" . self::TABLE_USAGE . "} WHERE sourcedata = :sourcedata $where";
        $records = $DB->get_records_sql($sql, $params);

        return $records;
    }

    /**
     * Get video duration for the video.
     *
     * @return int The video duration.
     */
    protected function get_video_duration() {
        global $DB;

        // Get video duration from video record or from session data.
        if ($this->video && isset($this->video->duration)) {
            return round($this->video->duration);
        } else if (!empty($this->data)) {
            // Try to get duration from first record's session data.
            $firstrecord = reset($this->data);
            $firstdata = json_decode($firstrecord->data);
            if (isset($firstdata->duration)) {
                return round($firstdata->duration);
            }
        }

        // Fallback: try to get from videolesson_data_external.
        $external = $DB->get_record('videolesson_data_external', ['sourcehash' => $this->contenthash]);
        if ($external && isset($external->duration)) {
            return round($external->duration);
        }

        return 0;
    }

    /**
     * Get completion data for the video.
     *
     * @return array The completion data.
     */
    public function completion_data() {
        global $DB;

        // Get all course modules that use this video (all source types).
        $instances = $DB->get_records('videolesson', ['sourcedata' => $this->contenthash]);
        $cmids = [];
        foreach ($instances as $instance) {
            $cm = get_coursemodule_from_instance('videolesson', $instance->id);
            if ($cm) {
                $cmids[] = $cm->id;
            }
        }

        if (empty($cmids)) {
            return [
                'count' => 0,
                'total' => 0,
            ];
        }

        // Get contacts from all courses.
        $contactids = array_filter($this->contacts, function ($id) {
            return is_numeric($id) && $id > 0;
        });
        $contactids = array_map('intval', $contactids);

        $params = ['completionstate' => 1];
        $where = "completionstate = :completionstate";

        if (!empty($contactids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($contactids, SQL_PARAMS_NAMED, 'contact', false);
            $where .= " AND userid $insql";
            $params = array_merge($params, $inparams);
        }

        [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $where .= " AND coursemoduleid $cmsql";
        $params = array_merge($params, $cmparams);

        $sql = "SELECT COUNT(id) FROM {course_modules_completion} WHERE $where";
        $total = $DB->count_records_sql($sql, $params);

        // Count enrolled users across all courses (excluding contacts).
        $enrolledcount = 0;
        foreach ($instances as $instance) {
            $cm = get_coursemodule_from_instance('videolesson', $instance->id);
            if ($cm) {
                $enrolledusers = get_enrolled_users(\context_course::instance($cm->course));
                foreach ($enrolledusers as $userid => $user) {
                    if (!in_array($userid, $this->contacts)) {
                        $enrolledcount++;
                    }
                }
            }
        }

        return [
            'count' => $total,
            'total' => $enrolledcount,
        ];
    }
}
