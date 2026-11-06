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
use filter_dixeo_imageeditor\adapter\feature_gate;
use filter_dixeo_imageeditor\adapter\file_replacer;
use filter_dixeo_imageeditor\adapter\tiles_section_photo;
use filter_dixeo_imageeditor\event\content_image_job_started;
use local_dixeo\dto\job_binding_metadata;
use local_dixeo\repository\image\job_repository;
use local_dixeo\external\service_factory;
use local_dixeo\service\image_generation_service;
use local_dixeo\util\input_limits;

/**
 * Start a content image generate job.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class start_generate extends external_api {
    use location_parameters;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $location = self::location_parameters_definition();
        return new external_function_parameters(array_merge($location->keys, [
            'prompt' => new external_value(PARAM_RAW, 'User prompt', VALUE_REQUIRED),
            'size' => new external_value(PARAM_ALPHANUMEXT, 'Image size', VALUE_DEFAULT, image_generation_service::DEFAULT_SIZE),
            'quality' => new external_value(PARAM_ALPHA, 'Quality', VALUE_DEFAULT, image_generation_service::DEFAULT_QUALITY),
        ]));
    }

    /**
     * Execute the start_generate web service.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @param int $courseid
     * @param string $prompt
     * @param string $size
     * @param string $quality
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
        string $prompt,
        string $size = image_generation_service::DEFAULT_SIZE,
        string $quality = image_generation_service::DEFAULT_QUALITY
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
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
        ]);

        $location = self::validate_location($params);
        input_limits::require_max_chars(
            trim($params['prompt']),
            input_limits::MAX_IMAGE_PROMPT_CHARS,
            'error:prompt_too_long',
            'local_dixeo'
        );
        feature_gate::require_content_generate($location->courseid);

        if (trim($params['prompt']) === '') {
            throw new \moodle_exception('prompt_required', 'filter_dixeo_imageeditor');
        }

        if (job_repository::has_blocking_job($location)) {
            throw new \moodle_exception('error_locked', 'filter_dixeo_imageeditor');
        }

        $quality = self::validate_image_quality($params['quality']);

        $file = $location->get_stored_file();
        if ($file) {
            $title = image_generation_service::resolve_title_for_stored_file($file);
            $binding = job_binding_metadata::for_stored_file($file);
        } else {
            $title = tiles_section_photo::section_title($location);
            $binding = job_binding_metadata::for_course($location->courseid);
        }

        $imageservice = service_factory::get_image_generation_service('filter_dixeo_imageeditor');
        $result = $imageservice->submit_content_image_generate_job(
            $location->courseid,
            $title,
            trim($params['prompt']),
            $params['size'],
            $quality,
            $binding
        );

        $jobid = trim((string) $result->jobid);
        if ($jobid === '') {
            throw new \moodle_exception('dixeo_image_job_empty_result', 'local_dixeo');
        }

        $modemapping = [
            '1536x1024' => 'landscape',
            '1024x1536' => 'portrait',
            '1024x1024' => 'square',
        ];
        $mode = $modemapping[$params['size']] ?? 'landscape';

        $queued = self::queue_content_image_job(
            $location,
            $jobid,
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED,
            trim($params['prompt']),
            $quality,
            $mode
        );

        content_image_job_started::create_from_location($location, (int) $USER->id, $jobid, 'generate')->trigger();

        return $queued;
    }

    /**
     * Returns description of method results.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_RAW, 'Remote job id'),
            'status' => new external_value(PARAM_ALPHA, 'Lock status'),
        ]);
    }
}
