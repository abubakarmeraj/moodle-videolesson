<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\task;
defined('MOODLE_INTERNAL') || die();
final class reconcile extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('cfreconciletask', 'mod_videolesson');
    }
    public function execute(): void {
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock('reconciliation-run', 5);
        if (!$lock) { throw new \moodle_exception('locktimeout'); }
        try {
        foreach (\mod_videolesson\local\provisioning::run() as $id => $state) {
            mtrace("CodeFortex intent $id: $state");
        }
        foreach (\mod_videolesson\local\references::run() as $id => $state) {
            mtrace("CodeFortex reference $id: $state");
        }
        \mod_videolesson\local\replacement::run();
        } finally { $lock->release(); }
    }
}
