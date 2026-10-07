<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy subsystem implementation for mod_videolesson.
 *
 * @package    mod_videolesson
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API provider for the Video Lesson activity.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata describing stored user data.
     *
     * @param collection $collection Initial collection.
     * @return collection Collection with this plugin's metadata added.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('videolesson_intent', array_fill_keys([
            'userid', 'courseid', 'instanceid', 'operationkey', 'tenantid', 'videouuid', 'kind',
            'state', 'attempts', 'timecreated', 'timemodified', 'nextattempt', 'purpose', 'baseuuid',
            'baserevision'], 'cfprivacyintent'), 'cfprivacyintent');
        foreach (['videolesson_session', 'videolesson_progress'] as $table) {
            $fields = ['userid', 'cmid', 'videouuid', 'revision', 'duration'];
            $fields = array_merge($fields, $table === 'videolesson_session'
                ? ['tokenhash', 'lastseq', 'credited', 'timecreated', 'lasttime', 'expires']
                : ['ranges', 'seconds', 'timemodified']);
            $collection->add_database_table($table, array_fill_keys($fields, 'cfprivacy'), 'cfprivacy');
        }
        $collection->add_database_table(
            'videolesson_cm_progress',
            [
                'cmid' => 'privacy:metadata:videolesson_cm_progress:cmid',
                'userid' => 'privacy:metadata:videolesson_cm_progress:userid',
                'progress' => 'privacy:metadata:videolesson_cm_progress:progress',
                'sourcedata' => 'privacy:metadata:videolesson_cm_progress:sourcedata',
                'timecreated' => 'privacy:metadata:videolesson_cm_progress:timecreated',
                'timemodified' => 'privacy:metadata:videolesson_cm_progress:timemodified',
            ],
            'privacy:metadata:videolesson_cm_progress'
        );

        $collection->add_database_table(
            'videolesson_usage',
            [
                'userid' => 'privacy:metadata:videolesson_usage:userid',
                'cm' => 'privacy:metadata:videolesson_usage:cm',
                'session' => 'privacy:metadata:videolesson_usage:session',
                'source' => 'privacy:metadata:videolesson_usage:source',
                'sourcedata' => 'privacy:metadata:videolesson_usage:sourcedata',
                'watchduration' => 'privacy:metadata:videolesson_usage:watchduration',
                'ip' => 'privacy:metadata:videolesson_usage:ip',
                'platform' => 'privacy:metadata:videolesson_usage:platform',
                'browser' => 'privacy:metadata:videolesson_usage:browser',
                'os' => 'privacy:metadata:videolesson_usage:os',
                'city' => 'privacy:metadata:videolesson_usage:city',
                'country' => 'privacy:metadata:videolesson_usage:country',
                'timestamp' => 'privacy:metadata:videolesson_usage:timestamp',
                'data' => 'privacy:metadata:videolesson_usage:data',
                'timecreated' => 'privacy:metadata:videolesson_usage:timecreated',
                'timemodified' => 'privacy:metadata:videolesson_usage:timemodified',
            ],
            'privacy:metadata:videolesson_usage'
        );

        $collection->add_external_location_link('CodeFortex Video Service',
            array_fill_keys(['tenant', 'owner_subject', 'video_uuid', 'revision', 'activity_references',
                'short_lived_playback_subject'], 'cfprivacyservice'), 'cfprivacyservice');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        if (!\core_user::get_user($userid)) {
            return new contextlist();
        }

        $contextlist = new contextlist();
        $modname = 'videolesson';
        $ctxlevel = CONTEXT_MODULE;
        $useridstr = (string) $userid;

        $cmidchar = $DB->sql_cast_to_char('cm.id');

        $sqlusage = "SELECT ctx.id
                       FROM {context} ctx
                       JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {videolesson_usage} vu ON vu.cm = $cmidchar
                      WHERE vu.userid = :useridstr";

        $contextlist->add_from_sql($sqlusage, [
            'ctxlevel' => $ctxlevel,
            'modname' => $modname,
            'useridstr' => $useridstr,
        ]);

        $sqlprogress = "SELECT ctx.id
                          FROM {context} ctx
                          JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel2
                          JOIN {modules} m ON m.id = cm.module AND m.name = :modname2
                          JOIN {videolesson_cm_progress} vcp ON vcp.cmid = cm.id
                         WHERE vcp.userid = :userid";

        $contextlist->add_from_sql($sqlprogress, [
            'ctxlevel2' => $ctxlevel,
            'modname2' => $modname,
            'userid' => $userid,
        ]);

        foreach (['videolesson_session', 'videolesson_progress'] as $table) {
            foreach ($DB->get_records($table, ['userid' => $userid], '', 'id,cmid') as $row) {
                if (get_coursemodule_from_id('videolesson', $row->cmid)) {
                    $contextlist->add_from_sql('SELECT id FROM {context} WHERE id = :id',
                        ['id' => \context_module::instance($row->cmid)->id]);
                }
            }
        }
        $contextlist->add_from_sql('SELECT c.id FROM {context} c JOIN {videolesson_intent} i
            ON i.courseid = c.instanceid WHERE c.contextlevel = :level AND i.userid = :userid',
            ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a module context.
     *
     * @param userlist $userlist The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {videolesson_intent}
                WHERE courseid = :course AND userid > 0', ['course' => $context->instanceid]);
            return;
        }

        if (!$context instanceof \context_module) {
            return;
        }

        $cmid = $context->instanceid;
        $cmidstr = (string) $cmid;

        // Legacy userid is CHAR; avoid a PostgreSQL bigint=char privacy join.
        foreach ($DB->get_records('videolesson_usage', ['cm' => $cmidstr], '', 'id,userid') as $row) {
            if (ctype_digit((string)$row->userid)) {
                $userlist->add_user((int)$row->userid);
            }
        }

        $sqlprogress = "SELECT vcp.userid
                          FROM {videolesson_cm_progress} vcp
                         WHERE vcp.cmid = :cmid";

        $userlist->add_from_sql('userid', $sqlprogress, ['cmid' => $cmid]);
        foreach (['videolesson_session', 'videolesson_progress'] as $table) {
            $ids = array_map(fn($row) => (int)$row->userid, $DB->get_records($table, ['cmid' => $cmid], '', 'id,userid'));
            $userlist->add_users($ids);
        }
    }

    /**
     * Export all user data for the contexts in the approved list.
     *
     * @param approved_contextlist $contextlist Approved contexts for this user.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $useridstr = (string) $user->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_COURSE) {
                $intents = array_values($DB->get_records('videolesson_intent',
                    ['userid' => $user->id, 'courseid' => $context->instanceid], '',
                    'id,instanceid,courseid,userid,tenantid,videouuid,kind,state,attempts,timecreated,timemodified,nextattempt,purpose,baseuuid,baserevision'));
                writer::with_context($context)->export_data(['CodeFortex provisioning'], (object)['intents' => $intents]);
                continue;
            }
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('videolesson', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $cmid = $cm->id;
            $cmidstr = (string) $cmid;

            $usage = $DB->get_records_select(
                'videolesson_usage',
                'cm = ? AND userid = ?',
                [$cmidstr, $useridstr],
                'timecreated ASC, id ASC'
            );

            $progress = $DB->get_records('videolesson_cm_progress', [
                'cmid' => $cmid,
                'userid' => $user->id,
            ], 'timecreated ASC, id ASC');

            $newprogress = array_values($DB->get_records('videolesson_progress', ['cmid' => $cmid, 'userid' => $user->id]));
            $sessions = array_values($DB->get_records('videolesson_session', ['cmid' => $cmid, 'userid' => $user->id], '',
                'id,cmid,userid,videouuid,revision,duration,lastseq,credited,timecreated,lasttime,expires'));
            if (!$usage && !$progress && !$newprogress && !$sessions) {
                continue;
            }

            $exportusage = [];
            foreach ($usage as $row) {
                $exportusage[] = (object) [
                    'session' => $row->session,
                    'source' => $row->source,
                    'sourcedata' => $row->sourcedata,
                    'watchduration' => $row->watchduration,
                    'ip' => $row->ip,
                    'platform' => $row->platform,
                    'browser' => $row->browser,
                    'os' => $row->os,
                    'city' => $row->city,
                    'country' => $row->country,
                    'timestamp' => $row->timestamp,
                    'data' => $row->data,
                    'timecreated' => transform::datetime($row->timecreated),
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }

            $exportprogress = [];
            foreach ($progress as $row) {
                $exportprogress[] = (object) [
                    'progress' => $row->progress,
                    'sourcedata' => $row->sourcedata,
                    'timecreated' => transform::datetime($row->timecreated),
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }

            $contextdata = helper::get_context_data($context, $user);
            $contextdata = (object) array_merge(
                (array) $contextdata,
                [
                    'video_watch_sessions' => $exportusage,
                    'video_progress' => $exportprogress,
                ]
            );

            writer::with_context($context)->export_data([], $contextdata);
            writer::with_context($context)->export_data(['CodeFortex'], (object)['progress' => $newprogress, 'sessions' => $sessions]);
            helper::export_context_files($context, $user);
        }
    }

    /**
     * Delete all user data for this activity context.
     *
     * @param \context $context Context to erase.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel === CONTEXT_COURSE) {
            self::erase_intents(['courseid' => $context->instanceid]);
            return;
        }

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('videolesson', $context->instanceid);
        if (!$cm) {
            return;
        }

        $cmidstr = (string) $cm->id;
        $DB->delete_records('videolesson_usage', ['cm' => $cmidstr]);
        $DB->delete_records('videolesson_cm_progress', ['cmid' => $cm->id]);
        $DB->delete_records('videolesson_session', ['cmid' => $cm->id]);
        $DB->delete_records('videolesson_progress', ['cmid' => $cm->id]);
    }

    /**
     * Delete user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist Contexts and user to erase for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        foreach ($contextlist->get_contexts() as $approved) {
            if ($approved->contextlevel === CONTEXT_COURSE) {
                self::erase_intents(['courseid' => $approved->instanceid, 'userid' => $contextlist->get_user()->id]);
            }
        }

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $useridstr = (string) $user->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('videolesson', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $cmidstr = (string) $cm->id;
            $DB->delete_records('videolesson_usage', ['cm' => $cmidstr, 'userid' => $useridstr]);
            $DB->delete_records('videolesson_cm_progress', ['cmid' => $cm->id, 'userid' => $user->id]);
            $DB->delete_records('videolesson_session', ['cmid' => $cm->id, 'userid' => $user->id]);
            $DB->delete_records('videolesson_progress', ['cmid' => $cm->id, 'userid' => $user->id]);
        }
    }

    /**
     * Delete data for selected users in one context.
     *
     * @param approved_userlist $userlist Approved users in a single context.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context instanceof \context_course) {
            foreach ($userlist->get_userids() as $userid) {
                self::erase_intents(['courseid' => $context->instanceid, 'userid' => $userid]);
            }
            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('videolesson', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $cmidstr = (string) $cm->id;

        foreach ($userids as $userid) {
            $DB->delete_records('videolesson_usage', ['cm' => $cmidstr, 'userid' => (string)$userid]);
        }

        $select = "cmid = :cmid AND userid $insql";
        $params = array_merge(['cmid' => $cm->id], $inparams);
        $DB->delete_records_select('videolesson_cm_progress', $select, $params);
        $DB->delete_records_select('videolesson_session', $select, $params);
        $DB->delete_records_select('videolesson_progress', $select, $params);
    }

    /** Retain only non-user-linked terminal deduplication tombstones. Never lose an ambiguous operation. */
    private static function erase_intents(array $conditions): void {
        global $DB;
        $records = $DB->get_records('videolesson_intent', $conditions);
        foreach ($records as $record) {
            if (!in_array($record->state, ['ready', 'abandoned'], true)) {
                throw new \moodle_exception('cfprivacyreconcile', 'mod_videolesson');
            }
        }
        foreach ($records as $record) {
            $DB->set_field('videolesson_intent', 'userid', 0, ['id' => $record->id]);
        }
    }
}
