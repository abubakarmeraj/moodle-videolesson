<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** The Moodle reference set is authoritative; service retention never counts storage objects here. */
final class references {
    /** Discover restored/pre-V2.1 references without provider I/O or forged service metadata. */
    public static function run(): array {
        global $DB;
        $cursor = (int)get_config('mod_videolesson', 'referencecursor');
        $records = $DB->get_records_select('videolesson', 'id > :cursor', ['cursor' => $cursor], 'id', '*', 0, 50);
        foreach ($records as $record) {
            if ($record->trustedref && $record->videouuid && $record->tenantid === providers::tenant() &&
                    !$DB->record_exists('videolesson_intent', ['instanceid' => $record->id, 'purpose' => 'create'])) {
                $key = hash('sha256', $record->tenantid . ':reference:' . $record->id);
                $DB->insert_record('videolesson_intent', (object)['operationkey' => $key, 'instanceid' => $record->id,
                    'courseid' => $record->course, 'userid' => 0, 'tenantid' => $record->tenantid,
                    'kind' => $record->mediakind, 'state' => 'ready', 'videouuid' => $record->videouuid,
                    'timecreated' => time(), 'timemodified' => time()]);
            }
            $cursor = $record->id;
        }
        set_config('referencecursor', $records ? $cursor : 0, 'mod_videolesson');
        $cursor = (int)get_config('mod_videolesson', 'snapshotcursor');
        $intents = $DB->get_records_select('videolesson_intent', 'state = :state AND id > :cursor',
            ['state' => 'ready', 'cursor' => $cursor], 'id', '*', 0, 50);
        $result = [];
        foreach ($intents as $intent) {
            if ($intent->userid && (!$DB->record_exists('course', ['id' => $intent->courseid]) ||
                    !$DB->record_exists('user', ['id' => $intent->userid, 'deleted' => 0]))) {
                $DB->set_field('videolesson_intent', 'userid', 0, ['id' => $intent->id]);
            }
            try {
                if ($intent->tenantid === providers::tenant()) {
                    self::sync($intent->videouuid);
                    $result[$intent->id] = 'synchronized';
                }
            } catch (\Throwable $e) { $result[$intent->id] = 'retry-required'; }
            $cursor = $intent->id;
        }
        set_config('snapshotcursor', $intents ? $cursor : 0, 'mod_videolesson');
        return $result;
    }

    public static function sync(string $uuid): int {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Reference synchronization requires committed state');
        }
        $provider = providers::get();
        if (!$provider instanceof service_lifecycle) {
            throw new \coding_exception('Provider must implement the V2.1 lifecycle contract');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock('reference-snapshot', 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $records = $DB->get_records('videolesson', ['tenantid' => providers::tenant(), 'videouuid' => $uuid,
                'trustedref' => 1], 'id', 'id');
            $references = array_map(fn($id) => 'activity-' . $id, array_keys($records));
            $pending = $DB->get_records_sql('SELECT DISTINCT v.id FROM {videolesson} v
                JOIN {videolesson_intent} i ON i.instanceid = v.id AND i.courseid = v.course
                WHERE i.purpose = :purpose AND i.state = :state AND i.videouuid = :uuid
                    AND i.baseuuid = v.videouuid AND i.baserevision = v.mediarevision
                    AND v.tenantid = :tenant AND v.trustedref = 1',
                ['purpose' => 'replacement', 'state' => 'ready', 'uuid' => $uuid, 'tenant' => providers::tenant()]);
            $references = array_values(array_unique(array_merge($references,
                array_map(fn($id) => 'activity-' . $id, array_keys($pending)))));
            $generation = (int)get_config('mod_videolesson', 'referencegeneration') + 1;
            set_config('referencegeneration', $generation, 'mod_videolesson');
            $provider->sync_references(providers::tenant(), $uuid, $references, $generation);
            return $generation;
        } finally {
            $lock->release();
        }
    }
}
