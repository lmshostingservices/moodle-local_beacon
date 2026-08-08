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
 * Install-time defaults.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Pre-tick the default set of cards, gauges and reports so the library is
 * populated the first time an admin opens it, before they visit setup.
 *
 * @return bool
 */
function xmldb_local_beacon_install(): bool {
    $stats = [];
    $kpis = [];
    foreach (\local_beacon\local\catalogue::metrics() as $m) {
        if (!$m->defaulton) {
            continue;
        }
        if ($m->kind === 'kpi') {
            $kpis[] = $m->id;
        } else {
            $stats[] = $m->id;
        }
    }
    $reports = [];
    foreach (\local_beacon\local\catalogue::reports() as $r) {
        if ($r->defaulton) {
            $reports[] = $r->id;
        }
    }

    set_config('enabledstats', implode(',', $stats), 'local_beacon');
    set_config('enabledkpis', implode(',', $kpis), 'local_beacon');
    set_config('enabledreports', implode(',', $reports), 'local_beacon');

    return true;
}
