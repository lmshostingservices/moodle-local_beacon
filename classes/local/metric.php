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
 * A single metric: a stat card or a KPI gauge.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * A metric is one headline number.
 *
 * Unlike the previous generation of this plugin, a metric owns exactly one
 * hand-written, self-contained SQL statement. Nothing is assembled from
 * fragments, so there is nothing to assemble wrong. Every query follows the
 * patterns proven correct against real Moodle data (distinct-enrolment
 * anchoring, deleted-user exclusion, completion-tracking denominators).
 */
class metric {
    /** @var string Machine id. */
    public string $id;
    /** @var string 'stat' or 'kpi'. */
    public string $kind;
    /** @var string Family key for colour and grouping. */
    public string $family;
    /** @var string Icon key. */
    public string $icon;
    /** @var string Format: number, percent, decimal, duration. */
    public string $format;
    /** @var string better: higher, lower, neutral. */
    public string $better;
    /** @var bool Whether the metric is on by default. */
    public bool $defaulton;
    /** @var float|null KPI target (percentage). */
    public ?float $target;
    /** @var float|null KPI amber threshold. */
    public ?float $amber;
    /** @var float|null KPI green threshold. */
    public ?float $green;
    /** @var callable Returns [value|null, denominator|null] given (moodle_database, context). */
    private $computefn;
    /** @var string|null Table this metric depends on; null = always available. */
    public ?string $requirestable;

    /**
     * Build a metric from a definition array.
     *
     * @param array $d Definition.
     */
    public function __construct(array $d) {
        $this->id            = $d['id'];
        $this->kind          = $d['kind'];
        $this->family        = $d['family'];
        $this->icon          = $d['icon'] ?? 'chart';
        $this->format        = $d['format'] ?? 'number';
        $this->better        = $d['better'] ?? 'higher';
        $this->defaulton     = $d['defaulton'] ?? true;
        $this->target        = $d['target'] ?? null;
        $this->amber         = $d['amber'] ?? null;
        $this->green         = $d['green'] ?? null;
        $this->computefn     = $d['compute'];
        $this->requirestable = $d['requirestable'] ?? null;
    }

    /**
     * Is this metric a KPI (has a target)?
     *
     * @return bool
     */
    public function is_kpi(): bool {
        return $this->kind === 'kpi';
    }

    /**
     * Does this site have the table this metric needs?
     *
     * @return bool
     */
    public function is_available(): bool {
        global $DB;
        if ($this->requirestable === null) {
            return true;
        }
        return $DB->get_manager()->table_exists($this->requirestable);
    }

    /**
     * Display name.
     *
     * @return string
     */
    public function name(): string {
        return get_string('m_' . $this->id, 'local_beacon');
    }

    /**
     * The short note under the figure.
     *
     * @return string
     */
    public function note(): string {
        return get_string('m_' . $this->id . '_note', 'local_beacon');
    }

    /**
     * Plain-English explanation, shown in the setup checklist.
     *
     * @return string
     */
    public function explanation(): string {
        return get_string('m_' . $this->id . '_expl', 'local_beacon');
    }

    /**
     * Compute the current value.
     *
     * Returns value and (for rates) the denominator so callers can detect a
     * "no data to measure" situation and show a friendly state instead of a
     * misleading zero.
     *
     * @param \context $context Context.
     * @return array{value: float|null, denominator: float|null}
     */
    public function compute(\context $context): array {
        global $DB;
        try {
            [$value, $denominator] = ($this->computefn)($DB, $context);
        } catch (\Throwable $e) {
            debugging('Beacon metric ' . $this->id . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['value' => null, 'denominator' => null];
        }
        return [
            'value'       => $value === null ? null : (float) $value,
            'denominator' => $denominator === null ? null : (float) $denominator,
        ];
    }

    /**
     * The status band for a KPI value: on, near or off target.
     *
     * @param float $value The measured value.
     * @return string
     */
    public function status(float $value): string {
        if (!$this->is_kpi()) {
            return 'none';
        }
        if ($this->better === 'lower') {
            if ($value <= $this->amber) {
                return 'on';
            }
            return $value <= $this->green ? 'near' : 'off';
        }
        if ($value >= $this->green) {
            return 'on';
        }
        return $value >= $this->amber ? 'near' : 'off';
    }
}
