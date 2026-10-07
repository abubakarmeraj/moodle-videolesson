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
 * Watch time class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\external;

// Quarantined V1 API: unregistered in services.php; cannot invoke vendor or legacy tracking code.
throw new \moodle_exception('cflegacydisabled', 'mod_videolesson');
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/videolesson/locallib.php');
require_once($CFG->dirroot . '/mod/videolesson/classes/util.php');
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use context_module;
use stdClass;

/**
 * Watch time external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watchtime extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'data' => new external_value(PARAM_TEXT, 'The data to save, encoded as a json array', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'progress' => new external_value(PARAM_FLOAT, 'Progress'),
            'notify' => new external_single_structure([
                'type' => new external_value(PARAM_TEXT, 'type', VALUE_OPTIONAL),
                'message' => new external_value(PARAM_TEXT, 'message', VALUE_OPTIONAL),
            ], 'notification', VALUE_OPTIONAL),
            'activity_info' => new external_value(PARAM_RAW, 'completion', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Executes the external function to save watch time.
     *
     * @param string $data JSON-encoded payload including cm and userid (see vplyr.js).
     */
    public static function execute(string $data) {
        global $USER;

        $params = external_api::validate_parameters(self::execute_parameters(), [
            'data' => $data,
        ]);

        $jsondata = json_decode($params['data'], true);
        if (!is_array($jsondata)) {
            throw new \invalid_parameter_exception('Invalid JSON in data parameter');
        }
        if (empty($jsondata['cm']) || !is_numeric($jsondata['cm'])) {
            throw new \invalid_parameter_exception('Missing or invalid course module id (cm)');
        }
        if (empty($jsondata['userid']) || (int) $jsondata['userid'] !== (int) $USER->id) {
            throw new \invalid_parameter_exception('Invalid user id in tracking payload');
        }

        $context = context_module::instance((int) $jsondata['cm']);
        self::validate_context($context);
        require_capability('mod/videolesson:view', $context);

        return self::user_usage($params['data']);
    }

    /**
     * Saves the watch time data to the database.
     *
     * @param string $data The data to save.
     * @return array The result of the watch time save.
     */
    private static function user_usage($data) {
        global $DB, $OUTPUT, $PAGE;

        $jsondata = json_decode($data, true);
        $obj = (object) $jsondata;
        $obj->data = $data;

        if ($jsondata['duration'] && $jsondata['source'] != MOD_VIDEOLESSON_SRC_GALLERY) {
            $externaltype = null;
            $externalvideoid = null;
            $sourceurl = null;
            $sourcehash = null;

            // For MOD_VIDEOLESSON_SRC_EXTERNAL, check if sourcedata is in normalized format (youtube:ID or vimeo:ID).
            if ($jsondata['source'] == MOD_VIDEOLESSON_SRC_EXTERNAL) {
                $sourcedata = $jsondata['sourcedata'];

                // Check if sourcedata is in normalized format (e.g., "youtube:VIDEO_ID").
                if (preg_match('/^(youtube|vimeo):([a-zA-Z0-9_-]+)$/i', $sourcedata, $matches)) {
                    // Create a normalized hash: "provider:videoid".
                    $externaltype = strtolower($matches[1]);
                    $externalvideoid = $matches[2];
                    $normalized = $externaltype . ':' . $externalvideoid;
                    $sourcehash = md5($normalized);
                    $sourceurl = null; // No source URL for normalized format.
                } else {
                    $sourceurl = $sourcedata;
                    $sourcehash = md5($sourceurl);

                    // Try to detect if it's a direct video URL.
                    if (\mod_videolesson\util::is_video_url($sourceurl)) {
                        $externaltype = 'direct_url';
                        $externalvideoid = null;
                    } else {
                        // Unsupported embed or other - no tracking.
                        $externaltype = null;
                        $externalvideoid = null;
                    }
                }
            } else {
                // For other sources (gallery), use MD5 of sourcedata.
                $sourceurl = $jsondata['sourcedata'];
                $sourcehash = md5($sourceurl);
                $externaltype = null;
                $externalvideoid = null;
            }

            $sourceinfo = $DB->get_record('videolesson_data_external', [
                'sourcehash' => $sourcehash,
            ]);

            if (!$sourceinfo) {
                $obj = new stdClass();
                $obj->sourcehash = $sourcehash;
                $obj->duration = $jsondata['duration'];
                $obj->externaltype = $externaltype;
                $obj->externalvideoid = $externalvideoid;
                $obj->sourceurl = $sourceurl;
                $obj->timecreated = time();
                $obj->timemodified = time();
                $DB->insert_record('videolesson_data_external', $obj);
            } else {
                // Update existing record with new fields if they're empty.
                $needsupdate = false;
                if (empty($sourceinfo->externaltype) && $externaltype) {
                    $sourceinfo->externaltype = $externaltype;
                    $needsupdate = true;
                }
                if (empty($sourceinfo->externalvideoid) && $externalvideoid) {
                    $sourceinfo->externalvideoid = $externalvideoid;
                    $needsupdate = true;
                }
                if (empty($sourceinfo->sourceurl) && $sourceurl) {
                    $sourceinfo->sourceurl = $sourceurl;
                    $needsupdate = true;
                }
                if (empty($sourceinfo->timecreated) || $sourceinfo->timecreated == 0) {
                    $sourceinfo->timecreated = time();
                    $needsupdate = true;
                }
                // Always update timemodified when duration is updated.
                $sourceinfo->timemodified = time();
                $sourceinfo->duration = $jsondata['duration'];
                $needsupdate = true;

                if ($needsupdate) {
                    $DB->update_record('videolesson_data_external', $sourceinfo);
                }
            }
        }

        // Normalize sourcedata for storage: YouTube/Vimeo use normalized format, external URLs use hash.
        $sourcedataforquery = \mod_videolesson\util::normalize_sourcedata_for_usage(
            $jsondata['source'],
            $obj->sourcedata
        );

        $record = $DB->get_record(
            'videolesson_usage',
            [
                'userid' => $obj->userid,
                'cm' => $obj->cm,
                'session' => $obj->session,
                'timestamp' => $obj->timestamp,
                'source' => $obj->source,
                'sourcedata' => $sourcedataforquery,
            ]
        );

        if ($record) {
            $record->watchduration = $jsondata['watchduration'];
            $record->timemodified = time();
            $record->data = $data;
            $DB->update_record('videolesson_usage', $record);
        } else {
            $obj->timecreated = time();
            $obj->sourcedata = $sourcedataforquery; // Normalized format for embeds, hash for external URLs.
            $DB->insert_record('videolesson_usage', $obj);
        }

        self::user_save_progress($jsondata);

        $result = [
            'progress' => $jsondata['totalprogess'],
        ];

        if (!$jsondata['notified']) {
            $activity = new \mod_videolesson\activity((int) $jsondata['cm'], (int)$jsondata['userid']);
            if ($activity->possible_mark_complete(false)) {
                $result['notify'] = [
                    'message' => get_string('ws:notify:reqcomplete', 'mod_videolesson'),
                    'type' => 'success',
                ];

                $outputrenderer = $PAGE->get_renderer('core', 'course');
                $modinfo = get_fast_modinfo($activity->course->id, $jsondata['userid']);
                $cm = $modinfo->get_cm($jsondata['cm']);
                $result['activity_info'] = self::render_activity_info_html(
                    $cm,
                    (int) $jsondata['userid'],
                    $activity->course,
                    $outputrenderer
                );
            }
        }

        return $result;
    }

    /**
     * Build activity completion HTML for AJAX refresh after watch progress.
     *
     * Uses activity_completion + activity_dates (Moodle 4.3+, required in 5.2).
     * Falls back to activity_information on Moodle 4.1–4.2.
     *
     * @param \cm_info $cm Course module info for the activity.
     * @param int $userid User id for completion/dates.
     * @param \stdClass $course Course record.
     * @param \renderer_base $outputrenderer Course renderer for template export.
     * @return string Rendered HTML for core_course/activity_info.
     */
    private static function render_activity_info_html(
        \cm_info $cm,
        int $userid,
        \stdClass $course,
        \renderer_base $outputrenderer,
    ): string {
        global $OUTPUT, $PAGE;

        if (!$PAGE->cm || $PAGE->cm->id != $cm->id) {
            $PAGE->set_cm($cm, $course);
        }

        $completion = \core_completion\cm_completion_details::get_instance($cm, $userid);
        $moduledates = \core\activity_dates::get_dates_for_module($cm, $userid);

        if (class_exists(\core_course\output\activity_completion::class)) {
            $activitycompletion = new \core_course\output\activity_completion($cm, $completion, false);
            $activitycompletiondata = (array) $activitycompletion->export_for_template($outputrenderer);

            $activitydatesoutput = new \core_course\output\activity_dates($moduledates);
            $activitydatesdata = (array) $activitydatesoutput->export_for_template($outputrenderer);

            $data = (object) array_merge($activitycompletiondata, $activitydatesdata);
        } else {
            $activityinfo = new \core_course\output\activity_information($cm, $completion, $moduledates);
            $data = $activityinfo->export_for_template($outputrenderer);
            $data->hascompletion = true;
        }

        return $OUTPUT->render_from_template('core_course/activity_info', $data);
    }

    /**
     * Saves the user progress data to the database.
     *
     * @param string $data The data to save.
     * @return array The result of the user progress save.
     */
    private static function user_save_progress($data) {
        global $DB;
        $time = time();

        // Normalize sourcedata for storage: YouTube/Vimeo use normalized format, external URLs use hash.
        if ($data['source'] != MOD_VIDEOLESSON_SRC_GALLERY) {
            $data['sourcedata'] = \mod_videolesson\util::normalize_sourcedata_for_usage($data['source'], $data['sourcedata']);
        }

        $userprogressobj = (object) [
            'cmid' => $data['cm'],
            'userid' => $data['userid'],
            'progress' => round($data['totalprogess'], 2),
            'sourcedata' => $data['sourcedata'],
            'timecreated' => $time,
            'timemodified' => $time,
        ];

        $userprogress = $DB->get_record(
            'videolesson_cm_progress',
            [
                'cmid' => $userprogressobj->cmid,
                'userid' => $userprogressobj->userid,
                'sourcedata' => $userprogressobj->sourcedata,
            ]
        );

        if ($userprogress) {
            $userprogressobj->id = $userprogress->id;
            $userprogress->timemodified = time();
            $DB->update_record('videolesson_cm_progress', $userprogressobj);
        } else {
            $DB->insert_record('videolesson_cm_progress', $userprogressobj);
        }
    }
}
