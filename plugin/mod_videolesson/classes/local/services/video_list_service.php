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
 * Service helper for building video listing contexts.
 *
 * @package     mod_videolesson
 * @author      BitKea Technologies LLP
 * @copyright   2022-2026 BitKea Technologies LLP
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_videolesson\local\services;

use coding_exception;
use mod_videolesson\conversion;
use mod_videolesson\util;
use moodle_url;

/**
 * Service helper for building video listing contexts.
 *
 * @package     mod_videolesson
 */
class video_list_service {
    /** Default per page. */
    private const DEFAULT_PERPAGE = 10;

    /** Max per page. */
    private const MAX_PERPAGE = 50;

    /**
     * Build listing context for templates/AJAX.
     *
     * @param array $options Options: folderid, search, page, perpage
     * @return array
     * @throws coding_exception
     */
    public static function build_listing(array $options): array {
        global $DB, $OUTPUT;

        $page = max(0, (int)($options['page'] ?? 0));
        $perpage = (int)($options['perpage'] ?? self::DEFAULT_PERPAGE);
        $perpage = min(max(5, $perpage), self::MAX_PERPAGE);
        $search = trim((string)($options['search'] ?? ''));
        $folderidentifier = $options['folderid'] ?? null;

        [$folderjoin, $foldercondition, $params] = self::build_folder_sql($folderidentifier);

        $conditions = [];
        if ($foldercondition) {
            $conditions[] = $foldercondition;
        }
        if ($search !== '') {
            $conditions[] = $DB->sql_like('c.name', ':search', false);
            $params['search'] = '%' . $search . '%';
        }
        $where = $conditions ? implode(' AND ', $conditions) : '1=1';

        $countsql = "SELECT COUNT(DISTINCT c.id)
                       FROM {videolesson_conv} c
                  LEFT JOIN {videolesson_folder_items} fi ON fi.videolessonid = c.id
                      WHERE $where";
        $total = $DB->count_records_sql($countsql, $params);

        $offset = $page * $perpage;

        $fields = "c.id, c.name, c.contenthash, c.uploaded, c.status, c.transcoder_status,
                   c.mediaconvert, c.hasmp4, c.bucket_size, c.timecreated,
                   d.duration, d.size AS sourcesize,
                   COUNT(DISTINCT v.id) AS instances,
                   fi.folderid, fi.sortorder,
                   f.name AS foldername";

        $ordersortnull = $DB->sql_order_by_null('fi.sortorder', SORT_ASC);
        $sql = "SELECT $fields
                  FROM {videolesson_conv} c
                  JOIN {videolesson_data} d ON d.contenthash = c.contenthash
             LEFT JOIN {videolesson} v ON v.sourcedata = c.contenthash
             $folderjoin
             LEFT JOIN {videolesson_folders} f ON f.id = fi.folderid
                 WHERE $where
              GROUP BY c.id, c.name, c.contenthash, c.uploaded, c.status, c.transcoder_status,
                       c.mediaconvert, c.hasmp4, c.bucket_size, c.timecreated,
                       d.duration, d.size, fi.folderid, fi.sortorder, f.name
              ORDER BY $ordersortnull, c.timecreated DESC";

        $records = $DB->get_records_sql($sql, $params, $offset, $perpage);

        $conversion = new conversion();
        $awshandler = new \mod_videolesson\aws_handler('output');
        $cloudfront = $awshandler->cloudfrontdomain();
        $placeholder = (new moodle_url('/mod/videolesson/pix/monologo.svg'))->out(false);

        $videos = array_map(function ($record) use ($conversion, $OUTPUT, $cloudfront, $placeholder) {
            return self::format_record($record, $conversion, $OUTPUT, $cloudfront, $placeholder);
        }, $records);

        $totalpages = $perpage ? max(1, (int)ceil($total / $perpage)) : 1;
        $pages = self::build_pagination_pages($page, $totalpages);

        // Get folder name if folder is selected.
        $normalisedfolderid = self::normalise_folder_identifier($folderidentifier);
        $foldername = null;
        if ($normalisedfolderid && $normalisedfolderid !== 'all' && $normalisedfolderid !== 'uncategorized') {
            $folder = $DB->get_record('videolesson_folders', ['id' => (int)$normalisedfolderid], 'name');
            if ($folder) {
                $foldername = $folder->name;
            }
        } else if ($normalisedfolderid === 'uncategorized') {
            $foldername = get_string('folder:uncategorized', 'mod_videolesson');
        }

        return [
            'videos' => array_values($videos),
            'hasvideos' => !empty($videos),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'perpage' => $perpage,
                'totalpages' => $totalpages,
                'haspages' => $totalpages > 1,
                'hasprev' => $page > 0,
                'hasnext' => ($page + 1) < $totalpages,
                'prevpage' => max(0, $page - 1),
                'nextpage' => min(max(0, $totalpages - 1), $page + 1),
                'pages' => $pages,
            ],
            'filters' => [
                'search' => $search,
                'folderid' => $normalisedfolderid,
                'foldername' => $foldername,
                'active' => !empty($search) || ($normalisedfolderid && $normalisedfolderid !== 'all'),
            ],
        ];
    }

    /**
     * Build pagination pages array.
     *
     * @param int $current
     * @param int $totalpages
     * @return array
     */
    private static function build_pagination_pages(int $current, int $totalpages): array {
        $pages = [];
        if ($totalpages <= 1) {
            return $pages;
        }

        $window = 5;
        $start = max(0, $current - 2);
        $end = min($totalpages - 1, $start + $window - 1);
        if ($end - $start < $window - 1) {
            $start = max(0, $end - ($window - 1));
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = [
                'number' => $i,
                'display' => $i + 1,
                'current' => $i === $current,
            ];
        }

        return $pages;
    }

    /**
     * Normalise folder identifier.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function normalise_folder_identifier($value): ?string {
        if ($value === null || $value === '' || $value === 'all') {
            return 'all';
        }
        if ($value === 'null' || $value === 'uncategorized' || (string)$value === '-1') {
            return 'uncategorized';
        }
        return (string)intval($value);
    }

    /**
     * Format record for template.
     *
     * @param \stdClass $record
     * @param conversion $conversion
     * @param mixed $output Renderer (core\output\renderer_base or compatible)
     * @param string $cloudfront Cloudfront domain
     * @param string $placeholder Placeholder image
     * @return array
     */
    private static function format_record(
        \stdClass $record,
        conversion $conversion,
        $output,
        string $cloudfront,
        string $placeholder
    ): array {
        if ($record->mediaconvert) {
            $videosrc = "{$cloudfront}{$record->contenthash}/conversions/{$record->contenthash}.m3u8";
            $thumbnail = "{$cloudfront}{$record->contenthash}/thumbnails/{$record->contenthash}_1080p_thumbnail.0000000.jpg";
        } else {
            $videosrc = "{$cloudfront}{$record->contenthash}/conversions/{$record->contenthash}_hls_playlist.m3u8";
            $thumbnail = "{$cloudfront}{$record->contenthash}/conversions/thumbnails/192x108/00001-192x108.png";
        }

        $thumbnail = $thumbnail ?: $placeholder;

        // Build inplace_editable-style HTML for the video name so it can be edited inline.
        $displayname = format_string($record->name);
        $edithint = get_string('inplace:edit:name', 'mod_videolesson');
        $editlabel = get_string('inplace:edit:newname', 'mod_videolesson', $displayname);
        $rawvalue = (string)$record->name;

        // Escape attributes.
        $attrvalue = s($rawvalue);
        $attreditlabel = s($editlabel);
        $attredithead = s($edithint);

        $namehtml = '<span class="inplaceeditable inplaceeditable-text"'
            . ' data-inplaceeditable="1"'
            . ' data-component="mod_videolesson"'
            . ' data-itemtype="videoname"'
            . ' data-itemid="' . (int)$record->id . '"'
            . ' data-value="' . $attrvalue . '"'
            . ' data-editlabel="' . $attreditlabel . '"'
            . ' data-type="text"'
            . ' data-options="">'
            . '<span class="inplaceeditable-displayvalue">' . $displayname . '</span>'
            . '<a href="#" class="quickeditlink aalink" data-inplaceeditablelink="1"'
            . ' title="' . $attredithead . '">'
            . '<span class="quickediticon visibleifjs icon-size-3">'
            . '<i class="icon fa fa-pencil-alt" aria-hidden="true"></i>'
            . '</span>'
            . '</a>'
            . '</span>';

        // Combine uploaded and transcoder status into a single status field.
        // Logic: pending -> uploaded -> transcoder status.
        $status = '';
        $statusbadge = '';

        if ($record->uploaded != $conversion::CONVERSION_FINISHED) {
            // Not uploaded yet - show pending.
            $status = get_string('upload:status:' . $record->uploaded, 'mod_videolesson');
            if ($record->uploaded == $conversion::CONVERSION_UPLOAD_ERROR || $record->uploaded == 500) {
                $statusbadge = 'danger';
            } else {
                $statusbadge = 'warning';
            }
        } else if (!empty($record->transcoder_status) && $record->transcoder_status != null) {
            // Uploaded and has transcoder status - show transcoder status.
            $status = get_string('transcoding:status:' . $record->transcoder_status, 'mod_videolesson');
            if ($record->transcoder_status == $conversion::CONVERSION_IN_PROGRESS) {
                $statusbadge = 'info';
            } else if ($record->transcoder_status == $conversion::CONVERSION_ACCEPTED) {
                $statusbadge = 'warning';
            } else if ($record->transcoder_status == $conversion::CONVERSION_FINISHED) {
                $statusbadge = 'success';
            } else if (in_array($record->transcoder_status, [$conversion::CONVERSION_NOT_FOUND, $conversion::CONVERSION_ERROR])) {
                $statusbadge = 'danger';
            }
        } else {
            // Uploaded but no transcoder status yet - show uploaded.
            $status = get_string('status:uploaded', 'mod_videolesson');
            $statusbadge = 'info';
        }
        $uncategorized = get_string('folder:uncategorized', 'mod_videolesson');
        $foldername = $record->folderid ? format_string($record->foldername) : $uncategorized;

        $viewaction = [
            'contenthash' => $record->contenthash,
            'src' => $videosrc,
            'title' => $record->name,
        ];

        $mp4action = null;
        if (!empty($record->hasmp4)) {
            $mp4action = [
                'url' => "{$cloudfront}{$record->contenthash}/mp4/{$record->contenthash}.mp4",
                'label' => get_string('manage:video:mp4', 'mod_videolesson'),
            ];
        }

        $reporturl = new moodle_url('/mod/videolesson/report.php', [
            'action' => 'video',
            'contenthash' => $record->contenthash,
        ]);

        $deleteurl = new moodle_url('/mod/videolesson/library.php', [
            'action' => 'delete',
            'contenthash' => $record->contenthash,
        ]);

        $retryurl = new moodle_url('/mod/videolesson/library.php', [
            'action' => 'retry',
            'contenthash' => $record->contenthash,
        ]);

        $instancesurl = null;
        if ($record->instances > 0) {
            $instancesurl = new moodle_url('/mod/videolesson/library.php', [
                'action' => 'instances',
                'contenthash' => $record->contenthash,
            ]);
        }

        $canretry = ($record->uploaded == $conversion::CONVERSION_UPLOAD_ERROR);

        $canview = $record->transcoder_status == $conversion::CONVERSION_FINISHED;

        $canassign = true;

        $cansubtitle = $record->transcoder_status == $conversion::CONVERSION_FINISHED;

        $result = [
            'id' => (int)$record->id,
            'contenthash' => $record->contenthash,
            'namehtml' => $namehtml,
            'title' => format_string($record->name),
            'thumbnail' => $thumbnail,
            'placeholder' => $placeholder,
            'duration' => util::durationformat($record->duration),
            'foldername' => $foldername,
            'folderid' => $record->folderid ? (int)$record->folderid : null,
            'status' => $status,
            'statusbadge' => $statusbadge,
            'instances' => (int)$record->instances,
            'instancesurl' => $instancesurl ? $instancesurl->out(false) : null,
            'size' => util::formatbytes($record->bucket_size ?? 0),
            'sourcesize' => util::formatbytes($record->sourcesize ?? 0),
            'timecreated' => userdate($record->timecreated, '%m/%d/%y %I:%M %p'),
            'timecreatedtimestamp' => (int)$record->timecreated,
            'reporturl' => $reporturl->out(false),
            'retryurl' => $canretry ? $retryurl->out(false) : null,
            'deleteurl' => $deleteurl->out(false),
            'hasvideoactions' => true,
            'assignable' => $canassign,
            'cansubtitle' => $cansubtitle,
        ];

        // Only include mp4action key when we actually have an MP4 action.
        if (!empty($mp4action)) {
            $result['mp4action'] = $mp4action;
        }

        if ($canview) {
            $result['viewaction'] = $viewaction;
        }

        return $result;
    }

    /**
     * Build SQL fragments for folder filtering.
     *
     * @param mixed $folderidentifier
     * @return array [$join, $condition, $params]
     */
    private static function build_folder_sql($folderidentifier): array {
        $folderidentifier = self::normalise_folder_identifier($folderidentifier);

        $params = [];
        $join = "LEFT JOIN {videolesson_folder_items} fi ON fi.videolessonid = c.id";
        $condition = '';

        if ($folderidentifier === 'uncategorized') {
            $condition = 'fi.folderid IS NULL';
        } else if ($folderidentifier !== 'all') {
            $condition = 'fi.folderid = :folderid';
            $params['folderid'] = (int)$folderidentifier;
        }

        return [$join, $condition, $params];
    }
}
