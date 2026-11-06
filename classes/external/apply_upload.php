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

namespace filter_dixeo_imageeditor\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use filter_dixeo_imageeditor\adapter\file_replacer;
use filter_dixeo_imageeditor\adapter\image_util;
use local_dixeo\repository\image\job_repository;

/**
 * Apply an uploaded image and archive the previous version.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class apply_upload extends external_api {
    use location_parameters;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $location = self::location_parameters_definition();
        return new external_function_parameters(array_merge($location->keys, [
            'image_base64' => new external_value(PARAM_RAW, 'Base64-encoded image bytes', VALUE_REQUIRED),
        ]));
    }

    /**
     * Execute the apply_upload web service.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @param int $courseid
     * @param string $imagebase64
     * @return array
     */
    public static function execute(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename,
        int $courseid,
        string $imagebase64
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'courseid' => $courseid,
            'image_base64' => $imagebase64,
        ]);

        $location = self::validate_location($params);

        if (job_repository::has_blocking_job($location)) {
            throw new \moodle_exception('error_upload_blocked', 'filter_dixeo_imageeditor');
        }

        $binary = image_util::decode_image_base64((string) $params['image_base64'], $location->courseid);
        image_util::assert_valid_web_image($binary, $location->courseid);

        file_replacer::apply_binary($location, $binary, (int) $USER->id, file_replacer::SOURCE_UPLOAD);

        return self::image_apply_returns($location);
    }

    /**
     * Returns description of method results.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'imageurl' => new external_value(PARAM_URL, 'Current image URL'),
            'current_contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Current file contenthash'),
            'history' => self::history_returns(),
        ]);
    }
}
