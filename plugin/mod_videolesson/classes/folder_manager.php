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
 * Folder manager class for organizing videos in folders
 *
 * @package    mod_videolesson
 * @author     BitKea Technologies LLP
 * @copyright  2022-2026 BitKea Technologies LLP
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videolesson;

/**
 * Class folder_manager
 */
class folder_manager {
    /** Maximum depth for folder hierarchy */
    const MAX_DEPTH = 3;

    /**
     * Create a new folder
     *
     * @param string $name Folder name
     * @param int|null $parentid Parent folder ID (null for root)
     * @return int|false Folder ID on success, false on failure
     */
    public static function create_folder($name, $parentid = null) {
        global $DB;

        // Validate name.
        $name = trim($name);
        if (empty($name)) {
            return false;
        }

        // Validate parent and depth.
        if ($parentid !== null && $parentid > 0) {
            $parent = $DB->get_record('videolesson_folders', ['id' => $parentid]);
            if (!$parent) {
                return false;
            }
            if ($parent->depth >= self::MAX_DEPTH) {
                return false; // Max depth reached.
            }
            $depth = $parent->depth + 1;
            // Parent's path already ends with parent ID (e.g., "/18/"), so just use it as base.
            $path = $parent->path;
        } else {
            $parentid = null;
            $depth = 0;
            $path = '/';
        }

        // Get next sortorder.
        $sortorder = self::get_next_sortorder($parentid);

        $folder = (object)[
            'name' => $name,
            'parent' => $parentid,
            'depth' => $depth,
            'path' => '', // Will be set after we get the folder ID.
            'sortorder' => $sortorder,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $folderid = $DB->insert_record('videolesson_folders', $folder);
        if ($folderid) {
            // Calculate final path: parent path + folder ID.
            // Example: parent path "/18/", folder ID 21 -> "/18/21/".
            $folder->id = $folderid;
            $folder->path = $path . $folderid . '/';
            $DB->update_record('videolesson_folders', $folder);
        }

        return $folderid;
    }

    /**
     * Update folder name or move folder
     *
     * @param int $folderid Folder ID
     * @param string|null $newname New folder name (null to keep current)
     * @param int|null $newparentid New parent folder ID (null to keep current)
     * @return bool Success
     */
    public static function update_folder($folderid, $newname = null, $newparentid = null) {
        global $DB;

        $folder = $DB->get_record('videolesson_folders', ['id' => $folderid]);
        if (!$folder) {
            return false;
        }

        $update = false;

        // Update name if provided.
        if ($newname !== null) {
            $newname = trim($newname);
            if (!empty($newname) && $newname !== $folder->name) {
                $folder->name = $newname;
                $update = true;
            }
        }

        // Move folder if parent changed.
        // Normalize both values for comparison (handle null, 0, and type differences).
        $currentparent = $folder->parent;
        $normalizednewparent = ($newparentid === null || $newparentid === 0 || $newparentid === '0')
            ? null
            : (int)$newparentid;
        $normalizedcurrentparent = ($currentparent === null || $currentparent === 0 || $currentparent === '0')
            ? null
            : (int)$currentparent;

        if ($normalizednewparent !== $normalizedcurrentparent) {
            // Prevent moving folder into itself or its descendants.
            if ($normalizednewparent && self::is_descendant($normalizednewparent, $folderid)) {
                return false;
            }

            // Validate new parent depth.
            if ($normalizednewparent) {
                $newparent = $DB->get_record('videolesson_folders', ['id' => $normalizednewparent]);
                if (!$newparent) {
                    return false;
                }
                if ($newparent->depth >= self::MAX_DEPTH) {
                    return false; // Max depth reached.
                }
                $newdepth = $newparent->depth + 1;
                // The parent's path already ends with the parent ID, so just append the folder ID.
                // Example: parent path is "/1/2/", new path should be "/1/2/{folderid}/".
                $newpath = $newparent->path . $folderid . '/';
            } else {
                $newdepth = 0;
                $newpath = '/' . $folderid . '/';
            }

            $oldpath = $folder->path;
            $folder->parent = $normalizednewparent;
            $folder->depth = $newdepth;
            $folder->path = $newpath;
            $folder->sortorder = self::get_next_sortorder($normalizednewparent);
            $update = true;

            // Update all descendants' paths and depths.
            self::update_descendants($folderid, $oldpath, $folder->path, $newdepth);
        }

        if ($update) {
            $folder->timemodified = time();
            $DB->update_record('videolesson_folders', $folder);
        }

        return true;
    }

    /**
     * Delete a folder
     *
     * @param int $folderid Folder ID
     * @param bool $movevideos If true, move videos to parent folder; if false, remove folder assignment
     * @return bool Success
     */
    public static function delete_folder($folderid, $movevideos = false) {
        global $DB;

        $folder = $DB->get_record('videolesson_folders', ['id' => $folderid]);
        if (!$folder) {
            return false;
        }

        if (!self::can_delete_folder($folderid)) {
            return false;
        }

        // Recursively delete child folders first.
        $children = self::get_child_folders($folderid);
        foreach ($children as $child) {
            if (!self::delete_folder($child->id, true)) {
                return false;
            }
        }

        // Handle videos in this folder.
        self::reassign_or_delete_folder_videos($folder, $movevideos);

        // Delete the folder.
        $DB->delete_records('videolesson_folders', ['id' => $folderid]);

        return true;
    }

    /**
     * Placeholder for validating folder deletion.
     *
     * @param int $folderid
     * @return bool
     */
    public static function can_delete_folder(int $folderid): bool {
        // Additional business rules can be added here later.
        return true;
    }

    /**
     * Get folder by ID
     *
     * @param int $folderid Folder ID
     * @return object|false Folder object or false
     */
    public static function get_folder($folderid) {
        global $DB;
        return $DB->get_record('videolesson_folders', ['id' => $folderid]);
    }

    /**
     * Get all folders in a tree structure
     *
     * @param int|null $parentid Parent folder ID (null for root folders)
     * @return array Array of folder objects
     */
    public static function get_folders($parentid = null) {
        global $DB;

        if ($parentid === null) {
            $folders = $DB->get_records('videolesson_folders', ['parent' => null], 'sortorder ASC, name ASC');
        } else {
            // Ensure parentid is an integer for proper comparison.
            $parentid = (int)$parentid;
            // Use SQL to ensure proper type matching (parent might be stored as string in some cases).
            $sql = "SELECT * FROM {videolesson_folders} WHERE parent = :parentid ORDER BY sortorder ASC, name ASC";
            $folders = $DB->get_records_sql($sql, ['parentid' => $parentid]);
        }

        return $folders;
    }

    /**
     * Get folder tree structure
     *
     * @return array Tree structure with nested children
     */
    public static function get_folder_tree() {
        $rootfolders = self::get_folders(null);
        $tree = [];

        foreach ($rootfolders as $folder) {
            $tree[] = self::build_folder_node($folder);
        }

        return $tree;
    }

    /**
     * Build a folder node with children recursively
     *
     * @param object $folder Folder object
     * @return array Folder node with children
     */
    private static function build_folder_node($folder) {
        // Ensure folder ID is an integer.
        $folderid = (int)$folder->id;

        $node = [
            'id' => $folderid,
            'name' => $folder->name,
            'parent' => $folder->parent !== null ? (int)$folder->parent : null,
            'depth' => (int)$folder->depth,
            'path' => $folder->path,
            'sortorder' => (int)$folder->sortorder,
            'video_count' => self::get_video_count($folderid),
            'children' => [],
            'selected' => false,
        ];

        // Get children using the folder ID (ensure it's an integer).
        $children = self::get_folders($folderid);
        foreach ($children as $child) {
            // Safety check: ensure child is not the same as parent (prevent infinite loops).
            $childid = (int)$child->id;
            if ($childid !== $folderid) {
                // Verify parent relationship is correct.
                $childparent = $child->parent !== null ? (int)$child->parent : null;
                if ($childparent === $folderid) {
                    $node['children'][] = self::build_folder_node($child);
                }
            }
        }
        $node['has_children'] = !empty($node['children']);

        return $node;
    }

    /**
     * Mark selected folder nodes.
     *
     * @param array $tree
     * @param int|null $selectedid
     * @return array
     */
    public static function mark_selected(array $tree, ?int $selectedid): array {
        foreach ($tree as &$node) {
            $node['selected'] = ($selectedid !== null && (int)$node['id'] === $selectedid);
            if (!empty($node['children'])) {
                $node['children'] = self::mark_selected($node['children'], $selectedid);
            }
        }
        return $tree;
    }

    /**
     * Get video count in a folder
     *
     * @param int $folderid Folder ID
     * @return int Video count
     */
    public static function get_video_count($folderid) {
        global $DB;
        return $DB->count_records('videolesson_folder_items', ['folderid' => $folderid]);
    }

    /**
     * Get count of videos that are not assigned to any folder.
     *
     * @return int
     */
    public static function get_uncategorized_count(): int {
        global $DB;

        $sql = "SELECT COUNT(c.id)
                  FROM {videolesson_conv} c
             LEFT JOIN {videolesson_folder_items} fi ON fi.videolessonid = c.id
                 WHERE fi.folderid IS NULL";

        return (int)$DB->count_records_sql($sql);
    }

    /**
     * Get total count of all videos.
     *
     * @return int
     */
    public static function get_total_video_count(): int {
        global $DB;
        return (int)$DB->count_records('videolesson_conv');
    }

    /**
     * Get folder options as flat list for selectors.
     *
     * @return array
     */
    public static function get_folder_options(): array {
        $tree = self::get_folder_tree();
        $options = [];
        self::flatten_folder_options($tree, $options);
        return $options;
    }

    /**
     * Check whether a folder exists.
     *
     * @param int $folderid
     * @return bool
     */
    public static function folder_exists(int $folderid): bool {
        global $DB;
        return $DB->record_exists('videolesson_folders', ['id' => $folderid]);
    }

    /**
     * Flatten folder tree into options array.
     *
     * @param array $nodes
     * @param array $options
     * @param int $depth
     * @return void
     */
    private static function flatten_folder_options(array $nodes, array &$options, int $depth = 0): void {
        foreach ($nodes as $node) {
            $indent = $depth > 0 ? str_repeat('— ', $depth) : '';
            $options[$node['id']] = trim($indent . $node['name']);
            if (!empty($node['children'])) {
                self::flatten_folder_options($node['children'], $options, $depth + 1);
            }
        }
    }

    /**
     * Get videos in a folder
     *
     * @param int|null $folderid Folder ID (null for videos not in any folder)
     * @return array Array of video IDs
     */
    public static function get_videos_in_folder($folderid) {
        global $DB;

        if ($folderid === null) {
            $videos = $DB->get_records('videolesson_folder_items', ['folderid' => null], 'sortorder ASC');
        } else {
            $videos = $DB->get_records('videolesson_folder_items', ['folderid' => $folderid], 'sortorder ASC');
        }

        return array_column($videos, 'videolessonid');
    }

    /**
     * Move video to folder
     *
     * @param int $videolessonid Video ID (from videolesson_conv table)
     * @param int|null $folderid Target folder ID (null to remove from folder)
     * @return bool Success
     */
    public static function move_video($videolessonid, $folderid = null) {
        global $DB;

        // Verify video exists.
        if (!$DB->record_exists('videolesson_conv', ['id' => $videolessonid])) {
            return false;
        }

        // Verify folder exists if provided.
        if ($folderid !== null && !$DB->record_exists('videolesson_folders', ['id' => $folderid])) {
            return false;
        }

        $item = $DB->get_record('videolesson_folder_items', ['videolessonid' => $videolessonid]);
        $time = time();

        if ($item) {
            // Update existing record.
            $item->folderid = $folderid;
            $item->sortorder = self::get_next_video_sortorder($folderid);
            $item->timemodified = $time;
            $DB->update_record('videolesson_folder_items', $item);
        } else {
            // Create new record.
            $item = (object)[
                'folderid' => $folderid,
                'videolessonid' => $videolessonid,
                'sortorder' => self::get_next_video_sortorder($folderid),
                'timecreated' => $time,
                'timemodified' => $time,
            ];
            $DB->insert_record('videolesson_folder_items', $item);
        }

        return true;
    }

    /**
     * Update video sort order
     *
     * @param array $videoids Array of video IDs in desired order
     * @param int|null $folderid Folder ID (null for root)
     * @return bool Success
     */
    public static function update_video_sortorder($videoids, $folderid = null) {
        global $DB;

        if (empty($videoids)) {
            return true;
        }

        [$insql, $params] = $DB->get_in_or_equal($videoids, SQL_PARAMS_NAMED);

        if ($folderid === null) {
            $select = "videolessonid {$insql} AND folderid IS NULL";
        } else {
            $select = "videolessonid {$insql} AND folderid = :folderid";
            $params['folderid'] = $folderid;
        }

        $records = $DB->get_records_select('videolesson_folder_items', $select, $params);
        $byvideoid = [];
        foreach ($records as $record) {
            $byvideoid[$record->videolessonid] = $record;
        }

        $now = time();
        $sortorder = 0;
        foreach ($videoids as $videoid) {
            if (empty($byvideoid[$videoid])) {
                continue;
            }
            $item = $byvideoid[$videoid];
            $item->sortorder = $sortorder++;
            $item->timemodified = $now;
            $DB->update_record('videolesson_folder_items', $item);
        }

        return true;
    }

    /**
     * Get next sort order for a folder
     *
     * @param int|null $parentid Parent folder ID
     * @return int Next sort order
     */
    private static function get_next_sortorder($parentid) {
        global $DB;

        if ($parentid === null) {
            $max = $DB->get_field_sql("SELECT MAX(sortorder) FROM {videolesson_folders} WHERE parent IS NULL");
        } else {
            $max = $DB->get_field_sql("SELECT MAX(sortorder) FROM {videolesson_folders} WHERE parent = ?", [$parentid]);
        }

        return ($max !== false) ? $max + 1 : 0;
    }

    /**
     * Get next sort order for a video in a folder
     *
     * @param int|null $folderid Folder ID
     * @return int Next sort order
     */
    private static function get_next_video_sortorder($folderid) {
        global $DB;

        if ($folderid === null) {
            $max = $DB->get_field_sql("SELECT MAX(sortorder) FROM {videolesson_folder_items} WHERE folderid IS NULL");
        } else {
            $max = $DB->get_field_sql("SELECT MAX(sortorder) FROM {videolesson_folder_items} WHERE folderid = ?", [$folderid]);
        }

        return ($max !== false) ? $max + 1 : 0;
    }

    /**
     * Reassign or delete videos when removing a folder.
     *
     * @param \stdClass $folder
     * @param bool $movevideos
     * @return void
     */
    private static function reassign_or_delete_folder_videos(\stdClass $folder, bool $movevideos): void {
        global $DB;

        $now = time();
        if ($movevideos && $folder->parent) {
            $params = ['oldfolderid' => $folder->id];
            $DB->set_field_select(
                'videolesson_folder_items',
                'timemodified',
                $now,
                'folderid = :oldfolderid',
                $params
            );
            $DB->set_field_select(
                'videolesson_folder_items',
                'folderid',
                $folder->parent,
                'folderid = :oldfolderid',
                $params
            );
        } else if ($movevideos && !$folder->parent) {
            $params = ['folderid' => $folder->id];
            $DB->set_field_select(
                'videolesson_folder_items',
                'timemodified',
                $now,
                'folderid = :folderid',
                $params
            );
            $DB->set_field_select(
                'videolesson_folder_items',
                'folderid',
                null,
                'folderid = :folderid',
                $params
            );
        } else {
            self::delete_videos_in_folder($folder->id);
        }
    }

    /**
     * Get child folders
     *
     * @param int $folderid Parent folder ID
     * @return array Array of child folder objects
     */
    private static function get_child_folders($folderid) {
        global $DB;
        return $DB->get_records('videolesson_folders', ['parent' => $folderid]);
    }

    /**
     * Check if a folder is a descendant of another
     *
     * @param int $ancestorid Ancestor folder ID
     * @param int $descendantid Potential descendant folder ID
     * @return bool True if descendant
     */
    private static function is_descendant($ancestorid, $descendantid) {
        global $DB;

        $descendant = $DB->get_record('videolesson_folders', ['id' => $descendantid]);
        if (!$descendant) {
            return false;
        }

        // Check if ancestor's path is in descendant's path.
        $ancestorpath = $DB->get_field('videolesson_folders', 'path', ['id' => $ancestorid]);
        if (!$ancestorpath) {
            return false;
        }

        return strpos($descendant->path, $ancestorpath) === 0;
    }

    /**
     * Update all descendant folders' paths and depths
     *
     * @param int $folderid Folder ID
     * @param string $oldpath Old path
     * @param string $newpath New path
     * @param int $newdepth New depth
     */
    private static function update_descendants($folderid, $oldpath, $newpath, $newdepth) {
        global $DB;

        // Find all descendants using path.
        $descendants = $DB->get_records_select('videolesson_folders', "path LIKE ?", [$oldpath . '%']);

        foreach ($descendants as $descendant) {
            if ($descendant->id == $folderid) {
                continue; // Skip the folder itself.
            }

            // Calculate the correct new path based on parent relationship.
            // The old path structure is: /ancestor1/ancestor2/folderid/descendant...
            // We need to replace the old path prefix with the new path prefix.
            $descendantoldpath = $descendant->path;
            if (strpos($descendantoldpath, $oldpath) === 0) {
                // Replace the old path prefix with the new path prefix.
                $descendant->path = $newpath . substr($descendantoldpath, strlen($oldpath));
            } else {
                // Fallback: recalculate path from parent.
                $parent = $descendant->parent ? $DB->get_record('videolesson_folders', ['id' => $descendant->parent]) : null;
                if ($parent) {
                    $descendant->path = $parent->path . $descendant->id . '/';
                    $descendant->depth = $parent->depth + 1;
                } else {
                    $descendant->path = '/' . $descendant->id . '/';
                    $descendant->depth = 0;
                }
            }

            // Recalculate depth based on path segments (more reliable).
            $pathsegments = array_filter(explode('/', $descendant->path));
            $descendant->depth = count($pathsegments) - 1; // Subtract 1 because path includes folder itself.

            $descendant->timemodified = time();
            $DB->update_record('videolesson_folders', $descendant);
        }
    }

    /**
     * Delete all videos inside a folder when not moving them.
     *
     * @param int $folderid
     * @return bool
     */
    protected static function delete_videos_in_folder(int $folderid): bool {
        global $DB;

        // Ensure MOD_VIDEOLESSON_SRC_GALLERY constant is available.
        require_once(__DIR__ . '/../locallib.php');

        // Get all folder items for this folder.
        $folderitems = $DB->get_records('videolesson_folder_items', ['folderid' => $folderid]);

        if (empty($folderitems)) {
            return true;
        }

        $videosource = new \mod_videolesson\videosource();

        foreach ($folderitems as $folderitem) {
            // Get the video record.
            $video = $DB->get_record('videolesson_conv', ['id' => $folderitem->videolessonid]);
            if (!$video) {
                // Video doesn't exist, just delete the folder item.
                $DB->delete_records('videolesson_folder_items', ['id' => $folderitem->id]);
                continue;
            }

            // Check if video has instances (is being used in courses).
            // Check for any records in videolesson table with matching sourcedata and source = MOD_VIDEOLESSON_SRC_GALLERY.
            // This matches the check used in videosource->output_delete().
            $hasinstances = $DB->record_exists('videolesson', [
                'sourcedata' => $video->contenthash,
                'source' => MOD_VIDEOLESSON_SRC_GALLERY,
            ]);

            if ($hasinstances) {
                // Move to uncategorized (set folderid to NULL).
                $DB->set_field('videolesson_folder_items', 'folderid', null, ['id' => $folderitem->id]);
            } else {
                // Delete the video (this will also delete the folder item via delete_related_records).
                $result = $videosource->output_delete($video->contenthash);
                if (!$result['success']) {
                    // Log error but continue with other videos.
                    // Don't fail the entire operation if one video fails to delete.
                    continue;
                }
            }
        }

        // Delete any remaining folder items for this folder (should be none if all went well).
        $DB->delete_records('videolesson_folder_items', ['folderid' => $folderid]);

        return true;
    }

    /**
     * Placeholder hook for future delete conditions.
     *
     * @param \stdClass $video
     * @return bool
     */
    protected static function can_delete_video(\stdClass $video): bool {
        global $DB;

        // Future conditions can be added here.
        if ($DB->record_exists('videolesson', ['sourcedata' => $video->contenthash])) {
            return false;
        }

        return true;
    }

    /**
     * Remove video conversion and related data.
     *
     * @param \stdClass $video
     * @return void
     */
    protected static function perform_video_delete(\stdClass $video): void {
        global $DB;

        $DB->delete_records('videolesson_folder_items', ['videolessonid' => $video->id]);
        $DB->delete_records('videolesson_conv', ['id' => $video->id]);

        if (!$DB->record_exists('videolesson_conv', ['contenthash' => $video->contenthash])) {
            $DB->delete_records('videolesson_data', ['contenthash' => $video->contenthash]);
        }
    }

    /**
     * Recalculate and fix all folder paths based on parent relationships
     * This is useful for fixing paths that were incorrectly calculated
     * Processes folders in order of depth to ensure parents are fixed before children
     *
     * @return int Number of folders updated
     */
    public static function recalculate_all_paths() {
        global $DB;

        // Get all folders ordered by depth (parents before children) and then by ID for consistency.
        $allfolders = $DB->get_records('videolesson_folders', [], 'depth ASC, id ASC');
        $updated = 0;
        $processedpaths = []; // Cache of corrected paths by folder ID.

        foreach ($allfolders as $folder) {
            $folderid = (int)$folder->id;
            $newpath = '';
            $newdepth = 0;

            // Use parent field to determine correct path (parent field should be correct).
            $currentparent = $folder->parent !== null ? (int)$folder->parent : null;

            if ($currentparent !== null) {
                // Use cached path if parent was already processed, otherwise get from DB.
                if (isset($processedpaths[$currentparent])) {
                    $parentpath = $processedpaths[$currentparent];
                    $parentdepth = substr_count($parentpath, '/') - 1;
                } else {
                    $parent = $DB->get_record('videolesson_folders', ['id' => $currentparent]);
                    if ($parent) {
                        // Recursively ensure parent's path is correct first.
                        $parentpath = $parent->path;
                        // Verify parent path is correct format.
                        if (!preg_match('#^/' . $currentparent . '/$#', $parentpath)) {
                            // Parent path might be wrong, recalculate it.
                            if ($parent->parent !== null) {
                                $grandparent = $DB->get_record('videolesson_folders', ['id' => (int)$parent->parent]);
                                $parentpath = $grandparent ? $grandparent->path . $currentparent . '/' : '/' . $currentparent . '/';
                            } else {
                                $parentpath = '/' . $currentparent . '/';
                            }
                        }
                        $parentdepth = (int)$parent->depth;
                    } else {
                        // Parent not found, treat as root.
                        $parentpath = '/' . $currentparent . '/';
                        $parentdepth = 0;
                    }
                }
                // Parent's path already ends with parent ID, so just append folder ID.
                $newpath = $parentpath . $folderid . '/';
                $newdepth = $parentdepth + 1;
            } else {
                // Root folder.
                $newpath = '/' . $folderid . '/';
                $newdepth = 0;
            }

            // Only update if path or depth changed.
            if ($folder->path !== $newpath || $folder->depth != $newdepth) {
                $folder->path = $newpath;
                $folder->depth = $newdepth;
                $folder->timemodified = time();
                $DB->update_record('videolesson_folders', $folder);
                $updated++;
            }

            // Cache the corrected path for this folder.
            $processedpaths[$folderid] = $newpath;
        }

        return $updated;
    }
}
