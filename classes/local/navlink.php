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
     * The sub-pages shown under the staff "Beacon" menu: one per library page
     * the viewer may open (teachers see Overview and Reports; site-wide viewers
     * also see Stat cards and KPI gauges). Learners get no sub-pages.
     *
     * @param array $link The link from for_current_user().
     * @return array[] Each ['key' => string, 'label' => string, 'url' => \moodle_url, 'view' => string].
     */
    public static function children(array $link): array {
        if ($link['key'] !== 'localbeacon') {
            return [];
        }
        $out = [];
        $library = \local_beacon\output\library::class;
        foreach ($library::views_for(\context_system::instance()) as $view) {
            $out[] = ['key' => 'localbeacon_' . $view, 'label' => $library::view_label($view),
                      'url' => $library::view_url($view), 'view' => $view];
        }
        return $out;
    }

    /**
     * The library view the current page is showing, or null when the current
     * page is not the library (so no sub-page is highlighted).
     *
     * @return string|null
     */
    public static function current_view(): ?string {
        global $PAGE;
        if (empty($PAGE->url)) {
            return null;
        }
        if ($PAGE->url->get_path(false) === '/local/beacon/charts.php') {
            return 'charts';
        }
        if ($PAGE->url->get_path(false) !== '/local/beacon/index.php') {
            return null;
        }
        $view = $PAGE->url->get_param('view');
        return $view ? (string) $view : 'all';
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
