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

/**
 * Library functions for filter_dixeo_imageeditor.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve version history files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function filter_dixeo_imageeditor_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
) {
    global $DB;

    if ($context->contextlevel !== CONTEXT_SYSTEM || $filearea !== 'history') {
        return false;
    }

    require_login();

    if (count($args) < 2) {
        return false;
    }

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = '/' . implode('/', $args) . '/';
    if ($filepath === '//') {
        $filepath = '/';
    }

    $version = $DB->get_record('filter_dixeo_imageeditor_version', ['id' => $itemid], '*', IGNORE_MISSING);
    if (!$version) {
        return false;
    }

    $targetfile = get_file_storage()->get_file(
        (int) $version->contextid,
        (string) $version->component,
        (string) $version->filearea,
        (int) $version->itemid,
        (string) $version->filepath,
        (string) $version->filename
    );
    if (!$targetfile || !\filter_dixeo_imageeditor\adapter\eligibility::is_eligible_stored_file($targetfile)) {
        return false;
    }

    $courseid = \local_dixeo\service\image\pluginfile_helper::resolve_course_id_for_file($targetfile);
    if ($courseid < 1) {
        return false;
    }

    try {
        \filter_dixeo_imageeditor\adapter\location_access::require_edit_access_for_file($targetfile);
    } catch (\Throwable $e) {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'filter_dixeo_imageeditor', 'history', $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Only raster images are shown inline; anything else is downloaded.
    $inline = \filter_dixeo_imageeditor\adapter\image_util::is_raster_web_mimetype((string) $file->get_mimetype());
    send_stored_file($file, HOURSECS, 0, $forcedownload || !$inline, $options);
}
