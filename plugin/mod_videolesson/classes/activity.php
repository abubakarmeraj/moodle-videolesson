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
 * Activity class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;
defined('MOODLE_INTERNAL') || die;
global $CFG;

use cache;
use context_module;
use context_course;

require_once($CFG->dirroot . '/mod/videolesson/lib.php');
require_once($CFG->dirroot . '/mod/videolesson/locallib.php');
require_once($CFG->dirroot . '/mod/videolesson/classes/util.php');
require_once($CFG->libdir . '/completionlib.php');
/**
 * Activity class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity {
    /** @var \stdClass Course module record for this videolesson instance. */
    public $cm = null;
    /** @var \stdClass Course record containing this activity. */
    public $course = null;
    /** @var \stdClass Row from {videolesson} for this course module instance. */
    public $moduleinstance = null;
    /** @var \context_module Module context for capability checks and files. */
    public $modulecontext = null;
    /** @var \stdClass|string|null Gallery conversion row (MOD_VIDEOLESSON_SRC_GALLERY) or raw sourcedata for other sources. */
    public $videofile = null;
    /** @var int Video source constant (e.g. MOD_VIDEOLESSON_SRC_GALLERY, MOD_VIDEOLESSON_SRC_EXTERNAL). */
    public $source = null;
    /** @var string|null Raw sourcedata from the module instance (URL, embed id, content hash, etc.). */
    public $sourcedata = null;
    /** @var \stdClass|null Decoded JSON from the module instance options field. */
    public $options = null;
    /** @var int User id this activity state is scoped to (constructor default: current user). */
    public $userid = null;
    /** @var array|null Cached payload from {@see self::video()} for the player. */
    public $videodata = null;
    /** @var array|null Cached result from {@see self::get_watch_data()} (progress, ranges, etc.). */
    public $watchdata = null;
    /** @var bool True if the scoped user has the student role in the course context. */
    public $isstudent = false;

    /**
     * Constructor for the activity class.
     *
     * @param int $cmid Course module ID.
     * @param int|null $userid User ID (optional).
     */
    public function __construct($cmid, $userid = null) {
        global $DB, $USER;

        if ($userid == null) {
            $this->userid = $USER->id;
        } else {
            $this->userid = $userid;
        }

        $this->cm = get_coursemodule_from_id('videolesson', $cmid, 0, false, MUST_EXIST);
        $this->course = get_course($this->cm->course);
        $this->moduleinstance = $DB->get_record('videolesson', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $this->modulecontext = context_module::instance($cmid);
        $this->source = $this->moduleinstance->source;
        $this->sourcedata = $this->moduleinstance->sourcedata;

        if ($this->source == MOD_VIDEOLESSON_SRC_GALLERY) {
            $this->videofile = $DB->get_record('videolesson_conv', ['contenthash' => $this->moduleinstance->sourcedata]);
        } else {
            $this->videofile = $this->moduleinstance->sourcedata;
        }

        $this->options = json_decode($this->moduleinstance->options);

        $context = context_course::instance($this->course->id);
        $role = $DB->get_record('role', ['shortname' => 'student']);
        $this->isstudent = user_has_role_assignment($this->userid, $role->id, $context->id);
    }

    /**
     * Gets the course module.
     *
     * @return object The course module object.
     */
    public function get_cm() {
        return $this->cm;
    }

    /**
     * Gets the course details.
     *
     * @return object The course object.
     */
    public function get_course() {
        return $this->course;
    }

    /**
     * Gets the instance of the module.
     *
     * @return object The module instance.
     */
    public function get_instance() {
        return $this->moduleinstance;
    }

    /**
     * Gets the context of the module.
     *
     * @return object The context object.
     */
    public function get_context() {
        return $this->modulecontext;
    }

    /**
     * Gets the video file.
     *
     * @return mixed The video file object or data.
     */
    public function videofile() {
        return $this->videofile;
    }

    /**
     * Retrieves video data based on the source.
     *
     * @return array Video data.
     */
    public function video() {

        if ($this->videodata) {
            return $this->videodata;
        }

        switch ($this->source) {
            case MOD_VIDEOLESSON_SRC_EXTERNAL:
                $videodata = $this->get_external_video_data();
                break;

            default:
                $videodata = $this->get_gallery_video_data();
                break;
        }

        $videodata['chart'] = true;
        $videodata['showchart'] = $this->role_exluded() ? false : true; // Only show to students.
        $this->videodata = $videodata;
        return $videodata;
    }

    /**
     * Get video data for external video sources (direct URLs, YouTube/Vimeo, or unsupported embeds).
     *
     * @return array Video data for external sources.
     */
    private function get_external_video_data() {
        global $CFG;
        $sourcedata = $this->moduleinstance->sourcedata;

        // Check if sourcedata is in normalized format (YouTube/Vimeo).
        if (preg_match('/^(youtube|vimeo):([a-zA-Z0-9_-]+)$/i', $sourcedata, $matches)) {
            // Handle YouTube/Vimeo videos.
            $externaltype = strtolower($matches[1]);
            $externalvideoid = $matches[2];
            $externalembedurl = null;
            $poster = '';

            if ($externaltype === 'youtube') {
                $externalembedurl = \mod_videolesson\util::get_youtube_embed_url($externalvideoid, $CFG->wwwroot);
                $poster = \mod_videolesson\util::get_youtube_thumbnail($externalvideoid);
            } else if ($externaltype === 'vimeo') {
                $externalembedurl = \mod_videolesson\util::get_vimeo_embed_url($externalvideoid);
            }

            return [
                'videoid' => 0,
                'title' => '',
                'provider' => MOD_VIDEOLESSON_SRC_EXTERNAL,
                'sourcedata' => $sourcedata,
                'subtitles' => [],
                'sourceurl' => $externalembedurl ?: '',
                'poster' => $poster,
                'type' => 'text/html',
                'external' => true,
                'externaltype' => $externaltype,
                'externalvideoid' => $externalvideoid,
                'externalembedurl' => $externalembedurl,
                'external_embed' => true,
                'external_file' => false,
                'duration' => \mod_videolesson\util::get_video_duration($this->source, $this->sourcedata),
            ];
        } else if (stripos($sourcedata, '<iframe') !== false) {
            // Handle unsupported embed providers (iframe but not YouTube/Vimeo).
            // Extract URL from iframe if possible.
            $url = \mod_videolesson\util::extract_url_from_embed_code($sourcedata);
            $sourceurl = $url ?: $sourcedata;

            // For unsupported embeds, store hash in sourcedata (for identification).
            // And keep the full iframe HTML separate for direct output.
            $sourcedatahash = md5($sourcedata);

            return [
                'videoid' => 0,
                'title' => '',
                'provider' => MOD_VIDEOLESSON_SRC_EXTERNAL,
                'subtitles' => [],
                'sourcedata' => $sourcedatahash, // Store hash instead of full HTML.
                'sourceurl' => $sourceurl,
                'poster' => '',
                'type' => 'text/html',
                'external' => true,
                'external_embed' => true,
                'external_file' => false,
                // Don't set externaltype/externalvideoid for unsupported embeds.
                // This ensures template conditionals work correctly.
                'externalembedurl' => $sourceurl, // Use extracted URL or original.
                'unsupported_embed' => true, // Flag to indicate unsupported embed.
                'unsupported_embed_html' => $sourcedata, // Store full iframe HTML for direct output.
                'duration' => \mod_videolesson\util::get_video_duration($this->source, $this->sourcedata),
            ];
        } else {
            // Handle direct video file URLs.
            return [
                'videoid' => 0,
                'title' => '',
                'provider' => MOD_VIDEOLESSON_SRC_EXTERNAL,
                'subtitles' => [],
                'sourcedata' => $sourcedata,
                'sourceurl' => $sourcedata,
                'poster' => '',
                'type' => \mod_videolesson\util::extract_media_type($sourcedata),
                'external' => true,
                'external_file' => true,
                'external_embed' => false,
                'duration' => \mod_videolesson\util::get_video_duration($this->source, $this->sourcedata),
            ];
        }
    }

    /**
     * Get video data for gallery video sources (AWS uploaded videos).
     *
     * @return array Video data for gallery sources.
     */
    private function get_gallery_video_data() {
        $videosource = new \mod_videolesson\videosource();
        $videodata = $videosource->get_video_data(
            $this->moduleinstance->sourcedata,
            $this->modulecontext
        );

        $videodata['videoid'] = $this->videofile->id;
        $videodata['title'] = $this->videofile->name;
        $videodata['sourcedata'] = $this->moduleinstance->sourcedata;
        $videodata['aws'] = true;
        $videodata['duration'] = \mod_videolesson\util::get_video_duration($this->source, $this->sourcedata);

        return $videodata;
    }

    /**
     * Checks if the video is ready for playback.
     *
     * @return bool True if the video is ready, false otherwise.
     */
    public function is_video_ready() {
        if ($this->source != MOD_VIDEOLESSON_SRC_GALLERY) {
            return true;
        }
        return $this->videofile->status == \mod_videolesson\conversion::CONVERSION_FINISHED;
    }

    /**
     * Checks if there is an error with the video.
     *
     * @return bool True if there is an error, false otherwise.
     */
    public function is_video_error() {

        if ($this->source != MOD_VIDEOLESSON_SRC_GALLERY) {
            return false;
        } else if ($this->source == MOD_VIDEOLESSON_SRC_GALLERY && !$this->videofile) {
            return true;
        }

        return $this->videofile->transcoder_status == \mod_videolesson\conversion::CONVERSION_ERROR;
    }

    /**
     * Checks if there is no video data available.
     *
     * @return bool True if no video data is found, false otherwise.
     */
    public function no_video_data() {
        return $this->source == MOD_VIDEOLESSON_SRC_GALLERY && !$this->videofile;
    }

    /**
     * Prepares parameters for rendering the video template.
     *
     * @return array Template parameters for video playback.
     */
    public function templateparams() {
        $watchdata = $this->get_watch_data();
        $params = $this->video();
        $params['watchdata'] = json_encode($watchdata['simplewatchdata']);
        $params['incomplete'] = $this->is_complete() ? false : true;

        // Add data attributes for large/complex data to reduce js_call_amd payload.
        $params['data_attributes'] = $this->get_video_data_attributes();

        return $params;
    }

    /**
     * Checks if the current user's role is excluded from certain functionalities.
     *
     * @return bool True if the role is excluded, false otherwise.
     */
    public function role_exluded() {
        $exluderoles = get_config('mod_videolesson', 'exluderoles');

        if (!$this->isstudent && $exluderoles) {
            return true;
        }

        return false;
    }

    /**
     * Initializes JavaScript modules for the activity.
     */
    public function js_amd() {
        global $PAGE;

        if ($this->is_video_error()) {
            return;
        }

        if (!$this->is_video_ready()) {
            $params = ['contenthash' => $this->sourcedata];
            $params['cmid'] = $this->cm->id;
            $PAGE->requires->js_call_amd('mod_videolesson/processing', 'init', [$params]);
            return;
        }

        $geoinfo = $this->get_geo_info();
        $videodata = $this->video();
        $watchdata = $this->get_watch_data();
        $playerconfig = $this->get_player_config();

        $requiresyoutube = !empty($videodata['external'])
            && ($videodata['externaltype'] ?? '') === 'youtube'
            && !empty($videodata['externalembedurl']);
        $requiresvimeo = !empty($videodata['external'])
            && ($videodata['externaltype'] ?? '') === 'vimeo'
            && !empty($videodata['externalembedurl']);
        videolesson_register_player_page_requires($PAGE, $requiresyoutube, $requiresvimeo);

        $params = $this->build_js_params($geoinfo, $videodata, $watchdata, $playerconfig);
        $jsparams = [
            'userid' => $params['userid'],
            'cm' => $params['cm'],
            'session' => $params['session'],
            'ip' => $params['ip'],
            'city' => $params['city'],
            'country' => $params['country'],
            'tracking' => $params['tracking'],
            'notified' => $params['notified'],
            'student' => $params['student'],
            'disableseek' => $params['disableseek'],
            'allowrewind' => $params['allowrewind'],
            'pip' => $params['pip'],
            'speed' => $params['speed'],
            'ishls' => $params['ishls'],
            'duration' => $params['duration'],
            'leftOff' => $params['leftOff'],
            'progress' => $params['progress'],
            'max' => $params['max'],
            // Essential video identifiers only.
            'videoid' => $videodata['videoid'] ?? 0,
            'provider' => $videodata['provider'] ?? '',
            'externaltype' => $videodata['externaltype'] ?? null,
            'externalvideoid' => $videodata['externalvideoid'] ?? null,
        ];

        $PAGE->requires->js_call_amd('mod_videolesson/vplyr', 'init', [$jsparams]);
    }

    /**
     * Retrieves and caches geo information for the current user.
     *
     * Populated from MaxMind GeoIP2 ($CFG->geoip2file) when configured; otherwise blank fields.
     * Results are stored in a session-mode MUC cache (keyed by client IP) for the current Moodle session.
     *
     * @return \stdClass Geo information (city, country_code, etc.).
     */
    private function get_geo_info() {
        $ip = getremoteaddr();
        $cache = cache::make('mod_videolesson', 'geoinfo');
        $cachekey = sha1($ip);
        $geoinfo = $cache->get($cachekey);
        if ($geoinfo === false) {
            $geoinfo = util::geoinfo($ip);
            $cache->set($cachekey, $geoinfo);
        }

        return $geoinfo;
    }

    /**
     * Loads admin override settings for player configuration.
     *
     * @return array Player configuration with seek, speed, and pip settings.
     */
    private function get_player_config() {
        $overrideseek = get_config('mod_videolesson', 'overrideseekbehavior');
        $overridespeed = get_config('mod_videolesson', 'overridedisablespeed');
        $overridepip = get_config('mod_videolesson', 'overridedisablepip');

        // Determine seek options.
        $seekoption = $this->options->player->seek ?? 0;

        if ($overrideseek) {
            // Admin override is active.
            $disableseek = ($overrideseek > 1);
            $allowrewind = ($overrideseek == 3);
        } else {
            // Use activity settings.
            $disableseek = (bool) $seekoption;
            $allowrewind = ($seekoption == 2);
        }

        if ($this->isstudent) {
            // Determine speed option.
            $speed = $overridespeed ? false : empty($this->options->player->disablespeed);

            // Determine PiP option.
            $pip = $overridepip ? false : empty($this->options->player->disablepip);
        } else {
            $pip = true;
            $speed = true;
        }

        return [
            'disableseek' => $disableseek,
            'allowrewind' => $allowrewind,
            'pip' => $pip,
            'speed' => $speed,
        ];
    }

    /**
     * Get video data attributes for storing in HTML data attributes.
     * This reduces the payload size for js_call_amd.
     *
     * @return array Data attributes array.
     */
    private function get_video_data_attributes() {
        $videodata = $this->video();

        return [
            'sourceurl' => $videodata['sourceurl'] ?? '',
            'sourcedata' => $videodata['sourcedata'] ?? '',
            'poster' => $videodata['poster'] ?? '',
            'type' => $videodata['type'] ?? '',
            'subtitles' => htmlspecialchars(json_encode($videodata['subtitles'] ?? []), ENT_QUOTES, 'UTF-8'),
            'title' => $videodata['title'] ?? '',
            'external' => $videodata['external'] ?? false,
            'external_embed' => $videodata['external_embed'] ?? false,
            'external_file' => $videodata['external_file'] ?? false,
        ];
    }

    /**
     * Builds the JavaScript parameters array for video player initialization.
     *
     * @param object $geoinfo Geo information object.
     * @param array $videodata Video data array.
     * @param array $watchdata Watch data array.
     * @param array $playerconfig Player configuration array.
     * @return array Complete parameters for JavaScript init (includes session: opaque id stable for this Moodle login session).
     */
    private function build_js_params($geoinfo, $videodata, $watchdata, $playerconfig) {
        $ip = getremoteaddr();
        $city = $geoinfo ? ($geoinfo->city ?? '') : '';
        $country = $geoinfo ? ($geoinfo->country_code ?? '') : '';

        $trackcache = cache::make('mod_videolesson', 'player_tracking');
        $sid = $trackcache->get('session_uuid');
        if ($sid === false) {
            $sid = \core\uuid::generate();
            $trackcache->set('session_uuid', $sid);
        }

        return [
            'userid' => $this->userid,
            'session' => $sid,
            'ip' => $ip,
            'city' => $city,
            'country' => $country,
            'cm' => $this->cm->id,
            'ishls' => \mod_videolesson\util::is_hls($videodata['sourceurl']),
            'disableseek' => $playerconfig['disableseek'],
            'allowrewind' => $playerconfig['allowrewind'],
            'pip' => $playerconfig['pip'],
            'speed' => $playerconfig['speed'],
            'duration' => $videodata['duration'],
            'leftOff' => $this->role_exluded() ? false : $watchdata['resume'],
            'progress' => $watchdata['progress'],
            'tracking' => true,
            'notified' => $this->is_complete(),
            'max' => $watchdata['max'],
            'student' => $this->isstudent,
        ];
    }

    /**
     * Sets activity header descriptions and adds notifications if the video is not ready or has errors.
     */
    public function activity_header() {
        global $PAGE;

        if (!$this->is_video_ready()) {
            $PAGE->activityheader->set_description('');

            if (!$this->no_video_data()) {
                \core\notification::add(
                    get_string('activity:processing', 'mod_videolesson'),
                    \core\notification::ERROR
                );
            }

            $PAGE->activityheader->set_hidecompletion(true);
        }
    }

    /**
     * Renders content based on the video�s state, displaying error messages or loading states as needed.
     */
    public function content() {
        global $OUTPUT;

        if ($this->source != MOD_VIDEOLESSON_SRC_EXTERNAL) {
            $access = new \mod_videolesson\access();
            if ($access->restrict()) {
                return $access->get_restrict_activity_message();
            }
        }

        if ($this->no_video_data()) {
            $msg = $OUTPUT->pix_icon('i/error', 'Error', 'mod_videolesson', []);
            $msg .= get_string('activity:notfound', 'mod_videolesson');
            return $OUTPUT->notification($msg, \core\output\notification::NOTIFY_ERROR, false);
        } else if ($this->is_video_error()) {
            $msg = $OUTPUT->pix_icon('i/error', 'Error', 'mod_videolesson', []);
            $msg .= get_string('player:video:error', 'mod_videolesson');
            return $OUTPUT->notification($msg, \core\output\notification::NOTIFY_ERROR, false);
        } else if (!$this->is_video_ready()) {
            return $OUTPUT->render_from_template('mod_videolesson/processing_message', []);
        }

        return $OUTPUT->render_from_template('mod_videolesson/player', $this->templateparams());
    }

    /**
     * Checks if the current activity is complete based on custom completion rules.
     *
     * This function determines whether an activity is complete by evaluating user progress
     * against custom completion criteria defined in the course module settings. The progress
     * is checked against the stored data, either directly or by hashing, depending on the source.
     *
     * @return bool Returns true if the activity is complete based on the custom rules; otherwise, false.
     */
    /**
     * Get normalized sourcedata for usage tables (embed videos: normalized format, external URLs: hash)
     *
     * @return string The normalized sourcedata matching videolesson_usage.sourcedata format
     */
    private function get_normalized_sourcedata_hash() {
        // Use normalize_sourcedata_for_usage() to match videolesson_usage.sourcedata format.
        // Embed videos: normalized format, External URLs: hash, Gallery: contenthash.
        return \mod_videolesson\util::normalize_sourcedata_for_usage($this->source, $this->sourcedata);
    }
    /**
     * Checks if the current activity is complete based on custom completion rules.
     *
     * @return bool Returns true if the activity is complete based on the custom rules; otherwise, false.
     */
    public function is_complete() {
        global $DB;

        $cminfo = videolesson_get_coursemodule_info($this->cm);
        $customrules = isset($cminfo->customdata['customcompletionrules']) ? $cminfo->customdata['customcompletionrules'] : [];

        $sourcedata = $this->get_normalized_sourcedata_hash();

        // Use pre calculated progress.
        $userprogress = $DB->get_record(
            'videolesson_cm_progress',
            [
                'cmid' => $this->cm->id,
                'userid' => $this->userid,
                'sourcedata' => $sourcedata,
            ]
        );

        if (isset($customrules['completionprogress']) && $customrules['completionprogress']) {
            if ($userprogress && ($userprogress->progress >= $customrules['completionprogress'])) {
                return true;
            }
        }

        // Return false if the completion criteria are not met.
        return false;
    }

    /**
     * Attempts to mark the activity as complete for the current user if conditions are met.
     *
     * This function checks if the activity is complete and, if so, attempts to mark it as completed
     * in Moodle's completion system. It considers whether completion tracking is enabled and if the
     * activity's completion is set to automatic.
     *
     * @param bool $notified Determines if the user has been notified of the completion.
     *                       If false, it checks the current completion state before updating.
     * @return bool Returns true if the activity was successfully marked as complete or
     *              was already complete, otherwise returns false.
     */
    public function possible_mark_complete($notified = true) {
        if ($this->is_complete()) {
            // Invalidate course cache to ensure completion status is refreshed.
            // This is necessary because completion data may be cached.
            $this->course->cacherev = time();
            $completion = new \completion_info($this->course);
            if ($completion->is_enabled($this->cm) && $this->cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
                // Force fresh completion data by passing false to get_data.
                $completiondata = $completion->get_data($this->cm, false, $this->userid);

                if (!$notified && $completiondata->completionstate == COMPLETION_COMPLETE) {
                    return true;
                }

                if ($completiondata->completionstate == COMPLETION_INCOMPLETE) {
                    $completion->update_state($this->cm, COMPLETION_COMPLETE, $this->userid);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Retrieves and processes watch data for the video.
     *
     * @return array Processed watch data including watched and unwatched segments, progress, and execution time.
     */
    public function get_watch_data() {
        global $DB;

        if ($this->watchdata) {
            return $this->watchdata;
        }

        $exectime = microtime(true);

        $sourcedatahash = $this->get_normalized_sourcedata_hash();

        $records = $DB->get_records('videolesson_usage', [
            'userid' => $this->userid,
            'cm' => $this->cm->id,
            'source' => $this->source,
            'sourcedata' => $sourcedatahash,
        ]);

        // Use raw sourcedata for duration calculation.
        $videoduration = \mod_videolesson\util::get_video_duration($this->source, $this->sourcedata);
        $timelinearray = range(1, $videoduration);
        $simplewatchdata = [];

        // Track the furthest point reached across all watch sessions.
        $resume = 0;  // Furthest resume point (same as max, kept for backward compatibility).
        $max = 0;     // Furthest point reached in the video.

        // Process all watch records to extract ranges and find furthest point.
        foreach ($records as $record) {
            $data = json_decode($record->data);

            $allranges = $data->ranges;
            foreach ($allranges as $ranges) {
                foreach ($ranges as $range) {
                    $simplewatchdata[] = [round($range[0]), round($range[1])];
                    // Track the furthest point reached (both resume and max should reflect this).
                    $resume = max($resume, $range[1]);
                    $max = max($max, $range[1]);
                }
            }
        }

        // Calculate unwatched segments by removing watched ranges from timeline.
        foreach ($simplewatchdata as $watch) {
            $watchedrange = range($watch[0], $watch[1]);
            $timelinearray = array_diff($timelinearray, $watchedrange);
        }
        $watchedtimelinearray = array_diff(range(1, $videoduration), $timelinearray);
        $unwatched = count($timelinearray);

        // Calculate progress based on furthest point reached (max) instead of accumulated watched time
        // This gives a more accurate representation of how far the user has progressed in the video.
        $furthestpoint = round($max);
        $progress = \mod_videolesson\util::calculate_percentage($furthestpoint, $videoduration);

        // Calculate progress percentage explicitly from furthest point (max) for clarity.
        $progresspercentage = \mod_videolesson\util::calculate_percentage($furthestpoint, $videoduration) . '%';

        $endexectime = microtime(true);

        $returndata = [
            'simplewatchdata' => $simplewatchdata,
            'unwatched_seconds' => $unwatched,
            'watched_seconds' => $videoduration - $unwatched,
            'progress' => $progress,
            'progress_percentage' => $progresspercentage, // Explicitly calculated from furthest point (max).
            'resume' => round($resume), // Furthest resume point (same as max).
            'max' => $furthestpoint, // Furthest point reached in the video.
            'execution_time' => $endexectime - $exectime,
        ];

        $this->watchdata = $returndata;
        return $returndata;
    }
}
