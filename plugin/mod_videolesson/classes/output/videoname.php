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
 * Video name inplace editable
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\output;

use lang_string;

/**
 * Class to prepare a video name for display.
 */
class videoname extends \core\output\inplace_editable {
    /**
     * Constructor.
     *
     * @param stdClass $video
     */
    public function __construct($video) {
        $sitecontext = \context_system::instance();
        $editable = has_capability('mod/videolesson:manage', $sitecontext);
        $displayvalue = format_string($video->name);
        parent::__construct(
            'mod_videolesson',
            'videoname',
            $video->id,
            $editable,
            $displayvalue,
            $video->name,
            new lang_string('inplace:edit:name', 'mod_videolesson'),
            new lang_string('inplace:edit:newname', 'mod_videolesson', $displayvalue)
        );
    }

    /**
     * Render the inplace editable using the caller's renderer.
     *
     * We override the core type-hinted signature to avoid strict type issues
     * when bootstrap_renderer (a renderer_base subclass) is passed in.
     *
     * @param mixed $output Renderer instance.
     * @return string
     */
    public function render($output) {
        return $output->render_from_template('core/inplace_editable', $this->export_for_template($output));
    }

    /**
     * Updates video name and returns instance of this object
     *
     * @param int $itemid
     * @param string $newvalue
     * @return static
     */
    public static function update($itemid, $newvalue) {
        global $DB;

        $record = $DB->get_record('videolesson_conv', ['id' => $itemid], '*', MUST_EXIST);
        $sitecontext = \context_system::instance();
        \core_external\external_api::validate_context($sitecontext);
        require_capability('mod/videolesson:manage', $sitecontext);
        $newvalue = clean_param($newvalue, PARAM_TEXT);
        if (strval($newvalue) !== '') {
            $record->name = $newvalue;
            $DB->update_record('videolesson_conv', ['id' => $itemid, 'name' => $newvalue]);
        }
        return new static($record);
    }
}
