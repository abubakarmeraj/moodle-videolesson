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
 * Service for managing transcoding status via DynamoDB.
 *
 * @package     mod_videolesson
 * @author      BitKea Technologies LLP
 * @copyright   2022-2026 BitKea Technologies LLP
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_videolesson\local\services;

use mod_videolesson\conversion;

/**
 * Service for managing transcoding status via DynamoDB.
 *
 * @package     mod_videolesson
 */
class conversion_status_service {
    /**
     * Get transcoding status from DynamoDB for a specific contenthash.
     *
     * @param string $contenthash The video content hash
     * @return array|null Status data or null if not found
     */
    public static function get_status(string $contenthash): ?array {
        $config = get_config('mod_videolesson');
        $hostingtype = $config->hosting_type ?? '';

        // Check if DynamoDB table name is configured (for self-managed).
        // For hosted mode, DynamoDB is accessed via hosted API, so table name check is not required.
        if (empty($config->dynamodb_table_name) && $hostingtype !== 'hosted') {
            return null;
        }

        try {
            $dynamodb = new \mod_videolesson\dynamodb_handler();
            return $dynamodb->get_status($contenthash);
        } catch (\Exception $e) {
            debugging('mod_videolesson: Error getting DynamoDB status: ' . $e->getMessage(), DEBUG_NORMAL);
            return null;
        }
    }

    /**
     * Update Moodle DB status from DynamoDB for a specific contenthash.
     *
     * @param string $contenthash The video content hash
     * @return bool Success
     */
    public static function update_status_from_dynamodb(string $contenthash): bool {
        global $DB;

        $config = get_config('mod_videolesson');
        $hostingtype = $config->hosting_type ?? '';

        // Check if DynamoDB table name is configured (for self-managed).
        // For hosted mode, DynamoDB is accessed via hosted API, so table name check is not required.
        if (empty($config->dynamodb_table_name) && $hostingtype !== 'hosted') {
            return false;
        }

        $record = $DB->get_record('videolesson_conv', ['contenthash' => $contenthash]);
        if (!$record) {
            return false;
        }

        // Only update if still pending/in-progress.
        if (
            $record->transcoder_status != conversion::CONVERSION_ACCEPTED &&
            $record->transcoder_status != conversion::CONVERSION_IN_PROGRESS
        ) {
            return false;
        }

        $status = self::get_status($contenthash);
        if ($status === null) {
            return false;
        }

        $statusvalue = strtoupper($status['status'] ?? '');

        if ($statusvalue === 'COMPLETE' || $statusvalue === 'COMPLETED') {
            $update = new \stdClass();
            $update->id = $record->id;
            $update->transcoder_status = conversion::CONVERSION_FINISHED;
            $DB->update_record('videolesson_conv', $update);
            return true;
        } else if ($statusvalue === 'ERROR' || $statusvalue === 'FAILED') {
            $update = new \stdClass();
            $update->id = $record->id;
            $update->transcoder_status = conversion::CONVERSION_ERROR;
            $update->status = conversion::CONVERSION_ERROR;
            $update->timecompleted = time();
            $DB->update_record('videolesson_conv', $update);
            return true;
        }

        return false;
    }
}
