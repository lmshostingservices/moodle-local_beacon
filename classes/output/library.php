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
 * The library (main) page renderable.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\output;

use local_beacon\local\build;
use local_beacon\local\config;
use renderable;
use templatable;
use renderer_base;

/**
 * The library: enabled stat cards, then KPI gauges, then report cards.
 */
class library implements renderable, templatable {

    /** @var \context Context. */
    protected \context $context;

    /**
     * Constructor.
     *
     * @param \context $context Context.
     */
    public function __construct(\context $context) {
        $this->context = $context;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $ctxid = $this->context->id;
        $iscourse = $this->context instanceof \context_course;

        // Stat cards and KPI gauges are computed at site scope, so they show only
        // on the site dashboard — not inside a single course.
        $stats = [];
        $kpis = [];
        if (!$iscourse) {
            // One query loads every cached metric value for this context.
            \local_beacon\local\metric_cache::prefetch($ctxid);
            foreach (config::stats() as $m) {
                $stats[] = build::stat($m, $ctxid, $this->context);
            }
            foreach (config::kpis() as $m) {
                $kpis[] = build::kpi($m, $ctxid, $this->context);
            }
        }

        // Inside a course, only offer reports that can actually scope to it.
        $scopeable = ['course', 'category', 'group'];
        $reports = [];
        foreach (config::reports() as $r) {
            if ($iscourse && empty(array_intersect($scopeable, $r->filters))) {
                continue;
            }
            $reports[] = build::report_card($r, $ctxid);
        }

        $scopelabel = $iscourse
            ? format_string(get_course($this->context->instanceid)->fullname) : '';

        return [
            'contextid'    => $ctxid,
            'iscourse'     => $iscourse,
            'scopelabel'   => $scopelabel,
            'hasstats'     => !empty($stats),
            'haskpis'      => !empty($kpis),
            'hasreports'   => !empty($reports),
            'stats'        => $stats,
            'kpis'         => $kpis,
            'reports'      => $reports,
            'statcount'    => count($stats),
            'kpicount'     => count($kpis),
            'reportcount'  => count($reports),
            'empty'        => empty($stats) && empty($kpis) && empty($reports),
            'canrequest'   => has_capability('moodle/site:config', \context_system::instance()),
            'cansettings'  => has_capability('moodle/site:config', \context_system::instance()),
            'requesturl'   => (new \moodle_url('/local/beacon/request.php', ['contextid' => $ctxid]))->out(false),
            'settingsurl'  => (new \moodle_url('/local/beacon/configure.php'))->out(false),
        ];
    }
}
