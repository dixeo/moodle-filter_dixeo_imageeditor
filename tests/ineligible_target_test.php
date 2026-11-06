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
 * Tests that files outside editor-authored image areas are never edited, sent or served.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use filter_dixeo_imageeditor\adapter\file_replacer;
use filter_dixeo_imageeditor\external\apply_upload;
use filter_dixeo_imageeditor\external\start_edit;
use filter_dixeo_imageeditor\task\cleanup_version_history;
use local_dixeo\external\service_factory;
use local_dixeo\service\image\content\location;
use local_dixeo\service\image\policy;
use local_dixeo\service\image_generation_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/filter/dixeo_imageeditor/lib.php');

/**
 * Ineligible targets: non-images, other users' files, backups and student-authored areas.
 *
 * @covers \filter_dixeo_imageeditor\external\apply_upload
 * @covers \filter_dixeo_imageeditor\external\start_edit
 * @covers \filter_dixeo_imageeditor\text_filter
 * @covers \filter_dixeo_imageeditor\task\cleanup_version_history
 * @covers ::filter_dixeo_imageeditor_pluginfile
 */
final class ineligible_target_test extends \advanced_testcase {
    /** @var \stdClass Course holding the files. */
    private \stdClass $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        filter_set_global_state('dixeo_imageeditor', TEXTFILTER_ON);
        set_config('image_generation_enabled', 1, 'local_dixeo');
        set_config('image_generation_content_mode', policy::MODE_GENERATE_EDIT, 'local_dixeo');
        $this->course = $this->getDataGenerator()->create_course();
    }

    protected function tearDown(): void {
        service_factory::reset();
        parent::tearDown();
    }

    /**
     * PNG bytes from the core file storage fixtures.
     *
     * @return string
     */
    private static function png(): string {
        global $CFG;
        return (string) file_get_contents($CFG->dirroot . '/lib/filestorage/tests/fixtures/testimage.png');
    }

    /**
     * Context id of a new module of the given type in the course.
     *
     * @param string $modname Module name.
     * @return int
     */
    private function module_contextid(string $modname): int {
        $module = $this->getDataGenerator()->create_module($modname, ['course' => $this->course->id]);
        return (int) \context_module::instance($module->cmid)->id;
    }

    /**
     * Store a file.
     *
     * @param int $contextid Context id.
     * @param string $component Component.
     * @param string $filearea File area.
     * @param string $filename File name, which sets the MIME type.
     * @param string $content Content.
     * @param int|null $userid Owner.
     * @return \stored_file
     */
    private function store(
        int $contextid,
        string $component,
        string $filearea,
        string $filename,
        string $content,
        ?int $userid = null
    ): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $content);
    }

    /**
     * Call apply_upload on a file and assert it is refused and left unchanged.
     *
     * @param \stored_file $file Target.
     * @return void
     */
    private function assert_upload_refused(\stored_file $file): void {
        $hash = $file->get_contenthash();
        try {
            apply_upload::execute(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                (int) $this->course->id,
                base64_encode(self::png())
            );
            $this->fail('Expected error_not_eligible');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_not_eligible', $e->errorcode);
        }
        $this->assertSame($hash, get_file_storage()->get_file_by_id($file->get_id())->get_contenthash());
    }

    /**
     * A PDF in a file resource is not replaced.
     */
    public function test_upload_refuses_a_resource_pdf(): void {
        $file = $this->store($this->module_contextid('resource'), 'mod_resource', 'content', 'notes.pdf', '%PDF-1.4 test');
        $this->assert_upload_refused($file);
    }

    /**
     * A student's submitted image and a course backup are not replaced.
     */
    public function test_upload_refuses_a_submission_and_a_backup(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assert_upload_refused($this->store(
            $this->module_contextid('assign'),
            'assignsubmission_file',
            'submission_files',
            'work.png',
            self::png(),
            (int) $student->id
        ));
        $this->assert_upload_refused($this->store(
            (int) \context_course::instance($this->course->id)->id,
            'backup',
            'course',
            'backup.mbz',
            'backup bytes'
        ));
    }

    /**
     * An AI edit of a non-image submits nothing.
     */
    public function test_start_edit_on_a_non_image_submits_nothing(): void {
        $file = $this->store($this->module_contextid('page'), 'mod_page', 'content', 'notes.pdf', '%PDF-1.4 test');
        $images = $this->createMock(image_generation_service::class);
        $images->expects($this->never())->method('submit_content_image_edit_job');
        service_factory::set_test_image_generation_service($images);

        try {
            start_edit::execute(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                (int) $this->course->id,
                'Make it brighter'
            );
            $this->fail('Expected error_not_eligible');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_not_eligible', $e->errorcode);
        }
    }

    /**
     * An image in a forum post is not wrapped.
     */
    public function test_filter_does_not_wrap_a_forum_post_image(): void {
        $file = $this->store($this->module_contextid('forum'), 'mod_forum', 'post', 'post.png', self::png());
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
        $html = '<p><img src="' . $url . '" alt="post"></p>';

        $filter = new text_filter(\context_course::instance($this->course->id), []);

        $this->assertSame($html, $filter->filter($html, ['stage' => 'post_clean']));
    }

    /**
     * Add a version row and history blob for a target file.
     *
     * @param \stored_file $target Target file.
     * @return int Version id.
     */
    private function add_version(\stored_file $target): int {
        global $DB, $USER;

        $location = location::from_stored_file($target);
        $fields = $location->to_record_fields();
        unset($fields['courseid']);
        $versionid = (int) $DB->insert_record('filter_dixeo_imageeditor_version', (object) array_merge($fields, [
            'contenthash' => sha1('older'),
            'source' => file_replacer::SOURCE_UPLOAD,
            'usermodified' => (int) $USER->id,
            'timecreated' => time(),
        ]));
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'filter_dixeo_imageeditor',
            'filearea' => file_replacer::FILEAREA_HISTORY,
            'itemid' => $versionid,
            'filepath' => '/' . $location->contextid . '/' . $location->hash() . '/',
            'filename' => 'version-' . $versionid . '.bin',
        ], 'older');
        return $versionid;
    }

    /**
     * History of an ineligible target is not served, and the cleanup task removes it.
     */
    public function test_history_of_a_forum_post_is_not_served_and_is_cleaned_up(): void {
        global $DB;

        $target = $this->store($this->module_contextid('forum'), 'mod_forum', 'post', 'post.png', self::png());
        $versionid = $this->add_version($target);
        $location = location::from_stored_file($target);

        $served = filter_dixeo_imageeditor_pluginfile(
            null,
            null,
            \context_system::instance(),
            file_replacer::FILEAREA_HISTORY,
            [$versionid, $location->contextid, $location->hash(), 'version-' . $versionid . '.bin'],
            false,
            ['dontdie' => true]
        );
        $this->assertFalse($served);

        (new cleanup_version_history())->execute();

        $this->assertFalse($DB->record_exists('filter_dixeo_imageeditor_version', ['id' => $versionid]));
        $this->assertTrue(get_file_storage()->is_area_empty(
            \context_system::instance()->id,
            'filter_dixeo_imageeditor',
            file_replacer::FILEAREA_HISTORY,
            $versionid
        ));
    }
}
