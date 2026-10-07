<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
interface gateway_provider {
    public function scoped_grant(string $tenant, string $uuid, int $revision, int $userid, string $session): array;
    public function grant_url(array $grant): string;
}
