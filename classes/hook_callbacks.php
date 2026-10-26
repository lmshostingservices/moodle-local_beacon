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
 * Hook callbacks.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon;

use local_beacon\local\navlink;

/**
 * Callbacks for Moodle core hooks.
 */
class hook_callbacks {
    /**
     * Add Beacon to the site's top navigation bar (primary navigation).
     *
     * Since Moodle 4.0 the Boost-based themes build the top bar from the primary
     * navigation view, which ignores nodes added through extend_navigation(), so
     * the "Main navigation" placement needs this hook (available from 4.3).
     *
     * @param \core\hook\navigation\primary_extend $hook The hook.
     * @return void
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        if (during_initial_install() || !in_array('nav', navlink::placements(), true)) {
            return;
        }
        $link = navlink::for_current_user();
        if ($link === null) {
            return;
        }
        $primary = $hook->get_primaryview();
        if ($primary->get($link['key']) !== false) {
            return;
        }
        $node = $primary->add($link['label'], $link['url'], \navigation_node::TYPE_CUSTOM, null,
            $link['key'], new \pix_icon('icon', '', 'local_beacon'));

        // Staff: the Beacon entry becomes a dropdown of its pages (Overview,
        // Stat cards, KPI gauges, Reports — whichever this viewer may open).
        $current = navlink::current_view();
        foreach (navlink::children($link) as $child) {
            $sub = $node->add($child['label'], $child['url'], \navigation_node::TYPE_CUSTOM, null, $child['key']);
            if ($current === $child['view']) {
                $sub->make_active();
            }
        }

        // Highlight the Beacon entry while the user is anywhere inside Beacon.
        global $PAGE;
        if (!empty($PAGE->url) && strpos($PAGE->url->get_path(false), '/local/beacon/') !== false) {
            $node->make_active();
        }
    }
}
