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

namespace filter_dixeo_imageeditor\adapter;

use local_dixeo\service\image\policy;
use local_dixeo\service\image\content\capability;

/**
 * Feature gate checks for the filter and its externals.
 *
 * Combines the Moodle filter active state, {@see \local_dixeo\service\image\policy} modes, and
 * Moodle caps for editor UI (see also {@see \local_dixeo\service\image\content\capability} on externals).
 *
 * @package    filter_dixeo_imageeditor
 * @copyright  2026 Dixeo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class feature_gate {
    /**
     * Whether the filter is not disabled site-wide on the Manage filters page.
     *
     * Off (but available) still returns true so a course can turn the filter on.
     *
     * @return bool
     */
    public static function is_globally_enabled(): bool {
        return filter_is_enabled('dixeo_imageeditor');
    }

    /**
     * Require the filter edit capability in a course.
     *
     * @param int $courseid
     * @return void
     */
    public static function require_filter_edit(int $courseid): void {
        require_capability('filter/dixeo_imageeditor:edit', \context_course::instance($courseid));
    }

    /**
     * Policy flags for UI.
     *
     * @param int $courseid Course whose content-image override applies. Zero uses the Dixeo layer.
     * @return array{can_generate: bool, can_edit: bool}
     */
    public static function policy_flags(int $courseid = 0): array {
        $context = $courseid > 0 ? \context_course::instance($courseid) : null;
        return [
            'can_generate' => policy::is_enabled(
                policy::ENTITY_CONTENT,
                policy::ACTION_GENERATE,
                $context
            ),
            'can_edit' => policy::is_enabled(
                policy::ENTITY_CONTENT,
                policy::ACTION_EDIT,
                $context
            ),
        ];
    }

    /**
     * Return generate and edit capability flags for a course.
     *
     * @param int $courseid
     * @return array{can_generate: bool, can_edit: bool}
     */
    public static function capability_flags(int $courseid): array {
        $context = \context_course::instance($courseid);
        $hasfilteredit = has_capability('filter/dixeo_imageeditor:edit', $context);
        $cangenerate = has_capability('local/dixeo:contentimagegenerate', $context) || $hasfilteredit;
        $canedit = has_capability('local/dixeo:contentimageedit', $context) || $hasfilteredit;

        return [
            'can_generate' => $cangenerate,
            'can_edit' => $canedit,
        ];
    }

    /**
     * Require permission to start a content image generate job.
     *
     * @param int $courseid
     * @return void
     */
    public static function require_content_generate(int $courseid): void {
        $context = \context_course::instance($courseid);
        if (has_capability('filter/dixeo_imageeditor:edit', $context)) {
            return;
        }
        capability::require_generate($courseid);
    }

    /**
     * Require permission to start a content image edit job.
     *
     * @param int $courseid
     * @return void
     */
    public static function require_content_edit(int $courseid): void {
        $context = \context_course::instance($courseid);
        if (has_capability('filter/dixeo_imageeditor:edit', $context)) {
            return;
        }
        capability::require_edit($courseid);
    }
}
