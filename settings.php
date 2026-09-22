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

    // Marking-queue heading.
    $settings->add(new admin_setting_heading(
        'local_beacon/markingheading',
        get_string('markingheading', 'local_beacon'),
        get_string('markingheading_desc', 'local_beacon')
    ));

    // Send the daily per-trainer marking digest email.
    $settings->add(new admin_setting_configcheckbox(
        'local_beacon/markingdigest',
        get_string('markingdigest', 'local_beacon'),
        get_string('markingdigest_desc', 'local_beacon'),
        1
    ));

    // Teacher roles: which of the site's real roles Beacon treats as teachers,
    // so custom roles (Trainer, Assessor, …) scope correctly — not just the
    // built-in archetypes.
    $settings->add(new admin_setting_heading(
        'local_beacon/teacherroles_heading',
        get_string('teacherroles_heading', 'local_beacon'),
        get_string('teacherroles_heading_desc', 'local_beacon')
    ));
    if ($ADMIN->fulltree) {
        $rolemenu = \local_beacon\local\roles::menu();
        $settings->add(new admin_setting_configmultiselect(
            'local_beacon/teacherroles_course',
            get_string('teacherroles_course', 'local_beacon'),
            get_string('teacherroles_course_desc', 'local_beacon'),
            \local_beacon\local\roles::defaults('course'),
            $rolemenu
        ));
        $settings->add(new admin_setting_configmultiselect(
            'local_beacon/teacherroles_group',
            get_string('teacherroles_group', 'local_beacon'),
            get_string('teacherroles_group_desc', 'local_beacon'),
            \local_beacon\local\roles::defaults('group'),
            $rolemenu
        ));
    }

    $ADMIN->add('localplugins', $settings);

    // KPI targets: let the admin retune each gauge's cut-offs without touching code.
    $kpipage = new admin_settingpage(
        'local_beacon_kpitargets',
        get_string('kpitargets_menu', 'local_beacon')
    );
    if ($ADMIN->fulltree) {
        $kpipage->add(new admin_setting_heading(
            'local_beacon/kpitargets_intro',
            '',
            get_string('kpitargets_intro', 'local_beacon')
        ));
        foreach (\local_beacon\local\catalogue::metrics() as $m) {
            if (!$m->is_kpi()) {
                continue;
            }
            $dirkey = ($m->better === 'lower') ? 'kpi_dir_lower' : 'kpi_dir_higher';
            $kpipage->add(new admin_setting_heading(
                'local_beacon/kpihead_' . $m->id,
                get_string('m_' . $m->id, 'local_beacon'),
                get_string($dirkey, 'local_beacon')
            ));
            $kpipage->add(new admin_setting_configtext(
                'local_beacon/kpi_' . $m->id . '_target',
                get_string('kpi_target', 'local_beacon'),
                get_string('kpi_target_desc', 'local_beacon'),
                (string) $m->targetdefault,
                PARAM_INT,
                5
            ));
            $kpipage->add(new admin_setting_configtext(
                'local_beacon/kpi_' . $m->id . '_green',
                get_string('kpi_green', 'local_beacon'),
                get_string('kpi_green_desc', 'local_beacon'),
                (string) $m->greendefault,
                PARAM_INT,
                5
            ));
            $kpipage->add(new admin_setting_configtext(
                'local_beacon/kpi_' . $m->id . '_amber',
                get_string('kpi_amber', 'local_beacon'),
                get_string('kpi_amber_desc', 'local_beacon'),
                (string) $m->amberdefault,
                PARAM_INT,
                5
            ));
        }
    }
    $ADMIN->add('localplugins', $kpipage);
}
