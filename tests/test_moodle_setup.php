<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright 2026 CodeFortex. Explicit disposable-site setup smoke, not production.
define('CLI_SCRIPT', true);
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php test_moodle_setup.php <disposable-moodle-config.php>\n");
    exit(2);
}
require($argv[1]);
require_once($CFG->libdir . '/adminlib.php');
\core\session\manager::set_user(get_admin());
$connection = (new \mod_videolesson\local\real_provider())->test_connection();
$out = ['connection_status' => $connection['connection_status'],
    'plugin_version' => get_config('mod_videolesson', 'version')];
echo json_encode($out), "\n";
exit($out['connection_status'] === 'COMPATIBLE' && $out['plugin_version'] == 2026100701 ? 0 : 1);
