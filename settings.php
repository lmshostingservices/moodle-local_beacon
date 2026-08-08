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
 * Administration links.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // The reports page itself, under Site administration > Reports.
    $ADMIN->add('reports', new admin_externalpage(
        'local_beacon_reports',
        get_string('pluginname', 'local_beacon'),
        new moodle_url('/local/beacon/index.php'),
        'local/beacon:view'
    ));

    // The "Set up your library" checklist, under Plugins > Local plugins.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_beacon_setup',
        get_string('setup_menu', 'local_beacon'),
        new moodle_url('/local/beacon/configure.php'),
        'moodle/site:config'
    ));

    // Settings: where the Beacon link appears in navigation.
    $settings = new admin_settingpage(
        'local_beacon_settings',
        get_string('settings_menu', 'local_beacon')
    );
    $settings->add(new admin_setting_configmulticheckbox(
        'local_beacon/navplacement',
        get_string('navplacement', 'local_beacon'),
        get_string('navplacement_desc', 'local_beacon'),
        ['nav' => 1],
        [
            'nav'       => get_string('nav_main', 'local_beacon'),
            'home'      => get_string('nav_home', 'local_beacon'),
            'dashboard' => get_string('nav_dashboard', 'local_beacon'),
            'mycourses' => get_string('nav_mycourses', 'local_beacon'),
        ]
    ));
    $ADMIN->add('localplugins', $settings);
}
