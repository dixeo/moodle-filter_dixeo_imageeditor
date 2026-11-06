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
 * Tests for image_util helpers.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use filter_dixeo_imageeditor\adapter\image_util;

/**
 * Tests for image_util helpers.
 *
 * @covers \filter_dixeo_imageeditor\adapter\image_util
 */
final class image_util_test extends \advanced_testcase {
    /** @var int */
    private int $courseid;

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
        $course = $this->getDataGenerator()->create_course();
        $this->courseid = (int) $course->id;
    }

    public function test_decode_image_base64_strips_data_url_prefix(): void {
        $png = self::fixture_png_bytes();
        $payload = 'data:image/png;base64,' . base64_encode($png);
        $this->assertSame($png, image_util::decode_image_base64($payload, $this->courseid));
    }

    public function test_assert_valid_web_image_accepts_png_and_jpeg(): void {
        image_util::assert_valid_web_image(self::fixture_png_bytes(), $this->courseid);
        image_util::assert_valid_web_image(self::fixture_jpeg_bytes(), $this->courseid);
    }

    public function test_assert_valid_web_image_content_accepts_png_and_jpeg(): void {
        image_util::assert_valid_web_image_content(self::fixture_png_bytes());
        image_util::assert_valid_web_image_content(self::fixture_jpeg_bytes());
    }

    public function test_assert_valid_web_image_rejects_svg(): void {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
        try {
            image_util::assert_valid_web_image($svg, $this->courseid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }
    }

    public function test_assert_valid_web_image_content_rejects_svg(): void {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<script>alert(1)</script><rect width="10" height="10"/></svg>';
        try {
            image_util::assert_valid_web_image_content($svg);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }
    }

    public function test_accept_attribute_excludes_svg(): void {
        $accept = image_util::get_web_image_accept_attribute();
        $this->assertNotEmpty($accept);
        $this->assertStringNotContainsStringIgnoringCase('svg', $accept);
    }

    public function test_assert_valid_web_image_rejects_empty_payload(): void {
        try {
            image_util::assert_valid_web_image('', $this->courseid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }
    }

    public function test_assert_valid_web_image_rejects_non_web_image(): void {
        try {
            image_util::assert_valid_web_image('plain text', $this->courseid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }
    }

    public function test_assert_valid_web_image_content_rejects_non_web_image(): void {
        try {
            image_util::assert_valid_web_image_content('plain text');
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_invalid_image', $e->errorcode);
        }
    }

    public function test_assert_valid_web_image_rejects_oversize_payload(): void {
        global $DB;

        $png = self::fixture_png_bytes();
        $DB->set_field('course', 'maxbytes', strlen($png) - 1, ['id' => $this->courseid]);
        $teacher = $this->getDataGenerator()->create_and_enrol(get_course($this->courseid), 'editingteacher');
        $this->setUser($teacher);

        try {
            image_util::assert_valid_web_image($png, $this->courseid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_too_large', $e->errorcode);
            $this->assertSame('filter_dixeo_imageeditor', $e->module);
        }
    }

    public function test_decode_refuses_an_oversized_payload_before_decoding(): void {
        global $DB;

        $DB->set_field('course', 'maxbytes', 1024, ['id' => $this->courseid]);
        $teacher = $this->getDataGenerator()->create_and_enrol(get_course($this->courseid), 'editingteacher');
        $this->setUser($teacher);

        // Not valid base64 either: the size error proves the decoder was not reached.
        $payload = str_repeat('!', 4 * intdiv(1024 + 2, 3) + 5);
        try {
            image_util::decode_image_base64($payload, $this->courseid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_too_large', $e->errorcode);
            $this->assertSame('filter_dixeo_imageeditor', $e->module);
        }

        $this->assertSame(1024, strlen(image_util::decode_image_base64(
            base64_encode(str_repeat('a', 1024)),
            $this->courseid
        )));
    }

    public function test_admin_is_bounded_by_the_site_maximum(): void {
        set_config('maxbytes', 1024);
        $this->setAdminUser();

        $this->assertSame(1024, image_util::get_max_upload_bytes($this->courseid));
        try {
            image_util::decode_image_base64(base64_encode(str_repeat('a', 2048)), $this->courseid);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_upload_too_large', $e->errorcode);
        }
    }

    public function test_assert_valid_web_image_uses_custom_error_string(): void {
        try {
            image_util::assert_valid_web_image('', $this->courseid, 'error_manual_invalid_image');
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_manual_invalid_image', $e->errorcode);
        }
    }
}
