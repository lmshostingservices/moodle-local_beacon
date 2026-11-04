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
 * Report Studio — deterministic chart recommendation.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local\studio;

/**
 * Picks the right chart for a result by rules, not by guesswork. The choice is a
 * pure function of the data shape — the dimension type (temporal vs categorical),
 * the measure (count vs rate) and the number of categories — following the
 * expressiveness/effectiveness logic behind Tableau's "Show Me" and Mackinlay's
 * APT. Includes the when-NOT-to-chart guards an AoI image-guesser lacks.
 */
class viz {

    /** @var string[] Beacon's categorical palette, magenta-led. */
    private const PALETTE = ['#c3037c', '#7f10f9', '#0176ee', '#12b886', '#fe8500',
                             '#e8368f', '#9775fa', '#4dabf7', '#38d9a9', '#ffa94d'];

    /**
     * Recommend a chart for the given result, or null when a chart would not help
     * (no variation, a single point, or too many slices with no measure to rank).
     *
     * @param array $dim The dimension definition.
     * @param array $measure The measure definition.
     * @param string[] $labels Category/period labels.
     * @param float[] $values Measure values, aligned to labels.
     * @return \stdClass|null {type, chart:\core\chart_base, title}
     */
    public static function recommend(array $dim, array $measure, array $labels, array $values): ?\stdClass {
        $n = count($values);
        // Guard: nothing worth drawing.
        if ($n < 2) {
            return null;
        }
        $nonzero = array_filter($values, fn($v) => (float) $v != 0.0);
        if (!$nonzero) {
            return null;
        }

        $ispercent = $measure['format'] === 'percent';
        $type = self::choose_type($dim['type'], $ispercent, $n);

        switch ($type) {
            case 'line':
                $chart = new \core\chart_line();
                $chart->set_smooth(true);
                break;
            case 'doughnut':
                $chart = new \core\chart_pie();
                $chart->set_doughnut(true);
                break;
            case 'hbar':
                $chart = new \core\chart_bar();
                $chart->set_horizontal(true);
                break;
            default:
                $chart = new \core\chart_bar();
        }

        $series = new \core\chart_series($measure['label'], array_values($values));
        if ($type === 'doughnut') {
            $series->set_colors(array_slice(self::cycle($n), 0, $n));
        } else {
            $series->set_color(self::PALETTE[0]);
        }
        $chart->add_series($series);
        $chart->set_labels(array_values($labels));

        if ($type !== 'doughnut') {
            $axis = $type === 'hbar' ? $chart->get_xaxis(0, true) : $chart->get_yaxis(0, true);
            $axis->set_min(0);
            if ($ispercent) {
                $axis->set_max(100);
                $axis->set_label('%');
            }
        }
        $chart->set_legend_options(['display' => $type === 'doughnut']);

        $out = new \stdClass();
        $out->type = $type;
        $out->chart = $chart;
        $out->title = $measure['label'] . ' ' . get_string('studio_by', 'local_beacon') . ' '
                    . \core_text::strtolower($dim['label']);
        return $out;
    }

    /**
     * The decision table: data shape → chart type.
     *
     *  - temporal dimension                     → line (show the trend)
     *  - categorical + a rate (percent) measure → bar  (never a pie of rates)
     *  - categorical + a count, ≤ 6 slices      → doughnut (part-to-whole)
     *  - categorical + a count, more slices      → bar (or horizontal when many)
     *
     * @param string $dimtype 'temporal'|'category'|'ordinal'
     * @param bool $ispercent
     * @param int $n Number of categories.
     * @return string 'line'|'bar'|'hbar'|'doughnut'
     */
    private static function choose_type(string $dimtype, bool $ispercent, int $n): string {
        if ($dimtype === 'temporal') {
            return 'line';
        }
        if ($ispercent) {
            return $n > 8 ? 'hbar' : 'bar';
        }
        if ($n <= 6) {
            return 'doughnut';
        }
        return $n > 10 ? 'hbar' : 'bar';
    }

    /**
     * Palette cycled to at least n colours.
     *
     * @param int $n
     * @return string[]
     */
    private static function cycle(int $n): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::PALETTE[$i % count(self::PALETTE)];
        }
        return $out;
    }
}
