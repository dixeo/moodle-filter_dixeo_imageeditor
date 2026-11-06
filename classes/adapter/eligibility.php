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

namespace filter_dixeo_imageeditor\adapter;

/**
 * Determines whether a stored file is eligible for AI editing.
 *
 * Only raster web images in content areas that course editors author are eligible: the
 * teacher-authored editor fields that pass through format_text(), plus the slideshow featured
 * image and the tiles photo, which this plugin edits from its own controls. Submissions, posts,
 * attachments, backups, user and question files are never eligible.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class eligibility {
    /** @var array<string, string[]> Allowed file areas by component, in addition to any mod_* intro. */
    private const ALLOWED_AREAS = [
        'course' => ['section', 'summary'],
        'mod_page' => ['content'],
        'mod_book' => ['chapter'],
        'mod_lesson' => ['page_contents'],
        'mod_slideshow' => ['content', 'image'],
        'block_html' => ['content'],
        'format_tiles' => ['tilephoto'],
    ];

    /** @var string[] Activity modules whose intro is not edited here. */
    private const EXCLUDED_MODULES = ['mod_h5pactivity'];

    /**
     * Whether a stored file is eligible for Dixeo editing.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function is_eligible_stored_file(\stored_file $file): bool {
        if ($file->is_directory()) {
            return false;
        }
        if ($file->is_external_file()) {
            return false;
        }
        if ((int) $file->get_referencefileid() > 0) {
            return false;
        }
        if (!self::is_allowed_area($file->get_component(), $file->get_filearea())) {
            return false;
        }
        if (!image_util::is_raster_web_mimetype((string) $file->get_mimetype())) {
            return false;
        }

        $context = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return in_array((int) $context->contextlevel, [CONTEXT_COURSE, CONTEXT_MODULE, CONTEXT_BLOCK], true);
    }

    /**
     * Whether a component and file area hold editor-authored course content.
     *
     * @param string $component Frankenstyle component of the file.
     * @param string $filearea File area of the file.
     * @return bool
     */
    public static function is_allowed_area(string $component, string $filearea): bool {
        if (in_array($component, self::EXCLUDED_MODULES, true)) {
            return false;
        }
        if (str_starts_with($component, 'mod_') && $filearea === 'intro') {
            return true;
        }
        return in_array($filearea, self::ALLOWED_AREAS[$component] ?? [], true);
    }

    /**
     * Resolve an eligible stored file from a pluginfile URL.
     *
     * @param string $imageurl
     * @return \stored_file|null
     */
    public static function resolve_eligible_file_from_url(string $imageurl): ?\stored_file {
        $file = \local_dixeo\service\image\pluginfile_helper::get_stored_file_from_pluginfile_url($imageurl);
        if (!$file || !self::is_eligible_stored_file($file)) {
            return null;
        }
        return $file;
    }
}
