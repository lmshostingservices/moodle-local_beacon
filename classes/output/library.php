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
    /** The pages the library can show: everything, or one section on its own. */
    public const VIEWS = ['all', 'stats', 'kpis', 'reports', 'charts'];

    /** @var \context Context. */
    protected \context $context;

    /** @var string Which page: one of VIEWS. */
    protected string $view;

    /**
     * Constructor.
     *
     * @param \context $context Context.
     * @param string $view One of VIEWS; anything else falls back to 'all'.
     */
    public function __construct(\context $context, string $view = 'all') {
        $this->context = $context;
        $this->view = in_array($view, self::views_for($context), true) ? $view : 'all';
    }

    /**
     * The pages this viewer may open in this context. Stat cards and KPI gauges
     * are site-wide figures, so they exist only on the site dashboard and only
     * for viewers entitled to site-wide data (local/beacon:viewall).
     *
     * @param \context $context Context.
     * @return string[]
     */
    public static function views_for(\context $context): array {
        $sitewide = !($context instanceof \context_course)
            && has_capability('local/beacon:viewall', \context_system::instance());
        return $sitewide ? self::VIEWS : ['all', 'reports', 'charts'];
    }

    /**
     * The URL of one library page.
     *
     * @param string $view One of VIEWS.
     * @param \context|null $context Course context to keep, or null for site.
     * @return \moodle_url
     */
    public static function view_url(string $view, ?\context $context = null): \moodle_url {
        $params = [];
        if ($context instanceof \context_course) {
            $params['contextid'] = $context->id;
        }
        if ($view === 'charts') {
            return new \moodle_url('/local/beacon/charts.php', $params);
        }
        if ($view !== 'all') {
            $params['view'] = $view;
        }
        return new \moodle_url('/local/beacon/index.php', $params);
    }

    /** Type (family) order used for grouping and chips. */
    public const FAMILIES = ['people', 'progress', 'assessment', 'engagement', 'compliance', 'operations', 'workplace'];

    /**
     * Group cards under their type, in FAMILIES order, adding the text the
     * finder searches on to each card.
     *
     * @param array[] $items Card contexts (each with family, familylabel, name).
     * @return array[] Each ['family', 'label', 'count', 'items'].
     */
    public static function group(array $items): array {
        $byfam = [];
        foreach ($items as $item) {
            $item['search'] = \core_text::strtolower(trim(($item['name'] ?? '') . ' ' . ($item['desc'] ?? '') . ' '
                . ($item['note'] ?? '') . ' ' . ($item['familylabel'] ?? '') . ' ' . ($item['grain'] ?? '')));
            $byfam[$item['family']][] = $item;
        }
        $out = [];
        $order = array_merge(self::FAMILIES, array_diff(array_keys($byfam), self::FAMILIES));
        foreach ($order as $f) {
            if (empty($byfam[$f])) {
                continue;
            }
            $out[] = ['family' => $f, 'label' => $byfam[$f][0]['familylabel'], 'count' => count($byfam[$f]),
                      'items' => $byfam[$f]];
        }
        return $out;
    }

    /**
     * The page tabs for a viewer: one per page they may open, each a plain link.
     * Empty when there is only one page.
     *
     * @param \context $context Context.
     * @param string $current The page being shown.
     * @return array[]
     */
    public static function tabs(\context $context, string $current): array {
        $views = self::views_for($context);
        if (count($views) < 2) {
            return [];
        }
        $tabs = [];
        foreach ($views as $v) {
            $tabs[] = ['label' => self::view_label($v), 'url' => self::view_url($v, $context)->out(false),
                       'on' => $v === $current];
        }
        return $tabs;
    }

    /**
     * The tab / menu label for a view.
     *
     * @param string $view One of VIEWS.
     * @return string
     */
    public static function view_label(string $view): string {
        return get_string($view === 'all' ? 'view_all' : 'sec_' . $view, 'local_beacon');
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

        // Site-wide access: only Managers/admins (viewall) see the unscoped stat
        // cards, KPI gauges and site-wide reports. A scoped teacher must not be
        // shown site-wide aggregate totals alongside their own-learner reports.
        $seesall = has_capability('local/beacon:viewall', \context_system::instance());

        // Stat cards and KPI gauges are computed at site scope, so they show only
        // on the site dashboard (not inside a single course) and only to viewers
        // entitled to site-wide figures.
        $view = $this->view;
        $wantstats = in_array($view, ['all', 'stats'], true);
        $wantkpis = in_array($view, ['all', 'kpis'], true);
        $wantreports = in_array($view, ['all', 'reports'], true);

        $stats = [];
        $kpis = [];
        if (!$iscourse && $seesall && ($wantstats || $wantkpis)) {
            // One query loads every cached metric value for this context.
            \local_beacon\local\metric_cache::prefetch($ctxid);
            if ($wantstats) {
                foreach (config::stats() as $m) {
                    $stats[] = build::stat($m, $ctxid, $this->context);
                }
            }
            if ($wantkpis) {
                foreach (config::kpis() as $m) {
                    $kpis[] = build::kpi($m, $ctxid, $this->context);
                }
            }
        }

        // Inside a course — or for any non-admin teacher, at any context — only
        // offer reports that can actually be scoped (to the course, or to the
        // teacher's own learners). Site-wide reports stay admin-only.
        $reports = [];
        foreach (($wantreports ? config::reports() : []) as $r) {
            if (($iscourse || !$seesall) && !$r->scopeable_for_teacher()) {
                continue;
            }
            $reports[] = build::report_card($r, $ctxid);
        }

        $scopelabel = $iscourse
            ? format_string(get_course($this->context->instanceid)->fullname) : '';

        $tabs = self::tabs($this->context, $view);

        // Finder: every card is grouped under its type (family) heading, and the
        // toolbar offers a search box plus one chip per type present, with counts.
        $statgroups = self::group($stats);
        $kpigroups = self::group($kpis);
        $reportgroups = self::group($reports);
        $chips = [];
        foreach (array_merge($stats, $kpis, $reports) as $item) {
            $f = $item['family'];
            if (!isset($chips[$f])) {
                $chips[$f] = ['family' => $f, 'label' => $item['familylabel'], 'count' => 0];
            }
            $chips[$f]['count']++;
        }
        $chips = array_values(array_filter(array_map(fn($f) => $chips[$f] ?? null, self::FAMILIES)));
        $finder = [
            'placeholder' => get_string('finder_placeholder_' . $view, 'local_beacon'),
            'chips' => $chips,
            'haschips' => count($chips) > 1,
            'total' => count($stats) + count($kpis) + count($reports),
        ];
        $heading = $view === 'all' ? get_string('libraryheading', 'local_beacon') : self::view_label($view);
        $sub = get_string($view === 'all' ? 'librarysub' : 'view_' . $view . '_sub', 'local_beacon');

        return [
            'heading'      => $heading,
            'sub'          => $sub,
            'hastabs'      => !empty($tabs),
            'statgroups'   => $statgroups,
            'kpigroups'    => $kpigroups,
            'reportgroups' => $reportgroups,
            'finder'       => $finder,
            'hasfinder'    => $finder['total'] > 1,
            'tabs'         => $tabs,
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
