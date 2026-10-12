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

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use filter_dixeo_imageeditor\adapter\eligibility;
use filter_dixeo_imageeditor\adapter\location_access;
use filter_dixeo_imageeditor\adapter\file_replacer;
use local_dixeo\dto\job_binding_metadata;
use local_dixeo\repository\image\job_repository;
use local_dixeo\service\image\job_orchestrator;
use local_dixeo\service\image\content\location;
use local_dixeo\service\image\content_target;
use local_dixeo\service\image_generation_service;

/**
 * Shared validation for filter externals.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait location_parameters {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    protected static function location_parameters_definition(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'File context id', VALUE_REQUIRED),
            'component' => new external_value(PARAM_COMPONENT, 'File component', VALUE_REQUIRED),
            'filearea' => new external_value(PARAM_AREA, 'File area', VALUE_REQUIRED),
            'itemid' => new external_value(PARAM_INT, 'File item id', VALUE_REQUIRED),
            'filepath' => new external_value(PARAM_PATH, 'File path', VALUE_DEFAULT, '/'),
            'filename' => new external_value(PARAM_FILE, 'File name', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Validate the file coordinates and authorise the user against the file's own course.
     *
     * The course id is derived server-side from the stored file's context; the client
     * supplied courseid is never used for authorisation.
     *
     * @param array $params
     * @return location
     */
    protected static function validate_location(array $params): location {
        $definition = self::location_parameters_definition();
        $params = self::validate_parameters($definition, array_intersect_key($params, $definition->keys));

        $file = location::from_params($params)->get_stored_file();
        if (!$file) {
            $pending = \filter_dixeo_imageeditor\adapter\tiles_section_photo::pending_location($params);
            if (!$pending) {
                throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
            }
            location_access::require_edit_access_for_course($pending->courseid);
            self::validate_context(\context_course::instance($pending->courseid));
            self::require_dixeo_enabled($pending->courseid);
            return $pending;
        }
        if (!eligibility::is_eligible_stored_file($file)) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        $location = location::from_stored_file($file);
        if ($location->courseid < 1) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        self::authorise_location_access($file, $location->courseid);
        self::require_dixeo_enabled($location->courseid);

        return $location;
    }

    /**
     * Stop an image-editor request when Dixeo is off for the file's course.
     *
     * @param int $courseid
     * @return void
     */
    private static function require_dixeo_enabled(int $courseid): void {
        \local_dixeo\external\service_factory::get_settings_config()
            ->require_enabled(\context_course::instance($courseid));
    }

    /**
     * Require login and validate context for the stored file location.
     *
     * @param \stored_file $file
     * @param int $courseid
     * @return void
     */
    protected static function authorise_location_access(\stored_file $file, int $courseid): void {
        location_access::require_edit_access_for_file($file);

        $filecontext = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
        if (!$filecontext) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        if ($filecontext->contextlevel === CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id(null, $filecontext->instanceid, 0, false, MUST_EXIST);
            self::validate_context(\context_module::instance($cm->id));
        } else {
            self::validate_context(\context_course::instance($courseid));
        }
    }

    /**
     * Normalise image quality to a supported API value.
     *
     * @param string $quality
     * @return string
     */
    protected static function validate_image_quality(string $quality): string {
        $quality = strtolower(trim($quality));
        if (!in_array($quality, ['low', 'medium', 'high'], true)) {
            return image_generation_service::DEFAULT_QUALITY;
        }

        return $quality;
    }

    /**
     * Standard response after an in-place image replacement.
     *
     * @param location $location
     * @return array<string, mixed>
     */
    protected static function image_apply_returns(location $location): array {
        return [
            'imageurl' => file_replacer::get_current_image_url($location),
            'current_contenthash' => file_replacer::get_current_contenthash($location),
            'history' => file_replacer::get_history_for_location($location),
        ];
    }

    /**
     * Queue lock and adhoc poll task after a remote job was accepted.
     *
     * @param location $location
     * @param string $jobid
     * @param int $userid
     * @param string $source
     * @param string|null $prompt
     * @param string|null $quality
     * @param string|null $mode
     * @return array{jobid: string, status: string}
     */
    protected static function queue_content_image_job(
        location $location,
        string $jobid,
        int $userid,
        string $source,
        ?string $prompt = null,
        ?string $quality = null,
        ?string $mode = null
    ): array {
        $file = $location->get_stored_file();
        $binding = $file ? job_binding_metadata::for_stored_file($file) : null;
        $cmid = ($binding !== null && $binding->cmid > 0) ? $binding->cmid : null;

        job_orchestrator::submit_and_queue(
            content_target::from_location($location),
            $jobid,
            $userid,
            [
                'placeholderid' => null,
                'targettable' => null,
                'targetfield' => null,
                'targetid' => null,
                'cmid' => $cmid,
                'origin' => job_repository::ORIGIN_MODAL,
                'prompt' => $prompt,
                'quality' => $quality,
                'mode' => $mode,
            ],
            $source
        );

        return ['jobid' => $jobid, 'status' => job_repository::STATUS_PENDING];
    }

    /**
     * Return external structure for version history rows.
     *
     * @return external_multiple_structure
     */
    protected static function history_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Version id'),
            'source' => new external_value(PARAM_ALPHA, 'Source'),
            'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Archived contenthash'),
            'timecreated' => new external_value(PARAM_INT, 'Created time'),
            'usermodified' => new external_value(PARAM_INT, 'User id'),
            'previewurl' => new external_value(PARAM_URL, 'Preview URL'),
        ]));
    }

    /**
     * Returns description of method results.
     *
     * @return external_single_structure
     */
    protected static function location_status_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'idle|pending|processing|applied|failed'),
            'imageurl' => new external_value(PARAM_URL, 'Updated image URL', VALUE_OPTIONAL),
            'current_contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Current file contenthash', VALUE_OPTIONAL),
            'errormessage' => new external_value(PARAM_RAW, 'Error message', VALUE_OPTIONAL),
            'lockid' => new external_value(PARAM_INT, 'Lock id', VALUE_OPTIONAL),
            'prefill_prompt' => new external_value(PARAM_RAW, 'Prefill prompt', VALUE_OPTIONAL),
            'prefill_quality' => new external_value(PARAM_ALPHA, 'Prefill quality', VALUE_OPTIONAL),
            'prefill_mode' => new external_value(PARAM_ALPHA, 'Prefill mode', VALUE_OPTIONAL),
        ]);
    }
}
