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
 * Tests for {@see \filter_dixeo_imageeditor\text_filter}.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

/**
 * The filter is cheap for users who cannot edit and for text without images.
 *
 * @covers \filter_dixeo_imageeditor\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /** @var \stdClass Course with the image. */
    private \stdClass $course;

    /** @var string HTML with one eligible embedded image. */
    private string $html;

    protected function setUp(): void {
        global $USER;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('dixeo_imageeditor', TEXTFILTER_ON);

        $this->course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $context = \context_module::instance($page->cmid);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'embedded.png',
        ], (string) file_get_contents(__DIR__ . '/../../../lib/filestorage/tests/fixtures/testimage.png'));
        file_save_draft_area_files($draftitemid, $context->id, 'mod_page', 'content', 0, ['subdirs' => 0, 'maxfiles' => 1]);
        $file = $fs->get_file($context->id, 'mod_page', 'content', 0, '/', 'embedded.png');
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
        $this->html = '<p><img src="' . $url . '" alt="embedded"></p>';
    }

    /**
     * Filter for the course context.
     *
     * @return text_filter
     */
    private function filter(): text_filter {
        return new text_filter(\context_course::instance((int) $this->course->id), []);
    }

    /**
     * A student's text is returned unchanged, and a repeated call reads nothing.
     */
    public function test_student_text_is_unchanged_without_reads(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $filter = $this->filter();

        $this->assertSame($this->html, $filter->filter($this->html, ['stage' => 'post_clean']));
        $reads = $DB->perf_get_reads();
        $this->assertSame($this->html, $filter->filter($this->html, ['stage' => 'post_clean']));
        $this->assertSame(0, $DB->perf_get_reads() - $reads);
    }

    /**
     * An editor still gets the edit control, with a revision on the image URL.
     */
    public function test_editor_image_is_wrapped(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);

        $html = $this->filter()->filter($this->html, ['stage' => 'post_clean']);

        $this->assertStringContainsString('dixeo-imageeditor-wrap', $html);
        $this->assertStringContainsString('rev=', $html);
    }

    /**
     * Text without an image does no database read.
     */
    public function test_text_without_image_reads_nothing(): void {
        global $DB;

        $filter = $this->filter();
        $reads = $DB->perf_get_reads();
        $this->assertSame('<p>Hello</p>', $filter->filter('<p>Hello</p>', ['stage' => 'post_clean']));
        $this->assertSame(0, $DB->perf_get_reads() - $reads);
    }

    /**
     * A student does not download the editor scripts.
     */
    public function test_setup_skips_scripts_for_a_student(): void {
        global $PAGE;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $context = \context_course::instance((int) $this->course->id);
        $page = new \moodle_page();
        $page->set_context($context);
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-topics');
        $page->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        $PAGE = $page;
        $page->initialise_theme_and_output();

        $this->filter()->setup($page, $context);

        $this->assertStringNotContainsString('filter_dixeo_imageeditor/editor', $page->requires->get_end_code());
    }
}
