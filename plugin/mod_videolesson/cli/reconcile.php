<?php
// CodeFortex additions, GPL v3 or later.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
[$options, $unknown] = cli_get_params(['help' => false, 'run' => false], ['h' => 'help']);
if ($unknown || $options['help']) {
    cli_writeln('CodeFortex reconciliation: default read-only counts; --run processes up to 50 due committed intents.');
    exit($unknown ? 1 : 0);
}
if ($options['run']) {
    (new \mod_videolesson\task\reconcile())->execute();
} else {
    $counts = [];
    foreach (['pending', 'creating', 'ready', 'abandoned'] as $state) {
        $counts[$state] = $DB->count_records('videolesson_intent', ['state' => $state]);
    }
    cli_writeln(json_encode($counts, JSON_THROW_ON_ERROR));
}
