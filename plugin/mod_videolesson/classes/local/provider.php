<?php
// CodeFortex additions, GPL v3 or later. See plugin COPYING/license notices.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** Service-owned media operations. No bucket, pathname or browser URL arguments. */
interface provider {
    public function create_video(string $tenant, string $owner, string $key, string $kind): array;
    public function create_upload_session(string $tenant, string $uuid, string $owner, string $key): array;
    public function get_video(string $tenant, string $uuid): array;
    public function retry_processing(string $tenant, string $uuid, string $owner, string $key): array;
    public function archive(string $tenant, string $uuid, string $owner): void;
    /** Returns token, file, kind and Unix expires; current Moodle authorization is checked before each mint. */
    public function request_playback_grant(string $tenant, string $uuid, int $revision, int $userid): array;
}
