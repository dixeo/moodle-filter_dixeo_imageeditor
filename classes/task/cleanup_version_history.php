<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace filter_dixeo_imageeditor\task;

use filter_dixeo_imageeditor\adapter\eligibility;
use filter_dixeo_imageeditor\adapter\file_replacer;
use filter_dixeo_imageeditor\adapter\image_util;

/**
 * Delete version history rows and archived files that are no longer kept.
 *
 * A version is removed when its target image no longer exists (deleted file, module or
 * course), or when the target is no longer an eligible image (its area is not edited here,
 * or it is not a raster image). Otherwise the history would accumulate in the
 * system-context file storage.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_version_history extends \core\task\scheduled_task {
    /**
     * Return the scheduled task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_version_history', 'filter_dixeo_imageeditor');
    }

    /**
     * Remove version history rows and files that are no longer kept.
     *
     * Every version is read with its target file in one query.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $fs = get_file_storage();
        $systemcontextid = \context_system::instance()->id;

        $sql = "SELECT v.id, v.component, v.filearea, v.contenthash, v.timecreated,
                       f.id AS fileid, f.mimetype, f.contenthash AS currenthash
                  FROM {filter_dixeo_imageeditor_version} v
             LEFT JOIN {files} f ON f.contextid = v.contextid AND f.component = v.component
                                AND f.filearea = v.filearea AND f.itemid = v.itemid
                                AND f.filepath = v.filepath AND f.filename = v.filename
              ORDER BY v.id ASC";
        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $version) {
            if (!$this->must_delete($version)) {
                continue;
            }
            $fs->delete_area_files(
                $systemcontextid,
                'filter_dixeo_imageeditor',
                file_replacer::FILEAREA_HISTORY,
                (int) $version->id
            );
            $DB->delete_records('filter_dixeo_imageeditor_version', ['id' => $version->id]);
        }
        $rs->close();
    }

    /**
     * Whether a version row joined with its target file is no longer kept.
     *
     * @param \stdClass $version Version row with fileid, mimetype and currenthash of the target.
     * @return bool
     */
    private function must_delete(\stdClass $version): bool {
        if (empty($version->fileid)) {
            return true;
        }
        if (!eligibility::is_allowed_area((string) $version->component, (string) $version->filearea)) {
            return true;
        }
        return !image_util::is_raster_web_mimetype((string) $version->mimetype);
    }
}
