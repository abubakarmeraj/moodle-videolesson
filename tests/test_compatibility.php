<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright 2026 CodeFortex. Offline classification only; no Moodle/network access.
define('MOODLE_INTERNAL', true);
require(__DIR__ . '/../plugin/mod_videolesson/classes/local/service_setup.php');
use mod_videolesson\local\service_setup;
$base = ['protocol_version' => 1, 'service_version' => '0.5.0-rc1',
    'schema_ready' => true, 'schema_versions' => [1, 2, 3, 4, 5],
    'storage_config_valid' => true, 'raw_reachable' => true,
    'processed_reachable' => true, 'redis_ready' => true, 'worker_capacity_ready' => true,
    'max_duration_seconds' => 21600];
foreach ([
    'COMPATIBLE' => [],
    'SERVICE_TOO_OLD' => ['service_version' => '0.5.0-dev.1'],
    'SERVICE_NEWER_UNTESTED' => ['service_version' => '0.5.1'],
    'SCHEMA_NOT_READY' => ['schema_ready' => false],
    'STORAGE_NOT_READY' => ['raw_reachable' => false],
    'NO_WORKER_CAPACITY' => ['worker_capacity_ready' => false],
] as $expected => $change) {
    if (service_setup::compatibility(array_replace($base, $change)) !== $expected) {
        fwrite(STDERR, "Compatibility mismatch: $expected\n");
        exit(1);
    }
}
echo "COMPATIBILITY_CASES=6 PASS\n";
