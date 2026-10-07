<?php
// GPL v3 or later. Replacement uses the existing durable provisioning outbox.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

final class replacement {
    public static function current(int $instanceid): ?\stdClass {
        global $DB;
        return $DB->get_record('videolesson_intent', ['instanceid' => $instanceid,
            'purpose' => 'replacement']) ?: null;
    }

    /** POST caller already enforces course edit capability and sesskey. No remote call before commit. */
    public static function begin(\stdClass $instance, string $expected): int {
        global $DB, $USER;
        $cm = get_coursemodule_from_instance('videolesson', $instance->id, $instance->course, false, MUST_EXIST);
        require_capability('moodle/course:manageactivities', \context_module::instance($cm->id));
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock('replacement:' . $instance->id, 5);
        if (!$lock) { throw new \moodle_exception('locktimeout'); }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $instance = $DB->get_record_sql('SELECT * FROM {videolesson} WHERE id = :id FOR UPDATE',
                    ['id' => $instance->id], MUST_EXIST);
                if (!$instance->trustedref || $instance->tenantid !== providers::tenant() ||
                        !hash_equals(hash('sha256', $instance->videouuid . ':' . $instance->mediarevision), $expected)) {
                    throw new \invalid_parameter_exception('Stale replacement');
                }
                $intent = self::current($instance->id);
                if (!$intent) {
                    $id = provisioning::intent((object)['course' => $instance->course, 'mediakind' => $instance->mediakind]);
                    $DB->update_record('videolesson_intent', (object)['id' => $id, 'instanceid' => $instance->id,
                        'purpose' => 'replacement', 'baseuuid' => $instance->videouuid, 'baserevision' => $instance->mediarevision]);
                } else {
                    // Another editor cannot take over an upload owned by the original editor.
                    if ((int)$intent->userid !== (int)$USER->id) { throw new \invalid_parameter_exception('Upload already active'); }
                    $id = $intent->id;
                }
                $tx->allow_commit();
                return (int)$id;
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }

    /** Safe retry after timeout. Old media remains the authoritative player reference until Ready. */
    public static function publish(int $id): bool {
        global $DB, $CFG;
        $i = $DB->get_record('videolesson_intent', ['id' => $id, 'purpose' => 'replacement', 'state' => 'ready']);
        if (!$i || !$i->videouuid || $i->tenantid !== providers::tenant()) { return false; }
        $media = providers::get()->get_video($i->tenantid, $i->videouuid);
        if (($media['state'] ?? '') !== 'ready' || ($media['tenant'] ?? '') !== $i->tenantid ||
                ($media['kind'] ?? '') !== $i->kind || ($media['uuid'] ?? '') !== $i->videouuid ||
                !is_int($media['revision'] ?? null) || $media['revision'] < 1 ||
                !is_numeric($media['duration'] ?? null) || !is_finite((float)$media['duration']) ||
                $media['duration'] <= 0 || $media['duration'] > service_setup::MAX_DURATION) { return false; }
        $tx = $DB->start_delegated_transaction();
        try {
            $v = $DB->get_record_sql('SELECT * FROM {videolesson} WHERE id = :id FOR UPDATE', ['id' => $i->instanceid]);
            $current = self::current($i->instanceid);
            if (!$current || (int)$current->id !== (int)$i->id) { $tx->allow_commit(); return false; }
            if (!$v || !$v->trustedref || $v->tenantid !== $i->tenantid || $v->videouuid !== $i->baseuuid ||
                    (int)$v->mediarevision !== (int)$i->baserevision) {
                // A concurrent relink/deletion wins. Never overwrite it or destroy the unattached media.
                $DB->set_field('videolesson_intent', 'purpose', 'cancelled', ['id' => $i->id]);
                $tx->allow_commit(); return false;
            }
            $DB->update_record('videolesson', (object)['id' => $v->id, 'videouuid' => $i->videouuid,
                'mediarevision' => $media['revision'], 'timemodified' => time()]);
            $DB->set_field('videolesson_intent', 'purpose', 'published', ['id' => $i->id]);
            // Old session/event authorization now fails its existing exact UUID check. Re-evaluate completion.
            require_once($CFG->libdir . '/completionlib.php');
            $course = $DB->get_record('course', ['id' => $v->course], '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('videolesson', $v->id, $v->course, false, MUST_EXIST);
            rebuild_course_cache($course->id, true);
            if ((int)$cm->completion === COMPLETION_TRACKING_AUTOMATIC) {
                $info = get_fast_modinfo($course)->get_cm($cm->id);
                $completion = new \completion_info($course);
                $users = $DB->get_recordset('course_modules_completion', ['coursemoduleid' => $cm->id], '', 'id,userid');
                foreach ($users as $user) { $completion->update_state($info, COMPLETION_UNKNOWN, $user->userid); }
                $users->close();
            }
            $tx->allow_commit();
            return true;
        } catch (\Throwable $e) { $tx->rollback($e); }
    }

    public static function run(): void {
        global $DB;
        $cursor = (int)get_config('mod_videolesson', 'replacementcursor');
        $rows = $DB->get_records_select('videolesson_intent', 'purpose = :purpose AND state = :state AND id > :cursor',
            ['purpose' => 'replacement', 'state' => 'ready', 'cursor' => $cursor], 'id', '*', 0, 50);
        foreach ($rows as $i) {
            try { self::publish($i->id); } catch (\Throwable $e) { /* Durable state will be retried. */ }
            $cursor = $i->id;
        }
        set_config('replacementcursor', $rows ? $cursor : 0, 'mod_videolesson');
    }
}
