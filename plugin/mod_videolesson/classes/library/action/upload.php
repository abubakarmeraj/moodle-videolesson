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
 * Upload action handler
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\library\action;

/**
 * Handles video upload action
 */
class upload extends base {
    /**
     * Setup navigation for upload action
     */
    public function setup_navigation() {
        $this->setup_base_navigation();

        // Add "Video Library" link.
        $this->add_breadcrumb(get_string('header_manage_videos', 'mod_videolesson'), $this->listurl);
    }

    /**
     * Execute upload action
     */
    public function execute() {
        global $OUTPUT, $PAGE, $CFG;

        // Check if uploads are restricted for external hosting type.
        $access = new \mod_videolesson\access();
        if ($access->restrict_library()) {
            $settingsurl = $CFG->wwwroot . '/admin/settings.php?section=modsettingvideolesson';
            redirect(
                $this->listurl,
                get_string('error:upload:external:not_available', 'mod_videolesson', $settingsurl),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        $requestedfolder = optional_param('folder', 'uncategorized', PARAM_TEXT);
        $defaultfolder = \mod_videolesson\local\services\video_list_service::normalise_folder_identifier($requestedfolder);
        if ($defaultfolder === 'all' || $defaultfolder === null) {
            $defaultfolder = 'uncategorized';
        }

        $pageurl = new \moodle_url('/mod/videolesson/library.php', [
            'action' => 'upload',
            'folder' => $requestedfolder,
        ]);

        $heading = get_string('upload:new:video', 'mod_videolesson');
        $PAGE->set_title($heading);
        $PAGE->set_heading($heading);

        $awshandler = new \mod_videolesson\aws_handler('output');
        $folderoptions = \mod_videolesson\folder_manager::get_folder_options();
        $folderoptions = ['uncategorized' => get_string('folder:uncategorized', 'mod_videolesson')] + $folderoptions;

        $mform = new \mod_videolesson\local\form\manage_upload_form($pageurl->out(false), [
            'folderoptions' => $folderoptions,
            'defaultfolder' => $defaultfolder,
            'canmanagefolders' => has_capability('mod/videolesson:manage', $this->systemcontext),
        ]);

        if ($mform->is_cancelled()) {
            redirect($this->listurl);
        } else if ($data = $mform->get_data()) {
            $this->process_upload($data, $awshandler);
            redirect($this->listurl);
        }

        $canupload = $awshandler->canupload();
        if (!$canupload['can_upload']) {
            redirect(
                $this->listurl,
                get_string('canupload:' . $canupload['code'], 'mod_videolesson'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        $ffprobe = new \mod_videolesson\ffprobe(false);

        echo $OUTPUT->header();
        echo $this->render_breadcrumb();
        if (!$ffprobe->is_valid_path()) {
            echo $OUTPUT->notification(
                get_string(
                    'error:upload:video:ffprobe:notset',
                    'mod_videolesson',
                    $CFG->wwwroot . '/admin/settings.php?section=modsettingvideolesson#id_s_mod_videolesson_pathtoffprobe'
                ),
                'error',
                false
            );
        } else {
            $mform->display();
        }
        echo $OUTPUT->footer();
    }

    /**
     * Process uploaded files
     *
     * @param object $data Form data
     * @param \mod_videolesson\aws_handler $awshandler AWS handler
     */
    private function process_upload($data, $awshandler) {
        global $DB;

        $exists = [];
        $added = [];
        $selectedfoldervalue = $data->targetfolder ?? 'uncategorized';
        $selectedfolderid = null;
        if ($selectedfoldervalue !== 'uncategorized' && $selectedfoldervalue !== '') {
            $selectedfolderid = (int)$selectedfoldervalue;
        }

        // Check if there's a submitted draft item for new video.
        if ($draftitemid = file_get_submitted_draft_itemid('videos')) {
            // Save draft area files.
            $sitecontext = $this->systemcontext;
            file_save_draft_area_files($draftitemid, $sitecontext->id, 'mod_videolesson', 'toaws', 0, []);
            $videosource = new \mod_videolesson\videosource();

            $existingprefixes = $awshandler->list_all_prefixes_array(); // All in the bucket.

            // Get file storage.
            $fs = get_file_storage();
            // Get area files.
            $files = $fs->get_area_files($sitecontext->id, 'mod_videolesson', 'toaws', 0, 'sortorder DESC, id ASC', false);
            foreach ($files as $file) {
                if (in_array($file->get_contenthash(), $existingprefixes)) {
                    $src = $videosource->get_video_src($file->get_contenthash());
                    $attr = [
                        'class' => 'videolesson-viewmodal-href',
                        'data-videolesson-action' => 'viewmodal',
                        'data-videolesson-contenthash' => $file->get_contenthash(),
                    ];
                    $exists[] = \html_writer::link('#videolesson-src=' . $src, $file->get_filename(), $attr);
                } else {
                    $opts = [];
                    if (!empty($data->subtitle)) {
                        // Temp. we will add more opts in future like what languages but for now just a flag.
                        // Default langs will be used.
                        $opts['subtitle'] = 1;
                    }
                    videolesson_maybe_addfiletosources($file, $opts);
                    if (isset($selectedfoldervalue)) {
                        $conversion = $DB->get_record(
                            'videolesson_conv',
                            ['contenthash' => $file->get_contenthash()],
                            'id',
                            IGNORE_MISSING
                        );
                        if ($conversion) {
                            \mod_videolesson\folder_manager::move_video((int)$conversion->id, $selectedfolderid);
                        }
                    }
                    $added[] = $file->get_filename();
                }
            }
        }

        if ($added) {
            $message = get_string('manage:upload:video:added', 'mod_videolesson');
            $message .= \html_writer::alist($added, null, 'ul');
            \core\notification::add($message, \core\notification::SUCCESS);
        }

        if ($exists) {
            $message = get_string('manage:upload:video:skipped', 'mod_videolesson');
            $message .= \html_writer::alist($exists, null, 'ul');
            \core\notification::add($message, \core\notification::ERROR);
        }
    }
}
