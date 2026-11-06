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
 * Tests for apply_manual_edit external.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use context_module;
use filter_dixeo_imageeditor\external\apply_manual_edit;
use filter_dixeo_imageeditor\adapter\file_replacer;
use local_dixeo\service\image\content\location;
use local_dixeo\repository\image\job_repository;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/page/lib.php');

/**
 * Tests for the apply_manual_edit external function.
 *
 * @covers \filter_dixeo_imageeditor\external\apply_manual_edit
 */
final class apply_manual_edit_test extends \advanced_testcase {
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

    public function test_apply_manual_edit_replaces_file_and_archives(): void {
        global $DB, $USER;

        [$location, $courseid] = $this->create_page_image_location();
        $originalhash = $location->get_stored_file()->get_contenthash();
        $jpegb64 = base64_encode(self::fixture_jpeg_bytes());

        $result = apply_manual_edit::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            $jpegb64
        );

        $this->assertNotEmpty($result['imageurl']);
        $this->assertNotSame($originalhash, $result['current_contenthash']);

        $version = $DB->get_record('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
            'contenthash' => $originalhash,
        ]);
        $this->assertNotFalse($version);
        $this->assertSame(file_replacer::SOURCE_MANUAL, $version->source);

        $latest = $DB->get_record('filter_dixeo_imageeditor_version', [
            'locationhash' => $location->hash(),
        ], '*', IGNORE_MULTIPLE);
        $this->assertNotFalse($latest);
    }

    public function test_apply_manual_edit_rejects_empty_payload(): void {
        [$location, $courseid] = $this->create_page_image_location();

        $this->expectException(\moodle_exception::class);
        apply_manual_edit::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            ''
        );
    }

    public function test_apply_manual_edit_rejects_when_locked(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();
        job_repository::create_job($location, 'job-locked', (int) $USER->id);
        job_repository::update_status(
            (int) job_repository::get_active_job_for_location($location)->id,
            job_repository::STATUS_PROCESSING
        );

        $this->expectException(\moodle_exception::class);
        apply_manual_edit::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            base64_encode(self::fixture_jpeg_bytes())
        );
    }

    public function test_apply_manual_edit_accepts_data_url_prefix(): void {
        [$location, $courseid] = $this->create_page_image_location();
        $payload = 'data:image/jpeg;base64,' . base64_encode(self::fixture_jpeg_bytes());

        $result = apply_manual_edit::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            $payload
        );

        $this->assertNotEmpty($result['imageurl']);
        $this->assertNotEmpty($result['current_contenthash']);
    }

    public function test_apply_manual_edit_rejects_invalid_image(): void {
        [$location, $courseid] = $this->create_page_image_location();

        try {
            apply_manual_edit::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                $courseid,
                base64_encode('not-an-image')
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_manual_invalid_image', $e->errorcode);
        }
    }

    public function test_apply_manual_edit_rejects_oversize_payload(): void {
        global $DB;

        [$location, $courseid] = $this->create_page_image_location();
        $png = self::fixture_png_bytes();
        $DB->set_field('course', 'maxbytes', strlen($png) - 1, ['id' => $courseid]);
        $teacher = $this->getDataGenerator()->create_and_enrol(get_course($courseid), 'editingteacher');
        $this->setUser($teacher);

        try {
            apply_manual_edit::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                $courseid,
                base64_encode($png)
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_too_large', $e->errorcode);
            $this->assertSame('filter_dixeo_imageeditor', $e->module);
        }
    }

    public function test_apply_manual_edit_uses_location_course_for_size_limit(): void {
        global $DB;

        [$location, $courseid] = $this->create_page_image_location();
        $png = self::fixture_png_bytes();
        $DB->set_field('course', 'maxbytes', strlen($png) - 1, ['id' => $courseid]);

        $othercourse = $this->getDataGenerator()->create_course(['maxbytes' => max(10485760, strlen($png) * 10)]);
        $teacher = $this->getDataGenerator()->create_and_enrol(get_course($courseid), 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $othercourse->id, 'editingteacher');
        $this->setUser($teacher);

        try {
            apply_manual_edit::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                (int) $othercourse->id,
                base64_encode($png)
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_too_large', $e->errorcode);
            $this->assertSame('filter_dixeo_imageeditor', $e->module);
        }
    }
}
