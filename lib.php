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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Core callbacks.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Map Beacon's pix icon onto Font Awesome so any theme renders it.
 *
 * @return array
 */
function local_beacon_get_fontawesome_icon_map(): array {
    return [
        'local_beacon:icon' => 'fa-chart-line',
    ];
}

/**
 * Add Beacon to navigation, in the locations the admin has chosen.
 *
 * Staff (who can see the site dashboard) get the "Beacon" link to the reports
 * library; everyone else who is a learner gets a "My reports" link to their own
 * self-view. Placement is controlled by the beacon/navplacement setting.
 *
 * @param global_navigation $navigation The navigation tree.
 * @return void
 */
function local_beacon_extend_navigation(global_navigation $navigation): void {
    $syscontext = context_system::instance();
    $canview = has_capability('local/beacon:view', $syscontext);
    $canmine = isloggedin() && !isguestuser()
        && has_capability('local/beacon:viewmine', $syscontext);
    if (!$canview && !$canmine) {
        return;
    }

    // Which link this person gets: staff → library; learner-only → My reports.
    if ($canview) {
        $label = get_string('pluginname', 'local_beacon');
        $url = new moodle_url('/local/beacon/index.php');
        $key = 'localbeacon';
    } else {
        $label = get_string('myreports', 'local_beacon');
        $url = new moodle_url('/local/beacon/myreports.php');
        $key = 'localbeaconmine';
    }
    $icon = new pix_icon('icon', '', 'local_beacon');

    // Chosen placements (default: main navigation).
    $raw = get_config('local_beacon', 'navplacement');
    $places = ($raw === false || $raw === null || $raw === '') ? ['nav'] : explode(',', $raw);

    $addto = function(string $parentkey) use ($navigation, $label, $url, $key, $icon) {
        $parent = $navigation->find($parentkey, null);
        if ($parent) {
            $parent->add($label, $url, navigation_node::TYPE_CUSTOM, null, $key . '_' . $parentkey, $icon);
        }
    };

    if (in_array('nav', $places, true)) {
        $navigation->add($label, $url, navigation_node::TYPE_CUSTOM, null, $key, $icon);
    }
    if (in_array('home', $places, true)) {
        $addto('home');
    }
    if (in_array('dashboard', $places, true)) {
        $addto('myhome');
    }
    if (in_array('mycourses', $places, true)) {
        $addto('mycourses');
    }
}

/**
 * Add a course-scoped "Course reports" link to a course's navigation, so
 * teachers and managers can open Beacon's reports filtered to that course.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 * @return void
 */
function local_beacon_extend_navigation_course(navigation_node $navigation,
        stdClass $course, context_course $context): void {
    if (!has_capability('local/beacon:view', $context)) {
        return;
    }
    $navigation->add(
        get_string('coursereports', 'local_beacon'),
        new moodle_url('/local/beacon/index.php', ['contextid' => $context->id]),
        navigation_node::TYPE_SETTING,
        null,
        'localbeaconcourse',
        new pix_icon('icon', '', 'local_beacon')
    );
}
