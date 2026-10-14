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
use filter_dixeo_imageeditor\adapter\image_util;
use local_dixeo\repository\image\job_repository;

/**
 * Load modal context for one image location.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_editor_context extends external_api {
    use location_parameters;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return self::location_parameters_definition();
    }

    /**
     * Execute the get_editor_context web service.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @param int $courseid
     * @return array
     */
    public static function execute(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $filepath,
        string $filename,
        int $courseid
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'contextid',
            'component',
            'filearea',
            'itemid',
            'filepath',
            'filename',
            'courseid'
        ));

        $location = self::validate_location($params);

        $policy = feature_gate::policy_flags((int) $location->courseid);
        $caps = feature_gate::capability_flags($location->courseid);
        $status = job_repository::get_location_status($location, false);
        $currentfile = $location->get_stored_file();

        $prefillprompt = '';
        $prefillquality = 'medium';
        $prefillmode = 'landscape';
        if (($status['status'] ?? '') === job_repository::STATUS_FAILED) {
            $prefillprompt = (string) ($status['prefill_prompt'] ?? '');
            $prefillquality = (string) ($status['prefill_quality'] ?? 'medium');
            $prefillmode = (string) ($status['prefill_mode'] ?? 'landscape');
        }

        return [
            'imageurl' => $currentfile ? file_replacer::get_current_image_url($location) : '',
            'current_contenthash' => $currentfile ? $currentfile->get_contenthash() : '',
            'history' => file_replacer::get_history_for_location($location),
            'policy_can_generate' => $policy['can_generate'],
            'policy_can_edit' => $policy['can_edit'],
            'cap_can_generate' => $caps['can_generate'],
            'cap_can_edit' => $caps['can_edit'],
            'locked' => job_repository::has_blocking_job($location),
            'location_status' => $status,
            'upload_accept' => image_util::get_web_image_accept_attribute(),
            'prefill_prompt' => $prefillprompt,
            'prefill_quality' => $prefillquality,
            'prefill_mode' => $prefillmode,
        ];
    }

    /**
     * Returns description of method results.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'imageurl' => new external_value(PARAM_RAW, 'Current image URL', VALUE_DEFAULT, ''),
            'current_contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Current file contenthash', VALUE_DEFAULT, ''),
            'history' => self::history_returns(),
            'policy_can_generate' => new external_value(PARAM_BOOL, 'Policy allows generate'),
            'policy_can_edit' => new external_value(PARAM_BOOL, 'Policy allows edit'),
            'cap_can_generate' => new external_value(PARAM_BOOL, 'User can generate'),
            'cap_can_edit' => new external_value(PARAM_BOOL, 'User can edit'),
            'locked' => new external_value(PARAM_BOOL, 'Job in progress'),
            'location_status' => self::location_status_returns(),
            'upload_accept' => new external_value(PARAM_TEXT, 'Accepted upload file types'),
            'prefill_prompt' => new external_value(PARAM_RAW, 'Prefill prompt when last job failed', VALUE_DEFAULT, ''),
            'prefill_quality' => new external_value(PARAM_ALPHA, 'Prefill quality when last job failed', VALUE_DEFAULT, 'medium'),
            'prefill_mode' => new external_value(PARAM_ALPHA, 'Prefill mode when last job failed', VALUE_DEFAULT, 'landscape'),
        ]);
    }
}
