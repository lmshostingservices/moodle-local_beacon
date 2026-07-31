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
 * Reads which catalogue items the admin has switched on.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * The admin's choices, resolved against what the site can actually run.
 */
class config {

    /**
     * The set of ids selected for a config key.
     *
     * A never-saved setting returns null, which callers read as "use defaults".
     *
     * @param string $name Config key: enabledstats, enabledkpis, enabledreports.
     * @return array|null List of ids, or null if never configured.
     */
    private static function selected(string $name): ?array {
        $raw = get_config('local_beacon', $name);
        if ($raw === false || $raw === null) {
            return null;
        }
        if ($raw === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Enabled stat cards, in catalogue order, available on this site.
     *
     * @return metric[]
     */
    public static function stats(): array {
        return self::filter_metrics('stat', 'enabledstats');
    }

    /**
     * Enabled KPI gauges, in catalogue order, available on this site.
     *
     * @return metric[]
     */
    public static function kpis(): array {
        return self::filter_metrics('kpi', 'enabledkpis');
    }

    /**
     * Enabled reports, in catalogue order, available on this site.
     *
     * @return report[]
     */
    public static function reports(): array {
        $selected = self::selected('enabledreports');
        $out = [];
        foreach (catalogue::reports() as $r) {
            if (!$r->is_available()) {
                continue;
            }
            $on = $selected === null ? $r->defaulton : in_array($r->id, $selected, true);
            if ($on) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Filter metrics by kind and enabled config.
     *
     * @param string $kind stat or kpi.
     * @param string $name Config key.
     * @return metric[]
     */
    private static function filter_metrics(string $kind, string $name): array {
        $selected = self::selected($name);
        $out = [];
        foreach (catalogue::metrics() as $m) {
            if ($m->kind !== $kind || !$m->is_available()) {
                continue;
            }
            $on = $selected === null ? $m->defaulton : in_array($m->id, $selected, true);
            if ($on) {
                $out[] = $m;
            }
        }
        return $out;
    }
}
