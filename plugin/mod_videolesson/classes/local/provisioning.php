<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** Durable Moodle intent, not a distributed transaction. Provider I/O only after commit. */
final class provisioning {
    public static function key(string $submission, int $courseid, int $userid): string {
        if (!preg_match('/^[a-f0-9]{64}$/D', $submission)) {
            throw new \invalid_parameter_exception('Invalid submission identity');
        }
        return hash('sha256', providers::tenant() . ":$courseid:$userid:$submission");
    }

    /** Called inside Moodle's add_moduleinfo transaction. No service I/O here. */
    public static function intent(\stdClass $data): int {
        global $DB, $USER;
        if (!in_array($data->mediakind ?? 'direct', ['direct', 'hls'], true)) {
            throw new \invalid_parameter_exception('Invalid media kind');
        }
        // Native forms always supply a persistent submission key. CLI callers receive one operation per call.
        $key = self::key($data->cfsubmission ?? bin2hex(random_bytes(32)), $data->course, $USER->id);
        if ($DB->record_exists('videolesson_intent', ['operationkey' => $key])) {
            throw new \moodle_exception('cfduplicatesubmission', 'mod_videolesson');
        }
        return $DB->insert_record('videolesson_intent', (object)[
            'operationkey' => $key, 'instanceid' => 0, 'courseid' => $data->course, 'userid' => $USER->id,
            'tenantid' => providers::tenant(), 'kind' => $data->mediakind ?? 'direct',
            'state' => 'pending', 'videouuid' => '', 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    public static function attach(int $intentid, int $instanceid): void {
        global $DB;
        $DB->set_field('videolesson_intent', 'instanceid', $instanceid, ['id' => $intentid]);
    }

    /** Bounded, idempotent attempt. Request termination leaves a durable replayable key. */
    public static function reconcile(int $id): string {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Provisioning requires committed Moodle state');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock("intent:$id", 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $intent = $DB->get_record('videolesson_intent', ['id' => $id], '*', MUST_EXIST);
            if (in_array($intent->state, ['ready', 'abandoned'], true)) {
                return $intent->state;
            }
            if ($intent->tenantid !== providers::tenant()) {
                throw new \moodle_exception('cfrelink', 'mod_videolesson');
            }
            $instance = $DB->get_record('videolesson', ['id' => $intent->instanceid, 'course' => $intent->courseid]);
            if ((!$instance || $intent->purpose === 'cancelled') && $intent->state === 'pending') {
                $intent->state = 'abandoned';
                $DB->update_record('videolesson_intent', $intent);
                return $intent->state;
            }
            // Commit this marker before provider call. A timeout MUST NOT allocate a different key.
            $intent->state = 'creating'; $intent->attempts++;
            $intent->timemodified = time();
            $intent->nextattempt = time() + min(3600, 30 * (2 ** min(7, $intent->attempts)));
            $DB->update_record('videolesson_intent', $intent);
            $media = providers::get()->create_video($intent->tenantid, (string)$intent->userid,
                $intent->operationkey, $intent->kind);
            if (!preg_match('/^[a-f0-9-]{36}$/D', $media['uuid'] ?? '') ||
                    ($media['tenant'] ?? '') !== $intent->tenantid || ($media['kind'] ?? '') !== $intent->kind ||
                    !is_int($media['revision'] ?? null) || $media['revision'] < 1) {
                throw new \invalid_parameter_exception('Invalid provider response');
            }
            $intent->videouuid = $media['uuid'];
            // Lock the committed row, not just a PHP callback. Deletion's outer Moodle transaction
            // can outlive its callback lock. FOR UPDATE is qualified on PostgreSQL and MariaDB.
            $transaction = $DB->start_delegated_transaction();
            try {
                $instance = $DB->get_record_sql('SELECT * FROM {videolesson}
                    WHERE id = :id AND course = :course FOR UPDATE',
                    ['id' => $intent->instanceid, 'course' => $intent->courseid]);
                $intent->purpose = $DB->get_field('videolesson_intent', 'purpose', ['id' => $intent->id], MUST_EXIST);
                if ($intent->purpose === 'cancelled') { $instance = false; }
                if ($instance) {
                if ($intent->purpose === 'create') {
                    $DB->update_record('videolesson', (object)['id' => $instance->id,
                        'videouuid' => $media['uuid'], 'mediarevision' => $media['revision']]);
                }
                $intent->state = 'ready';
                }
                $DB->update_record('videolesson_intent', $intent);
                $transaction->allow_commit();
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
            if (!$instance) {
                // Only compensate abandoned *creation*, never ordinary deletion of ready/shared media.
                if (!$DB->record_exists('videolesson', ['tenantid' => $intent->tenantid, 'videouuid' => $media['uuid']])) {
                    providers::get()->archive($intent->tenantid, $media['uuid'], (string)$intent->userid);
                }
                $intent->state = 'abandoned';
                $DB->update_record('videolesson_intent', $intent);
            }
            return $intent->state;
        } finally {
            $lock->release();
        }
    }

    public static function run(int $limit = 50): array {
        global $DB;
        $results = [];
        $records = $DB->get_records_select('videolesson_intent',
            'state IN (:pending, :creating) AND nextattempt <= :now',
            ['pending' => 'pending', 'creating' => 'creating', 'now' => time()], 'id', '*', 0, min(50, max(1, $limit)));
        foreach ($records as $intent) {
            try {
                $results[$intent->id] = self::reconcile($intent->id);
            } catch (\Throwable $error) {
                // No provider reply/body/token is written to logs. Durable marker retains retry state.
                $results[$intent->id] = 'retry-required';
            }
        }
        return $results;
    }
}
