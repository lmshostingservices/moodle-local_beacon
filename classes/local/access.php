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
 * Access helpers for Beacon's staff vs learner routing.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Who may see the staff reports library (as opposed to the learner self-view).
 */
class access {
    /**
     * Whether the current user is a Beacon staff viewer for this context.
     *
     * A manager/admin holds local/beacon:view at the system context. A teacher
     * (editing or non-editing) holds it only in the course(s) they teach, because
     * that is where their role is assigned — so at the SYSTEM context the plain
     * capability check returns false even though they are legitimately staff. We
     * therefore also treat a user who holds the capability in any course as staff
     * at the system context; the report pages then hard-limit their data to their
     * own learners through the viewer scope, so this widens who reaches the staff
     * library, never what data they can see.
     *
     * @param \context $context The context being viewed.
     * @return bool
     */
    public static function is_staff(\context $context): bool {
        global $USER;
        static $cache = [];
        $key = (int) ($USER->id ?? 0) . ':' . $context->id;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (has_capability('local/beacon:view', $context)) {
            return $cache[$key] = true;
        }
        if ($context instanceof \context_system) {
            return $cache[$key] = (bool) get_user_capability_course('local/beacon:view', null, false);
        }
        return $cache[$key] = false;
    }
}
