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
 * Class videosource handles video source operations for mod_videolesson.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once("$CFG->dirroot/mod/videolesson/classes/util.php");
require_once("$CFG->dirroot/mod/videolesson/locallib.php");

/**
 * Videosource class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class videosource {
    /** @var string Cache key for the S3 bucket prefix list. */
    private const CACHE_KEY_ALL_PREFIXES = 'all_prefixes';

    /** @var string Cache key prefix for S3 subtitle listings per content hash. */
    private const CACHE_KEY_SUBTITLES_PREFIX = 'subtitles_';

    /** @var object Plugin configuration */
    private $config;

    /** @var aws_handler S3 output handler */
    private $s3output;

    /** @var string CloudFront domain */
    private $cloudfrontdomain;

    /**
     * Constructor initializes the video source handler.
     */
    public function __construct() {
        $this->config = get_config('mod_videolesson');
        $this->s3output = new \mod_videolesson\aws_handler('output');
        $this->cloudfrontdomain = $this->s3output->cloudfrontdomain();
    }

    /**
     * Retrieve video items.
     *
     * @param string|null $selected Selected content hash.
     * @param bool $all Get all videos regardless of upload status
     * @param bool $includemissing Include missing videos
     * @param int|null $folderid Filter by folder ID (null for all, 0 for uncategorized)
     * @return array List of video items.
     */
    public function get_items($selected = null, $all = false, $includemissing = true, $folderid = null): array {
        global $DB;
        $classconversion = new \mod_videolesson\conversion();
        $awshandler = new \mod_videolesson\aws_handler('output');
        $prefixes = array_flip($awshandler->list_all_prefixes_array());

        // Build SQL with optional folder filter.
        $params = [];
        $folderjoin = '';
        $folderwhere = '';

        if ($folderid !== null) {
            if ($folderid == 0) {
                // Uncategorized videos (not in any folder).
                $folderjoin = "LEFT JOIN {videolesson_folder_items} fi ON fi.videolessonid = c.id";
                $folderwhere = "AND fi.folderid IS NULL";
            } else {
                // Videos in specific folder.
                $folderjoin = "INNER JOIN {videolesson_folder_items} fi ON fi.videolessonid = c.id";
                $folderwhere = "AND fi.folderid = :folderid";
                $params['folderid'] = (int)$folderid;
            }
        } else {
            // Always left join so folder columns can be selected without errors.
            $folderjoin = "LEFT JOIN {videolesson_folder_items} fi ON fi.videolessonid = c.id";
        }

        if ($all) {
            $sql = "SELECT c.*, d.duration, fi.folderid, fi.sortorder as folder_sortorder
                    FROM {videolesson_conv} c
                        INNER JOIN {videolesson_data} d ON d.contenthash = c.contenthash
                        $folderjoin
                    WHERE 1=1 $folderwhere
                    ORDER BY " . ($folderid !== null ? "fi.sortorder ASC, " : "") . "c.timecreated DESC";
            $sources = $DB->get_records_sql($sql, $params);
        } else {
            $sql = "SELECT c.*, d.duration, fi.folderid, fi.sortorder as folder_sortorder
                    FROM {videolesson_conv} c
                        INNER JOIN {videolesson_data} d ON d.contenthash = c.contenthash
                        $folderjoin
                    WHERE c.uploaded = :uploaded $folderwhere
                    ORDER BY " . ($folderid !== null ? "fi.sortorder ASC, " : "") . "c.timecreated DESC";
            $params['uploaded'] = 200;
            $sources = $DB->get_records_sql($sql, $params);
        }

        $objects = [];
        foreach ($sources as $source) {
            $contenthash = $source->contenthash;
            if ($source->mediaconvert) {
                $src = "{$this->cloudfrontdomain}{$contenthash}/conversions/{$contenthash}.m3u8";
                $thumbnail = "{$this->cloudfrontdomain}{$contenthash}/thumbnails/{$contenthash}_1080p_thumbnail.0000000.jpg";
            } else {
                $src = "{$this->cloudfrontdomain}{$contenthash}/conversions/{$contenthash}_hls_playlist.m3u8";
                $thumbnail = "{$this->cloudfrontdomain}{$contenthash}/conversions/thumbnails/192x108/00001-192x108.png";
            }

            $text = '';
            $badge = '';
            switch ($source->transcoder_status) {
                case $classconversion::CONVERSION_FINISHED:
                    $status = '';
                    break;
                case $classconversion::CONVERSION_IN_PROGRESS:
                    $status = $source->transcoder_status;
                    $text = get_string('transcoding:status:' . $source->transcoder_status, 'mod_videolesson');
                    $badge = 'info';
                    break;
                case $classconversion::CONVERSION_ACCEPTED:
                    $status = $source->transcoder_status;
                    $text = get_string('transcoding:status:' . $source->transcoder_status, 'mod_videolesson');
                    $badge = 'warning';
                    break;
                case $classconversion::CONVERSION_NOT_FOUND:
                case $classconversion::CONVERSION_ERROR:
                    $status = $source->transcoder_status;
                    $text = get_string('transcoding:status:' . $source->transcoder_status, 'mod_videolesson');
                    $badge = 'error';
                    break;
                default:
                    break;
            }

            // In missing bucket, only those finished ,
            // Not found and error included in the check, in progress and accepted might not yet in the prefixes list.
            $inbucket = isset($prefixes[$contenthash]);

            $statuses = [
                $classconversion::CONVERSION_FINISHED,
                $classconversion::CONVERSION_NOT_FOUND,
                $classconversion::CONVERSION_ERROR,
            ];

            $missing = false;
            if (!$inbucket && in_array($source->transcoder_status, $statuses)) {
                $missing = true;
                if (!$includemissing && ($selected !== $contenthash)) {
                    continue;
                }
            }

            // Get folder name if folderid exists.
            $foldername = null;
            if (!empty($source->folderid)) {
                $folder = \mod_videolesson\folder_manager::get_folder($source->folderid);
                if ($folder) {
                    $foldername = $folder->name;
                }
            }

            $objects[$contenthash] = [
                'selected' => $selected === $contenthash,
                'name' => $source->name,
                'contenthash' => $contenthash,
                'src' => $src,
                'type' => 'video/fmp4',
                'duration' => \mod_videolesson\util::durationformat($source->duration),
                'timeadded' => userdate($source->timecreated, '%m/%d/%y %I:%M %p'),
                'thumbnail' => $thumbnail,
                'transcodingstatus' => $status,
                'transcodingstatustext' => $text,
                'transcodingstatusbadgeclass' => $badge,
                'missing' => $missing,
                'folderid' => isset($source->folderid) ? $source->folderid : null,
                'foldername' => $foldername,
                'videolessonid' => $source->id,
            ];
        }

        return $objects;
    }

    /**
     * Get video items by folder.
     *
     * @param int|null $folderid Folder ID (null for all, 0 for uncategorized)
     * @param string|null $selected Selected content hash
     * @param bool $all Get all videos regardless of upload status
     * @param bool $includemissing Include missing videos
     * @return array List of video items
     */
    public function get_items_by_folder($folderid = null, $selected = null, $all = false, $includemissing = true): array {
        return $this->get_items($selected, $all, $includemissing, $folderid);
    }

    /**
     * Get video data.
     *
     * @param string $contenthash Content hash.
     * @param object|bool $context File context.
     * @return array Video data.
     */
    public function get_video_data($contenthash, $context = false): array {
        $type = 'application/x-mpegURL';
        $src = $this->get_video_src($contenthash);
        $poster = $this->get_poster_url($context);
        $subtitles = $this->get_video_subtitles($contenthash);
        return [
            'provider' => MOD_VIDEOLESSON_SRC_GALLERY,
            'sourceurl' => $src,
            'poster' => $poster,
            'subtitles' => $subtitles,
            'type' => $type,
        ];
    }

    /**
     * Get video source URL.
     *
     * @param string $contenthash Content hash.
     * @return string Video source URL.
     */
    public function get_video_src($contenthash): string {
        global $DB;

        $record = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash]);
        $m3u8 = $this->get_m3u8_url($contenthash, $record->mediaconvert);
        $src = "{$this->cloudfrontdomain}{$m3u8}";

        return $src;
    }

    /**
     * Get video subtitles.
     *
     * @param string $contenthash Content hash.
     * @return array Video subtitles.
     */
    public function get_video_subtitles($contenthash) {
        global $DB, $CFG;

        $languagemap = array_flip(subtitle_languages::get_supported_languages());

        $subtitles = [];

        // Query videolesson_subtitles table for completed subtitles.
        $subtitlerecords = $DB->get_records('videolesson_subtitles', [
            'contenthash' => $contenthash,
            'status' => 'completed',
        ]);

        if (!empty($subtitlerecords)) {
            foreach ($subtitlerecords as $subrecord) {
                $code = $subrecord->language_code;
                $key = $contenthash . '/subtitles/' . $code . '.vtt';
                $filename = basename($key);
                $languagename = array_search($code, $languagemap);
                $url = $this->cloudfrontdomain . $key;
                $url = $CFG->wwwroot . '/mod/videolesson/proxy.php?sub=' . urlencode($url);
                $subtitles[] = [
                    'code' => $code,
                    'filename' => $filename,
                    'language' => $languagename,
                    'url' => $url,
                ];
            }
            return $subtitles;
        }

        // Fallback to legacy subtitle field for backward compatibility.
        $record = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash]);

        if (!empty($record->subtitle) && $record->subtitle != 'en,original') {
            $codes = array_filter(array_map('trim', explode(',', $record->subtitle)));
            $subtitles = $this->build_subtitles_from_language_codes($contenthash, $codes, $languagemap, true);
            if (!empty($subtitles)) {
                return $subtitles;
            }
        }

        // Define common variables.
        $prefix = "{$contenthash}/subtitles";

        $cache = \cache::make('mod_videolesson', 'prefixes_cache');
        $cachekey = self::CACHE_KEY_SUBTITLES_PREFIX . $contenthash;
        $cachedcodes = $cache->get($cachekey);
        if ($cachedcodes !== false) {
            if ($cachedcodes === '') {
                return [];
            }
            $codes = array_filter(array_map('trim', explode(',', $cachedcodes)));
            return $this->build_subtitles_from_language_codes($contenthash, $codes, $languagemap);
        }

        // Array to store S3 object keys and their corresponding URLs.
        $save = [];
        $subtitles = [];
        $result = null;
        try {
            $result = $this->s3output->list_objects($prefix, '', false, true);
        } catch (\Throwable $e) {
            debugging('mod_videolesson: Error listing subtitles from S3: ' . $e->getMessage(), DEBUG_NORMAL);
        }
        $listsuccess = ($result !== null);
        if ($listsuccess && !empty($result['Contents'])) {
            foreach ($result['Contents'] as $value) {
                if (substr($value['Key'], -4) === '.vtt') {
                    $filename = basename($value['Key']);
                    $code = pathinfo($filename, PATHINFO_FILENAME);
                    $languagename = array_search($code, $languagemap);
                    $url = $this->s3output->cloudfrontdomainlistformat($value['Key']);
                    $url = $CFG->wwwroot . '/mod/videolesson/proxy.php?sub=' . urlencode($url);
                    $save[] = $code;
                    $subtitles[] = [
                        'code' => $code,
                        'filename' => $filename,
                        'language' => $languagename,
                        'url' => $url,
                    ];
                }
            }

            if (!empty($save)) {
                $DB->set_field(
                    'videolesson_conv',
                    'subtitle',
                    implode(',', $save),
                    ['contenthash' => $contenthash]
                );
            }
        }

        if ($listsuccess) {
            $cache->set($cachekey, implode(',', $save));
        }

        return $subtitles;
    }

    /**
     * Build subtitle entries from language codes.
     *
     * @param string $contenthash Content hash.
     * @param array $codes Language codes.
     * @param array $languagemap Map of language code to display name.
     * @param bool $legacyurls Use legacy CloudFront URL concatenation.
     * @return array Subtitle entries.
     */
    private function build_subtitles_from_language_codes(
        string $contenthash,
        array $codes,
        array $languagemap,
        bool $legacyurls = false
    ): array {
        global $CFG;

        $subtitles = [];
        foreach ($codes as $code) {
            if ($code === '') {
                continue;
            }
            $key = $contenthash . '/subtitles/' . $code . '.vtt';
            $filename = basename($key);
            $languagename = array_search($code, $languagemap);
            if ($legacyurls) {
                $url = $this->cloudfrontdomain . $key;
            } else {
                $url = $this->s3output->cloudfrontdomainlistformat($key);
            }
            $url = $CFG->wwwroot . '/mod/videolesson/proxy.php?sub=' . urlencode($url);
            $subtitles[] = [
                'code' => $code,
                'filename' => $filename,
                'language' => $languagename,
                'url' => $url,
            ];
        }

        return $subtitles;
    }

    /**
     * Purge cached S3 subtitle listing for a video.
     *
     * @param string $contenthash Content hash.
     */
    public static function purge_subtitles_cache(string $contenthash): void {
        \cache::make('mod_videolesson', 'prefixes_cache')->delete(
            self::CACHE_KEY_SUBTITLES_PREFIX . $contenthash
        );
    }

    /**
     * Get video title.
     *
     * @param string $contenthash Content hash.
     * @return string Video title.
     */
    public function get_video_title($contenthash): string {
        global $DB;
        $title = $DB->get_field('videolesson_conv', 'name', ['contenthash' => $contenthash]);
        return format_text($title, FORMAT_PLAIN);
    }

    /**
     * Delete video output.
     *
     * @param string $contenthash Content hash.
     * @return array Deletion result with success status and errors.
     */
    public function output_delete($contenthash): array {
        global $DB;
        $errors = [];

        try {
            $isvideoused = $DB->count_records(
                'videolesson',
                ['source' => MOD_VIDEOLESSON_SRC_GALLERY, 'sourcedata' => $contenthash]
            );

            if ($isvideoused) {
                $errors[] = get_string('error:videoisused', 'mod_videolesson', $isvideoused);
            } else {
                $responses = $this->s3output->delete_objects([$contenthash]);
                $this->handle_delete_responses($responses, $errors);
            }
        } catch (\Exception $e) {
            // Handle exceptions.
            $errors[] = 'Error: ' . $e->getMessage();
        }

        if (empty($errors)) {
            $this->delete_related_records($contenthash);
            // Delete cache.
            $cache = \cache::make('mod_videolesson', 'prefixes_cache');
            $cache->delete('all_prefixes');
            self::purge_subtitles_cache($contenthash);
        }

        return [
            'success' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if the video is transcoded.
     *
     * @param string $contenthash Content hash.
     * @return array Transcode status, message, and type.
     */
    public function is_transcoded($contenthash): array {
        global $DB;
        $record = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash]);
        $status = false;
        $message = '';
        $type = '';

        if ($record && $record->status == 200) {
            $status = true;
            switch ($record->transcoder_status) {
                case 200:
                    $type = 'success';
                    $message = get_string('player:processing:video:ready', 'mod_videolesson');
                    break;
                case 500:
                    $type = 'error';
                    $message = get_string('player:processing:video:done', 'mod_videolesson');
                    break;
            }
        }

        return [
            'status' => $status,
            'message' => $message,
            'type' => $type,
        ];
    }

    /**
     * Get the m3u8 URL based on the conversion status.
     *
     * @param string $contenthash Content hash.
     * @param bool $mediaconvert MediaConvert status.
     * @return string m3u8 URL.
     */
    private function get_m3u8_url($contenthash, $mediaconvert): string {
        $prefix = "{$contenthash}/conversions";
        return $mediaconvert ? "{$prefix}/{$contenthash}.m3u8" : "{$prefix}/{$contenthash}_hls_playlist.m3u8";
    }

    /**
     * Get poster URL from the file context.
     *
     * @param object|bool $context File context.
     * @return string Poster URL.
     */
    private function get_poster_url($context): string {
        if (!$context) {
            return '';
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_videolesson', 'thumbnail', 0);
        foreach ($files as $file) {
            if ($file->get_mimetype()) {
                $thumbnail = \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                );
                return $thumbnail->out(true);
            }
        }

        return '';
    }

    /**
     * Handle delete responses from S3.
     *
     * @param array $responses Responses from S3.
     * @param array $errors Reference to the errors array to capture issues.
     */
    private function handle_delete_responses(array $responses, array &$errors): void {
        global $DB;

        foreach ($responses as $contenthash => $response) {
            $logdata = [
                'type' => $response['success'] ? 'INFO' : 'ERROR',
                'name' => 'S3',
                'other' => json_encode($response['success'] ? ['output_deleted' => $contenthash] : $response['errors']),
                'senttoadmin' => 0,
            ];

            if ($response['success']) {
                $DB->set_field('videolesson_conv', 'input_deleted', 1, ['contenthash' => $contenthash]);
            } else {
                $errors[] = $response['errors'];
            }

            $log = new logs(0, (object) $logdata);
            $log->create();
        }
    }

    /**
     * Delete related records from the database.
     *
     * @param string $contenthash Content hash.
     */
    private function delete_related_records(string $contenthash): void {
        global $DB;

        // Get videolessonid from videolesson_conv table.
        $convrecord = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash]);
        if ($convrecord) {
            // Delete from videolesson_folder_items using videolessonid.
            $DB->delete_records('videolesson_folder_items', ['videolessonid' => $convrecord->id]);
        }

        $DB->delete_records('videolesson_conv', ['contenthash' => $contenthash]);
        $DB->delete_records('videolesson_data', ['contenthash' => $contenthash]);
        $DB->delete_records('videolesson_usage', ['source' => MOD_VIDEOLESSON_SRC_GALLERY, 'sourcedata' => $contenthash]);
    }
}
