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
 * Precomputed metric values, so the library never computes on page load.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Read-through cache for stat and KPI values.
 *
 * A scheduled task warms every enabled metric into `local_beacon_metric_cache`.
 * Page loads then read one indexed row per card instead of running a query, so
 * the dashboard opens in the same time on a site with 200 users or 200,000.
 * If a value is not yet cached (fresh install, before the task first runs) it
 * is computed once, stored, and served — so the plugin works immediately and
 * heals itself into the fast path.
 */
class metric_cache {
    /** @var array<int,array<string,array>> In-request memo, keyed by contextid then metric id. */
    private static array $mem = [];

    /**
     * Load every cached row for a context in a single query.
     *
     * The library calls this once before rendering, turning N single-row reads
     * into one, so a dashboard of 16 cards costs one indexed query.
     *
     * @param int $contextid Context id.
     * @return void
     */
    public static function prefetch(int $contextid): void {
        global $DB;
        if (isset(self::$mem[$contextid])) {
            return;
        }
        self::$mem[$contextid] = [];
        try {
            $rows = $DB->get_records('local_beacon_metric_cache', ['contextid' => $contextid]);
        } catch (\dml_exception $e) {
            return;
        }
        foreach ($rows as $row) {
            self::$mem[$contextid][$row->metric] = [
                'value'       => $row->hasvalue ? (float) $row->value : null,
                'denominator' => $row->denominator === null ? null : (float) $row->denominator,
            ];
        }
    }

    /**
     * The current value of a metric, from cache where possible.
     *
     * @param metric $m Metric.
     * @param \context $context Context.
     * @return array{value: float|null, denominator: float|null}
     */
    public static function get(metric $m, \context $context): array {
        global $DB;
        $contextid = $context->id;

        // In-request memo (populated by prefetch or a prior get).
        if (isset(self::$mem[$contextid][$m->id])) {
            return self::$mem[$contextid][$m->id];
        }

        // If prefetch ran and the metric is absent, it is genuinely uncached.
        if (!isset(self::$mem[$contextid])) {
            try {
                $row = $DB->get_record(
                    'local_beacon_metric_cache',
                    ['metric' => $m->id, 'contextid' => $contextid]
                );
            } catch (\dml_exception $e) {
                return $m->compute($context);
            }
            if ($row) {
                $result = [
                    'value'       => $row->hasvalue ? (float) $row->value : null,
                    'denominator' => $row->denominator === null ? null : (float) $row->denominator,
                ];
                self::$mem[$contextid][$m->id] = $result;
                return $result;
            }
        }

        // Cold cache: compute once, store, serve.
        $result = $m->compute($context);
        self::store($m->id, $contextid, $result['value'], $result['denominator']);
        self::$mem[$contextid][$m->id] = $result;
        return $result;
    }

    /**
     * The cached value of a metric WITHOUT ever computing it live.
     *
     * Returns null when the value has not been warmed yet. Used by pages that
     * must stay cheap on large sites (e.g. the setup checklist), where computing
     * every metric on the request would risk a timeout.
     *
     * @param metric $m Metric.
     * @param \context $context Context.
     * @return array{value: float|null, denominator: float|null}|null
     */
    public static function peek(metric $m, \context $context): ?array {
        $contextid = $context->id;
        if (isset(self::$mem[$contextid][$m->id])) {
            return self::$mem[$contextid][$m->id];
        }
        global $DB;
        try {
            $row = $DB->get_record(
                'local_beacon_metric_cache',
                ['metric' => $m->id, 'contextid' => $contextid]
            );
        } catch (\dml_exception $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return [
            'value'       => $row->hasvalue ? (float) $row->value : null,
            'denominator' => $row->denominator === null ? null : (float) $row->denominator,
        ];
    }

    /**
     * When the cache for a metric was last computed (0 if never).
     *
     * @param string $metricid Metric id.
     * @param int $contextid Context id.
     * @return int
     */
    public static function computed_at(string $metricid, int $contextid): int {
        global $DB;
        try {
            return (int) $DB->get_field(
                'local_beacon_metric_cache',
                'timecomputed',
                ['metric' => $metricid, 'contextid' => $contextid]
            );
        } catch (\dml_exception $e) {
            return 0;
        }
    }

    /**
     * Store (upsert) a computed value.
     *
     * @param string $metricid Metric id.
     * @param int $contextid Context id.
     * @param float|null $value Value.
     * @param float|null $denominator Denominator.
     * @return void
     */
    public static function store(string $metricid, int $contextid, ?float $value, ?float $denominator): void {
        global $DB;

        $record = (object) [
            'metric'       => $metricid,
            'contextid'    => $contextid,
            'value'        => $value,
            'denominator'  => $denominator,
            'hasvalue'     => $value === null ? 0 : 1,
            'timecomputed' => time(),
        ];

        try {
            $existing = $DB->get_record(
                'local_beacon_metric_cache',
                ['metric' => $metricid, 'contextid' => $contextid],
                'id'
            );
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('local_beacon_metric_cache', $record);
            } else {
                $DB->insert_record('local_beacon_metric_cache', $record);
            }
        } catch (\dml_exception $e) {
            debugging('Beacon metric cache write failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Recompute and store every enabled metric for a context.
     *
     * Called by the scheduled task. Also returns the values so the caller can
     * fold them into the daily trend snapshot without computing twice.
     *
     * @param \context $context Context.
     * @return array<string,array{value: float|null, denominator: float|null}>
     */
    public static function refresh_all(\context $context): array {
        $out = [];
        foreach (array_merge(config::stats(), config::kpis()) as $m) {
            $result = $m->compute($context);
            self::store($m->id, $context->id, $result['value'], $result['denominator']);
            $out[$m->id] = $result;
        }
        return $out;
    }
}
