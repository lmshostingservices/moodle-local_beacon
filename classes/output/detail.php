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
 * A single stat / KPI / report detail page renderable.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\output;

use local_beacon\local\build;
use local_beacon\local\catalogue;
use local_beacon\local\delivery;
use local_beacon\local\filterset;
use local_beacon\local\icons;
use local_beacon\local\present;
use local_beacon\local\report;
use local_beacon\local\savedview;
use renderable;
use templatable;
use renderer_base;

/**
 * The detail page for one item, with a back link to the library.
 */
class detail implements renderable, templatable {

    /** @var \context Context. */
    protected \context $context;
    /** @var string Type: stat, kpi or report. */
    protected string $type;
    /** @var string Item id. */
    protected string $id;

    /**
     * Constructor.
     *
     * @param \context $context Context.
     * @param string $type stat, kpi or report.
     * @param string $id Item id.
     */
    public function __construct(\context $context, string $type, string $id) {
        $this->context = $context;
        $this->type = $type;
        $this->id = $id;
    }

    /**
     * Whether the requested item exists and is available.
     *
     * @return bool
     */
    public function is_valid(): bool {
        if ($this->type === 'mine') {
            $r = catalogue::personal_report($this->id);
            return $r !== null && $r->is_available();
        }
        if ($this->type === 'report') {
            $r = catalogue::report($this->id);
            return $r !== null && $r->is_available();
        }
        $m = catalogue::metric($this->id);
        return $m !== null && $m->is_available() && $m->kind === $this->type;
    }

    /**
     * The item's display name, for the page title.
     *
     * @return string
     */
    public function title(): string {
        if ($this->type === 'mine') {
            return catalogue::personal_report($this->id)->name();
        }
        if ($this->type === 'report') {
            return catalogue::report($this->id)->name();
        }
        return catalogue::metric($this->id)->name();
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        // Learner self-view goes back to My reports; everything else to the library.
        $backurl = ($this->type === 'mine')
            ? (new \moodle_url('/local/beacon/myreports.php'))->out(false)
            : (new \moodle_url('/local/beacon/index.php', ['contextid' => $this->context->id]))->out(false);

        if ($this->type === 'mine') {
            return ['backurl' => $backurl, 'isreport' => true] + $this->personal_report_context();
        }
        if ($this->type === 'report') {
            return ['backurl' => $backurl, 'isreport' => true] + $this->report_context();
        }
        return ['backurl' => $backurl, 'isreport' => false] + $this->metric_context();
    }

    /**
     * Detail context for a personal (learner self-view) report. No filter bar,
     * no saved-views/schedule actions — just the learner's own rows. The report
     * closure binds itself to $USER, so nothing here can widen the scope.
     *
     * @return array
     */
    private function personal_report_context(): array {
        $rep = catalogue::personal_report($this->id);
        $result = $rep->run(new filterset($this->context, []), 500);

        $columns = [];
        foreach ($rep->columns as $i => $c) {
            $type = $c[2];
            $columns[] = [
                'index'      => $i,
                'label'      => get_string($c[1], 'local_beacon'),
                'type'       => $type,
                'numeric'    => in_array($type, ['number'], true),
                'filterable' => in_array($type, ['text', 'status'], true),
            ];
        }
        $rows = [];
        foreach ($result['rows'] as $cells) {
            $rendered = [];
            foreach ($cells as $cell) {
                $sort = $cell['sort'] ?? \core_text::strtolower((string) $cell['v']);
                $rendered[] = [
                    'v'        => $cell['v'],
                    'badge'    => $cell['badge'] ?? '',
                    'isstatus' => !empty($cell['isstatus']),
                    'hasbadge' => !empty($cell['badge']),
                    'numeric'  => !empty($cell['numeric']),
                    'sort'     => is_string($sort) ? $sort : (string) $sort,
                ];
            }
            $rows[] = ['cells' => $rendered];
        }

        $pdfurl = (new \moodle_url('/local/beacon/download.php',
            ['id' => $rep->id, 'type' => 'mine', 'format' => 'pdf']))->out(false);

        $shown = count($rows);
        return [
            'name'        => $rep->name(),
            'description' => $rep->description(),
            'family'      => $rep->family,
            'familylabel' => get_string('family_' . $rep->family, 'local_beacon'),
            'icon'        => icons::svg($rep->icon),
            'grain'       => $rep->grainlabel(),
            'pdfurl'      => $pdfurl,
            'columns'     => $columns,
            'rows'        => $rows,
            'total'       => $result['total'],
            'shown'       => $shown,
            'capped'      => false,
            'error'       => $result['error'],
            'isempty'     => $shown === 0 && !$result['error'],
            'hasfilterbar' => false,
            'filterbar'    => null,
            'actions'      => null,
        ];
    }

    /**
     * Detail context for a stat or KPI.
     *
     * @return array
     */
    private function metric_context(): array {
        $m = catalogue::metric($this->id);
        $ctxid = $this->context->id;
        $iskpi = $m->kind === 'kpi';

        $common = [
            'iskpi'       => $iskpi,
            'name'        => $m->name(),
            'note'        => $m->note(),
            'explanation' => $m->explanation(),
            'family'      => $m->family,
            'familylabel' => get_string('family_' . $m->family, 'local_beacon'),
            'icon'        => icons::svg($m->icon),
        ];

        [$relatedurl, $relatedname] = $this->related_link($m->id);
        $common['hasrelated'] = $relatedurl !== null;
        $common['relatedurl'] = $relatedurl;
        $common['relatedname'] = $relatedname;

        if ($iskpi) {
            $card = build::kpi($m, $ctxid, $this->context);
            $raw = (float) $card['rawvalue'];
            $target = (int) $card['target'];
            $amber = (int) $m->amber;
            $green = (int) $m->green;
            $status = $card['status'];

            $series = present::series($m->id, $ctxid);
            $prev = present::previous($series);
            $trendpp = ($prev === null || $card['nodata']) ? null : round($raw - $prev);
            $gap = $card['nodata'] ? null : (int) round($raw - $target);

            if ($card['nodata']) {
                $meaning = get_string('kpi_meaning_nodata', 'local_beacon');
            } else if ($status === 'off') {
                $meaning = get_string('kpi_meaning_off', 'local_beacon', abs($gap));
            } else {
                $meaning = get_string('kpi_meaning_' . $status, 'local_beacon');
            }

            // Comparison period: current value vs ~30 days ago from snapshots.
            $monthago = present::asof($m->id, $ctxid, 30);
            $hascompare = !$card['nodata'] && $monthago !== null;
            $comparechange = $hascompare ? (int) round($raw - $monthago) : 0;
            $comparegood = $hascompare
                && ($m->better === 'higher' ? $comparechange >= 0 : $comparechange <= 0);

            $tiles = [
                ['label' => get_string('mini_current', 'local_beacon'),
                 'value' => $card['value'] . '%'],
                ['label' => get_string('mini_target', 'local_beacon'),
                 'value' => $target . '%'],
                ['label' => get_string('mini_gap', 'local_beacon'),
                 'value' => $card['nodata'] ? '—' : self::signed($gap) . ' ' . get_string('pp', 'local_beacon'),
                 'tone'  => $card['nodata'] ? '' : ($gap >= 0 ? 'on' : 'off')],
                ['label' => get_string('mini_trend', 'local_beacon'),
                 'value' => $trendpp === null ? get_string('newmetric', 'local_beacon')
                              : self::signed($trendpp) . ' ' . get_string('pp', 'local_beacon'),
                 'tone'  => $trendpp === null ? '' : ($trendpp >= 0 ? 'on' : 'off')],
            ];

            return $common + [
                'value'       => $card['value'],
                'unit'        => '%',
                'rawvalue'    => $card['rawvalue'],
                'target'      => $target,
                'amber'       => $amber,
                'green'       => $green,
                'status'      => $status,
                'statuslabel' => $card['statuslabel'],
                'nodata'      => $card['nodata'],
                'arclen'      => $card['arclen'],
                'arcoffset'   => $card['arcoffset'],
                'targetline'  => $card['targetline'],
                'meaning'     => $meaning,
                'hascompare'  => $hascompare,
                'comparelabel' => get_string('vsperiod', 'local_beacon'),
                'comparechange' => self::signed($comparechange) . ' ' . get_string('pp', 'local_beacon'),
                'compareup'   => $comparechange > 0,
                'compareflat' => $comparechange === 0,
                'comparegood' => $comparegood,
                'tiles'       => $tiles,
                'hastiles'    => true,
                // Value-on-scale bar: coloured zones + your marker + target tick.
                'zoneoff'     => $amber,
                'zonewatch'   => $green - $amber,
                'zoneon'      => 100 - $green,
                'markerpos'   => max(0, min(100, $raw)),
                'targetpos'   => max(0, min(100, $target)),
            ];
        }

        $card = build::stat($m, $ctxid, $this->context);
        $series = present::series($m->id, $ctxid);
        $unit = $card['unit'];

        $tiles = [];
        if ($card['hasvalue']) {
            $tiles[] = ['label' => get_string('mini_now', 'local_beacon'),
                        'value' => $card['value'] . $unit, 'tone' => ''];
            if (count($series) >= 2) {
                $tiles[] = ['label' => get_string('mini_peak', 'local_beacon'),
                            'value' => present::value(max($series), $m->format) . $unit, 'tone' => ''];
                $tiles[] = ['label' => get_string('mini_low', 'local_beacon'),
                            'value' => present::value(min($series), $m->format) . $unit, 'tone' => ''];
            }
            if ($card['hasdelta']) {
                $tiles[] = ['label' => get_string('mini_change', 'local_beacon'),
                            'value' => ($card['deltarising'] ? '+' : '−') . $card['delta'] . '%',
                            'tone'  => $card['deltagood'] ? 'on' : 'off'];
            }
        }

        return $common + [
            'value'       => $card['value'],
            'rawvalue'    => $card['rawvalue'],
            'unit'        => $unit,
            'hasvalue'    => $card['hasvalue'],
            'hasdelta'    => $card['hasdelta'],
            'delta'       => $card['delta'],
            'deltarising' => $card['deltarising'],
            'deltagood'   => $card['deltagood'],
            'better'      => get_string('better_' . $m->better, 'local_beacon'),
            'tiles'       => $tiles,
            'hastiles'    => !empty($tiles),
            'haschart'    => count($series) >= 2,
            'chart'       => count($series) >= 2 ? self::chart_path($series) : null,
        ];
    }

    /** Map a metric to the report that shows the learners behind it. */
    private const RELATED = [
        'course_completion_rate' => 'course_completion', 'activity_completion_rate' => 'course_progress',
        'monthly_active_rate' => 'login_activity', 'pass_rate' => 'grade_summary',
        'feedback_rate' => 'marking_queue', 'certification_currency' => 'certification_status',
        'awaiting_marking' => 'marking_queue', 'dormant_learners' => 'inactive_learners',
        'expiring_soon' => 'certification_status', 'in_progress' => 'course_progress',
        'new_enrolments' => 'enrolment_details', 'total_learners' => 'learner_roster',
        'live_enrolments' => 'enrolment_details', 'active_learners' => 'login_activity',
        'completions' => 'course_completion', 'average_grade' => 'grade_summary',
    ];

    /**
     * The URL and name of the report behind a metric, if any is available.
     *
     * @param string $metricid Metric id.
     * @return array{0:?string,1:?string}
     */
    private function related_link(string $metricid): array {
        if (!isset(self::RELATED[$metricid])) {
            return [null, null];
        }
        $rep = catalogue::report(self::RELATED[$metricid]);
        if ($rep === null || !$rep->is_available()) {
            return [null, null];
        }
        $url = (new \moodle_url('/local/beacon/view.php',
            ['contextid' => $this->context->id, 'type' => 'report', 'id' => $rep->id]))->out(false);
        return [$url, $rep->name()];
    }

    /**
     * A number with an explicit + / − sign.
     *
     * @param int|float $n Number.
     * @return string
     */
    private static function signed($n): string {
        return ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . abs($n);
    }

    /**
     * Detail context for a report — runs the query and returns the table.
     *
     * @return array
     */
    private function report_context(): array {
        $rep = catalogue::report($this->id);
        $filters = filterset::from_request($this->context);
        // Opened in a course context (e.g. a teacher from the course Reports
        // menu) → HARD-lock to that course so they can only see its data.
        if ($this->context instanceof \context_course) {
            $filters->lock_course($this->context->instanceid);
        }
        $limit = 200;
        $result = $rep->run($filters, $limit);

        $columns = [];
        foreach ($rep->columns as $i => $c) {
            $type = $c[2];
            $columns[] = [
                'index'      => $i,
                'label'      => get_string($c[1], 'local_beacon'),
                'type'       => $type,
                'numeric'    => in_array($type, ['number'], true),
                // Text and status columns get a faceted value filter; number/date get sort only.
                'filterable' => in_array($type, ['text', 'status'], true),
            ];
        }

        $rows = [];
        foreach ($result['rows'] as $cells) {
            $rendered = [];
            foreach ($cells as $cell) {
                $sort = $cell['sort'] ?? \core_text::strtolower((string) $cell['v']);
                $rendered[] = [
                    'v'        => $cell['v'],
                    'badge'    => $cell['badge'] ?? '',
                    'isstatus' => !empty($cell['isstatus']),
                    'hasbadge' => !empty($cell['badge']),
                    'numeric'  => !empty($cell['numeric']),
                    'sort'     => is_string($sort) ? $sort : (string) $sort,
                ];
            }
            $rows[] = ['cells' => $rendered];
        }

        $total = $result['total'];
        $shown = count($rows);

        // Active filters ride along on the branded PDF so what you see downloads.
        $extra = $filters->url_params();
        $pdfurl = (new \moodle_url('/local/beacon/download.php',
            ['contextid' => $this->context->id, 'id' => $rep->id, 'format' => 'pdf'] + $extra))->out(false);

        return [
            'name'        => $rep->name(),
            'description' => $rep->description(),
            'family'      => $rep->family,
            'familylabel' => get_string('family_' . $rep->family, 'local_beacon'),
            'icon'        => icons::svg($rep->icon),
            'grain'       => $rep->grainlabel(),
            'pdfurl'      => $pdfurl,
            'columns'     => $columns,
            'rows'        => $rows,
            'total'       => $total,
            'shown'       => $shown,
            'capped'      => $total > $shown,
            'error'       => $result['error'],
            'isempty'     => $shown === 0 && !$result['error'],
            'hasfilterbar' => $rep->has_filters(),
            'filterbar'    => $rep->has_filters() ? $this->build_filterbar($rep, $filters) : null,
            'actions'      => $this->build_actions($rep, $filters),
        ];
    }

    /**
     * The actions bar: saved views and scheduled email deliveries for this
     * report, plus the hidden inputs that carry the current filters into the
     * "save" and "schedule" forms.
     *
     * @param report $rep Report.
     * @param filterset $filters Active filters.
     * @return array
     */
    private function build_actions(report $rep, filterset $filters): array {
        global $USER;
        $ctxid = $this->context->id;
        $reporturl = new \moodle_url('/local/beacon/view.php',
            ['contextid' => $ctxid, 'type' => 'report', 'id' => $rep->id]);

        // Current filters as hidden form inputs (so save/schedule capture them).
        $hidden = [];
        foreach ($filters->url_params() as $name => $value) {
            $hidden[] = ['name' => $name, 'value' => $value];
        }

        // Saved views for this user + report.
        $views = [];
        foreach (savedview::for_user((int) $USER->id, $rep->id) as $v) {
            $views[] = [
                'id'       => $v->id,
                'name'     => $v->name,
                'applyurl' => (new \moodle_url($reporturl, savedview::params($v)))->out(false),
            ];
        }

        // Delivery schedules for this user + report.
        $deliveries = [];
        foreach (delivery::for_user((int) $USER->id, $rep->id) as $d) {
            $deliveries[] = [
                'id'         => $d->id,
                'name'       => $d->name,
                'freqlabel'  => get_string('freq_' . $d->frequency, 'local_beacon'),
                'format'     => strtoupper($d->format),
                'recipients' => $d->recipients,
            ];
        }

        $formats = [];
        foreach (delivery::FORMATS as $f) {
            $formats[] = ['value' => $f, 'label' => strtoupper($f), 'selected' => $f === 'pdf'];
        }
        $frequencies = [];
        foreach (delivery::FREQUENCIES as $f) {
            $frequencies[] = ['value' => $f, 'label' => get_string('freq_' . $f, 'local_beacon'),
                              'selected' => $f === 'weekly'];
        }

        return [
            'contextid'    => $ctxid,
            'reportid'     => $rep->id,
            'sesskey'      => sesskey(),
            'saveurl'      => (new \moodle_url('/local/beacon/savedview.php'))->out(false),
            'deliveryurl'  => (new \moodle_url('/local/beacon/deliveries.php'))->out(false),
            'hidden'       => $hidden,
            'hasfiltersnow' => $filters->has_any(),
            'views'        => $views,
            'hasviews'     => !empty($views),
            'viewcount'    => count($views),
            'deliveries'   => $deliveries,
            'hasdeliveries' => !empty($deliveries),
            'deliverycount' => count($deliveries),
            'formats'      => $formats,
            'frequencies'  => $frequencies,
        ];
    }

    /** URL parameter each filter type reads. */
    private const FPARAM = [
        'cohort' => 'f_cohort', 'cohortid' => 'f_cohortid', 'group' => 'f_group',
        'course' => 'f_course', 'category' => 'f_cat', 'role' => 'f_role', 'roleid' => 'f_roleid',
        'auth' => 'f_auth', 'enrolmethod' => 'f_enrol', 'idle' => 'f_idle',
        'certstatus' => 'f_certstatus', 'policystatus' => 'f_policystatus',
        'proficiency' => 'f_proficiency', 'contextlevel' => 'f_contextlevel',
        'gradeband' => 'f_gradeband', 'progressband' => 'f_progressband',
    ];

    /** The string-key suffix (under filter_) each type displays as. */
    private const FLABEL = [
        'cohort' => 'cohort', 'cohortid' => 'cohort', 'group' => 'group', 'course' => 'course',
        'category' => 'category', 'role' => 'role', 'roleid' => 'role', 'auth' => 'auth',
        'enrolmethod' => 'enrolmethod', 'idle' => 'idle', 'certstatus' => 'certstatus',
        'policystatus' => 'policystatus', 'proficiency' => 'proficiency', 'contextlevel' => 'contextlevel',
        'gradeband' => 'gradeband', 'progressband' => 'progressband',
    ];

    /**
     * Build the filter-bar viewmodel: the pill dropdowns, the date range and the
     * active-filter chips (each with a no-JS removal link).
     *
     * @param report $rep Report.
     * @param filterset $filters Active filters.
     * @return array
     */
    private function build_filterbar(report $rep, filterset $filters): array {
        $base = new \moodle_url('/local/beacon/view.php',
            ['contextid' => $this->context->id, 'type' => 'report', 'id' => $rep->id]);
        $pills = [];
        $chips = [];

        foreach ($rep->filters as $type) {
            // A locked filter (e.g. Course/Category when a teacher is confined
            // to one course) is neither shown nor removable.
            if ($filters->is_locked($type)) {
                continue;
            }
            if ($type === 'daterange') {
                $dr = $filters->daterange();
                $preset = $dr['preset'] ?? '';
                $presets = [];
                foreach (['7', '30', '90', '365', 'ytd'] as $tok) {
                    $presets[] = ['token' => $tok, 'label' => get_string('preset_' . $tok, 'local_beacon'),
                                  'selected' => $preset === $tok];
                }
                $from = ($dr && $dr['from'] !== null && $preset === '') ? userdate($dr['from'], '%Y-%m-%d') : '';
                $to = ($dr && $dr['to'] !== null && $preset === '') ? userdate($dr['to'], '%Y-%m-%d') : '';
                $summary = '';
                if ($dr) {
                    $summary = $preset !== '' ? get_string('preset_' . $preset, 'local_beacon')
                        : trim(($from ?: '…') . ' – ' . ($to ?: '…'));
                    $chips[] = ['label' => get_string('filter_daterange', 'local_beacon') . ': ' . $summary,
                                'removeurl' => (new \moodle_url($base, $filters->without('daterange', null)))->out(false)];
                }
                $pills[] = [
                    'isdate' => true, 'type' => 'daterange',
                    'label' => get_string('filter_daterange', 'local_beacon'),
                    'hasdatelabel' => !empty($rep->datelabel),
                    'datelabel' => $rep->datelabel ? get_string($rep->datelabel, 'local_beacon') : '',
                    'active' => $dr !== null, 'hassummary' => $summary !== '', 'summary' => $summary,
                    'custom' => $preset === '', 'presets' => $presets, 'from' => $from, 'to' => $to,
                ];
                continue;
            }

            $options = $filters->options($type);
            if (empty($options)) {
                continue;
            }
            $param = self::FPARAM[$type];
            $labelkey = 'filter_' . (self::FLABEL[$type] ?? $type);
            $selected = array_map('strval', $filters->selected($type));
            $opts = [];
            foreach ($options as $val => $lab) {
                $on = in_array((string) $val, $selected, true);
                $opts[] = ['value' => $val, 'label' => $lab, 'selected' => $on];
                if ($on) {
                    $chips[] = ['label' => get_string($labelkey, 'local_beacon') . ': ' . $lab,
                                'removeurl' => (new \moodle_url($base, $filters->without($type, $val)))->out(false)];
                }
            }
            $count = count($selected);
            $pills[] = [
                'isdate' => false, 'type' => $type, 'param' => $param,
                'label' => get_string($labelkey, 'local_beacon'),
                'active' => $count > 0, 'hascount' => $count > 0, 'count' => $count,
                'searchable' => count($opts) > 8, 'options' => $opts,
            ];
        }

        return [
            'baseurl'  => $base->out(false),
            'contextid' => $this->context->id,
            'id'       => $rep->id,
            'active'   => $filters->has_any(),
            'haschips' => !empty($chips),
            'chips'    => $chips,
            'pills'    => $pills,
        ];
    }

    /**
     * Build an SVG path for the big trend chart (760x150).
     *
     * @param float[] $series Values.
     * @return array{line:string,area:string,dots:array}
     */
    private static function chart_path(array $series): array {
        $w = 760; $h = 150;
        $min = min($series); $max = max($series);
        $range = ($max - $min) ?: 1;
        $n = count($series);
        $pts = [];
        $dots = [];
        foreach ($series as $i => $v) {
            $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
            $y = $h - (($v - $min) / $range) * ($h - 30) - 15;
            $pts[] = round($x, 1) . ' ' . round($y, 1);
            $dots[] = ['x' => round($x, 1), 'y' => round($y, 1)];
        }
        $line = 'M' . implode(' L ', $pts);
        return ['line' => $line, 'area' => $line . " L $w $h L 0 $h Z", 'dots' => $dots];
    }
}
