<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();
interface upload_provider {
    public function upload(string $action, \stdClass $instance, int $userid, array $input): array;
}
