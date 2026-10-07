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
 * Library of interface functions and constants.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/completionlib.php');

function videolesson_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO, FEATURE_BACKUP_MOODLE2, FEATURE_COMPLETION_TRACKS_VIEWS, FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_CONTENT,
        default => null,
    };
}
function videolesson_add_instance($data, $mform = null) {
    global $DB, $USER;
    $tenant = \mod_videolesson\local\providers::tenant();
    $kind = $data->mediakind ?? 'direct';
    $reference = empty($data->cfexisting) ? null :
        \mod_videolesson\local\library::select((int)$data->course, (int)$data->cfexisting);
    if ($reference) {
        $kind = $data->mediakind = $reference->mediakind;
    }
    $intentid = \mod_videolesson\local\provisioning::intent($data);
    $data->source = 'codefortex'; $data->sourcedata = '';
    $data->videouuid = ''; $data->tenantid = $tenant;
    $data->trustedref = 1;
    $data->mediarevision = 1; $data->mediakind = $kind;
    $data->options = json_encode(['seek' => !empty($data->disableseek)]);
    $data->completionprogress = empty($data->completionprogressenabled) ? 0 : (int)$data->completionprogress;
    if ($data->completionprogress < 0 || $data->completionprogress > 100) {
        throw new invalid_parameter_exception('Invalid completion threshold');
    }
    $data->timecreated = time(); $data->timemodified = time();
    $id = $DB->insert_record('videolesson', $data);
    \mod_videolesson\local\provisioning::attach($intentid, $id);
    if ($reference) {
        $DB->update_record('videolesson', (object)['id' => $id, 'videouuid' => $reference->videouuid,
            'mediarevision' => $reference->mediarevision]);
        $DB->update_record('videolesson_intent', (object)['id' => $intentid, 'state' => 'ready',
            'videouuid' => $reference->videouuid]);
    }
    return $id;
}
function videolesson_update_instance($data, $mform = null) {
    global $DB;
    $transaction = $DB->start_delegated_transaction();
    try {
    $old = $DB->get_record('videolesson', ['id' => $data->instance], '*', MUST_EXIST);
    // Never trust posted media identity; an optional selector resolves an authorized source server-side.
    $record = (object)['id' => $old->id, 'name' => $data->name,
        'intro' => $data->intro ?? $old->intro, 'introformat' => $data->introformat ?? $old->introformat,
        'completionprogress' => empty($data->completionprogressenabled) ? 0 : (int)$data->completionprogress,
        'options' => json_encode(['seek' => !empty($data->disableseek)]), 'timemodified' => time()];
    if ($record->completionprogress < 0 || $record->completionprogress > 100) {
        throw new invalid_parameter_exception('Invalid completion threshold');
    }
    if (!empty($data->cfexisting)) {
        $reference = \mod_videolesson\local\library::select((int)$old->course, (int)$data->cfexisting);
        $record->videouuid = $reference->videouuid; $record->tenantid = $reference->tenantid;
        $record->mediarevision = $reference->mediarevision; $record->mediakind = $reference->mediakind;
        $record->trustedref = 1;
        // Finish creation before relinking; do not race a reconciler across Moodle's outer update transaction.
        $intent = $DB->get_record('videolesson_intent', ['instanceid' => $old->id, 'purpose' => 'create']);
        if ($intent && in_array($intent->state, ['creating', 'pending'], true)) {
            throw new moodle_exception('cfprovisioning', 'mod_videolesson');
        }
        // Explicit relink wins over a queued replacement; its outbox remains available for safe reconciliation.
        $DB->set_field('videolesson_intent', 'purpose', 'cancelled',
            ['instanceid' => $old->id, 'purpose' => 'replacement']);
    }
    $result = $DB->update_record('videolesson', $record);
    if (isset($record->videouuid) && ($record->videouuid !== $old->videouuid ||
            (int)$record->mediarevision !== (int)$old->mediarevision)) {
        $course = $DB->get_record('course', ['id' => $old->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('videolesson', $old->id, $old->course, false, MUST_EXIST);
        if ((int)$cm->completion === COMPLETION_TRACKING_AUTOMATIC) {
            rebuild_course_cache($course->id, true);
            $info = get_fast_modinfo($course)->get_cm($cm->id);
            $completion = new completion_info($course);
            $users = $DB->get_recordset('course_modules_completion', ['coursemoduleid' => $cm->id], '', 'id,userid');
            foreach ($users as $user) {
                // Moodle itself preserves explicit staff overrides. Re-evaluate automatic status for the new UUID.
                $completion->update_state($info, COMPLETION_UNKNOWN, $user->userid);
            }
            $users->close();
        }
    }
    $transaction->allow_commit();
    return $result;
    } catch (Throwable $error) {
        $transaction->rollback($error);
    }
}
function videolesson_delete_instance($id) {
    global $DB;
    $instance = $DB->get_record('videolesson', ['id' => $id]);
    if (!$instance) {
        return false;
    }
    $intent = $DB->get_record('videolesson_intent', ['instanceid' => $id, 'purpose' => 'create']);
    $lock = $intent ? \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock("intent:$intent->id", 5) : null;
    if ($intent && !$lock) {
        throw new moodle_exception('locktimeout');
    }
    try {
    $cm = get_coursemodule_from_instance('videolesson', $id);
    $transaction = $DB->start_delegated_transaction();
    if ($cm) {
        foreach (['videolesson_session' => 'cmid', 'videolesson_progress' => 'cmid',
                'videolesson_usage' => 'cm', 'videolesson_cm_progress' => 'cmid'] as $table => $field) {
            $DB->delete_records($table, [$field => $cm->id]);
        }
    }
    if ($instance->trustedref && $instance->videouuid && $instance->tenantid === \mod_videolesson\local\providers::tenant()) {
        $key = hash('sha256', 'deleted:' . $instance->tenantid . ':' . $id . ':' . $instance->videouuid);
        if (!$DB->record_exists('videolesson_intent', ['operationkey' => $key])) {
            $DB->insert_record('videolesson_intent', (object)['operationkey' => $key, 'purpose' => 'reference',
                'instanceid' => $id, 'courseid' => $instance->course, 'userid' => 0, 'tenantid' => $instance->tenantid,
                'kind' => $instance->mediakind, 'state' => 'ready', 'videouuid' => $instance->videouuid,
                'timecreated' => time(), 'timemodified' => time()]);
        }
    }
    $DB->delete_records('videolesson', ['id' => $id]);
    $transaction->allow_commit();
    // Remove only this Moodle reference; NEVER destroy service-owned/shared media.
    return true;
    } finally {
        $lock?->release();
    }
}
function videolesson_view($instance, $course, $cm, $context) {
    $event = \mod_videolesson\event\course_module_viewed::create(['context' => $context, 'objectid' => $instance->id]);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('videolesson', $instance);
    $event->trigger();
    (new completion_info($course))->set_module_viewed($cm);
}
function videolesson_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, $options = []) {
    if (!$cm || $context->contextlevel !== CONTEXT_MODULE || $context->instanceid !== $cm->id ||
            $filearea !== 'thumbnail') {
        return false;
    }
    \mod_videolesson\local\access::activity($cm->id);
    if (count($args) < 2 || (int)array_shift($args) !== 0) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file($context->id, 'mod_videolesson', 'thumbnail', 0, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, ['cacheability' => 'private'] + $options);
}
function videolesson_get_coursemodule_info($cm) {
    global $DB;
    $instance = $DB->get_record('videolesson', ['id' => $cm->instance]);
    if (!$instance) {
        return false;
    }
    $result = new cached_cm_info();
    $result->name = $instance->name;
    if ($cm->showdescription) {
        $result->content = format_module_intro('videolesson', $instance, $cm->id, false);
    }
    if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionprogress'] = $instance->completionprogress;
    }
    return $result;
}
function videolesson_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    $cm = $settings->get_page()->cm;
    if ($cm && has_capability('mod/videolesson:reports', context_module::instance($cm->id))) {
        $node->add(get_string('reports'), new moodle_url('/mod/videolesson/report.php', ['id' => $cm->id]));
    }
}
