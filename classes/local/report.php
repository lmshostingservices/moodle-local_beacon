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
 * A ready-made report: a named, self-contained table.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * A report owns one hand-written query and the columns it returns.
 */
class report {

    /** @var string Machine id. */
    public string $id;
    /** @var string Family key. */
    public string $family;
    /** @var string Icon key. */
    public string $icon;
    /** @var string Grain description string key suffix. */
    public string $grain;
    /** @var bool Default on. */
    public bool $defaulton;
    /** @var array Column definitions: list of [key, label, type]. */
    public array $columns;
    /** @var callable Returns [rows[], total] given (moodle_database, filterset, limit). */
    private $runfn;
    /** @var string|null Required table. */
    public ?string $requirestable;
    /** @var string[] Filter types this report offers, in display order. */
    public array $filters;
    /** @var string|null Column-label string key the date range applies to. */
    public ?string $datelabel;

    /**
     * Constructor.
     *
     * @param array $d Definition.
     */
    public function __construct(array $d) {
        $this->id            = $d['id'];
        $this->family        = $d['family'];
        $this->icon          = $d['icon'] ?? 'doc';
        $this->grain         = $d['grain'];
        $this->defaulton     = $d['defaulton'] ?? true;
        $this->columns       = $d['columns'];
        $this->runfn         = $d['run'];
        $this->requirestable = $d['requirestable'] ?? null;
        $this->filters       = $d['filters'] ?? [];
        $this->datelabel     = $d['datelabel'] ?? null;
        $this->personal      = $d['personal'] ?? false;
    }

    /** @var bool True for learner self-view reports (bound to the current user). */
    public bool $personal = false;

    /**
     * Whether this report offers any server-side filters.
     *
     * @return bool
     */
    public function has_filters(): bool {
        return !empty($this->filters);
    }

    /**
     * Is the required table present on this site?
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
     * Report name.
     *
     * @return string
     */
    public function name(): string {
        return get_string('r_' . $this->id, 'local_beacon');
    }

    /**
     * One-line description, shown on the card and detail page.
     *
     * @return string
     */
    public function description(): string {
        return get_string('r_' . $this->id . '_desc', 'local_beacon');
    }

    /**
     * The grain label, e.g. "One row per learner".
     *
     * @return string
     */
    public function grainlabel(): string {
        return get_string('grain_' . $this->grain, 'local_beacon');
    }

    /**
     * Run the report.
     *
     * @param filterset $filters The active filters (also carries the context).
     * @param int $limit Row cap for preview/paging (0 = a safe default).
     * @return array{rows: array, total: int, error: bool}
     */
    public function run(filterset $filters, int $limit = 100): array {
        global $DB, $USER;

        // Short-lived result cache: a repeat open / page / export of the same
        // filtered view is served without re-running the query. Personal
        // (learner self-view) reports are keyed by user, so one learner's rows
        // can never be served to another from this shared application cache.
        $cache = \cache::make('local_beacon', 'reports');
        $key = $this->id . '_' . $filters->context->id . '_' . $limit . '_' . $filters->signature()
            . ($this->personal ? '_u' . (int) $USER->id : '');
        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            [$rows, $total] = ($this->runfn)($DB, $filters, $limit);
        } catch (\Throwable $e) {
            debugging('Beacon report ' . $this->id . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['rows' => [], 'total' => 0, 'error' => true];
        }

        $result = ['rows' => $rows, 'total' => $total, 'error' => false];
        $cache->set($key, $result);
        return $result;
    }
}
