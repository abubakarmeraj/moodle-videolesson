<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

final class access {
    /** Same boundary for view, files, sessions, reports and mutations. */
    public static function activity(int $cmid, bool $edit = false, bool $report = false): array {
        global $DB, $USER;
        $cm = get_coursemodule_from_id('videolesson', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);
        $context = \context_module::instance($cmid);
        $info = get_fast_modinfo($course, $USER->id)->get_cm($cmid);
        if (!$info->uservisible || isguestuser() ||
                (!is_enrolled($context, $USER, '', true) && !has_capability('moodle/course:view', $context))) {
            throw new \required_capability_exception($context, 'mod/videolesson:view', 'nopermissions', '');
        }
        require_capability('mod/videolesson:view', $context);
        if ($edit) {
            require_capability('moodle/course:manageactivities', $context);
        }
        if ($report) {
            require_capability('mod/videolesson:reports', $context);
        }
        return [$cm, $course, $context, $DB->get_record('videolesson', ['id' => $cm->instance], '*', MUST_EXIST)];
    }
}
