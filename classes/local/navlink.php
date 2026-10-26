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
 * Which Beacon navigation link the current user gets, and where it goes.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Shared by the legacy navigation callback and the primary-navigation hook so
 * both show the same link to the same people.
 */
class navlink {
    /**
     * The link for the current user: staff get the reports library, learners
     * get "My reports", anyone else gets nothing.
     *
     * @return array|null ['label' => string, 'url' => \moodle_url, 'key' => string] or null.
     */
    public static function for_current_user(): ?array {
        if (!isloggedin() || isguestuser()) {
            return null;
        }
        $syscontext = \context_system::instance();
        if (access::is_staff($syscontext)) {
            return ['label' => get_string('pluginname', 'local_beacon'),
                    'url' => new \moodle_url('/local/beacon/index.php'), 'key' => 'localbeacon'];
        }
        if (has_capability('local/beacon:viewmine', $syscontext)) {
            return ['label' => get_string('myreports', 'local_beacon'),
                    'url' => new \moodle_url('/local/beacon/myreports.php'), 'key' => 'localbeaconmine'];
        }
        return null;
    }

    /**
     * The placements the admin ticked (default: main navigation).
     *
     * @return string[]
     */
    public static function placements(): array {
        $raw = get_config('local_beacon', 'navplacement');
        return ($raw === false || $raw === null || $raw === '') ? ['nav'] : explode(',', $raw);
    }
}
