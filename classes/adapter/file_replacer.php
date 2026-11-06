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

use local_dixeo\service\image\content\asset_helper;
use local_dixeo\service\image\content\location;
use local_dixeo\service\image\result_helper;
use filter_dixeo_imageeditor\event\content_image_updated;
use filter_dixeo_imageeditor\event\content_image_version_deleted;

/**
 * Archives versions and performs in-place file replacement.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_replacer {
    /** @var string File area for archived version blobs in filter storage. */
    public const FILEAREA_HISTORY = 'history';

    /** @var string Archived version created by AI generation. */
    public const SOURCE_GENERATED = 'generated';
    /** @var string Archived version created by AI edit. */
    public const SOURCE_EDITED = 'edited';
    /** @var string Archived version created by revert. */
    public const SOURCE_REVERTED = 'reverted';
    /** @var string Archived version created by manual edit. */
    public const SOURCE_MANUAL = 'manual';
    /** @var string Archived version created by upload. */
    public const SOURCE_UPLOAD = 'uploaded';

    /**
     * Return version history rows for a content image location.
     *
     * @param location $location
     * @return array<int, array<string, mixed>>
     */
    public static function get_history_for_location(location $location): array {
        global $DB;

        $records = $DB->get_records(
            'filter_dixeo_imageeditor_version',
            ['locationhash' => $location->hash()],
            'timecreated DESC, id DESC'
        );
        $history = [];
        foreach ($records as $record) {
            // Never expose placeholder/error stubs even if they were archived earlier.
            if (self::is_status_asset_hash((string) $record->contenthash)) {
                continue;
            }
            $history[] = self::format_version_record($record, $location);
        }
        return $history;
    }

    /**
     * Format a version database record for API output.
     *
     * @param \stdClass $record
     * @param location $location
     * @return array<string, mixed>
     */
    private static function format_version_record(\stdClass $record, location $location): array {
        $systemcontext = \context_system::instance();
        $filepath = self::history_filepath($location);
        $url = \moodle_url::make_pluginfile_url(
            $systemcontext->id,
            'filter_dixeo_imageeditor',
            self::FILEAREA_HISTORY,
            (int) $record->id,
            $filepath,
            self::history_filename($record)
        )->out(false);

        return [
            'id' => (int) $record->id,
            'source' => (string) $record->source,
            'contenthash' => (string) $record->contenthash,
            'timecreated' => (int) $record->timecreated,
            'usermodified' => (int) $record->usermodified,
            'previewurl' => $url,
        ];
    }

    /**
     * Return the current stored file contenthash for a location.
     *
     * @param location $location
     * @return string
     */
    public static function get_current_contenthash(location $location): string {
        $file = $location->get_stored_file();
        return $file ? $file->get_contenthash() : '';
    }

    /**
     * Append a stable cache-busting query param derived from file contenthash.
     *
     * @param string $url Image src URL (may already include a rev param).
     * @param string $contenthash Stored file contenthash.
     * @return string
     */
    public static function append_image_rev(string $url, string $contenthash): string {
        if ($contenthash === '') {
            return $url;
        }

        $url = preg_replace('/([?&])rev=[^&]*/', '', $url) ?? $url;
        $url = rtrim($url, '?&');
        $separator = (strpos($url, '?') !== false) ? '&' : '?';

        return $url . $separator . 'rev=' . rawurlencode($contenthash);
    }

    /**
     * Pluginfile URL for the current image with a contenthash rev query param.
     *
     * @param location $location
     * @return string
     */
    public static function get_current_image_url(location $location): string {
        return self::append_image_rev(
            $location->get_pluginfile_url(),
            self::get_current_contenthash($location)
        );
    }

    /**
     * Delete one archived version from history.
     *
     * @param int $versionid
     * @param location $location
     * @param int $userid
     * @return void
     */
    public static function delete_version(int $versionid, location $location, int $userid): void {
        global $DB;

        $version = $DB->get_record('filter_dixeo_imageeditor_version', ['id' => $versionid], '*', MUST_EXIST);
        if ((string) $version->locationhash !== $location->hash()) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        $currenthash = self::get_current_contenthash($location);
        if ($currenthash !== '' && (string) $version->contenthash === $currenthash) {
            throw new \moodle_exception('error_delete_current', 'filter_dixeo_imageeditor');
        }

        $systemcontext = \context_system::instance();
        $fs = get_file_storage();
        $historyfile = $fs->get_file(
            $systemcontext->id,
            'filter_dixeo_imageeditor',
            self::FILEAREA_HISTORY,
            $versionid,
            self::history_filepath($location),
            self::history_filename($version)
        );
        if ($historyfile) {
            $historyfile->delete();
        }

        $DB->delete_records('filter_dixeo_imageeditor_version', ['id' => $versionid]);

        content_image_version_deleted::create_from_location($location, $userid, $versionid)->trigger();
    }

    /**
     * Whether a contenthash is the pending placeholder or the failure image.
     *
     * @param string $contenthash
     * @return bool
     */
    public static function is_status_asset_hash(string $contenthash): bool {
        return asset_helper::is_status_asset_hash($contenthash);
    }

    /**
     * Archive the current file bytes into version history.
     *
     * @param location $location
     * @param string $source
     * @param int $userid
     * @return int|null Version row id, or null when content is already archived
     */
    public static function archive_current(location $location, string $source, int $userid): ?int {
        global $DB;

        $file = $location->get_stored_file();
        if (!$file) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        $contenthash = $file->get_contenthash();
        // Pending/failure stubs must not enter version history (e.g. retry after error.png).
        if (self::is_status_asset_hash($contenthash)) {
            return null;
        }
        if (self::has_history_contenthash($location, $contenthash)) {
            return null;
        }

        $now = time();
        $fields = $location->to_record_fields();
        unset($fields['courseid']);

        $versionid = $DB->insert_record('filter_dixeo_imageeditor_version', (object) array_merge($fields, [
            'contenthash' => $contenthash,
            'source' => $source,
            'usermodified' => $userid,
            'timecreated' => $now,
        ]));

        $systemcontext = \context_system::instance();
        $record = [
            'contextid' => $systemcontext->id,
            'component' => 'filter_dixeo_imageeditor',
            'filearea' => self::FILEAREA_HISTORY,
            'itemid' => $versionid,
            'filepath' => self::history_filepath($location),
            'filename' => self::history_filename((object) ['id' => $versionid, 'timecreated' => $now]),
            'userid' => $userid,
            'mimetype' => $file->get_mimetype(),
        ];

        $fs = get_file_storage();
        $fs->create_file_from_string($record, $file->get_content());

        return (int) $versionid;
    }

    /**
     * Apply a completed AI job result to the stored file.
     *
     * @param location $location
     * @param array $jobresult
     * @param int $userid
     * @param string $source
     * @return void
     */
    public static function apply_job_result(
        location $location,
        array $jobresult,
        int $userid,
        string $source = self::SOURCE_GENERATED
    ): void {
        $binary = result_helper::extract_image_binary_from_result($jobresult);
        if ($binary === '') {
            throw new \moodle_exception('dixeo_image_job_empty_result', 'local_dixeo');
        }
        self::apply_binary($location, $binary, $userid, $source);
    }

    /**
     * Replace stored file content with new image bytes.
     *
     * @param location $location
     * @param string $binary
     * @param int $userid
     * @param string $source
     * @return void
     */
    public static function apply_binary(location $location, string $binary, int $userid, string $source): void {
        // AI job results skip the upload externals; validate here so every write
        // path rejects SVG and non-images before course files are replaced.
        image_util::assert_valid_web_image_content($binary);

        $file = $location->get_stored_file();
        if (!$file) {
            // Icon tiles have no photo yet. The first image creates the file
            // and records it as the section photo, which replaces the icon.
            tiles_section_photo::create($location, $binary, $userid);
            content_image_updated::create_from_location($location, $userid, $source)->trigger();
            return;
        }

        self::archive_current($location, $source, $userid);
        self::replace_file_content($file, $binary, $userid);

        content_image_updated::create_from_location($location, $userid, $source)->trigger();
    }

    /**
     * Revert the stored file to a historical version.
     *
     * @param location $location
     * @param int $versionid
     * @param int $userid
     * @return string New pluginfile URL
     */
    public static function revert_to_version(location $location, int $versionid, int $userid): string {
        global $DB;

        $version = $DB->get_record('filter_dixeo_imageeditor_version', ['id' => $versionid], '*', MUST_EXIST);
        if ((string) $version->locationhash !== $location->hash()) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        $target = $location->get_stored_file();
        if (!$target) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        $systemcontext = \context_system::instance();
        $fs = get_file_storage();
        $historyfile = $fs->get_file(
            $systemcontext->id,
            'filter_dixeo_imageeditor',
            self::FILEAREA_HISTORY,
            $versionid,
            self::history_filepath($location),
            self::history_filename($version)
        );
        if (!$historyfile) {
            throw new \moodle_exception('error_not_eligible', 'filter_dixeo_imageeditor');
        }

        $currenthash = $target->get_contenthash();
        $versionhash = (string) $version->contenthash;
        if ($currenthash === $versionhash) {
            return self::get_current_image_url($location);
        }

        self::archive_current($location, self::SOURCE_REVERTED, $userid);

        self::replace_file_content($target, $historyfile->get_content(), $userid);

        content_image_updated::create_from_location($location, $userid, self::SOURCE_REVERTED)->trigger();

        return self::get_current_image_url($location);
    }

    /**
     * Whether an archived version already stores this file content.
     *
     * @param location $location
     * @param string $contenthash
     * @return bool
     */
    private static function has_history_contenthash(location $location, string $contenthash): bool {
        global $DB;

        return $DB->record_exists('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
            'contenthash' => $contenthash,
        ]);
    }

    /**
     * Replace stored file content using a draft area temp file.
     *
     * @param \stored_file $file
     * @param string $binary
     * @param int $userid
     * @return void
     */
    private static function replace_file_content(\stored_file $file, string $binary, int $userid): void {
        global $DB;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $draftrecord = [
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => self::allocate_draft_itemid($userid),
            'filepath' => '/',
            'filename' => 'dixeo-replace-' . time() . '.bin',
            'userid' => $userid,
        ];
        $temp = $fs->create_file_from_string($draftrecord, $binary);
        $file->replace_file_with($temp);
        $temp->delete();

        $mimetype = self::detect_mimetype($binary, $file->get_filename());
        if ($mimetype !== $file->get_mimetype()) {
            $DB->set_field('files', 'mimetype', $mimetype, ['id' => $file->get_id()]);
        }
    }

    /**
     * Allocate a draft item id without calling file_get_unused_draft_itemid().
     *
     * That helper requires an active web session (require_login), which breaks
     * image apply during adhoc polling and other non-interactive contexts.
     *
     * @param int $userid
     * @return int
     */
    private static function allocate_draft_itemid(int $userid): int {
        $fs = get_file_storage();
        $context = \context_user::instance($userid);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $draftitemid = random_int(100000, 999999999);
            if (!$fs->file_exists($context->id, 'user', 'draft', $draftitemid, '/', '.')) {
                return $draftitemid;
            }
        }

        throw new \moodle_exception('error_job_failed', 'filter_dixeo_imageeditor');
    }

    /**
     * Detect mimetype from binary content or filename.
     *
     * @param string $binary
     * @param string $filename
     * @return string
     */
    private static function detect_mimetype(string $binary, string $filename): string {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fromcontent = $finfo->buffer($binary);
        if (is_string($fromcontent) && $fromcontent !== '' && $fromcontent !== 'application/octet-stream') {
            return $fromcontent;
        }
        return \file_storage::mimetype('content://', $filename) ?: 'application/octet-stream';
    }

    /**
     * Return the file path for archived history blobs.
     *
     * @param location $location
     * @return string
     */
    private static function history_filepath(location $location): string {
        return '/' . $location->contextid . '/' . $location->hash() . '/';
    }

    /**
     * Return the filename for an archived history blob.
     *
     * @param \stdClass $record
     * @return string
     */
    private static function history_filename(\stdClass $record): string {
        return 'version-' . (int) $record->id . '-' . (int) $record->timecreated . '.bin';
    }
}
