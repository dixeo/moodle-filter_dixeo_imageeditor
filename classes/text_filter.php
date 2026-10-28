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

namespace filter_dixeo_imageeditor;

use filter_dixeo_imageeditor\adapter\eligibility;
use filter_dixeo_imageeditor\adapter\feature_gate;
use filter_dixeo_imageeditor\adapter\file_replacer;
use local_dixeo\repository\image\job_repository;
use local_dixeo\service\image\content\location;

/**
 * Injects AI image edit controls on eligible embedded images.
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** @var bool */
    private static bool $amdsloaded = false;

    /** @var bool */
    private static bool $tilesloaded = false;

    #[\Override]
    public function setup($page, $context): void {
        if (!feature_gate::is_globally_enabled()) {
            return;
        }
        // Image editing is a Dixeo feature, so its scripts stay unloaded while Dixeo is off.
        // Global filter setup passes the system context, so a course page uses that course.
        if (!$this->dixeo_enabled_for_page($page, $context)) {
            return;
        }
        if (!self::$amdsloaded) {
            $page->requires->js_call_amd('filter_dixeo_imageeditor/editor', 'init');
            // Styles.css is included by the theme. Cropper.css is a third-party sheet.
            $page->requires->css('/filter/dixeo_imageeditor/cropper.css');
            self::$amdsloaded = true;
        }
        self::setup_tiles_course_page($page);
    }

    /**
     * Add the image editor control on tiles course pages.
     *
     * Section photos are CSS backgrounds, and icon tiles have no file yet, so
     * the text filter never sees them. The course page script opens the same
     * modal for both.
     *
     * @param \moodle_page $page
     * @return void
     */
    private static function setup_tiles_course_page(\moodle_page $page): void {
        if (self::$tilesloaded) {
            return;
        }
        if ($page->pagetype !== 'course-view-tiles') {
            return;
        }
        $coursecontext = $page->context;
        if (!$coursecontext instanceof \context_course) {
            return;
        }
        if (!has_capability('filter/dixeo_imageeditor:edit', $coursecontext)) {
            return;
        }
        if (!\filter_dixeo_imageeditor\adapter\tiles_section_photo::photos_allowed()) {
            return;
        }

        $page->requires->js_call_amd('filter_dixeo_imageeditor/tiles_course', 'init', [[
            'courseid' => (int) $coursecontext->instanceid,
            'contextid' => (int) $coursecontext->id,
        ]]);
        self::$tilesloaded = true;
    }

    #[\Override]
    public function filter($text, array $options = []): string {
        if (!feature_gate::is_globally_enabled()) {
            return $text;
        }
        // Image editing is a Dixeo feature, so the text is left unchanged while Dixeo is off.
        if (!\local_dixeo\external\service_factory::get_settings_config()->enabled_for($this->context)) {
            return $text;
        }
        if ($text === '' || stripos($text, '<img') === false) {
            return $text;
        }
        if (!empty($options['stage']) && $options['stage'] !== 'post_clean') {
            return $text;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="dixeo-imageeditor-root">' . $text . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $text;
        }

        $xpath = new \DOMXPath($dom);
        $images = $xpath->query('//img');
        if (!$images || $images->length === 0) {
            return $text;
        }

        $modified = false;
        $wrappersbyhash = [];
        foreach ($images as $img) {
            if (!($img instanceof \DOMElement)) {
                continue;
            }
            $src = $img->getAttribute('src');
            if ($src === '' || stripos($src, 'pluginfile.php') === false) {
                continue;
            }

            $file = eligibility::resolve_eligible_file_from_url($src);
            if (!$file) {
                continue;
            }

            $location = location::from_stored_file($file);
            if ($location->courseid < 1) {
                continue;
            }

            $coursecontext = \context_course::instance($location->courseid, IGNORE_MISSING);
            if (!$coursecontext || !has_capability('filter/dixeo_imageeditor:edit', $coursecontext)) {
                continue;
            }

            $contenthash = $file->get_contenthash();
            $img->setAttribute('src', file_replacer::get_current_image_url($location));

            // Successful file after a failed gen: strip stale status classes/hash so
            // content_image_pending does not re-bust to a cached error.png URL.
            if (!file_replacer::is_status_asset_hash($contenthash)) {
                $class = trim(preg_replace(
                    '/\s*\bdixeo-img-gen-(?:pending|failed)\b/u',
                    '',
                    $img->getAttribute('class')
                ) ?? '');
                $img->setAttribute('class', $class);
                $img->setAttribute('data-dixeo-contenthash', $contenthash);
            }

            $wrapper = $dom->createElement('span');
            $wrapper->setAttribute('class', 'dixeo-imageeditor-wrap');
            $wrapper->setAttribute('data-dixeo-imageeditor', '1');
            $wrapper->setAttribute('data-contextid', (string) $location->contextid);
            $wrapper->setAttribute('data-component', $location->component);
            $wrapper->setAttribute('data-filearea', $location->filearea);
            $wrapper->setAttribute('data-itemid', (string) $location->itemid);
            $wrapper->setAttribute('data-filepath', $location->filepath);
            $wrapper->setAttribute('data-filename', $location->filename);
            $wrapper->setAttribute('data-courseid', (string) $location->courseid);
            $wrapper->setAttribute('data-contenthash', $contenthash);

            $parent = $img->parentNode;
            if (!$parent) {
                continue;
            }
            $parent->replaceChild($wrapper, $img);
            $wrapper->appendChild($img);

            $button = $dom->createElement('button');
            $button->setAttribute('type', 'button');
            $button->setAttribute('class', 'dixeo-imageeditor-editbtn');
            $button->setAttribute('data-action', 'open-editor');
            $button->setAttribute('aria-label', get_string('editimage', 'filter_dixeo_imageeditor'));
            $button->textContent = get_string('editimage', 'filter_dixeo_imageeditor');
            $wrapper->appendChild($button);

            $wrappersbyhash[$location->hash()] = $wrapper;
            $modified = true;
        }

        if (!$modified) {
            return $text;
        }

        // Single bulk query so the client only polls images with an in-flight job.
        $pendinghashes = job_repository::get_pending_locationhashes(array_keys($wrappersbyhash));
        foreach ($pendinghashes as $hash) {
            if (isset($wrappersbyhash[$hash])) {
                $wrappersbyhash[$hash]->setAttribute('data-dixeo-pending', '1');
            }
        }

        $root = $dom->getElementById('dixeo-imageeditor-root');
        if (!$root) {
            return $text;
        }

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }
        return $html;
    }

    /**
     * Whether Dixeo image editing runs for this page.
     *
     * A page inside a course follows that course. Any other page follows the
     * context the filter was given.
     *
     * @param \moodle_page $page
     * @param \context $context
     * @return bool
     */
    private function dixeo_enabled_for_page(\moodle_page $page, \context $context): bool {
        $courseid = (int) ($page->course->id ?? 0);
        $gatecontext = $courseid > SITEID ? \context_course::instance($courseid) : $context;
        return \local_dixeo\external\service_factory::get_settings_config()->enabled_for($gatecontext);
    }
}
