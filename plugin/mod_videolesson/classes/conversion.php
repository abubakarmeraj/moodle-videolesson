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
 * Conversion
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use Aws\S3\Exception\S3Exception;
use mod_videolesson\logs as videolesson_logs;
use mod_videolesson\local\services\subtitle_service;
use mod_videolesson\local\services\conversion_status_service;
/**
 * Conversion class
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversion {
    /**
     * Video aws conversion finished without error.
     *
     * @var integer
     */
    public const CONVERSION_FINISHED = 200;

    /**
     * Video aws conversion is in progres.
     *
     * @var integer
     */
    public const CONVERSION_IN_PROGRESS = 201;

    /**
     * Video aws conversion job has been created but processing has not yet started.
     *
     * @var integer
     */
    public const CONVERSION_ACCEPTED = 202;

    /**
     * No Video aws conversion record found.
     *
     * @var integer
     */
    public const CONVERSION_NOT_FOUND = 404;

    /**
     * Video aws conversion finished with error.
     *
     * @var integer
     */
    public const CONVERSION_ERROR = 500;

    /**
     * Video aws Upload error
     *
     * @var integer
     */
    public const CONVERSION_UPLOAD_ERROR = 503;

    /**
     * Max files to get from Moodle files table per processing run.
     *
     * @var integer
     */
    private const MAX_FILES = 1000;

    /**
     *  The file is not found on disk to transcode.
     */
    private const FILE_NOT_FOUND = 3;

    /**
     * Create the Video aws conversion record.
     * These records will be processed by a scheduled task.
     *
     * @param \stored_file $file The file object to create the conversion for.
     * @param array $opts Optional settings for the conversion.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \dml_write_exception
     * @throws \moodle_exception
     */
    public function create_conversion(\stored_file $file, $opts = []): void {
        global $DB;
        $now = time();
        $convid = 0;

        $cnvrec = new \stdClass();
        $cnvrec->pathnamehash = $file->get_pathnamehash();
        $cnvrec->contenthash = $file->get_contenthash();
        $cnvrec->name = substr($file->get_filename(), 0, strrpos($file->get_filename(), '.'));

        $cnvrec->status = $this::CONVERSION_ACCEPTED;
        $cnvrec->transcoder_status = $this::CONVERSION_ACCEPTED;
        $cnvrec->mediaconvert = 1;
        $cnvrec->timecreated = $now;
        $cnvrec->timemodified = $now;
        $cnvrec->subtitle = $opts['subtitle'] ?? 0;

        // Note: Initial subtitle requests are handled after conversion completes.
        // The subtitle field is kept for backward compatibility but managed by subtitle_service.

        if (!empty($opts['subtitle'] ?? null)) {
            // Create initial subtitle request record for 'en' language.
            // This will be picked up by get_conversion_settings() and passed to Lambda.
            try {
                $subtitlerecord = new \stdClass();
                $subtitlerecord->contenthash = $file->get_contenthash();
                $subtitlerecord->language_code = 'en';
                $subtitlerecord->status = subtitle_service::STATUS_PENDING;
                $subtitlerecord->requested_at = $now;
                $subtitlerecord->retry_count = 0;
                $subtitlerecord->completed_at = null;
                $subtitlerecord->error_message = null;
                $subtitlerecord->sns_message_id = null;

                $DB->insert_record('videolesson_subtitles', $subtitlerecord);
            } catch (\dml_exception $e) {
                // Handle duplicate key errors gracefully (unique constraint on contenthash, language_code).
                // This can happen if conversion is created multiple times.
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    // Re-throw if it's not a duplicate error.
                    throw $e;
                }
            }
        }

        // Race conditions mean that we could try to create a conversion record multiple times.
        // This is OK and expected, we will handle the error.
        try {
            $convid = $DB->insert_record('videolesson_conv', $cnvrec);
        } catch (\dml_exception $e) {
            // If error is anything else but a duplicate insert, this is unexected,
            // so re-throw the error.
            // Postgres / Mysql error messages.
            if (stripos($e->getMessage(), 'duplicate') === false) {
                throw $e;
            }
        }
    }

    /**
     * Get conversion records to process conversions.
     *
     * @param int $status Status of records to get.
     * @return array $filerecords Records to process.
     */
    private function get_conversion_records(int $status): array {
        global $DB;

        $conditions = ['status' => $status];
        $limit = self::MAX_FILES;
        $fields = 'id, contenthash, status, transcoder_status, mediaconvert, subtitle, timecreated';

        // We should check forwards back, and prioritise immediate latency on new small files.
        // If something is taking a long time, we can clear many small newer files without blocking the queue.
        $filerecords = $DB->get_records('videolesson_conv', $conditions, 'timecreated DESC', $fields, 0, $limit);

        return $filerecords;
    }

    /**
     * Get the configured covnersion for this conversion record in a format that will
     * be sent to AWS for processing.
     *
     * @param \stdClass $conversionrecord The conversion record to get the settings for.
     * @param array|null $videodatabyhash Optional. Map of contenthash => videolesson_data row.
     * @param array|null $subtitlependingbyhash Optional map contenthash => true when pending EN subtitle exists.
     * @return array The conversion settings.
     */
    public function get_conversion_settings(
        \stdClass $conversionrecord,
        ?array $videodatabyhash = null,
        ?array $subtitlependingbyhash = null
    ): array {
        global $CFG, $DB;
        $settings = [];
        $settings['siteid'] = $CFG->siteidentifier;
        $settings['siteurl'] = $CFG->wwwroot;
        $settings['transcoder'] = 'mediaconvert';
        $settings['pluginversion'] = get_config('mod_videolesson', 'version');

        $record = false;
        if ($videodatabyhash !== null) {
            $record = $videodatabyhash[$conversionrecord->contenthash] ?? false;
        } else {
            $record = $DB->get_record('videolesson_data', ['contenthash' => $conversionrecord->contenthash]);
        }
        if ($record) {
            $settings['ffprobe'] = $record->metadata;
        }

        $mp4 = $this->get_mp4_output_resolution($conversionrecord, $record ?: null);
        if ($mp4) {
            $settings['mp4_output_reso'] = $mp4;
        }

        // Subtitle generation is now handled separately via subtitle_service.
        // Add subtitle settings to the settings array. get it from videolesson_subtitles table.
        // Check for initial subtitle request (pending status) for 'en' language.
        $hassubtitle = false;
        if ($subtitlependingbyhash !== null) {
            $hassubtitle = !empty($subtitlependingbyhash[$conversionrecord->contenthash]);
        } else {
            $subtitlerecord = $DB->get_record('videolesson_subtitles', [
                'contenthash' => $conversionrecord->contenthash,
                'language_code' => 'en',
                'status' => subtitle_service::STATUS_PENDING,
            ]);
            $hassubtitle = (bool) $subtitlerecord;
        }

        if ($hassubtitle) {
            // Lambda will read this setting and automatically generate subtitles after transcoding.
            $settings['subtitle'] = 'en';
        }

        return $settings;
    }

    /**
     * Send file for conversion processing in AWS.
     *
     * @param \stored_file $file The file to upload for conversion.
     * @param array $settings Settings to be used for file conversion.
     * @param \Aws\MockHandler|null $handler Optional handler.
     * @return int $status The status code of the upload.
     */
    private function send_file_for_processing(\stored_file $file, array $settings, $handler = null): int {

        $awshandler = new \mod_videolesson\aws_handler('input');

        $options = [
            'Metadata' => $settings,
        ];

        try {
            $result = $awshandler->put_object(
                $file->get_contenthash(),
                $file,
                $options
            );

            $status = self::CONVERSION_IN_PROGRESS;
        } catch (S3Exception $e) {
            $status = self::CONVERSION_ERROR;
            $details = $e->getAwsErrorCode() . ':' . $e->getMessage();
            $data = [
                'type' => 'ERROR',
                'name' => 'S3',
                'other' => json_encode([$details]),
                'senttoadmin' => 0,
            ];
            $errorlog = new videolesson_logs(0, (object) $data);
            $errorlog->create();
        }

        // Todo: Add event for file sending include status etc.
        return $status;
    }

    /**
     * Get the transcoded media files from AWS S3,
     *
     * @param \stdClass $conversionrecord The conversion record from the database.
     * @param \Aws\MockHandler|null $handler Optional handler.
     * @param bool $mp4check Check if the transcoded file is an MP4 file.
     * IMPROVE THIS
     * @return array $transcodedfiles Array of \stored_file objects.
     */
    public function get_transcode_files(\stdClass $conversionrecord, $handler = null, $mp4check = false): array {
        global $DB;

        $transcodedfiles = [];
        $totalsize = 0;
        $continuationtoken = null;

        $awshandler = new \mod_videolesson\aws_handler('output');

        do {
            // List objects in the bucket with the current continuation token.
            $result = $awshandler->list_objects($conversionrecord->contenthash, $continuationtoken);

            if (!empty($result['Contents']) && is_array($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $totalsize += $object['Size'];

                    $filename = basename($object['Key']);
                    $transcodedfiles[] = ['size' => $object['Size'], 'filename' => $filename];
                    if ($mp4check) {
                        $patharray = explode('/', $object['Key']);
                        if ($patharray[2] == 'mp4') {
                            $DB->set_field('videolesson_conv', 'hasmp4', 1, ['contenthash' => $conversionrecord->contenthash]);
                        }
                    }
                }
            }

            // Check if the response is truncated (i.e., more objects to retrieve).
            $continuationtoken = (!empty($result['IsTruncated']) && isset($result['NextContinuationToken']))
                ? $result['NextContinuationToken']
                : null;
        } while ($continuationtoken);

        return ['totalsize' => $totalsize, 'objects' => $transcodedfiles];
    }

    /**
     * Delete processed files from the AWS S3 input and output buckets.
     *
     * @param string $key The key of the file in the AWS S3 buckets.
     * @return bool
     */
    private function cleanup_aws_input_files(string $key) {
        // Delete original file from input bucket.
        try {
            $awshandler = new \mod_videolesson\aws_handler('input');
            $result = $awshandler->delete_object($key);
            $deleted = true;

            $details = ['input_deleted' => $key];
            $data = [
                'type' => 'INFO',
                'name' => 'S3',
                'other' => json_encode($details),
                'senttoadmin' => 0,
            ];
            $log = new \mod_videolesson\logs(0, (object) $data);
            $log->create();
        } catch (S3Exception $e) {
            $deleted = false;

            $details = [
                'input_deleted' => $key,
                'error' => $e->getAwsErrorMessage(),
                'type' => $e->getAwsErrorType(),
            ];

            $data = [
                'type' => 'ERROR',
                'name' => 'S3',
                'other' => json_encode($details),
                'senttoadmin' => 0,
            ];
            $log = new \mod_videolesson\logs(0, (object) $data);
            $log->create();

            debugging('mod_videolesson: Failed to delete object with key: ' . $key . ' from input bucket.');
        }
        return $deleted;
    }

    /**
     * Process conversion status from DynamoDB.
     *
     * @param \stdClass $conversionrecord The conversion record from the database.
     * @param array $dynamodbstatus Status data from DynamoDB.
     * @param \Aws\MockHandler|null $handler Optional handler.
     * @return \stdClass $conversionrecord The updated conversion record.
     */
    private function process_conversion_from_dynamodb(
        \stdClass $conversionrecord,
        array $dynamodbstatus,
        $handler = null
    ): \stdClass {
        global $DB, $CFG;

        $update = false;
        $statusvalue = strtoupper($dynamodbstatus['status'] ?? '');

        if ($statusvalue === 'COMPLETE' || $statusvalue === 'COMPLETED') {
            $update = true;

            // Get transcoded files.
            $files = $this->get_transcode_files($conversionrecord, $handler, true);
            $conversionrecord->bucket_size = $files['totalsize'];
            $conversionrecord->transcoder_status = self::CONVERSION_FINISHED;

            // Get all instance that is using the video and unhide it.
            require_once($CFG->dirroot . '/mod/videolesson/lib.php');
            videolesson_unhide_cms_using_source($conversionrecord->contenthash);
        } else if ($statusvalue === 'ERROR' || $statusvalue === 'FAILED') {
            $update = true;
            $conversionrecord->status = self::CONVERSION_ERROR;
            $conversionrecord->transcoder_status = self::CONVERSION_ERROR;
            $conversionrecord->timecompleted = time();

            $data = [
                'type' => 'ERROR',
                'name' => 'mediaconvert',
                'other' => json_encode([
                    'error_message' => $dynamodbstatus['error_message'] ?? 'Unknown error',
                    'job_id' => $dynamodbstatus['job_id'] ?? null,
                    'source' => 'dynamodb',
                ]),
                'senttoadmin' => 0,
            ];
            $errorlog = new videolesson_logs(0, (object) $data);
            $errorlog->create();
        }
        // PROGRESSING status - no update needed, keep as is.

        if ($update) {
            $DB->update_record('videolesson_conv', $conversionrecord);
        }

        return $conversionrecord;
    }

    /**
     * Update the overall completion status for a completion record.
     * Overall conversion record is finished when all the individual conversions are finished.
     *
     *
     * @param \stdClass $record The record to check the completion status for.
     * @param \Aws\MockHandler|null $handler Optional handler.
     * @return \stdClass $updatedrecord The updated completion record.
     */
    public function update_completion_status(\stdClass $record, $handler = null): \stdClass {
        global $DB;

        $completionfields = [
            'transcoder_status',
        ];

        $completionstatus = true;
        // Assume completion is true. Iterate through every field.
        // Errors or finished, either way the record is no longer pending.
        foreach ($completionfields as $field) {
            $completionstatus &= (
                $record->$field == self::CONVERSION_FINISHED ||
                $record->$field == self::CONVERSION_NOT_FOUND ||
                $record->$field == self::CONVERSION_ERROR
            );
        }

        if (!empty($record->timemodified)) {
            $timeout = $record->timemodified < (time() - DAYSECS);
        } else {
            $timeout = false;
        }

        // Only set the final completion status if all other processes are finished.
        if ($completionstatus || $timeout) {
            $record->status = self::CONVERSION_FINISHED;
            $record->timemodified = time();
            $record->timecompleted = time();

            // Delete the related files from AWS.
            if ($this->cleanup_aws_input_files($record->contenthash)) {
                $record->input_deleted = 1;
            }

            // Update the database with the modified conversion record.
            $DB->update_record('videolesson_conv', $record);
        }

        return $record;
    }

    /**
     * Update pending conversions.
     *
     * @return array $results The results of the processing.
     */
    public function update_pending_conversions(): array {
        global $DB;
        $results = [];
        $conversionrecords = $this->get_conversion_records(self::CONVERSION_IN_PROGRESS); // Get pending conversion records.

        // Also get conversion records that are finished but have pending/processing subtitles.
        $subtitlesql = "SELECT DISTINCT vc.id, vc.contenthash, vc.status, vc.transcoder_status, vc.mediaconvert, vc.subtitle
                        FROM {videolesson_conv} vc
                        INNER JOIN {videolesson_subtitles} vs ON vc.contenthash = vs.contenthash
                        WHERE vc.status = :finished_status
                          AND vs.status IN ('pending', 'processing')";
        $finishedwithsubtitles = $DB->get_records_sql($subtitlesql, ['finished_status' => self::CONVERSION_FINISHED]);

        // Merge both sets, avoiding duplicates by contenthash.
        $allrecords = [];
        foreach ($conversionrecords as $record) {
            $allrecords[$record->contenthash] = $record;
        }
        foreach ($finishedwithsubtitles as $record) {
            // Only add if not already in the list (prioritize IN_PROGRESS records).
            if (!isset($allrecords[$record->contenthash])) {
                $allrecords[$record->contenthash] = $record;
            }
        }

        $timeoutseconds = 3600 * 24; // 24 hours default timeout.
        $timeout = time() - $timeoutseconds;

        foreach ($allrecords as $conversionrecord) {
            $dynamodbstatus = conversion_status_service::get_status($conversionrecord->contenthash);
            if ($dynamodbstatus !== null) {
                $updatedrecord = $this->process_conversion_from_dynamodb($conversionrecord, $dynamodbstatus);
            } else if ($conversionrecord->timecreated < $timeout) {
                $update = new \stdClass();
                $update->id = $conversionrecord->id;
                $update->transcoder_status = self::CONVERSION_ERROR;
                $update->status = self::CONVERSION_ERROR;
                $update->timecompleted = time();
                $DB->update_record('videolesson_conv', $update);
                $conversionrecord->transcoder_status = self::CONVERSION_ERROR;
                $conversionrecord->status = self::CONVERSION_ERROR;
                $conversionrecord->timecompleted = time();
                $updatedrecord = $conversionrecord;
            } else {
                $updatedrecord = $conversionrecord;
            }

            $results[] = $this->update_completion_status($updatedrecord);
        }

        return $results;
    }
    /**
     * Get the output resolution for the MP4 file.
     *
     * @param \stdClass $conversiondata The conversion data to get the output resolution for.
     * @param \stdClass|null $datarecord Optional preloaded {videolesson_data} row (avoids a duplicate DB read).
     * @return string|false $resolution The output resolution.
     */
    private function get_mp4_output_resolution($conversiondata, ?\stdClass $datarecord = null) {

        global $DB;
        if ($datarecord === null) {
            $record = $DB->get_record('videolesson_data', ['contenthash' => $conversiondata->contenthash]);
        } else {
            $record = $datarecord;
        }
        if (!$record) {
            return false;
        }

        $width = $record->width;
        $height = $record->height;

        if ($width && $height) {
            // Cap the resolution at 1080p.
            if ($height > 1080 || $width > 1920) {
                // Maintain aspect ratio.
                $aspectratio = $width / $height;
                if ($aspectratio > (1920 / 1080)) {
                    $width = 1920;
                    $height = round(1920 / $aspectratio);
                } else {
                    $height = 1080;
                    $width = round(1080 * $aspectratio);
                }
            }

            // Ensure width and height are even values.
            if ($width % 2 !== 0) {
                $width += 1; // Make the width even.
            }
            if ($height % 2 !== 0) {
                $height += 1; // Make the height even.
            }

            return "$width,$height";
        } else {
            return false;
        }
    }
}
