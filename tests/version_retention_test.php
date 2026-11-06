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
 * Tests for version history retention.
 *
 * @package    filter_dixeo_imageeditor
 * @category   test
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_dixeo_imageeditor;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use filter_dixeo_imageeditor\adapter\file_replacer;
use filter_dixeo_imageeditor\privacy\provider;
use filter_dixeo_imageeditor\task\cleanup_version_history;
use local_dixeo\service\image\content\location;

/**
 * History is capped per image and expires with age, except the current image's version.
 *
 * @covers \filter_dixeo_imageeditor\adapter\file_replacer
 * @covers \filter_dixeo_imageeditor\task\cleanup_version_history
 */
final class version_retention_test extends \advanced_testcase {
    /** @var string Version table. */
    private const TABLE = 'filter_dixeo_imageeditor_version';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * A distinct small PNG for each colour index.
     *
     * @param int $index Colour index.
     * @return string
     */
    private static function png(int $index): string {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, $index % 256, intdiv($index, 256) % 256, 7));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * A page image location and its module context.
     *
     * @return array{0: location, 1: \context_module}
     */
    private function create_location(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'embedded.png',
        ], self::png(1000 + (int) $page->cmid));
        return [location::from_stored_file($file), $context];
    }

    /**
     * Number of history blobs in system file storage.
     *
     * @return int
     */
    private function count_blobs(): int {
        global $DB;
        return $DB->count_records_select(
            'files',
            "component = 'filter_dixeo_imageeditor' AND filearea = :area AND filename <> '.'",
            ['area' => file_replacer::FILEAREA_HISTORY]
        );
    }

    /**
     * Twenty-five replacements keep twenty rows and blobs, and privacy still covers them.
     */
    public function test_history_is_capped_and_still_exported_and_deleted(): void {
        global $DB, $USER;

        [$location, $context] = $this->create_location();
        for ($i = 1; $i <= 25; $i++) {
            file_replacer::apply_binary($location, self::png($i), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        }

        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $DB->count_records(self::TABLE));
        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $this->count_blobs());
        $this->assertCount(file_replacer::MAX_VERSIONS_PER_LOCATION, file_replacer::get_history_for_location($location));
        // The newest archived version is the image before the last replacement.
        $history = file_replacer::get_history_for_location($location);
        $this->assertSame(sha1(self::png(24)), $history[0]['contenthash']);

        provider::export_user_data(new approved_contextlist($USER, 'filter_dixeo_imageeditor', [$context->id]));
        $subcontext = [get_string('privacy:pathversions', 'filter_dixeo_imageeditor')];
        $exported = writer::with_context($context)->get_data($subcontext);
        $this->assertCount(file_replacer::MAX_VERSIONS_PER_LOCATION, $exported->versions);

        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records(self::TABLE));
        $this->assertSame(0, $this->count_blobs());
    }

    /**
     * A version older than the age limit is removed while its image exists; the current one stays.
     */
    public function test_aged_versions_expire_except_the_current_image(): void {
        global $DB, $USER;

        $clock = $this->mock_clock_with_frozen();
        [$location, ] = $this->create_location();
        $original = $location->get_stored_file()->get_contenthash();
        file_replacer::apply_binary($location, self::png(1), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        file_replacer::apply_binary($location, self::png(2), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        // Back to the first replacement: its version now matches the current image.
        $current = $DB->get_record(self::TABLE, ['contenthash' => sha1(self::png(1))], '*', MUST_EXIST);
        file_replacer::revert_to_version($location, (int) $current->id, (int) $USER->id);
        $this->assertSame(3, $DB->count_records(self::TABLE));
        $DB->set_field(self::TABLE, 'timecreated', $clock->time() - file_replacer::MAX_VERSION_AGE - 1);

        (new cleanup_version_history())->execute();

        $remaining = $DB->get_records(self::TABLE);
        $this->assertCount(1, $remaining);
        $this->assertSame(sha1(self::png(1)), reset($remaining)->contenthash);
        $this->assertNotSame($original, reset($remaining)->contenthash);
        $this->assertSame(1, $this->count_blobs());
    }

    /**
     * The cleanup does the same number of reads for one image as for several.
     */
    public function test_cleanup_reads_do_not_grow_with_locations(): void {
        global $DB, $USER;

        [$first, ] = $this->create_location();
        file_replacer::apply_binary($first, self::png(1), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        $task = new cleanup_version_history();
        $task->execute();
        $reads = $DB->perf_get_reads();
        $task->execute();
        $onelocation = $DB->perf_get_reads() - $reads;

        for ($i = 0; $i < 4; $i++) {
            [$location, ] = $this->create_location();
            file_replacer::apply_binary($location, self::png(10 + $i), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        }
        $this->assertSame(5, $DB->count_records(self::TABLE));
        $reads = $DB->perf_get_reads();
        $task->execute();
        $this->assertSame($onelocation, $DB->perf_get_reads() - $reads);
    }

    /**
     * Fill a location with exactly the capped number of versions, the original image being the oldest.
     *
     * @return array{0: location, 1: \stdClass} Location and the original image's version row.
     */
    private function create_full_history(): array {
        global $DB, $USER;

        [$location, ] = $this->create_location();
        $original = $location->get_stored_file()->get_contenthash();
        for ($i = 1; $i <= file_replacer::MAX_VERSIONS_PER_LOCATION; $i++) {
            file_replacer::apply_binary($location, self::png($i), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        }
        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $DB->count_records(self::TABLE));
        $oldest = $DB->get_records(self::TABLE, null, 'timecreated ASC, id ASC', '*', 0, 1);
        $oldest = reset($oldest);
        $this->assertSame($original, $oldest->contenthash);
        return [$location, $oldest];
    }

    /**
     * Reverting to the oldest version of a full history keeps that version, which is now the live image.
     */
    public function test_revert_to_oldest_version_keeps_it_in_history(): void {
        global $DB, $USER;

        [$location, $oldest] = $this->create_full_history();

        file_replacer::revert_to_version($location, (int) $oldest->id, (int) $USER->id);

        $this->assertSame($oldest->contenthash, $location->get_stored_file()->get_contenthash());
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $oldest->id]));
        $this->assertLessThanOrEqual(file_replacer::MAX_VERSIONS_PER_LOCATION + 1, $DB->count_records(self::TABLE));
    }

    /**
     * A write that fails after archiving prunes nothing.
     */
    public function test_failed_write_prunes_no_history(): void {
        global $DB;

        [$location, $oldest] = $this->create_full_history();
        $before = array_keys($DB->get_records(self::TABLE, null, 'id ASC', 'id'));
        $live = $location->get_stored_file()->get_contenthash();
        $nouser = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;

        try {
            file_replacer::revert_to_version($location, (int) $oldest->id, $nouser);
            $this->fail('Expected an exception from the write');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\dml_missing_record_exception::class, $e);
        }

        $this->assertSame($live, $location->get_stored_file()->get_contenthash());
        $after = array_keys($DB->get_records(self::TABLE, null, 'id ASC', 'id'));
        $this->assertSame([], array_diff($before, $after));
    }

    /**
     * The next change after reverting to the oldest version keeps that image as the newest version.
     */
    public function test_change_after_revert_to_oldest_keeps_previous_image(): void {
        global $DB, $USER;

        [$location, $oldest] = $this->create_full_history();
        file_replacer::revert_to_version($location, (int) $oldest->id, (int) $USER->id);

        file_replacer::apply_binary($location, self::png(500), (int) $USER->id, file_replacer::SOURCE_EDITED);

        $this->assert_newest_version_with_blob($location, $oldest->contenthash);
        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $DB->count_records(self::TABLE));
        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $this->count_blobs());
    }

    /**
     * Re-uploading the oldest image and then changing it keeps the re-uploaded image as the newest version.
     */
    public function test_reupload_of_oldest_image_keeps_previous_image(): void {
        global $DB, $USER;

        [$location, $oldest] = $this->create_full_history();
        $bytes = $this->version_content((int) $oldest->id);

        file_replacer::apply_binary($location, $bytes, (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        file_replacer::apply_binary($location, self::png(501), (int) $USER->id, file_replacer::SOURCE_UPLOAD);

        $this->assert_newest_version_with_blob($location, $oldest->contenthash);
        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $DB->count_records(self::TABLE));
        $this->assertSame(file_replacer::MAX_VERSIONS_PER_LOCATION, $this->count_blobs());
    }

    /**
     * Across new images, reverts and re-uploads, the image live before a write is always the newest version.
     */
    public function test_previous_image_is_never_pruned(): void {
        global $DB, $USER;

        [$location, ] = $this->create_full_history();
        for ($i = 0; $i < 48; $i++) {
            $previous = $location->get_stored_file()->get_contenthash();
            $oldest = $DB->get_records(self::TABLE, null, 'timecreated ASC, id ASC', '*', 0, 1);
            $oldest = reset($oldest);
            $useoldest = $oldest->contenthash !== $previous;

            if ($i % 3 !== 2 && $useoldest) {
                $bytes = $this->version_content((int) $oldest->id);
            } else {
                $bytes = self::png(600 + $i);
            }
            $newhash = sha1($bytes);
            $wasarchived = $DB->record_exists(self::TABLE, ['contenthash' => $newhash]);

            if ($i % 3 === 0 && $useoldest) {
                file_replacer::revert_to_version($location, (int) $oldest->id, (int) $USER->id);
            } else {
                file_replacer::apply_binary($location, $bytes, (int) $USER->id, file_replacer::SOURCE_UPLOAD);
            }

            $this->assertSame($newhash, $location->get_stored_file()->get_contenthash());
            $this->assert_newest_version_with_blob($location, $previous);
            if ($wasarchived) {
                $this->assertTrue($DB->record_exists(self::TABLE, ['contenthash' => $newhash]), "Write {$i}");
            }
            $rows = $DB->count_records(self::TABLE);
            $this->assertSame($rows, $this->count_blobs(), "Write {$i}");
            $this->assertLessThanOrEqual(file_replacer::MAX_VERSIONS_PER_LOCATION + 1, $rows, "Write {$i}");
        }
    }

    /**
     * Assert that a content hash is the newest history entry and its blob exists.
     *
     * @param location $location
     * @param string $contenthash
     * @return void
     */
    private function assert_newest_version_with_blob(location $location, string $contenthash): void {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['locationhash' => $location->hash(), 'contenthash' => $contenthash]);
        $this->assertNotFalse($row);
        $this->assertNotSame('', $this->version_content((int) $row->id));
        $this->assertSame($contenthash, file_replacer::get_history_for_location($location)[0]['contenthash']);
    }

    /**
     * Bytes of an archived version's blob.
     *
     * @param int $versionid
     * @return string Empty when the blob is missing.
     */
    private function version_content(int $versionid): string {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'filter_dixeo_imageeditor',
            file_replacer::FILEAREA_HISTORY,
            $versionid,
            'id',
            false
        );
        $file = reset($files);
        return $file ? $file->get_content() : '';
    }

    /**
     * Archived non-raster files are removed by the cleanup; raster history of the same image stays.
     */
    public function test_cleanup_removes_non_raster_history_blobs(): void {
        global $DB, $USER;

        [$location, ] = $this->create_location();
        file_replacer::apply_binary($location, self::png(1), (int) $USER->id, file_replacer::SOURCE_UPLOAD);
        $raster = $DB->get_record(self::TABLE, [], '*', MUST_EXIST);
        $svg = $this->insert_version_blob($location, '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml');
        $pdf = $this->insert_version_blob($location, '%PDF-1.4 legacy', 'application/pdf');
        $this->assertSame(3, $this->count_blobs());

        (new cleanup_version_history())->execute();

        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $raster->id]));
        $this->assertFalse($DB->record_exists(self::TABLE, ['id' => $svg]));
        $this->assertFalse($DB->record_exists(self::TABLE, ['id' => $pdf]));
        $this->assertSame(1, $this->count_blobs());
    }

    /**
     * Insert a version row and its archived blob directly, as history from older releases may hold.
     *
     * @param location $location Image the version belongs to.
     * @param string $bytes Archived bytes.
     * @param string $mimetype MIME type recorded for the blob.
     * @return int Version id.
     */
    private function insert_version_blob(location $location, string $bytes, string $mimetype): int {
        global $DB, $USER;

        $now = time();
        $fields = $location->to_record_fields();
        unset($fields['courseid']);
        $versionid = (int) $DB->insert_record(self::TABLE, (object) array_merge($fields, [
            'contenthash' => sha1($bytes),
            'source' => file_replacer::SOURCE_UPLOAD,
            'usermodified' => (int) $USER->id,
            'timecreated' => $now,
        ]));
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'filter_dixeo_imageeditor',
            'filearea' => file_replacer::FILEAREA_HISTORY,
            'itemid' => $versionid,
            'filepath' => '/' . $location->contextid . '/' . $location->hash() . '/',
            'filename' => 'version-' . $versionid . '-' . $now . '.bin',
            'userid' => (int) $USER->id,
            'mimetype' => $mimetype,
        ], $bytes);
        return $versionid;
    }
}
