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
 * Tests for start_generate external.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use context_module;
use filter_dixeo_imageeditor\external\start_generate;
use local_dixeo\dto\job_binding_metadata;
use local_dixeo\service\image\content\location;
use local_dixeo\repository\image\job_repository;
use local_dixeo\dto\operation_result;
use local_dixeo\external\service_factory;
use local_dixeo\service\image\policy;
use local_dixeo\service\image_generation_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/page/lib.php');

/**
 * Tests for the start_generate external function.
 *
 * @covers \filter_dixeo_imageeditor\external\start_generate
 */
final class start_generate_test extends \advanced_testcase {
    /**
     * Return PNG bytes from core filestorage fixtures.
     */
    private static function fixture_png_bytes(): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/lib/filestorage/tests/fixtures/testimage.png');
    }

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        filter_set_global_state('dixeo_imageeditor', TEXTFILTER_ON);
        set_config('image_generation_enabled', 1, 'local_dixeo');
        set_config('image_generation_content_mode', policy::MODE_GENERATE_EDIT, 'local_dixeo');
    }

    protected function tearDown(): void {
        service_factory::reset();
        parent::tearDown();
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

    public function test_start_generate_rejects_empty_prompt(): void {
        [$location, $courseid] = $this->create_page_image_location();

        try {
            start_generate::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                $courseid,
                '   '
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('prompt_required', $e->errorcode);
        }
    }

    public function test_start_generate_rejects_when_locked_before_submit(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();
        job_repository::create_job($location, 'existing-job', (int) $USER->id);
        job_repository::update_status(
            (int) job_repository::get_active_job_for_location($location)->id,
            job_repository::STATUS_PROCESSING
        );

        $mock = $this->createMock(image_generation_service::class);
        $mock->expects($this->never())->method('submit_content_image_generate_job');
        service_factory::set_test_image_generation_service($mock);

        try {
            start_generate::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                $courseid,
                'A scenic mountain'
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_locked', $e->errorcode);
        }
    }

    public function test_start_generate_queues_job_when_unlocked(): void {
        [$location, $courseid] = $this->create_page_image_location();

        $mock = $this->createMock(image_generation_service::class);
        $mock->expects($this->once())
            ->method('submit_content_image_generate_job')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(static function (?job_binding_metadata $metadata): bool {
                    return $metadata !== null
                        && $metadata->moduletype === 'page'
                        && $metadata->cmid > 0;
                })
            )
            ->willReturn(operation_result::pending('remote-job-99', 'pending', 0));
        service_factory::set_test_image_generation_service($mock);

        $result = start_generate::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            'A scenic mountain'
        );

        $this->assertSame('remote-job-99', $result['jobid']);
        $this->assertSame(job_repository::STATUS_PENDING, $result['status']);
        $this->assertTrue(job_repository::has_blocking_job($location));
    }

    /**
     * Text one character over the image limit is refused before the image service is called.
     */
    public function test_start_generate_refuses_text_over_the_limit(): void {
        [$location, $courseid] = $this->create_page_image_location();
        $mock = $this->createMock(image_generation_service::class);
        $mock->expects($this->never())->method('submit_content_image_generate_job');
        service_factory::set_test_image_generation_service($mock);

        try {
            start_generate::execute(
                $location->contextid,
                $location->component,
                $location->filearea,
                $location->itemid,
                $location->filepath,
                $location->filename,
                $courseid,
                str_repeat('a', \local_dixeo\util\input_limits::MAX_IMAGE_PROMPT_CHARS + 1)
            );
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:prompt_too_long', $e->errorcode);
            $this->assertSame('local_dixeo', $e->module);
        }
    }

    /**
     * Text of exactly the image limit is submitted.
     */
    public function test_start_generate_accepts_text_at_the_limit(): void {
        [$location, $courseid] = $this->create_page_image_location();
        $mock = $this->createMock(image_generation_service::class);
        $mock->expects($this->once())
            ->method('submit_content_image_generate_job')
            ->willReturn(operation_result::pending('remote-limit-1', 'pending', 0));
        service_factory::set_test_image_generation_service($mock);

        $result = start_generate::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            str_repeat('a', \local_dixeo\util\input_limits::MAX_IMAGE_PROMPT_CHARS)
        );

        $this->assertSame('remote-limit-1', $result['jobid']);
    }
}
