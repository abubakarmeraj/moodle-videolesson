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
 * Service for managing subtitle generation requests and tracking.
 *
 * @package     mod_videolesson
 * @author      BitKea Technologies LLP
 * @copyright   2022-2026 BitKea Technologies LLP
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_videolesson\local\services;

use mod_videolesson\conversion;
use mod_videolesson\subtitle_languages;
use mod_videolesson\sns_handler;

/**
 * Service for managing subtitle generation requests and tracking.
 *
 * @package     mod_videolesson
 */
class subtitle_service {
    /** Status constants */
    /** @var string $STATUS_PENDING The status of a pending subtitle request. */
    const STATUS_PENDING = 'pending';
    /** @var string $STATUS_PROCESSING The status of a processing subtitle request. */
    const STATUS_PROCESSING = 'processing';
    /** @var string $STATUS_COMPLETED The status of a completed subtitle request. */
    const STATUS_COMPLETED = 'completed';
    /** @var string $STATUS_FAILED The status of a failed subtitle request. */
    const STATUS_FAILED = 'failed';

    /**
     * Request subtitle generation for a video.
     *
     * @param string $contenthash The video content hash
     * @param array $languages Array of language codes
     * @return array ['success' => bool, 'requested' => array, 'skipped' => array, 'errors' => array]
     */
    public static function request_subtitles(string $contenthash, array $languages): array {
        global $DB;

        // Validate video exists and is transcoded.
        $video = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash]);
        if (!$video) {
            return [
                'success' => false,
                'requested' => [],
                'skipped' => [],
                'errors' => [get_string('error:video:notfound', 'mod_videolesson', $contenthash)],
            ];
        }

        $conversion = new conversion();
        if ($video->transcoder_status != $conversion::CONVERSION_FINISHED) {
            return [
                'success' => false,
                'requested' => [],
                'skipped' => [],
                'errors' => [get_string('error:subtitle:not_transcoded', 'mod_videolesson')],
            ];
        }

        // Validate language codes.
        $validated = [];
        foreach ($languages as $lang) {
            $lang = trim($lang);
            if ($lang === 'original' || subtitle_languages::is_supported($lang)) {
                $validated[] = $lang;
            } else {
                return [
                    'success' => false,
                    'requested' => [],
                    'skipped' => [],
                    'errors' => [get_string('error:subtitle:invalid_lang', 'mod_videolesson', $lang)],
                ];
            }
        }

        if (empty($validated)) {
            return [
                'success' => false,
                'requested' => [],
                'skipped' => [],
                'errors' => [get_string('error:subtitle:no_languages', 'mod_videolesson')],
            ];
        }

        // Get current status to filter out duplicates.
        $status = self::get_subtitle_status($contenthash);
        $allstatuses = array_merge($status['completed'], $status['pending'], $status['processing']);

        // Filter out already requested/completed languages.
        $newlanguages = [];
        $skipped = [];
        foreach ($validated as $lang) {
            if (in_array($lang, $allstatuses)) {
                $skipped[] = $lang;
            } else {
                $newlanguages[] = $lang;
            }
        }

        if (empty($newlanguages)) {
            return [
                'success' => true,
                'requested' => [],
                'skipped' => $skipped,
                'errors' => [],
            ];
        }

        // Get configuration for SNS.
        $config = get_config('mod_videolesson');
        $bucketkey = $config->bucket_key ?? 'videolesson';
        $objectkey = "{$bucketkey}/{$contenthash}";
        $filename = $contenthash;

        // Determine s3_uri based on video type.
        $s3uri = '';
        if ($video->hasmp4) {
            $s3uri = "s3://{$config->s3_output_bucket}/{$bucketkey}/{$contenthash}/mp4/{$contenthash}.mp4";
        } else if ($video->mediaconvert) {
            $s3uri = "s3://{$config->s3_output_bucket}/{$bucketkey}/{$contenthash}/conversions/{$contenthash}.m3u8";
        } else {
            $s3uri = "s3://{$config->s3_output_bucket}/{$bucketkey}/{$contenthash}/conversions/{$contenthash}_hls_playlist.m3u8";
        }

        // Create database records and send SNS messages.
        $requested = [];
        $errors = [];
        $now = time();

        try {
            $snshandler = new sns_handler();

            foreach ($newlanguages as $lang) {
                try {
                    // Check if a failed record exists for this language.
                    $existing = $DB->get_record('videolesson_subtitles', [
                        'contenthash' => $contenthash,
                        'language_code' => $lang,
                        'status' => self::STATUS_FAILED,
                    ]);

                    if ($existing) {
                        // Update existing failed record to retry.
                        $record = new \stdClass();
                        $record->id = $existing->id;
                        $record->status = self::STATUS_PENDING;
                        $record->requested_at = $now;
                        $record->retry_count = $existing->retry_count + 1;
                        $record->error_message = null;
                        $record->sns_message_id = null;
                        $DB->update_record('videolesson_subtitles', $record);
                        $recordid = $existing->id;
                    } else {
                        // Create new database record with status='pending'.
                        $record = new \stdClass();
                        $record->contenthash = $contenthash;
                        $record->language_code = $lang;
                        $record->status = self::STATUS_PENDING;
                        $record->requested_at = $now;
                        $record->retry_count = 0;

                        $recordid = $DB->insert_record('videolesson_subtitles', $record);
                    }

                    // Send SNS message.
                    $result = $snshandler->trigger_subtitle_generation($objectkey, $lang, $filename, $s3uri);

                    if ($result['success']) {
                        // Update status to processing and store SNS message ID.
                        $update = new \stdClass();
                        $update->id = $recordid;
                        $update->status = self::STATUS_PROCESSING;
                        $update->sns_message_id = $result['MessageId'] ?? null;
                        $DB->update_record('videolesson_subtitles', $update);

                        $requested[] = $lang;
                    } else {
                        // Mark as failed if SNS send failed.
                        $update = new \stdClass();
                        $update->id = $recordid;
                        $update->status = self::STATUS_FAILED;
                        $update->error_message = get_string('error:subtitle:trigger_failed', 'mod_videolesson');
                        $DB->update_record('videolesson_subtitles', $update);

                        $errors[] = get_string('error:subtitle:trigger_failed', 'mod_videolesson') . " ({$lang})";
                    }
                } catch (\Exception $e) {
                    // Mark as failed on exception.
                    if (isset($recordid)) {
                        $update = new \stdClass();
                        $update->id = $recordid;
                        $update->status = self::STATUS_FAILED;
                        $update->error_message = $e->getMessage();
                        $DB->update_record('videolesson_subtitles', $update);
                    }

                    $errors[] = get_string('error:subtitle:exception', 'mod_videolesson', $e->getMessage()) . " ({$lang})";
                }
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'requested' => $requested,
                'skipped' => $skipped,
                'errors' => array_merge($errors, [$e->getMessage()]),
            ];
        }

        return [
            'success' => count($errors) === 0,
            'requested' => $requested,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Get subtitle status for a video.
     *
     * @param string $contenthash The video content hash
     * @return array ['completed' => [], 'pending' => [], 'processing' => [], 'failed' => []]
     */
    public static function get_subtitle_status(string $contenthash): array {
        global $DB;

        $records = $DB->get_records('videolesson_subtitles', ['contenthash' => $contenthash]);

        $result = [
            'completed' => [],
            'pending' => [],
            'processing' => [],
            'failed' => [],
        ];

        foreach ($records as $record) {
            if (isset($result[$record->status])) {
                $result[$record->status][] = $record->language_code;
            }
        }

        return $result;
    }

    /**
     * Mark subtitle as completed.
     *
     * @param string $contenthash The video content hash
     * @param string $languagecode The language code
     * @return bool Success
     */
    public static function mark_completed(string $contenthash, string $languagecode): bool {
        global $DB;

        $record = $DB->get_record('videolesson_subtitles', [
            'contenthash' => $contenthash,
            'language_code' => $languagecode,
        ]);

        if (!$record) {
            // Create record if it doesn't exist (for backward compatibility).
            $record = new \stdClass();
            $record->contenthash = $contenthash;
            $record->language_code = $languagecode;
            $record->status = self::STATUS_COMPLETED;
            $record->requested_at = time();
            $record->completed_at = time();
            $record->retry_count = 0;
            $DB->insert_record('videolesson_subtitles', $record);
        } else {
            // Update existing record.
            $update = new \stdClass();
            $update->id = $record->id;
            $update->status = self::STATUS_COMPLETED;
            $update->completed_at = time();
            $update->error_message = null;
            $DB->update_record('videolesson_subtitles', $update);
        }

        // Sync to videolesson_conv.subtitle for backward compatibility.
        self::sync_to_legacy_field($contenthash);

        return true;
    }

    /**
     * Mark subtitle as processing.
     *
     * @param string $contenthash The video content hash
     * @param string $languagecode The language code
     * @return bool Success
     */
    public static function mark_processing(string $contenthash, string $languagecode): bool {
        global $DB;

        $record = $DB->get_record('videolesson_subtitles', [
            'contenthash' => $contenthash,
            'language_code' => $languagecode,
        ]);

        if (!$record) {
            return false;
        }

        // Only update if currently pending.
        if ($record->status != self::STATUS_PENDING) {
            return false;
        }

        $update = new \stdClass();
        $update->id = $record->id;
        $update->status = self::STATUS_PROCESSING;
        $DB->update_record('videolesson_subtitles', $update);

        return true;
    }

    /**
     * Mark subtitle as failed.
     *
     * @param string $contenthash The video content hash
     * @param string $languagecode The language code
     * @param string $errormessage Error message
     * @return bool Success
     */
    public static function mark_failed(string $contenthash, string $languagecode, string $errormessage): bool {
        global $DB;

        $record = $DB->get_record('videolesson_subtitles', [
            'contenthash' => $contenthash,
            'language_code' => $languagecode,
        ]);

        if (!$record) {
            return false;
        }

        $update = new \stdClass();
        $update->id = $record->id;
        $update->status = self::STATUS_FAILED;
        $update->error_message = $errormessage;
        $DB->update_record('videolesson_subtitles', $update);

        return true;
    }

    /**
     * Clean up stale pending/processing requests.
     *
     * @param int $timeoutseconds Timeout in seconds (default 3600 = 1 hour)
     * @return int Number of records cleaned up
     */
    public static function cleanup_stale_requests(int $timeoutseconds = 3600): int {
        global $DB;

        $timeout = time() - $timeoutseconds;

        $stale = $DB->get_records_sql(
            "SELECT * FROM {videolesson_subtitles}
             WHERE status IN (?, ?) AND requested_at < ?",
            [self::STATUS_PENDING, self::STATUS_PROCESSING, $timeout]
        );

        $count = 0;
        foreach ($stale as $record) {
            // Mark as failed if retry count is too high, otherwise reset to pending for retry.
            if ($record->retry_count >= 3) {
                $update = new \stdClass();
                $update->id = $record->id;
                $update->status = self::STATUS_FAILED;
                $update->error_message = get_string('error:subtitle:timeout', 'mod_videolesson');
                $DB->update_record('videolesson_subtitles', $update);
            } else {
                // Reset to pending for retry.
                $update = new \stdClass();
                $update->id = $record->id;
                $update->status = self::STATUS_PENDING;
                $update->retry_count = $record->retry_count + 1;
                $DB->update_record('videolesson_subtitles', $update);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Retry failed subtitle requests.
     *
     * @param string $contenthash The video content hash
     * @param array $languagecodes Optional, if empty retries all failed
     * @return array Results
     */
    public static function retry_failed(string $contenthash, array $languagecodes = []): array {
        global $DB;

        $params = ['contenthash' => $contenthash, 'status' => self::STATUS_FAILED];
        $where = 'contenthash = :contenthash AND status = :status';

        if (!empty($languagecodes)) {
            [$insql, $inparams] = $DB->get_in_or_equal($languagecodes, SQL_PARAMS_NAMED, 'lang');
            $where .= ' AND language_code ' . $insql;
            $params = array_merge($params, $inparams);
        }

        $failed = $DB->get_records_sql(
            "SELECT * FROM {videolesson_subtitles} WHERE {$where}",
            $params
        );

        if (empty($failed)) {
            return [
                'success' => true,
                'requested' => [],
                'errors' => [],
            ];
        }

        // Reset status and retry.
        $languages = [];
        foreach ($failed as $record) {
            $update = new \stdClass();
            $update->id = $record->id;
            $update->status = self::STATUS_PENDING;
            $update->requested_at = time();
            $update->error_message = null;
            $DB->update_record('videolesson_subtitles', $update);

            $languages[] = $record->language_code;
        }

        // Request subtitles again.
        return self::request_subtitles($contenthash, $languages);
    }

    /**
     * Check all pending/processing subtitles via S3 and update their status.
     *
     * @param int $timeoutseconds Timeout in seconds (default 3600 = 1 hour)
     * @return array ['checked' => int, 'completed' => int, 'failed' => int, 'still_pending' => int]
     */
    public static function check_pending_subtitles_via_s3(int $timeoutseconds = 3600): array {
        global $DB;

        $awshandler = new \mod_videolesson\aws_handler('output');
        $config = get_config('mod_videolesson');
        $bucketkey = $config->bucket_key ?? 'videolesson';

        // Get all pending/processing subtitle records.
        $pending = $DB->get_records_select(
            'videolesson_subtitles',
            "status IN (?, ?)",
            [self::STATUS_PENDING, self::STATUS_PROCESSING]
        );

        $checked = 0;
        $completed = 0;
        $failed = 0;
        $stillpending = 0;
        $timeout = time() - $timeoutseconds;

        foreach ($pending as $record) {
            $checked++;
            $s3key = "{$record->contenthash}/subtitles/{$record->language_code}.vtt";

            try {
                if ($awshandler->does_object_exist($s3key)) {
                    // File exists - mark as completed.
                    self::mark_completed($record->contenthash, $record->language_code);
                    $completed++;
                } else {
                    // File doesn't exist - check if timeout exceeded.
                    if ($record->requested_at < $timeout) {
                        // Timeout exceeded - mark as failed.
                        self::mark_failed(
                            $record->contenthash,
                            $record->language_code,
                            get_string('error:subtitle:timeout', 'mod_videolesson')
                        );
                        $failed++;
                    } else {
                        // Still within timeout - keep as pending/processing.
                        $stillpending++;
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue with other subtitles.
                debugging('Error checking subtitle via S3: ' . $e->getMessage(), DEBUG_NORMAL);
                // Don't increment any counter for errors - treat as still pending.
                $stillpending++;
            }
        }

        return [
            'checked' => $checked,
            'completed' => $completed,
            'failed' => $failed,
            'still_pending' => $stillpending,
        ];
    }

    /**
     * Sync completed subtitles to legacy videolesson_conv.subtitle field.
     *
     * @param string $contenthash The video content hash
     * @return void
     */
    private static function sync_to_legacy_field(string $contenthash): void {
        global $DB;

        $completed = $DB->get_records('videolesson_subtitles', [
            'contenthash' => $contenthash,
            'status' => self::STATUS_COMPLETED,
        ]);

        $languages = [];
        foreach ($completed as $record) {
            $languages[] = $record->language_code;
        }

        if (!empty($languages)) {
            $DB->set_field('videolesson_conv', 'subtitle', implode(',', $languages), ['contenthash' => $contenthash]);
        }
    }
}
