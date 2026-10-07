<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

final class source_policy {
    /** No DNS lookup, HEAD, get_headers, cURL or implicit metadata fetch. */
    public static function url(string $url, string $kind): string {
        global $CFG;
        if (!in_array($kind, ['hls', 'direct'], true)) {
            throw new \invalid_parameter_exception('Unknown media category');
        }
        if (strlen($url) > 1333 || preg_match('/[\x00-\x20\x7f<>"\'`\\\\]/', $url) ||
                preg_match('/[\x00-\x20\x7f<>"\'`\\\\]/', rawurldecode($url))) {
            throw new \invalid_parameter_exception('Malformed media URL');
        }
        $parts = parse_url($url);
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
                empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)) {
            throw new \invalid_parameter_exception('Invalid media URI');
        }
        $origin = $parts['scheme'] . '://' . strtolower($parts['host']) .
            (isset($parts['port']) ? ':' . $parts['port'] : '');
        $allowed = $CFG->videolesson_media_origins ?? [];
        if (!in_array($origin, $allowed, true) || ($parts['scheme'] === 'http' && empty($CFG->videolesson_test_mode))) {
            throw new \invalid_parameter_exception('Media origin is not authorized');
        }
        $suffix = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
        if (($kind === 'hls' && $suffix !== 'm3u8') ||
                ($kind === 'direct' && !in_array($suffix, ['mp4', 'webm'], true))) {
            throw new \invalid_parameter_exception('Media type mismatch');
        }
        return $url;
    }
}
