<?php
// GPL v3 or later. Committed lifecycle events; retained outbox retries outages.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
final class reference_observer {
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        if ($DB->is_transaction_started()) { return; }
        // Moodle's bulk course removal does not emit every module-deleted event.
        // Retained intents identify videos, never bucket prefixes. Bound immediate
        // work; the existing reconciler handles further intents and outages.
        $intents = $DB->get_records('videolesson_intent', ['courseid' => $event->objectid,
            'state' => 'ready', 'tenantid' => providers::tenant()], 'id', '*', 0, 50);
        foreach (array_unique(array_column($intents, 'videouuid')) as $uuid) {
            if (!$uuid) { continue; }
            try { references::sync($uuid); } catch (\Throwable $e) { /* Scheduled retry. */ }
        }
    }
    public static function changed(\core\event\base $event): void {
        global $DB;
        if (($event->other['modulename'] ?? '') !== 'videolesson' || $DB->is_transaction_started()) { return; }
        $id = (int)($event->other['instanceid'] ?? 0);
        $uuids = $DB->get_fieldset_select('videolesson_intent', 'videouuid',
            'instanceid = :id AND state = :state AND tenantid = :tenant',
            ['id' => $id, 'state' => 'ready', 'tenant' => providers::tenant()]);
        $current = $DB->get_record('videolesson', ['id' => $id, 'trustedref' => 1, 'tenantid' => providers::tenant()]);
        if ($current) { $uuids[] = $current->videouuid; }
        foreach (array_unique(array_filter($uuids)) as $uuid) {
            try { references::sync($uuid); } catch (\Throwable $e) { /* Scheduled reconciler retries. */ }
        }
    }
}
