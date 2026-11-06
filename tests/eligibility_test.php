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
 * Tests for eligibility rules.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use filter_dixeo_imageeditor\adapter\eligibility;
use filter_dixeo_imageeditor\adapter\image_util;

/**
 * Only raster images in editor-authored content areas are eligible.
 *
 * @covers \filter_dixeo_imageeditor\adapter\eligibility
 * @covers \filter_dixeo_imageeditor\adapter\image_util::is_raster_web_mimetype
 */
final class eligibility_test extends \advanced_testcase {
    /** @var int Course module context id used for activity files. */
    private int $modulecontextid;

    /** @var int Course context id used for course-level files. */
    private int $coursecontextid;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->modulecontextid = (int) \context_module::instance($page->cmid)->id;
        $this->coursecontextid = (int) \context_course::instance($course->id)->id;
    }

    /**
     * Image bytes of the given GD format.
     *
     * @param string $format png or gif.
     * @return string
     */
    private static function image(string $format): string {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        $format === 'gif' ? imagegif($image) : imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * Store a file and return it.
     *
     * @param int $contextid Context id.
     * @param string $component Component.
     * @param string $filearea File area.
     * @param string $filename File name, which sets the MIME type.
     * @param string $content Content.
     * @return \stored_file
     */
    private function store(int $contextid, string $component, string $filearea, string $filename, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * The slideshow featured image, the tiles photo and an activity intro are eligible.
     */
    public function test_allowed_areas_are_eligible(): void {
        $png = self::image('png');
        $this->assertTrue(eligibility::is_eligible_stored_file(
            $this->store($this->modulecontextid, 'mod_slideshow', 'image', 'featured.png', $png)
        ));
        $this->assertTrue(eligibility::is_eligible_stored_file(
            $this->store($this->coursecontextid, 'format_tiles', 'tilephoto', 'tile.png', $png)
        ));
        $this->assertTrue(eligibility::is_eligible_stored_file(
            $this->store($this->modulecontextid, 'mod_label', 'intro', 'label.png', $png)
        ));
    }

    /**
     * H5P intros, glossary entries, SVG content and submissions are not eligible.
     */
    public function test_other_files_are_not_eligible(): void {
        $png = self::image('png');
        $this->assertFalse(eligibility::is_eligible_stored_file(
            $this->store($this->modulecontextid, 'mod_h5pactivity', 'intro', 'intro.png', $png)
        ));
        $this->assertFalse(eligibility::is_eligible_stored_file(
            $this->store($this->modulecontextid, 'mod_glossary', 'entry', 'entry.png', $png)
        ));
        $this->assertFalse(eligibility::is_eligible_stored_file($this->store(
            $this->modulecontextid,
            'mod_page',
            'content',
            'drawing.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>'
        )));
        $this->assertFalse(eligibility::is_eligible_stored_file(
            $this->store($this->modulecontextid, 'assignsubmission_file', 'submission_files', 'work.gif', self::image('gif'))
        ));
    }

    /**
     * Area rules without a stored file.
     */
    public function test_is_allowed_area(): void {
        $this->assertTrue(eligibility::is_allowed_area('course', 'section'));
        $this->assertTrue(eligibility::is_allowed_area('mod_quiz', 'intro'));
        $this->assertTrue(eligibility::is_allowed_area('mod_book', 'chapter'));
        $this->assertTrue(eligibility::is_allowed_area('block_html', 'content'));
        $this->assertFalse(eligibility::is_allowed_area('course', 'overviewfiles'));
        $this->assertFalse(eligibility::is_allowed_area('format_dixeo', 'chapterimage'));
        $this->assertFalse(eligibility::is_allowed_area('block_dixeo_designer', 'generated_images'));
        $this->assertFalse(eligibility::is_allowed_area('mod_resource', 'content'));
        $this->assertFalse(eligibility::is_allowed_area('mod_forum', 'post'));
        $this->assertFalse(eligibility::is_allowed_area('backup', 'course'));
        $this->assertFalse(eligibility::is_allowed_area('theme_boost', 'logo'));
    }

    /**
     * Raster web images only.
     */
    public function test_is_raster_web_mimetype(): void {
        $this->assertTrue(image_util::is_raster_web_mimetype('image/png'));
        $this->assertTrue(image_util::is_raster_web_mimetype('image/jpeg'));
        $this->assertFalse(image_util::is_raster_web_mimetype('image/svg+xml'));
        $this->assertFalse(image_util::is_raster_web_mimetype('text/html'));
        $this->assertFalse(image_util::is_raster_web_mimetype('application/pdf'));
    }
}
