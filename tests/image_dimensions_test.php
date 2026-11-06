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
 * Tests for the pixel size bound on stored images.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use filter_dixeo_imageeditor\adapter\file_replacer;
use filter_dixeo_imageeditor\external\apply_upload;
use filter_dixeo_imageeditor\external\get_editor_context;
use local_dixeo\service\image\content\location;
use local_dixeo\service\image\image_bounds;

/**
 * A small file that declares a huge image is refused on every write path.
 *
 * @covers \filter_dixeo_imageeditor\adapter\image_util::assert_valid_web_image_content
 * @covers \filter_dixeo_imageeditor\external\apply_upload
 * @covers \filter_dixeo_imageeditor\adapter\file_replacer::apply_job_result
 * @covers \filter_dixeo_imageeditor\external\get_editor_context
 */
final class image_dimensions_test extends \advanced_testcase {
    /** @var location Page image location. */
    private location $location;

    /** @var int Course id. */
    private int $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        filter_set_global_state('dixeo_imageeditor', TEXTFILTER_ON);
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id,
            'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'embedded.png',
        ], self::png_of(16, 16));
        $this->location = location::from_stored_file($file);
        $this->courseid = (int) $course->id;
    }

    /**
     * A real PNG drawn with GD.
     *
     * @param int $width Width in pixels.
     * @param int $height Height in pixels.
     * @return string
     */
    private static function png_of(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * A valid 20000 x 20000 greyscale PNG of a few hundred kilobytes, compressed row by row.
     *
     * @return string
     */
    private static function decompression_bomb_png(): string {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $side = 20000;
        $row = str_repeat("\0", $side + 1);
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $idat = '';
        for ($y = 0; $y < $side; $y++) {
            $idat .= deflate_add($deflate, $row, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($deflate, '', ZLIB_FINISH);
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 0, 0, 0, 0))
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    /**
     * Upload base64 bytes to the page image.
     *
     * @param string $bytes Image bytes.
     * @return array
     */
    private function upload(string $bytes): array {
        $location = $this->location;
        return apply_upload::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $this->courseid,
            base64_encode($bytes)
        );
    }

    /**
     * Upload and AI results refuse the 20000 x 20000 PNG and leave the file unchanged.
     */
    public function test_decompression_bomb_is_refused(): void {
        $bomb = self::decompression_bomb_png();
        $this->assertLessThan(1048576, strlen($bomb));
        $hash = $this->location->get_stored_file()->get_contenthash();

        $calls = [
            fn() => $this->upload($bomb),
            fn() => file_replacer::apply_job_result(
                $this->location,
                ['image_base64' => base64_encode($bomb)],
                (int) get_admin()->id
            ),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected error:image_dimensions');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:image_dimensions', $e->errorcode);
                $this->assertSame('local_dixeo', $e->module);
            }
        }
        $this->assertSame($hash, $this->location->get_stored_file()->get_contenthash());
    }

    /**
     * A 1536 x 1024 image is accepted.
     */
    public function test_ordinary_image_is_accepted(): void {
        $bytes = self::png_of(1536, 1024);

        $this->upload($bytes);

        $this->assertSame(sha1($bytes), $this->location->get_stored_file()->get_contenthash());
    }

    /**
     * The editor receives the same bounds as the server.
     */
    public function test_editor_context_returns_the_bounds(): void {
        $location = $this->location;
        $context = get_editor_context::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $this->courseid
        );

        $this->assertSame(image_bounds::MAX_DIMENSION, $context['max_image_dimension']);
        $this->assertSame(image_bounds::MAX_PIXELS, $context['max_image_pixels']);
    }
}
