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
 * Upgrade steps.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_beacon_upgrade(int $oldversion): bool {
    global $DB, $CFG;
    $dbman = $DB->get_manager();

    // 1.0.0 is a complete rebuild. The 0.2.x generation shipped a recipe/report
    // builder with its own tables; this release replaces that model entirely.
    // Retire the obsolete tables and install the two this version needs.
    if ($oldversion < 2026073000001) {

        $obsolete = [
            'local_beacon_report', 'local_beacon_view', 'local_beacon_element',
            'local_beacon_element_role', 'local_beacon_schedule', 'local_beacon_recipient',
            'local_beacon_stat_cache', 'local_beacon_run', 'local_beacon_threshold',
            'local_beacon_recipe',
        ];
        foreach ($obsolete as $tablename) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }

        // Create the current tables if a prior install did not have them.
        $xmlfile = $CFG->dirroot . '/local/beacon/db/install.xml';
        foreach (['local_beacon_request', 'local_beacon_snapshot'] as $tablename) {
            if (!$dbman->table_exists(new xmldb_table($tablename))) {
                $dbman->install_one_table_from_xmldb_file($xmlfile, $tablename);
            }
        }

        upgrade_plugin_savepoint(true, 2026073000001, 'local', 'beacon');
    }

    // 1.1.0 — performance: precomputed metric cache so the library never
    // computes on page load.
    if ($oldversion < 2026073000002) {
        if (!$dbman->table_exists(new xmldb_table('local_beacon_metric_cache'))) {
            $dbman->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/local/beacon/db/install.xml', 'local_beacon_metric_cache');
        }
        upgrade_plugin_savepoint(true, 2026073000002, 'local', 'beacon');
    }

    // 1.1.2 — all 26 reports (and every stat/KPI) are now on by default. Enable
    // the full set on existing sites so nothing curated at install stays hidden;
    // admins can still untick any of them under "Choose what shows".
    if ($oldversion < 2026073000004) {
        $stats = [];
        $kpis = [];
        foreach (\local_beacon\local\catalogue::metrics() as $m) {
            if ($m->kind === 'kpi') {
                $kpis[] = $m->id;
            } else {
                $stats[] = $m->id;
            }
        }
        $reports = [];
        foreach (\local_beacon\local\catalogue::reports() as $r) {
            $reports[] = $r->id;
        }
        set_config('enabledstats', implode(',', $stats), 'local_beacon');
        set_config('enabledkpis', implode(',', $kpis), 'local_beacon');
        set_config('enabledreports', implode(',', $reports), 'local_beacon');

        upgrade_plugin_savepoint(true, 2026073000004, 'local', 'beacon');
    }

    // 1.5.0 — Saved views and scheduled email delivery each get a table.
    if ($oldversion < 2026073000008) {
        $xmlfile = $CFG->dirroot . '/local/beacon/db/install.xml';
        foreach (['local_beacon_savedview', 'local_beacon_delivery'] as $tablename) {
            if (!$dbman->table_exists(new xmldb_table($tablename))) {
                $dbman->install_one_table_from_xmldb_file($xmlfile, $tablename);
            }
        }
        upgrade_plugin_savepoint(true, 2026073000008, 'local', 'beacon');
    }

    if ($oldversion < 2026073100011) {
        // v1.6.2: Maintenance version bump. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026073100011, 'local', 'beacon');
    }

    if ($oldversion < 2026073100012) {
        // v1.6.3: setup.php renamed to configure.php to bypass host firewall block.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026073100012, 'local', 'beacon');
    }

    if ($oldversion < 2026073100013) {
        // v1.6.4: Version bump to force Moodle upgrade detection after v1.6.3
        // savepoint was missed on sites where 2026073100012 was already recorded.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026073100013, 'local', 'beacon');
    }

    if ($oldversion < 2026073100014) {
        // v1.6.5: Plugin-wide hover / selected-state contrast firewall.
        // No DB schema changes.
        upgrade_plugin_savepoint(true, 2026073100014, 'local', 'beacon');
    }

    return true;
}
