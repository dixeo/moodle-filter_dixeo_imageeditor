<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Image editor visibility when Dixeo is off.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use filter_dixeo_imageeditor\external\get_location_status;
use local_dixeo\service\image\content\location;

/**
 * The image filter and its web services stop when Dixeo is off.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_dixeo_imageeditor\text_filter::setup
 * @covers     \filter_dixeo_imageeditor\text_filter::filter
 * @covers     \filter_dixeo_imageeditor\external\get_location_status::execute
 */
final class enabled_gate_test extends \advanced_testcase {

    /**
     * Setup does not load the editor while the course is off, and does once it is on.
     */
    public function test_setup_skips_scripts_when_the_course_is_off(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('dixeo_imageeditor', TEXTFILTER_ON);
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        \local_dixeo\external\service_factory::get_settings_config()->set(
            'local_dixeo',
            'enabled',
            $context,
            '0'
        );

        $page = $this->course_page($course);
        $PAGE = $page;
        $page->initialise_theme_and_output();
        $filter = new text_filter($context, []);
        $filter->setup($page, $context);
        $this->assertStringNotContainsString('filter_dixeo_imageeditor/editor', $page->requires->get_end_code());

        \local_dixeo\external\service_factory::get_settings_config()->set(
            'local_dixeo',
            'enabled',
            $context,
            '1'
        );
        $page = $this->course_page($course);
        $PAGE = $page;
        $page->initialise_theme_and_output();
        $filter->setup($page, $context);
        $this->assertStringContainsString('filter_dixeo_imageeditor/editor', $page->requires->get_end_code());
    }

    /**
     * An eligible image is left unchanged while Dixeo is off for its course.
     */
    public function test_filter_leaves_text_unchanged_when_the_course_is_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('dixeo_imageeditor', TEXTFILTER_ON);

        [$file, $courseid] = $this->create_page_image();
        $context = \context_course::instance($courseid);
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
        $html = '<p><img src="' . $url . '" alt="embedded"></p>';
        $filter = new text_filter($context, []);

        \local_dixeo\external\service_factory::get_settings_config()->set(
            'local_dixeo',
            'enabled',
            $context,
            '0'
        );
        $this->assertSame($html, $filter->filter($html));

        \local_dixeo\external\service_factory::get_settings_config()->set(
            'local_dixeo',
            'enabled',
            $context,
            '1'
        );
        $this->assertStringContainsString('dixeo-imageeditor-wrap', $filter->filter($html));
    }

    /**
     * A stored course On does not reopen the editor while the Dixeo layer is off.
     */
    public function test_location_status_rejects_when_dixeo_is_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$file, $courseid] = $this->create_page_image();
        $location = location::from_stored_file($file);
        $config = \local_dixeo\external\service_factory::get_settings_config();
        $config->set('local_dixeo', 'enabled', \context_system::instance(), '0');
        $config->set('local_dixeo', 'enabled', \context_course::instance($courseid), '1');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:dixeo_disabled', 'local_dixeo'));
        get_location_status::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid
        );
    }

    /**
     * @param \stdClass $course
     * @return \moodle_page
     */
    private function course_page(\stdClass $course): \moodle_page {
        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-topics');
        $page->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        return $page;
    }

    /**
     * @return array{0: \stored_file, 1: int}
     */
    private function create_page_image(): array {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
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
        return [$file, (int) $course->id];
    }
}
