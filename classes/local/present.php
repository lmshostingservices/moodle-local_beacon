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
 * Display helpers: value formatting and trend history.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Formatting and trend lookups, kept out of the query and output layers.
 */
class present {
    /**
     * The unit suffix for a format.
     *
     * @param string $format Format key.
     * @return string
     */
    public static function unit(string $format): string {
        return ($format === 'percent' || $format === 'percent1') ? '%' : '';
    }

    /**
     * A raw value rendered for display (no unit).
     *
     * @param float|null $value Value.
     * @param string $format Format key.
     * @return string
     */
    public static function value(?float $value, string $format): string {
        if ($value === null) {
            return '—';
        }
        switch ($format) {
            case 'percent1':
                return number_format($value, 1);
            case 'percent':
                return (string) round($value);
            case 'decimal':
                return number_format($value, 1);
            default:
                return number_format($value);
        }
    }

    /**
     * The daily trend series for a metric, oldest first.
     *
     * @param string $metricid Metric id.
     * @param int $contextid Context id.
     * @param int $points Maximum points to return.
     * @return float[]
     */
    public static function series(string $metricid, int $contextid, int $points = 14): array {
        global $DB;
        try {
            $rows = $DB->get_records(
                'local_beacon_snapshot',
                ['metric' => $metricid, 'contextid' => $contextid],
                'daykey ASC',
                'id, value',
                0,
                200
            );
        } catch (\dml_exception $e) {
            return [];
        }
        $values = array_values(array_map(fn($r) => (float) $r->value, $rows));
        if (count($values) > $points) {
            $values = array_slice($values, -$points);
        }
        return $values;
    }

    /**
     * The value one step back in the trend, for a delta. Null if no history.
     *
     * @param float[] $series The series.
     * @return float|null
     */
    public static function previous(array $series): ?float {
        $n = count($series);
        return $n >= 2 ? $series[$n - 2] : null;
    }

    /**
     * The snapshot value as of (or most recently before) N days ago, powering
     * the KPI "this period vs last" comparison. Null if there's no such history.
     *
     * @param string $metricid Metric id.
     * @param int $contextid Context id.
     * @param int $daysago How many days back the comparison point sits.
     * @return float|null
     */
    public static function asof(string $metricid, int $contextid, int $daysago): ?float {
        global $DB;
        $target = (int) userdate(time() - $daysago * DAYSECS, '%Y%m%d');
        try {
            $rows = $DB->get_records_select(
                'local_beacon_snapshot',
                'metric = :m AND contextid = :c AND daykey <= :d',
                ['m' => $metricid, 'c' => $contextid, 'd' => $target],
                'daykey DESC',
                'id, value',
                0,
                1
            );
        } catch (\dml_exception $e) {
            return null;
        }
        $row = reset($rows);
        return $row ? (float) $row->value : null;
    }
}
