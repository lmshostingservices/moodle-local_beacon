<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_beacon\hook;

/**
 * Lets other plugins add their own stat cards, KPIs and reports to Beacon.
 *
 * A listener calls add_metric() and add_report() with the same definition arrays Beacon uses for
 * its own catalogue, plus 'component' (the plugin whose language file holds the strings):
 * 'm_<id>', 'm_<id>_note' and 'm_<id>_expl' for a metric; 'r_<id>' and 'r_<id>_desc' for a report;
 * and each column's label key (with an optional '<key>_help'). Ids must start with the plugin
 * name and an underscore, e.g. 'fastgrade_turnaround', so they never clash with Beacon's own.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Add metrics and reports to the Beacon catalogue')]
#[\core\attribute\tags('local_beacon', 'reports')]
final class collect_catalogue {
    /** @var array metric definitions */
    private array $metrics = [];
    /** @var array report definitions */
    private array $reports = [];

    /**
     * Add a stat card or KPI.
     *
     * @param array $def definition (id, kind, family, icon, format, better, compute, component, ...)
     */
    public function add_metric(array $def): void {
        if ($this->valid($def, ['id', 'kind', 'family', 'compute', 'component'])) {
            $this->metrics[$def['id']] = $def;
        }
    }

    /**
     * Add a report.
     *
     * @param array $def definition (id, family, grain, columns, run, component, ...)
     */
    public function add_report(array $def): void {
        if ($this->valid($def, ['id', 'family', 'grain', 'columns', 'run', 'component'])) {
            $this->reports[$def['id']] = $def;
        }
    }

    /**
     * Definitions collected.
     *
     * @return array
     */
    public function get_metrics(): array {
        return $this->metrics;
    }

    /**
     * Definitions collected.
     *
     * @return array
     */
    public function get_reports(): array {
        return $this->reports;
    }

    /**
     * Check a definition has what Beacon needs and a namespaced id.
     *
     * @param array $def
     * @param array $required
     * @return bool
     */
    private function valid(array $def, array $required): bool {
        foreach ($required as $key) {
            if (!array_key_exists($key, $def)) {
                debugging("Beacon: catalogue item is missing '$key'", DEBUG_DEVELOPER);
                return false;
            }
        }
        [, $name] = array_pad(explode('_', (string)$def['component'], 2), 2, '');
        if ($name === '' || strpos((string)$def['id'], $name . '_') !== 0) {
            debugging("Beacon: catalogue id '{$def['id']}' must start with '{$name}_'", DEBUG_DEVELOPER);
            return false;
        }
        return true;
    }
}
