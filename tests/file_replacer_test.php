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
 * Tests for file_replacer archive/replace/revert behaviour.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use context_module;
use filter_dixeo_imageeditor\adapter\file_replacer;
use local_dixeo\service\image\content\location;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/page/lib.php');

/**
 * Tests for file_replacer version history.
 *
 * @covers \filter_dixeo_imageeditor\adapter\file_replacer
 */
final class file_replacer_test extends \advanced_testcase {
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

    public function test_apply_binary_archives_before_replace(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $updated = $location->get_stored_file();
        $this->assertNotSame($originalhash, $updated->get_contenthash());
        $this->assertSame('image/jpeg', $updated->get_mimetype());

        $versions = $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]);
        $this->assertCount(1, $versions);
        $version = reset($versions);
        $this->assertSame($originalhash, $version->contenthash);
    }

    public function test_apply_binary_rejects_svg(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<script>alert(1)</script><rect width="10" height="10"/></svg>';

        try {
            file_replacer::apply_binary(
                $location,
                $svg,
                (int) $USER->id,
                file_replacer::SOURCE_GENERATED
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }

        $this->assertSame($originalhash, $location->get_stored_file()->get_contenthash());
        $this->assertCount(0, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
    }

    public function test_apply_binary_rejects_non_image(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();

        try {
            file_replacer::apply_binary(
                $location,
                'not-an-image-payload',
                (int) $USER->id,
                file_replacer::SOURCE_GENERATED
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }

        $this->assertSame($originalhash, $location->get_stored_file()->get_contenthash());
        $this->assertCount(0, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
    }

    public function test_apply_job_result_rejects_svg_payload(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        try {
            file_replacer::apply_job_result(
                $location,
                ['image_base64' => base64_encode($svg)],
                (int) $USER->id,
                file_replacer::SOURCE_GENERATED
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }

        $this->assertSame($originalhash, $location->get_stored_file()->get_contenthash());
        $this->assertCount(0, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
    }

    public function test_revert_restores_archived_bytes(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $this->assertNotEmpty($history);
        $versionid = (int) $history[0]['id'];

        file_replacer::revert_to_version($location, $versionid, (int) $USER->id);

        $restored = $location->get_stored_file();
        $this->assertSame($originalhash, $restored->get_contenthash());
        $this->assertSame('image/png', $restored->get_mimetype());
        $this->assertCount(2, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
    }

    public function test_revert_between_known_versions_does_not_duplicate_history(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $pngversionid = (int) $history[0]['id'];

        file_replacer::revert_to_version($location, $pngversionid, (int) $USER->id);
        $this->assertCount(2, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));

        $history = file_replacer::get_history_for_location($location);
        $jpegversionid = null;
        foreach ($history as $item) {
            $record = $DB->get_record('filter_dixeo_imageeditor_version', ['id' => $item['id']], '*', MUST_EXIST);
            if ($record->contenthash !== $originalhash) {
                $jpegversionid = (int) $record->id;
                break;
            }
        }
        $this->assertNotNull($jpegversionid);

        file_replacer::revert_to_version($location, $pngversionid, (int) $USER->id);
        $this->assertCount(2, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
        $this->assertSame($originalhash, $location->get_stored_file()->get_contenthash());

        file_replacer::revert_to_version($location, $jpegversionid, (int) $USER->id);
        $this->assertCount(2, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
        $this->assertNotSame($originalhash, $location->get_stored_file()->get_contenthash());
    }

    public function test_apply_binary_refreshes_existing_version_of_current_image(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $pngversionid = (int) $history[0]['id'];

        file_replacer::revert_to_version($location, $pngversionid, (int) $USER->id);
        $this->assertSame($originalhash, $location->get_stored_file()->get_contenthash());
        $countbefore = count($DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_EDITED
        );

        $countafter = count($DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
        $this->assertSame($countbefore, $countafter);
        $this->assertFalse($DB->record_exists('filter_dixeo_imageeditor_version', ['id' => $pngversionid]));
        $newest = file_replacer::get_history_for_location($location)[0];
        $this->assertSame($originalhash, $newest['contenthash']);
        $this->assertNotSame($pngversionid, $newest['id']);
    }

    public function test_delete_version_removes_record_and_file(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $this->assertCount(1, $history);
        $versionid = (int) $history[0]['id'];

        file_replacer::delete_version($versionid, $location, (int) $USER->id);

        $this->assertEmpty(file_replacer::get_history_for_location($location));
        $this->assertFalse($DB->record_exists('filter_dixeo_imageeditor_version', ['id' => $versionid]));
    }

    public function test_revert_rejects_foreign_location(): void {
        global $USER;

        [$location] = $this->create_page_image_location();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $versionid = (int) $history[0]['id'];

        [$otherlocation] = $this->create_page_image_location();

        $this->expectException(\moodle_exception::class);
        file_replacer::revert_to_version($otherlocation, $versionid, (int) $USER->id);
    }

    public function test_delete_version_rejects_foreign_location(): void {
        global $USER;

        [$location] = $this->create_page_image_location();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $versionid = (int) $history[0]['id'];

        [$otherlocation] = $this->create_page_image_location();

        $this->expectException(\moodle_exception::class);
        file_replacer::delete_version($versionid, $otherlocation, (int) $USER->id);
    }

    public function test_delete_version_rejects_current_contenthash(): void {
        global $USER;

        [$location] = $this->create_page_image_location();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $history = file_replacer::get_history_for_location($location);
        $pngversionid = (int) $history[0]['id'];

        file_replacer::revert_to_version($location, $pngversionid, (int) $USER->id);
        $this->assertSame(
            file_replacer::get_current_contenthash($location),
            $history[0]['contenthash']
        );

        try {
            file_replacer::delete_version($pngversionid, $location, (int) $USER->id);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_delete_current', $e->errorcode);
        }
    }

    /**
     * Retrying after a failed generation must not archive error.png into history.
     */
    public function test_apply_binary_skips_archiving_error_asset(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $file = $location->get_stored_file();
        $this->assertNotFalse($file);

        // Simulate a failed apply leaving the error stub as the current file.
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $temp = $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'error-stub.png',
            'userid' => (int) $USER->id,
        ], \local_dixeo\service\image\content\asset_helper::get_error_binary());
        $file->replace_file_with($temp);
        $temp->delete();

        $errorhash = $location->get_stored_file()->get_contenthash();
        $this->assertTrue(
            file_replacer::is_status_asset_hash($errorhash)
        );

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $versions = $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]);
        $this->assertCount(0, $versions);
        $this->assertSame([], file_replacer::get_history_for_location($location));
    }

    /**
     * Pending placeholder stubs must not be archived either.
     */
    public function test_apply_binary_skips_archiving_placeholder_asset(): void {
        global $USER, $DB;

        [$location] = $this->create_page_image_location();
        $file = $location->get_stored_file();

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $temp = $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'placeholder-stub.png',
            'userid' => (int) $USER->id,
        ], \local_dixeo\service\image\content\asset_helper::get_placeholder_binary());
        $file->replace_file_with($temp);
        $temp->delete();

        file_replacer::apply_binary(
            $location,
            self::fixture_jpeg_bytes(),
            (int) $USER->id,
            file_replacer::SOURCE_GENERATED
        );

        $this->assertCount(0, $DB->get_records('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ]));
    }
}
