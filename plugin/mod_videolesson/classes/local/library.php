<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** MVP: reuse an accessible ready reference in the SAME course and tenant. No global enumeration. */
final class library {
    public static function select(int $courseid, int $instanceid): \stdClass {
        global $DB, $USER;
        require_capability('moodle/course:manageactivities', \context_course::instance($courseid));
        if ($instanceid < 0) {
            // Only a site administrator can reuse a kept tombstone without a live source activity.
            $intent = storage_admin::intent(-$instanceid);
            $media = providers::get()->get_video($intent->tenantid, $intent->videouuid);
            if ($media['state'] !== 'ready' || empty($media['kept'])) {
                throw new \moodle_exception('cfrelink', 'mod_videolesson');
            }
            return (object)['videouuid' => $intent->videouuid, 'tenantid' => $intent->tenantid,
                'mediarevision' => $media['revision'], 'mediakind' => $media['kind'], 'trustedref' => 1];
        }
        // During a mutation hold the source reference until the destination is durable. A last-reference
        // deletion then cannot race a reuse into a falsely empty provider snapshot.
        $source = $DB->is_transaction_started()
            ? $DB->get_record_sql('SELECT * FROM {videolesson} WHERE id = :id AND course = :course FOR UPDATE',
                ['id' => $instanceid, 'course' => $courseid], MUST_EXIST)
            : $DB->get_record('videolesson', ['id' => $instanceid, 'course' => $courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('videolesson', $source->id, $courseid, false, MUST_EXIST);
        // Selector validation must not replace the edit form's PAGE/cm context.
        $context = \context_module::instance($cm->id);
        $visible = get_fast_modinfo($courseid, $USER->id)->get_cm($cm->id)->uservisible;
        if (!isloggedin() || isguestuser() || !$visible ||
                (!is_enrolled($context, $USER, '', true) && !has_capability('moodle/course:view', $context))) {
            throw new \required_capability_exception($context, 'mod/videolesson:view', 'nopermissions', '');
        }
        require_capability('mod/videolesson:view', $context);
        require_capability('moodle/course:manageactivities', $context);
        providers::media($source);
        return $source;
    }

    public static function options(int $courseid, int $exclude = 0): array {
        global $DB;
        require_capability('moodle/course:manageactivities', \context_course::instance($courseid));
        $options = [0 => get_string('cfcreatenew', 'mod_videolesson')];
        if (has_capability('moodle/site:config', \context_system::instance())) {
            try {
                // One bounded metadata request, not a network request for every historical intent.
                $kept = storage_admin::provider()->storage_request('storage_status', ['kept_only' => true]);
                foreach ($kept['videos'] as $video) {
                    $intents = $DB->get_records('videolesson_intent', ['tenantid' => providers::tenant(),
                        'state' => 'ready', 'videouuid' => $video['uuid']], 'id DESC', '*', 0, 1);
                    if (!$intents) { continue; }
                    $intent = reset($intents);
                    $options[-(int)$intent->id] = get_string('cfkeptvideo', 'mod_videolesson', $intent->id);
                }
            } catch (\moodle_exception $e) { /* Unavailable storage does not break the ordinary form. */ }
        }
        // Deliberately bounded; the MVP is not a tenant-wide search or upstream AWS library.
        foreach ($DB->get_records('videolesson', ['course' => $courseid], 'name, id', '*', 0, 100) as $record) {
            if ((int)$record->id === $exclude) {
                continue;
            }
            try {
                self::select($courseid, $record->id);
                $options[$record->id] = format_string($record->name);
            } catch (\moodle_exception $e) {
                // Missing, archived, cross-tenant or hidden entries are never enumerated.
            }
        }
        return $options;
    }
}
