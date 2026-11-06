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
 * Tests for get_location_status external.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use context_module;
use filter_dixeo_imageeditor\external\get_location_status;
use local_dixeo\service\image\content\location;
use local_dixeo\repository\image\job_repository;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/page/lib.php');

/**
 * Tests for the get_location_status external function.
 *
 * @covers \filter_dixeo_imageeditor\external\get_location_status
 */
final class get_location_status_test extends \advanced_testcase {
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

    protected function tearDown(): void {
        \local_dixeo\external\service_factory::set_test_job_service(null);
        \local_dixeo\service\image\apply\registry::set_modal_handler(null);
        parent::tearDown();
    }

    /**
     * A co-teacher's poll applies the owner's completed job without switching the session user.
     */
    public function test_poll_by_another_editor_applies_for_the_job_owner(): void {
        global $DB, $USER;

        [$location, $courseid] = $this->create_page_image_location();
        $course = get_course($courseid);
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $poller = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        job_repository::create_job($location, 'remote-owner', (int) $owner->id);

        $result = imagecreatetruecolor(32, 16);
        ob_start();
        imagepng($result);
        $png = (string) ob_get_clean();
        imagedestroy($result);
        $jobs = $this->createMock(\local_dixeo\service\job_service::class);
        $jobs->method('get_job_status')->willReturn(new \local_dixeo\dto\job_status(
            jobid: 'remote-owner',
            type: 'image_edit',
            status: \local_dixeo\dto\job_status::STATUS_COMPLETED,
            progress: 100,
            createdat: time(),
            result: ['image_base64' => base64_encode($png)],
        ));
        \local_dixeo\external\service_factory::set_test_job_service($jobs);
        $handler = new class implements \local_dixeo\service\image\content\apply_handler {
            /** @var int[] Session user id seen by each apply. */
            public array $sessionuserids = [];

            /**
             * Record the session user, then apply through the filter's modal handler.
             *
             * @param location $location
             * @param array $jobresult
             * @param int $userid
             * @param string $source
             * @return void
             */
            public function apply_job_result(location $location, array $jobresult, int $userid, string $source): void {
                global $USER;
                $this->sessionuserids[] = (int) $USER->id;
                (new \filter_dixeo_imageeditor\adapter\modal_apply_handler())->apply_job_result(
                    $location,
                    $jobresult,
                    $userid,
                    $source
                );
            }
        };
        \local_dixeo\service\image\apply\registry::set_modal_handler($handler);

        $this->setUser($poller);
        get_location_status::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid
        );

        $this->assertSame([(int) $poller->id], $handler->sessionuserids);
        $file = $location->get_stored_file();
        $this->assertSame(sha1($png), $file->get_contenthash());
        $this->assertSame((int) $owner->id, (int) $file->get_userid());
        $versions = $DB->get_records('filter_dixeo_imageeditor_version', ['locationhash' => $location->hash()]);
        $this->assertCount(1, $versions);
        $this->assertSame((int) $owner->id, (int) reset($versions)->usermodified);
        $this->assertSame((int) $poller->id, (int) $USER->id);
        $this->assertSame((int) $poller->id, (int) $_SESSION['USER']->id);
    }

    public function test_acknowledge_clears_terminal_lock(): void {
        global $USER;

        [$location, $courseid] = $this->create_page_image_location();
        job_repository::create_job($location, 'job-123', (int) $USER->id);
        job_repository::update_status(
            (int) job_repository::get_active_job_for_location($location)->id,
            job_repository::STATUS_APPLIED
        );

        $status = get_location_status::execute(
            $location->contextid,
            $location->component,
            $location->filearea,
            $location->itemid,
            $location->filepath,
            $location->filename,
            $courseid,
            true
        );

        $this->assertSame(job_repository::STATUS_APPLIED, $status['status']);
        $this->assertFalse(job_repository::has_blocking_job($location));
        $this->assertNull(job_repository::get_active_job_for_location($location));
    }
}
