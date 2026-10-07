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
 * Video
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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use context_system;
/**
 * Get subtitles external API.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class getsubtitles extends external_api {
    /**
     * Returns the description of the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contenthash' => new external_value(PARAM_TEXT, 'The content hash of the video to check', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns the description of the method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'Language code, e.g. en, ja'),
                    'filename' => new external_value(PARAM_FILE, 'Subtitle file name'),
                    'language' => new external_value(PARAM_TEXT, 'Language name'),
                    'url' => new external_value(PARAM_URL, 'Direct URL to subtitle file'),
                ]
            )
        );
    }

    /**
     * Returns the columns plugin order.
     *
     * @param string $contenthash content hash
     */
    public static function execute(string $contenthash) {

        $params = external_api::validate_parameters(self::execute_parameters(), [
            'contenthash' => $contenthash,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('mod/videolesson:manage', $context);
        $videosource = new \mod_videolesson\videosource();

        return $videosource->get_video_subtitles($params['contenthash']);
    }
}
