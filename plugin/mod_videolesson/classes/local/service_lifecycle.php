<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** Service contract: opaque tenant/owner references, never student learning telemetry or profile data. */
interface service_lifecycle {
    /** Complete current Moodle reference snapshot, monotonically ordered within this tenant. */
    public function sync_references(string $tenant, string $uuid, array $references, int $generation): void;
    /** Owner metadata export: no credentials, upload grants, student subjects or watch events. */
    public function export_owner(string $tenant, string $owner): array;
    /** Explicit idempotent deletion request. Referenced/held media must fail closed. */
    public function request_deletion(string $tenant, string $uuid, string $owner, string $key): array;
    /** Idempotent privacy request; shared content is retained and owner identity pseudonymized. */
    public function erase_owner(string $tenant, string $owner, string $key): array;
}
