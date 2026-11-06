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

use local_dixeo\util\input_limits;

/**
 * Helpers for validating uploaded web images.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class image_util {
    /**
     * Effective upload limit in bytes for the current user in a course.
     *
     * Matches {@see \local_dixeo\service\manual_upload_service::stage_upload_to_draft()}. A user
     * who may ignore file size limits is still bounded by the site maximum, which core caps at
     * the PHP upload and post limits.
     *
     * @param int $courseid
     * @return int
     */
    public static function get_max_upload_bytes(int $courseid): int {
        global $CFG;

        $course = get_course($courseid);
        $context = \context_course::instance($courseid);

        $max = get_user_max_upload_file_size($context, $CFG->maxbytes, $course->maxbytes);
        if ($max == USER_CAN_IGNORE_FILE_SIZE_LIMITS) {
            $max = get_max_upload_file_size($CFG->maxbytes);
        }
        return (int) $max;
    }

    /**
     * HTML file input accept attribute for Moodle web_image types.
     *
     * SVG is excluded: it can embed scripts and the capability is not RISK_XSS.
     *
     * @return string
     */
    public static function get_web_image_accept_attribute(): string {
        $extensions = file_get_typegroup('extension', 'web_image');
        $extensions = array_filter($extensions, static function (string $extension): bool {
            return stripos($extension, 'svg') === false;
        });
        return implode(',', $extensions);
    }

    /**
     * Whether a MIME type is a raster web image.
     *
     * SVG is excluded: it can embed scripts and the edit capability only declares RISK_SPAM.
     *
     * @param string $mimetype MIME type.
     * @return bool
     */
    public static function is_raster_web_mimetype(string $mimetype): bool {
        return $mimetype !== 'image/svg+xml' && file_mimetype_in_typegroup($mimetype, 'web_image');
    }

    /**
     * Validate image bytes for content type only (no upload size limit).
     *
     * Used by all apply paths including AI job results. Rejects SVG because it
     * can embed scripts and the edit capability only declares RISK_SPAM.
     *
     * @param string $binary
     * @param string $errorstring Language string key when validation fails.
     * @return void
     */
    public static function assert_valid_web_image_content(
        string $binary,
        string $errorstring = 'error_upload_invalid_image'
    ): void {
        if ($binary === '') {
            throw new \moodle_exception($errorstring, 'filter_dixeo_imageeditor');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimetype = $finfo->buffer($binary);
        if (!is_string($mimetype) || !self::is_raster_web_mimetype($mimetype)) {
            throw new \moodle_exception($errorstring, 'filter_dixeo_imageeditor');
        }

        if (@getimagesizefromstring($binary) === false) {
            throw new \moodle_exception($errorstring, 'filter_dixeo_imageeditor');
        }
    }

    /**
     * Validate image bytes against Moodle web_image types and course upload limit.
     *
     * @param string $binary
     * @param int $courseid Course id for maxbytes check.
     * @param string $errorstring Language string key when validation fails.
     * @return void
     */
    public static function assert_valid_web_image(
        string $binary,
        int $courseid,
        string $errorstring = 'error_upload_invalid_image'
    ): void {
        if ($binary === '') {
            throw new \moodle_exception($errorstring, 'filter_dixeo_imageeditor');
        }

        input_limits::require_max_size(
            $binary,
            self::get_max_upload_bytes($courseid),
            'error_upload_too_large',
            'filter_dixeo_imageeditor'
        );

        self::assert_valid_web_image_content($binary, $errorstring);
    }

    /**
     * Decode a base64 image payload from the browser.
     *
     * The encoded length is checked against the upload limit before decoding, so an oversized
     * payload never costs a decoded copy in memory.
     *
     * @param string $payload Raw base64, or a data URI.
     * @param int $courseid Course whose upload limit applies.
     * @return string Decoded bytes, or an empty string when the payload is empty or invalid.
     * @throws \moodle_exception error_upload_too_large when the payload exceeds the upload limit.
     */
    public static function decode_image_base64(string $payload, int $courseid): string {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        if (($pos = strpos($payload, 'base64,')) !== false) {
            $payload = substr($payload, $pos + 7);
        }

        // Base64 of $max bytes, plus slack for padding and line ends.
        $max = self::get_max_upload_bytes($courseid);
        if (strlen($payload) > 4 * intdiv($max + 2, 3) + 4) {
            throw new \moodle_exception('error_upload_too_large', 'filter_dixeo_imageeditor', '', display_size($max));
        }

        $binary = base64_decode($payload, true);
        return $binary === false ? '' : $binary;
    }
}
