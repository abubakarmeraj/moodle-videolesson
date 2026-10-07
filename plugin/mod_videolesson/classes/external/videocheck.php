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
 * Class videocheck
 * Provides an external API method to check if a video is transcoded.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\external;

// Quarantined V1 API: unregistered in services.php; cannot invoke vendor or legacy tracking code.
throw new \moodle_exception('cflegacydisabled', 'mod_videolesson');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use context_module;

/**
 * Videocheck external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class videocheck extends external_api {
    /**
     * Returns the description of the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contenthash' => new external_value(PARAM_TEXT, 'The content hash of the video to check', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns the description of the method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'The status of the video transcoding process'),
            'type' => new external_value(PARAM_TEXT, 'The type of the result message', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'The result message', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Executes the external function to check if a video is transcoded.
     *
     * @param string $contenthash The content hash of the video.
     * @param int $cmid Course module id.
     * @return array The result of the transcoding status check.
     */
    public static function execute(string $contenthash, int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'contenthash' => $contenthash,
            'cmid' => $cmid,
        ]);

        $context = \context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/videolesson:view', $context);
        // Initialize the video source handler.
        $videosource = new \mod_videolesson\videosource();

        // Check if the video is transcoded and return the result.
        return $videosource->is_transcoded($params['contenthash']);
    }
}
