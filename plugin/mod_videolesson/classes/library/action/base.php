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
 * Base action handler for library actions
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\library\action;

/**
 * Base class for library action handlers
 */
abstract class base {
    /** @var \context_system System context */
    protected $systemcontext;

    /** @var \moodle_url Base URL for library */
    protected $baseurl;

    /** @var \moodle_url List URL */
    protected $listurl;

    /** @var array Breadcrumb items */
    protected $breadcrumbs = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->systemcontext = \context_system::instance();
        $this->baseurl = new \moodle_url('/mod/videolesson/library.php');
        $this->listurl = new \moodle_url('/mod/videolesson/library.php', ['action' => 'list']);
    }

    /**
     * Check if user has manage capability
     */
    protected function require_capability() {
        if (!has_capability('mod/videolesson:manage', $this->systemcontext)) {
            redirect(
                $this->baseurl,
                get_string('error:nocap:access', 'mod_videolesson'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }

    /**
     * Check sesskey
     *
     * @param \moodle_url|null $redirecturl URL to redirect to on failure
     */
    protected function require_sesskey($redirecturl = null) {
        if (!confirm_sesskey()) {
            $url = $redirecturl ?? $this->baseurl;
            redirect(
                $url,
                get_string('error:invalidsesskey', 'mod_videolesson'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
    }

    /**
     * Check if user is site admin
     *
     * @return bool True if user has site config capability
     */
    protected function is_site_admin() {
        return has_capability('moodle/site:config', $this->systemcontext);
    }

    /**
     * Setup base navigation (Activity modules > Video Lesson)
     * Called before action-specific navigation
     */
    protected function setup_base_navigation() {
        if ($this->is_site_admin()) {
            // Add "Activity modules" breadcrumb.
            $this->add_breadcrumb(
                get_string('activitymodules'),
                new \moodle_url('/admin/category.php', ['category' => 'modsettings'])
            );

            // Add "Video Lesson" breadcrumb.
            $this->add_breadcrumb(
                get_string('modulename', 'mod_videolesson'),
                new \moodle_url('/admin/category.php', ['category' => 'modvideolessonfolder'])
            );
        } else {
            $this->add_breadcrumb(
                get_string('mycourses'),
                new \moodle_url('/my/courses.php')
            );
        }
        // Non-admin users skip Activity modules and Video Lesson.
    }

    /**
     * Add a breadcrumb item
     *
     * @param string $text Breadcrumb text
     * @param \moodle_url|null $url Breadcrumb URL (null for current page)
     */
    protected function add_breadcrumb($text, $url = null) {
        $item = ['text' => $text];
        if ($url !== null) {
            $item['url'] = $url->out(false);
        }
        $this->breadcrumbs[] = $item;
    }

    /**
     * Render breadcrumb using template
     *
     * @return string HTML output
     */
    public function render_breadcrumb() {
        global $OUTPUT;

        if (empty($this->breadcrumbs)) {
            return '';
        }

        return $OUTPUT->render_from_template('mod_videolesson/breadcrumb', [
            'items' => $this->breadcrumbs,
        ]);
    }

    /**
     * Setup navigation/breadcrumb for this action
     * Override in child classes to customize navigation
     */
    public function setup_navigation() {
        // Default: no navigation (for delete, retry, etc.).
    }

    /**
     * Execute the action
     */
    abstract public function execute();
}
