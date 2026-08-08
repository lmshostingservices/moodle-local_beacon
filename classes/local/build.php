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
 * Turns catalogue objects into template-ready arrays.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * View-model builder shared by the library and detail pages, so a card looks
 * identical wherever it appears.
 */
class build {
    /** @var int Gauge radius. */
    private const G_R = 72;
    /** @var int Gauge centre X. */
    private const G_CX = 86;
    /** @var int Gauge centre Y. */
    private const G_CY = 90;

    /**
     * The URL of a metric or report's own detail page.
     *
     * @param int $contextid Context id.
     * @param string $type stat, kpi or report.
     * @param string $id Item id.
     * @return string
     */
    public static function detail_url(int $contextid, string $type, string $id): string {
        return (new \moodle_url(
            '/local/beacon/view.php',
            ['contextid' => $contextid, 'type' => $type, 'id' => $id]
        ))->out(false);
    }

    /**
     * Build the context for a stat card.
     *
     * @param metric $m Metric.
     * @param int $contextid Context id.
     * @param \context $context Context.
     * @return array
     */
    public static function stat(metric $m, int $contextid, \context $context): array {
        $r = metric_cache::get($m, $context);
        $value = $r['value'];
        $series = present::series($m->id, $contextid);
        $previous = present::previous($series);

        $delta = null;
        $deltadir = 'flat';
        if ($value !== null && $previous !== null && $previous != 0.0) {
            $pct = (($value - $previous) / abs($previous)) * 100;
            $delta = number_format(abs($pct), 1);
            $rising = $pct > 0.05;
            $falling = $pct < -0.05;
            if ($rising || $falling) {
                $healthy = $rising === ($m->better === 'higher');
                $deltadir = $healthy ? 'up' : 'down';
                // Arrow points in the direction of change, colour reflects health.
                $delta = ['pct' => number_format(abs($pct), 1), 'rising' => $rising,
                          'good' => $healthy];
            } else {
                $delta = null;
            }
        }

        return [
            'id'        => $m->id,
            'name'      => $m->name(),
            'note'      => $m->note(),
            'family'    => $m->family,
            'familylabel' => get_string('family_' . $m->family, 'local_beacon'),
            'icon'      => icons::svg($m->icon),
            'value'     => present::value($value, $m->format),
            'rawvalue'  => $value === null ? 0 : $value,
            'unit'      => present::unit($m->format),
            'hasvalue'  => $value !== null,
            'hasdelta'  => is_array($delta),
            'delta'     => is_array($delta) ? $delta['pct'] : null,
            'deltarising' => is_array($delta) ? $delta['rising'] : false,
            'deltagood' => is_array($delta) ? $delta['good'] : false,
            'hasspark'  => count($series) >= 2,
            'spark'     => count($series) >= 2 ? self::spark_path($series) : null,
            'url'       => self::detail_url($contextid, 'stat', $m->id),
        ];
    }

    /**
     * Build the context for a KPI gauge.
     *
     * @param metric $m Metric.
     * @param int $contextid Context id.
     * @param \context $context Context.
     * @return array
     */
    public static function kpi(metric $m, int $contextid, \context $context): array {
        $r = metric_cache::get($m, $context);
        $value = $r['value'];
        $nodata = $value === null;
        $display = $nodata ? 0.0 : $value;

        $status = $nodata ? 'none' : $m->status($display);
        $geo = self::gauge_geometry($display, (float) $m->target);

        return [
            'id'          => $m->id,
            'name'        => $m->name(),
            'desc'        => $m->note(),
            'family'      => $m->family,
            'familylabel' => get_string('family_' . $m->family, 'local_beacon'),
            'value'       => present::value($value, $m->format),
            'rawvalue'    => $nodata ? 0 : $value,
            'unit'        => '%',
            'target'      => (int) $m->target,
            'nodata'      => $nodata,
            'status'      => $status,
            'statuslabel' => get_string('status_' . ($status === 'none' ? 'nodata' : $status), 'local_beacon'),
            'arclen'      => $geo['len'],
            'arcoffset'   => $geo['offset'],
            'targetline'  => $geo['target'],
            'url'         => self::detail_url($contextid, 'kpi', $m->id),
        ];
    }

    /**
     * Build the context for a report card.
     *
     * @param report $rep Report.
     * @param int $contextid Context id.
     * @return array
     */
    public static function report_card(report $rep, int $contextid): array {
        return [
            'id'          => $rep->id,
            'name'        => $rep->name(),
            'desc'        => $rep->description(),
            'family'      => $rep->family,
            'familylabel' => get_string('family_' . $rep->family, 'local_beacon'),
            'icon'        => icons::svg($rep->icon),
            'grain'       => $rep->grainlabel(),
            'url'         => self::detail_url($contextid, 'report', $rep->id),
        ];
    }

    /**
     * Gauge arc geometry for the mustache template.
     *
     * @param float $value Value 0-100.
     * @param float $target Target 0-100.
     * @return array{len:float,offset:float,target:array}
     */
    public static function gauge_geometry(float $value, float $target): array {
        $len = M_PI * self::G_R;
        $frac = max(0.0, min(1.0, $value / 100));
        $offset = $len * (1 - $frac);

        $tang = M_PI * (1 - max(0.0, min(1.0, $target / 100)));
        $tx = self::G_CX + self::G_R * cos($tang);
        $ty = self::G_CY - self::G_R * sin($tang);
        $tx2 = self::G_CX + (self::G_R + 11) * cos($tang);
        $ty2 = self::G_CY - (self::G_R + 11) * sin($tang);

        return [
            'len'    => round($len, 2),
            'offset' => round($offset, 2),
            'target' => [
                'x1' => round($tx, 1), 'y1' => round($ty, 1),
                'x2' => round($tx2, 1), 'y2' => round($ty2, 1),
            ],
        ];
    }

    /**
     * An SVG path for a sparkline over a 78x30 box.
     *
     * @param float[] $series Values, oldest first.
     * @return array{line:string,area:string}
     */
    public static function spark_path(array $series): array {
        $w = 78;
        $h = 30;
        $min = min($series);
        $max = max($series);
        $range = ($max - $min) ?: 1;
        $n = count($series);
        $pts = [];
        foreach ($series as $i => $v) {
            $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
            $y = $h - (($v - $min) / $range) * ($h - 4) - 2;
            $pts[] = round($x, 1) . ' ' . round($y, 1);
        }
        $line = 'M' . implode(' L ', $pts);
        $area = $line . " L $w $h L 0 $h Z";
        return ['line' => $line, 'area' => $area];
    }
}
