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
 * Plugin upgrade steps are defined here.
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
function xmldb_videolesson_upgrade($oldversion) {
    global $DB, $CFG;
    if ($oldversion < 2026090600) {
        $manager = $DB->get_manager();
        $file = new xmldb_file($CFG->dirroot . '/mod/videolesson/db/install.xml');
        $file->loadXMLStructure();
        $structure = $file->getStructure();
        $activity = new xmldb_table('videolesson');
        foreach (['videouuid', 'tenantid', 'mediarevision', 'mediakind'] as $name) {
            $field = $structure->getTable('videolesson')->getField($name);
            if (!$manager->field_exists($activity, $field)) {
                $manager->add_field($activity, $field);
            }
        }
        foreach (['videolesson_session', 'videolesson_progress'] as $name) {
            $table = $structure->getTable($name);
            if (!$manager->table_exists($table)) {
                $manager->create_table($table);
            }
        }
        // Preserve legacy source/telemetry; it is not trusted V2 progress.
        // Re-evaluate automatic completions against the new validated model.
        $module = $DB->get_field('modules', 'id', ['name' => 'videolesson']);
        foreach ($DB->get_records('course_modules', ['module' => $module, 'completion' => 2]) as $cm) {
            foreach ($DB->get_records('course_modules_completion', ['coursemoduleid' => $cm->id]) as $completion) {
                // Preserve explicit staff overrides, not browser-derived automatic credit.
                if (empty($completion->overrideby)) {
                    $DB->set_field('course_modules_completion', 'completionstate', 0, ['id' => $completion->id]);
                }
            }
        }
        upgrade_mod_savepoint(true, 2026090600, 'videolesson');
    }
    if ($oldversion < 2026090601) {
        $file = new xmldb_file($CFG->dirroot . '/mod/videolesson/db/install.xml');
        $file->loadXMLStructure();
        $table = $file->getStructure()->getTable('videolesson_intent');
        if (!$DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026090601, 'videolesson');
    }
    if ($oldversion < 2026090602) {
        $table = new xmldb_table('videolesson');
        $field = new xmldb_field('trustedref', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$DB->get_manager()->field_exists($table, $field)) {
            $DB->get_manager()->add_field($table, $field);
            // Preserve the accepted installed V2 references. New restores must independently validate.
            $DB->set_field('videolesson', 'trustedref', 1);
        }
        upgrade_mod_savepoint(true, 2026090602, 'videolesson');
    }
    if ($oldversion < 2026090800) {
        $table = new xmldb_table('videolesson_intent');
        foreach ([new xmldb_field('purpose', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'create'),
                new xmldb_field('baseuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, 'none'),
                new xmldb_field('baserevision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0')] as $field) {
            if (!$DB->get_manager()->field_exists($table, $field)) { $DB->get_manager()->add_field($table, $field); }
        }
        upgrade_mod_savepoint(true, 2026090800, 'videolesson');
    }
    if ($oldversion < 2026090801) {
        // Also reconcile the initial local RC2 test schema's empty default through XMLDB.
        $DB->get_manager()->change_field_default(new xmldb_table('videolesson_intent'),
            new xmldb_field('baseuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, 'none'));
        upgrade_mod_savepoint(true, 2026090801, 'videolesson');
    }
    if ($oldversion < 2026090802) {
        set_config('rawretentiondays', 1, 'mod_videolesson');
        if (get_config('mod_videolesson', 'hlssegmentseconds') === false) {
            set_config('hlssegmentseconds', 4, 'mod_videolesson');
        }
        upgrade_mod_savepoint(true, 2026090802, 'videolesson');
    }
    if ($oldversion < 2026090803) {
        foreach (['storagequotamib' => 0, 'recoverydays' => 7] as $name => $value) {
            if (get_config('mod_videolesson', $name) === false) { set_config($name, $value, 'mod_videolesson'); }
        }
        upgrade_mod_savepoint(true, 2026090803, 'videolesson');
    }
    if ($oldversion < 2026090804) {
        upgrade_mod_savepoint(true, 2026090804, 'videolesson');
    }
    if ($oldversion < 2026092300) {
        // Configuration/onboarding only; no schema or existing media changes.
        upgrade_mod_savepoint(true, 2026092300, 'videolesson');
    }
    if ($oldversion < 2026100700) {
        // Generic S3 browser upload compatibility only; no schema or media mutation.
        upgrade_mod_savepoint(true, 2026100700, 'videolesson');
    }
    if ($oldversion < 2026100701) {
        // RC1 release identity and original artwork only; no schema/media mutation.
        upgrade_mod_savepoint(true, 2026100701, 'videolesson');
    }
    return true;
}
