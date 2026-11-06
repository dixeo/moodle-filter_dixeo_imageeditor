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
 * Tests for revert_version external.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use context_module;
use filter_dixeo_imageeditor\external\revert_version;
use filter_dixeo_imageeditor\adapter\file_replacer;
use local_dixeo\service\image\content\location;
use local_dixeo\repository\image\job_repository;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/page/lib.php');

/**
 * Tests for the revert_version external function.
 *
 * @covers \filter_dixeo_imageeditor\external\revert_version
 */
final class revert_version_test extends \advanced_testcase {
    /**
     * Return PNG bytes from core filestorage fixtures.
     */
    private static function fixture_png_bytes(): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/lib/filestorage/tests/fixtures/testimage.png');
    }

    /**
     * Return JPEG bytes from core filestorage fixtures.
     */
    private static function fixture_jpeg_bytes(): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/lib/filestorage/tests/fixtures/testimage.jpg');
    }

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create a page module with an embedded PNG for tests.
     *
     * @return array{0: location, 1: int}
     */
    private function create_page_image_location(): array {
        global $USER;

        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $context = context_module::instance($page->cmid);

        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'embedded.png',
        ], self::fixture_png_bytes());

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_page',
            'content',
            0,
            ['subdirs' => 0, 'maxfiles' => 1]
        );

        $file = $fs->get_file($context->id, 'mod_page', 'content', 0, '/', 'embedded.png');
        $this->assertNotFalse($file);

        return [location::from_stored_file($file), (int) $course->id];
    }

    /**
     * A header-only PNG declaring the given size, small on disk.
     *
     * The signature and IHDR are enough for type detection and getimagesizefromstring().
     *
     * @param int $width Declared width.
     * @param int $height Declared height.
     * @return string
     */
    private static function png_header_of(int $width, int $height): string {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            . $chunk('IEND', '');
    }

    /**
     * Insert a version row and its archived blob directly, as history from older releases may hold.
     *
     * @param location $location Image the version belongs to.
     * @param string $bytes Archived bytes.
     * @param string $mimetype MIME type recorded for the blob.
     * @return int Version id.
     */
    private static function insert_legacy_version(location $location, string $bytes, string $mimetype): int {
        global $DB, $USER;

        $now = time() - 10;
        $fields = $location->to_record_fields();
        unset($fields['courseid']);
        $versionid = (int) $DB->insert_record('filter_dixeo_imageeditor_version', (object) array_merge($fields, [
            'contenthash' => sha1($bytes),
            'source' => file_replacer::SOURCE_UPLOAD,
            'usermodified' => (int) $USER->id,
            'timecreated' => $now,
        ]));
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'filter_dixeo_imageeditor',
            'filearea' => file_replacer::FILEAREA_HISTORY,
            'itemid' => $versionid,
            'filepath' => '/' . $location->contextid . '/' . $location->hash() . '/',
            'filename' => 'version-' . $versionid . '-' . $now . '.bin',
            'userid' => (int) $USER->id,
            'mimetype' => $mimetype,
        ], $bytes);
        return $versionid;
    }

    /**
     * Call the revert external for a location.
     *
     * @param location $location
     * @param int $courseid
     * @param int $versionid
     * @return array
     */
    private static function call_revert(location $location, int $courseid, int $versionid): array {
        return revert_version::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            $versionid
        );
    }

    /**
     * Assert that reverting to a version fails and leaves the target and history untouched.
     *
     * @param location $location
     * @param int $courseid
     * @param int $versionid
     * @param string $errorcode Expected exception error code.
     * @return void
     */
    private function assert_revert_refused(location $location, int $courseid, int $versionid, string $errorcode): void {
        global $DB;

        $before = $location->get_stored_file();
        $hash = $before->get_contenthash();
        $mimetype = $before->get_mimetype();
        $rows = $DB->count_records('filter_dixeo_imageeditor_version');

        try {
            self::call_revert($location, $courseid, $versionid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }

        $after = $location->get_stored_file();
        $this->assertSame($hash, $after->get_contenthash());
        $this->assertSame($mimetype, $after->get_mimetype());
        $this->assertSame($rows, $DB->count_records('filter_dixeo_imageeditor_version'));
    }

    public function test_revert_version_refuses_legacy_svg_history(): void {
        [$location, $courseid] = $this->create_page_image_location();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
        $versionid = self::insert_legacy_version($location, $svg, 'image/svg+xml');

        $this->assert_revert_refused($location, $courseid, $versionid, 'error_not_eligible');
    }

    public function test_revert_version_refuses_oversized_legacy_history(): void {
        [$location, $courseid] = $this->create_page_image_location();
        $versionid = self::insert_legacy_version($location, self::png_header_of(20000, 20000), 'image/png');

        $this->assert_revert_refused($location, $courseid, $versionid, 'error:image_dimensions');
    }

    public function test_revert_version_restores_in_bounds_png_with_its_type(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();
        file_replacer::apply_binary($location, self::fixture_jpeg_bytes(), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        $this->assertSame('image/jpeg', $location->get_stored_file()->get_mimetype());
        $versionid = (int) file_replacer::get_history_for_location($location)[0]['id'];

        $result = self::call_revert($location, $courseid, $versionid);

        $this->assertSame($originalhash, $result['current_contenthash']);
        $this->assertSame('image/png', $location->get_stored_file()->get_mimetype());
    }

    public function test_revert_version_restores_archived_image(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_UPLOAD
        );

        $history = file_replacer::get_history_for_location($location);
        $versionid = (int) $history[0]['id'];

        $result = revert_version::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            $versionid
        );

        $this->assertNotEmpty($result['imageurl']);
        $this->assertSame($originalhash, $result['current_contenthash']);
        $this->assertSame($originalhash, $location->get_stored_file()->get_contenthash());
    }

    public function test_revert_version_rejects_foreign_versionid(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_UPLOAD
        );

        $history = file_replacer::get_history_for_location($location);
        $versionid = (int) $history[0]['id'];

        [$otherlocation, $othercourseid] = $this->create_page_image_location();

        try {
            revert_version::execute(
                $otherlocation->contextid,
                $otherlocation->component,
                $otherlocation->filearea,
                $otherlocation->itemid,
                $otherlocation->filepath,
                $otherlocation->filename,
                $othercourseid,
                $versionid
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_not_eligible', $e->errorcode);
        }
    }

    public function test_revert_version_rejects_when_locked(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_UPLOAD
        );

        $history = file_replacer::get_history_for_location($location);
        $versionid = (int) $history[0]['id'];

        job_repository::create_job($location, 'job-locked', (int) $USER->id);
        job_repository::update_status(
            (int) job_repository::get_active_job_for_location($location)->id,
            job_repository::STATUS_PROCESSING
        );

        try {
            revert_version::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                $courseid,
                $versionid
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_revert_blocked', $e->errorcode);
        }
    }
}
