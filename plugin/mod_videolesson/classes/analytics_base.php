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
 * Base analytics class for video analytics
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

use moodle_url;

/**
 * Base class for analytics functionality shared between CM and video-level analytics
 */
abstract class analytics_base {
    /** @var string $contenthash The content hash. */
    public $contenthash;
    /** @var array $data The data. */
    public $data;
    /** @var array $contacts The contacts. */
    public $contacts = [];
    /** @var \stdClass $user The user. */
    public $user;
    /** @var string $TABLE_USAGE The table usage. */
    const TABLE_USAGE = 'videolesson_usage';

    /**
     * Get records from database - must be implemented by child classes
     * @param mixed $param Optional parameter (userid for video, cm flag for cm)
     * @return array
     */
    abstract public function get_records($param = 0);

    /**
     * Get video duration - must be implemented by child classes
     * @return int|false
     */
    abstract protected function get_video_duration();

    /**
     * Get completion data - must be implemented by child classes
     * @return array
     */
    abstract public function completion_data();

    /**
     * Get data - must be implemented by child classes
     * @return array
     */
    public function get_data() {
        $plays = $this->get_plays(true);

        return [
            'impression' => $plays['impression'],
            'totalplay' => $plays['total'],
            'unique' => $plays['unique'],
            'playsdata' => $plays['data'],
            'totaltime' => \mod_videolesson\util::format_watched_time($this->total_time()),
            'avgtimespent' => $this->avg_timespent(),
            'avgcompletion' => $this->avg_completion(),
        ];
    }

    /**
     * Get video data - must be implemented by child classes
     * @return array
     */
    public function get_video_data() {
        $plays = $this->get_plays(true);
        $timespent = $this->timespent_data();
        $completion = $this->completion_data();
        return [
            'impression' => $plays['impression'],
            'totalplay' => $plays['total'],
            'unique' => $plays['unique'],
            'playsdata' => $plays['data'],
            'totaltimeseconds' => $this->total_time(),
            'timespent' => $timespent['totaltime'],
            'sessions' => $timespent['sessions'],
            'completioncount' => $completion['count'],
            'completiontotal' => $completion['total'],
        ];
    }

    /**
     * Get impression - must be implemented by child classes
     * @param bool $includebasicinfo
     * @return array
     */
    public function get_impression($includebasicinfo = false) {
        $count = 0;
        $impressions = 0;
        $data = [];
        foreach ($this->data as $record) {
            $sessiondata = json_decode($record->data);

            $impressions++;
            if ($includebasicinfo) {
                $other = [
                    $sessiondata->platform,
                    $sessiondata->os,
                    $sessiondata->browser,
                    $sessiondata->ip,
                ];
                $data[] = [
                    'userid' => $sessiondata->userid,
                    'cm' => $sessiondata->cm,
                    'timestamp' => userdate(\mod_videolesson\util::convert_to_timestamp($sessiondata->timestamp)),
                    'browser' => $sessiondata->browser,
                    'os' => $sessiondata->os,
                    'combined' => implode(" | ", $other),
                ];
            }
        }

        if (!$includebasicinfo) {
            return $count;
        }

        return [
            'total' => $impressions,
            'data' => $data,
        ];
    }

    /**
     * Get plays - must be implemented by child classes
     * @param bool $includebasicinfo
     * @return array
     */
    public function get_plays($includebasicinfo = false) {
        $plays = [];
        $count = 0;
        $unique = [];
        $impressions = 0;
        $playurl = new moodle_url('/mod/videolesson/report.php', [
            'id' => 0,
            'action' => 'play',
            'playid' => 0,
        ]);

        foreach ($this->data as $record) {
            $sessiondata = json_decode($record->data);

            if (in_array($sessiondata->userid, $this->contacts)) {
                continue;
            }

            $impressions++;
            if (!empty($sessiondata->playbackEvents)) {
                if (!in_array($sessiondata->userid, $unique)) {
                    $unique[] = $sessiondata->userid;
                }

                $count++;
                if ($includebasicinfo) {
                    $sessionranges = [];
                    $allranges = $sessiondata->ranges;

                    foreach ($allranges as $ranges) {
                        foreach ($ranges as $range) {
                            $sessionranges[] = [round($range[0]), round($range[1])];
                        }
                    }

                    $userdate = userdate(\mod_videolesson\util::convert_to_timestamp($sessiondata->timestamp));
                    $url = $playurl->out(false, [
                        'id' => $sessiondata->cm,
                        'playid' => $record->id,
                    ]);

                    $progress = \mod_videolesson\util::calculate_percentage(
                        round($sessiondata->watchduration),
                        $sessiondata->duration
                    );

                    $plays[] = [
                        'userid' => $sessiondata->userid,
                        'playid' => $record->id,
                        'url' => $url,
                        'cm' => $sessiondata->cm,
                        'timestamp' => $sessiondata->timestamp,
                        'userdate' => $userdate,
                        'watchduration' => round($sessiondata->watchduration) . ' seconds',
                        'duration' => $sessiondata->duration,
                        'ranges' => json_encode($sessionranges),
                        'progress' => $progress,
                    ];
                }
            }
        }

        return [
            'impression' => $impressions,
            'total' => $count,
            'data' => $plays,
            'unique' => count($unique),
        ];
    }

    /**
     * Get play data - must be implemented by child classes
     * @param int $playid
     * @return array
     */
    public function get_play_data($playid) {
        global $DB;
        $return = [];
        $playrecord = $DB->get_record(self::TABLE_USAGE, ['id' => $playid]);
        $sessiondata = [];
        if ($playrecord) {
            $sessiondata = json_decode($playrecord->data);

            // Get active data.
            $activedata = $sessiondata->visibility;
            $visible = 0;
            foreach ($activedata as $data) {
                if ($data->active) {
                    $visible++;
                }
            }

            $avg = 0;
            if ($activedata && $visible) {
                $avg = ($visible / count($activedata)) * 100;
            }

            $return['active'] = [
                'average' => $avg,
                'active_times' => $visible,
            ];

            $user = $DB->get_record('user', ['id' => $sessiondata->userid]);
            if ($user) {
                $return['user'] = [
                    'fullname' => fullname($user),
                    'id' => $user->id,
                ];
            }

            $return['sessiondata'] = $sessiondata;
        }

        return $return;
    }

    /**
     * Get timespent data - must be implemented by child classes
     * @return array
     */
    public function timespent_data() {
        $duration = [];
        foreach ($this->data as $record) {
            $sessiondata = json_decode($record->data);
            if ($sessiondata->watchduration) {
                $duration[] = $sessiondata->watchduration;
            }
        }

        $totaltime = array_sum($duration);

        $sessions = count($duration);
        if ($duration) {
            return [
                'totaltime' => $totaltime,
                'sessions' => $sessions,
            ];
        }

        return [
            'totaltime' => 0,
            'sessions' => 0,
        ];
    }

    /**
     * Get average timespent - must be implemented by child classes
     * @return array
     */
    public function avg_timespent() {
        $data = $this->timespent_data();

        if ($data && $data['sessions'] > 0) {
            $avg = $data['totaltime'] / $data['sessions'];
            return \mod_videolesson\util::format_watched_time(round($avg, 2));
        }

        return 0;
    }

    /**
     * Get total time - must be implemented by child classes
     * @return array
     */
    public function total_time() {
        $duration = [];
        foreach ($this->data as $record) {
            $sessiondata = json_decode($record->data);
            if ($sessiondata->watchduration) {
                $duration[] = $sessiondata->watchduration;
            }
        }

        return round(array_sum($duration), 2);
    }

    /**
     * Get average completion - must be implemented by child classes
     * @return array
     */
    public function avg_completion() {
        $data = $this->completion_data();
        if (!$data['total']) {
            return 0;
        }

        $avg = ($data['count'] / $data['total']) * 100;

        return round($avg, 2) . '%';
    }

    /**
     * Get unique views - must be implemented by child classes
     * @return array
     */
    public function get_unique_views() {
        global $DB;
        $plays = [];
        $unique = [];
        foreach ($this->data as $record) {
            $sessiondata = json_decode($record->data);
            if (!empty($sessiondata->playbackEvents)) {
                $sessionranges = [];
                $allranges = $sessiondata->ranges;
                foreach ($allranges as $ranges) {
                    foreach ($ranges as $range) {
                        $sessionranges[] = [round($range[0]), round($range[1])];
                    }
                }

                $userdate = userdate(\mod_videolesson\util::convert_to_timestamp($sessiondata->timestamp));
                $progress = \mod_videolesson\util::calculate_percentage(
                    round($sessiondata->watchduration),
                    $sessiondata->duration
                );

                $plays[$sessiondata->userid][] = [
                    'userid' => $sessiondata->userid,
                    'cm' => $sessiondata->cm,
                    'timestamp' => $sessiondata->timestamp,
                    'userdate' => $userdate,
                    'watchduration' => round($sessiondata->watchduration) . ' seconds',
                    'duration' => $sessiondata->duration,
                    'ranges' => json_encode($sessionranges),
                    'progress' => $progress,
                ];

                if (!in_array($sessiondata->userid, $unique)) {
                    $unique[] = $sessiondata->userid;
                }
            }
        }

        $unique = array_values(array_unique($unique));

        $uniqueplays = [];

        if (!empty($unique)) {
            $users = $DB->get_records_list('user', 'id', $unique);

            foreach ($unique as $userid) {
                if (empty($users[$userid])) {
                    continue;
                }
                $user = $users[$userid];
                $uniqueplays[] = [
                    'user' => [
                        'id' => $user->id,
                        'fullname' => fullname($user),
                    ],
                    'data' => $plays[$user->id],
                    'count' => count($plays[$user->id]),
                ];
            }
        }

        return [
            'data' => $uniqueplays,
            'total' => count($unique),
        ];
    }

    /**
     * Get engagement chart data - must be implemented by child classes.
     * @return array
     */
    public function get_engagement_chart_data() {
        $records = $this->get_records();

        $browsers = [];
        $platforms = [];
        $countries = [];
        $watchdata = [];
        foreach ($records as $record) {
            $data = json_decode($record->data);

            $allranges = $data->ranges;
            foreach ($allranges as $ranges) {
                foreach ($ranges as $range) {
                    $watchdata[] = [round($range[0]), round($range[1])];
                }
            }

            if ($allranges) { // If has watched data.
                // Browser.
                $browser = explode('|', $record->browser);
                if (isset($browsers[$browser[0]])) {
                    $browsers[$browser[0]]++;
                } else {
                    $browsers[$browser[0]] = 1;
                }

                // Platform.
                $platform = $record->platform;
                if (isset($platforms[$platform])) {
                    $platforms[$platform]++;
                } else {
                    $platforms[$platform] = 1;
                }

                // Countries.
                $country = $record->country ? $record->country : 'n/a';
                if (isset($countries[$country])) {
                    $countries[$country]++;
                } else {
                    $countries[$country] = 1;
                }
            }
        }

        $duration = $this->get_video_duration();

        // Initialize an array to store the number of views for each range.
        $viewsinrange = array_fill(0, $duration + 1, 0);

        // Iterate through each range and calculate the number of views in that range.
        foreach ($watchdata as $range) {
            [$start, $end] = $range;

            // Ensure the range doesn't exceed the duration.
            $start = min($start, $duration);
            $end = min($end, $duration);

            // Increment the number of views for each second within the range.
            for ($i = $start; $i <= $end; $i++) {
                $viewsinrange[$i]++;
            }
        }

        $views = [
            'series' => $viewsinrange,
            'labels' => range(0, $duration),
            'empty' => empty($watchdata),
        ];

        return [
            'views' => $views,
            'browsers' => [
                'labels' => array_keys($browsers),
                'series' => array_values($browsers),
            ],
            'platforms' => [
                'labels' => array_keys($platforms),
                'series' => array_values($platforms),
            ],
            'countries' => [
                'labels' => array_keys($countries),
                'series' => array_values($countries),
            ],
        ];
    }

    /**
     * Get chart data for tabs - must be implemented by child classes
     * @return array
     */
    public function get_chart_data_for_tabs() {
        $chartdata = $this->get_engagement_chart_data();

        $viewchart = $this->generate_chart(
            $chartdata['views'],
            get_string('report:views:heading', 'mod_videolesson'),
            true
        );

        $platformchart = $this->generate_pie_chart(
            $chartdata['platforms'],
            get_string('report:platforms:heading', 'mod_videolesson')
        );

        $browserchart = $this->generate_pie_chart(
            $chartdata['browsers'],
            get_string('report:browsers:heading', 'mod_videolesson')
        );

        $countrieschart = $this->generate_pie_chart(
            $chartdata['countries'],
            get_string('report:countries:heading', 'mod_videolesson')
        );

        $tablabel = [
            ['id' => 'views', 'label' => get_string('report:views:heading', 'mod_videolesson'), 'active' => true],
            ['id' => 'platforms', 'label' => get_string('report:platforms:heading', 'mod_videolesson')],
            ['id' => 'browsers', 'label' => get_string('report:browsers:heading', 'mod_videolesson')],
            ['id' => 'countries', 'label' => get_string('report:countries:heading', 'mod_videolesson')],
        ];

        $tabcontent = [
            ['id' => 'views', 'content' => $viewchart, 'active' => true],
            ['id' => 'platforms', 'content' => $platformchart],
            ['id' => 'browsers', 'content' => $browserchart],
            ['id' => 'countries', 'content' => $countrieschart],
        ];

        $tabdata = [
            'tablabel' => $tablabel,
            'tabcontent' => $tabcontent,
        ];

        return $tabdata;
    }

    /**
     * Generate line chart - must be implemented by child classes
     * @param array $data
     * @param string $heading
     * @param bool $smooth
     * @return string
     */
    protected function generate_chart($data, $heading, $smooth = false) {
        global $OUTPUT;
        if ($data['empty']) {
            return $OUTPUT->notification(
                get_string('report:nodata', 'mod_videolesson'),
                \core\output\notification::NOTIFY_INFO,
                false
            );
        }
        $chart = new \core\chart_line();
        $chart->set_smooth($smooth);
        $series = new \core\chart_series($heading, $data['series']);
        $chart->add_series($series);
        $chart->set_labels($data['labels']);
        return $OUTPUT->render_chart($chart, false);
    }

    /**
     * Generate pie chart - must be implemented by child classes
     * @param array $data
     * @param string $heading
     * @return string
     */
    protected function generate_pie_chart($data, $heading) {
        global $OUTPUT;
        if (!$data['series']) {
            return $OUTPUT->notification(
                get_string('report:nodata', 'mod_videolesson'),
                \core\output\notification::NOTIFY_INFO,
                false
            );
        }
        $chart = new \core\chart_pie();
        $chart->set_doughnut(true);
        $series = new \core\chart_series($heading, $data['series']);
        $chart->add_series($series);
        $chart->set_labels($data['labels']);
        return $OUTPUT->render_chart($chart, false);
    }
}
