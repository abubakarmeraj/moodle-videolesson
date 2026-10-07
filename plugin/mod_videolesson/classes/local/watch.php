<?php
// CodeFortex additions, GPL v3 or later.
namespace mod_videolesson\local;
defined('MOODLE_INTERNAL') || die();

/** Bounded server-clock/identity checked observations, never client percentages. */
final class watch {
    public static function session(int $cmid): array {
        global $DB, $USER;
        [, , , $instance] = access::activity($cmid);
        $media = providers::media($instance);
        $token = bin2hex(random_bytes(32));
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock("watch:$cmid:$USER->id", 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
        $transaction = $DB->start_delegated_transaction();
        $previous = $DB->get_records('videolesson_session', ['userid' => $USER->id, 'cmid' => $cmid], 'id DESC', '*', 0, 1);
        $previous = reset($previous);
        // Rotate the session on reload but retain the user's wall-clock budget.
        $same = $previous && $previous->expires >= time() && $previous->videouuid === $instance->videouuid &&
            (int)$previous->revision === (int)$instance->mediarevision;
        $DB->set_field('videolesson_session', 'expires', 0, ['userid' => $USER->id, 'cmid' => $cmid]);
        $DB->delete_records_select('videolesson_session', 'userid = :userid AND cmid = :cmid AND expires < :cutoff',
            ['userid' => $USER->id, 'cmid' => $cmid, 'cutoff' => time() - 86400]);
        $DB->insert_record('videolesson_session', (object)[
            'tokenhash' => hash('sha256', $token), 'userid' => $USER->id, 'cmid' => $cmid,
            'videouuid' => $instance->videouuid, 'revision' => $instance->mediarevision,
            'duration' => $media['duration'], 'lastseq' => 0, 'credited' => $same ? $previous->credited : 0,
            'timecreated' => $same ? $previous->timecreated : time(), 'lasttime' => time(), 'expires' => time() + 1800,
        ]);
        $transaction->allow_commit();
        } catch (\Throwable $error) {
            if (isset($transaction)) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
        return ['session' => $token, 'video' => $instance->videouuid, 'revision' => (int)$instance->mediarevision,
            'duration' => (float)$media['duration'], 'sequence' => 0];
    }

    public static function event(int $cmid, array $event): array {
        global $DB, $USER, $CFG;
        [$cm, $course, , $instance] = access::activity($cmid);
        $keys = ['session', 'sequence', 'video', 'revision', 'start', 'end', 'rate'];
        if (count($event) !== count($keys) || array_diff($keys, array_keys($event)) ||
                !is_string($event['session']) || !preg_match('/^[a-f0-9]{64}$/D', $event['session']) ||
                !is_int($event['sequence']) || $event['sequence'] < 1 || !is_int($event['revision'])) {
            throw new \invalid_parameter_exception('Invalid watch envelope');
        }
        foreach (['start', 'end', 'rate'] as $field) {
            if ((!is_int($event[$field]) && !is_float($event[$field])) || !is_finite((float)$event[$field])) {
                throw new \invalid_parameter_exception('Invalid watch number');
            }
        }
        $media = providers::media($instance);
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock("watch:$cmid:$USER->id", 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $session = $DB->get_record('videolesson_session', ['tokenhash' => hash('sha256', $event['session']),
                'userid' => $USER->id, 'cmid' => $cmid], '*', MUST_EXIST);
            $now = time();
            $start = (float)$event['start']; $end = (float)$event['end']; $rate = (float)$event['rate'];
            $delta = $end - $start;
            if ($session->expires < $now || $session->videouuid !== $instance->videouuid ||
                    $event['video'] !== $instance->videouuid || $event['revision'] !== (int)$instance->mediarevision ||
                    (int)$session->revision !== $event['revision'] ||
                    abs((float)$session->duration - $media['duration']) > 0.001 ||
                    $event['sequence'] !== (int)$session->lastseq + 1 ||
                    $start < 0 || $end > (float)$session->duration + 0.001 || $delta <= 0 || $delta > 30 ||
                    $rate < 0.5 || $rate > 2 || $delta / $rate > min(30, $now - $session->lasttime + 1) ||
                    (float)$session->credited + $delta / $rate > $now - $session->timecreated + 1) {
                throw new \invalid_parameter_exception('Rejected watch observation');
            }
            $conditions = ['cmid' => $cmid, 'userid' => $USER->id, 'videouuid' => $instance->videouuid,
                'revision' => $instance->mediarevision];
            $progress = $DB->get_record('videolesson_progress', $conditions);
            $ranges = $progress ? json_decode($progress->ranges, true, 512, JSON_THROW_ON_ERROR) : [];
            // Encoded media timelines may end a few milliseconds before the probed duration.
            $normalizedend = abs($end - (float)$session->duration) <= 0.01 ? (float)$session->duration : $end;
            $ranges[] = [$start, min($normalizedend, (float)$session->duration)];
            $ranges = self::merge($ranges);
            if (count($ranges) > 256) {
                throw new \invalid_parameter_exception('Too many discontinuous ranges');
            }
            $seconds = array_sum(array_map(fn($r) => $r[1] - $r[0], $ranges));
            $record = (object)($conditions + ['ranges' => json_encode($ranges), 'seconds' => $seconds,
                'duration' => $session->duration, 'timemodified' => $now]);
            if ($progress) {
                $record->id = $progress->id; $DB->update_record('videolesson_progress', $record);
            } else {
                $DB->insert_record('videolesson_progress', $record);
            }
            $session->lastseq = $event['sequence']; $session->lasttime = $now;
            $session->credited += $delta / $rate;
            $DB->update_record('videolesson_session', $session);
            require_once($CFG->libdir . '/completionlib.php');
            (new \completion_info($course))->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
            $transaction->allow_commit();
            return ['progress' => min(100, 100 * $seconds / $session->duration), 'sequence' => $event['sequence']];
        } catch (\Throwable $error) {
            if (isset($transaction)) {
                $transaction->rollback($error);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public static function merge(array $ranges): array {
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        $result = [];
        foreach ($ranges as $range) {
            $last = count($result) - 1;
            if ($last >= 0 && $range[0] <= $result[$last][1] + 0.001) {
                $result[$last][1] = max($result[$last][1], $range[1]);
            } else {
                $result[] = $range;
            }
        }
        return $result;
    }

    /** Authoritative continuation point. No credit, sequence or interval is invented by reconnect. */
    public static function reconnect(int $cmid, string $token, bool $renew = false): array {
        global $DB, $USER;
        [, , , $instance] = access::activity($cmid);
        providers::media($instance);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \invalid_parameter_exception('Invalid session');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_videolesson')->get_lock("watch:$cmid:$USER->id", 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $session = $DB->get_record('videolesson_session', ['tokenhash' => hash('sha256', $token),
                'userid' => $USER->id, 'cmid' => $cmid], '*', MUST_EXIST);
            if ($session->expires < time() || $session->videouuid !== $instance->videouuid ||
                    (int)$session->revision !== (int)$instance->mediarevision) {
                throw new \invalid_parameter_exception('Session no longer current');
            }
            $progress = $DB->get_record('videolesson_progress', ['userid' => $USER->id, 'cmid' => $cmid,
                'videouuid' => $instance->videouuid, 'revision' => $instance->mediarevision]);
            $result = ['sequence' => (int)$session->lastseq,
                'progress' => $progress ? min(100, 100 * $progress->seconds / $progress->duration) : 0];
            if ($renew) {
                $provider = providers::get();
                $grant = $provider instanceof gateway_provider
                    ? $provider->scoped_grant($instance->tenantid, $instance->videouuid,
                        (int)$instance->mediarevision, (int)$USER->id, $token)
                    : $provider->request_playback_grant($instance->tenantid, $instance->videouuid,
                        (int)$instance->mediarevision, (int)$USER->id);
                if (!is_int($grant['expires'] ?? null) || $grant['expires'] <= time()) {
                    throw new \invalid_parameter_exception('Invalid grant expiry');
                }
                $result['url'] = $provider instanceof gateway_provider ? $provider->grant_url($grant)
                    : (new \moodle_url('/mod/videolesson/media.php',
                        ['id' => $cmid, 'grant' => $grant['token'], 'file' => $grant['file']]))->out(false);
                $result['expires'] = $grant['expires'];
                $DB->set_field('videolesson_session', 'expires', time() + 1800, ['id' => $session->id]);
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    public static function complete(\stdClass $instance, int $cmid, int $userid): bool {
        global $DB;
        if (!$instance->completionprogress) {
            return true;
        }
        if (empty($instance->videouuid)) {
            return false;
        }
        $row = $DB->get_record('videolesson_progress', ['cmid' => $cmid, 'userid' => $userid,
            'videouuid' => $instance->videouuid, 'revision' => $instance->mediarevision]);
        return $row && $row->duration > 0 &&
            $row->seconds + 0.001 >= $row->duration * $instance->completionprogress / 100;
    }
}
