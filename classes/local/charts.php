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
 * The charts catalogue: every chart Beacon draws, each with one query.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Charts are computed through the same filter set as reports, so the course,
 * category and cohort filters, the student-email prefix and — for teachers —
 * the automatic viewer scope apply exactly as they do on every report.
 *
 * Each chart's run() returns plain data:
 *   labels   string[]                       x-axis / slice labels
 *   series   [['name','values','color']]    one or more series
 *   headline ['value','delta','better','note'] the figure shown above the chart
 *   percent  bool                           values are percentages (axis 0–100)
 *
 * Trend charts bucket by calendar month in the viewer's timezone. The bucket
 * boundaries are computed in PHP and bound as parameters, so the SQL is the
 * same on MySQL/MariaDB and PostgreSQL. Each trend query also covers the
 * equal-length previous period, for the "vs previous period" comparison.
 */
class charts {
    /** Selectable periods, in months. */
    public const PERIODS = [3, 6, 12, 24];

    /** Default period, in months. */
    public const DEFAULT_PERIOD = 12;

    /** Sections, in display order. */
    public const SECTIONS = ['growth', 'engagement', 'progress', 'assessment', 'compliance'];

    /** Section colours (Beacon's family palette). */
    private const COLOURS = [
        'growth' => '#4b5563', 'engagement' => '#b7791f', 'progress' => '#0e9c7b',
        'assessment' => '#2563c9', 'compliance' => '#8b3fb8',
    ];

    /** Colour for the "previous period" comparison series. */
    private const PREVIOUS = '#b6bcc6';

    /** Palette for pie / doughnut slices and multi-series bars. */
    private const PALETTE = ['#0e9c7b', '#2563c9', '#b7791f', '#8b3fb8', '#0e7c93', '#c2410c', '#4b5563', '#be185d'];

    /** @var int Monotonic counter for unique bound-parameter names. */
    private static int $seq = 0;

    /** @var array|null Chart definitions, keyed by id. */
    private static ?array $defs = null;

    /** @var array Per-request memo for the shared hourly-activity query. */
    private static array $hourly = [];

    /**
     * Every chart, keyed by id, in display order.
     *
     * Keys: id, section, type (line|bar|hbar|doughnut), requirestable (optional),
     * sitewide (only for viewers who see site-wide data), report (related report
     * id, optional), run (callable taking a context object).
     *
     * @return array<string,array>
     */
    public static function definitions(): array {
        if (self::$defs !== null) {
            return self::$defs;
        }
        $d = [];

        // Growth.
        $d[] = ['id' => 'enrolments', 'section' => 'growth', 'type' => 'line', 'report' => 'enrolment_details',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'u.id', 'e.courseid');
                $from = "FROM {user_enrolments} ue
                         JOIN {enrol} e ON e.id = ue.enrolid
                         JOIN {course} c ON c.id = e.courseid AND c.id <> 1
                         JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                        WHERE 1 = 1 $fw";
                return self::trend($c, $from, $fp, 'ue.timecreated', '1', 'count', 'growth', 'sum', 'higher');
            }];
        $d[] = ['id' => 'newusers', 'section' => 'growth', 'type' => 'line', 'sitewide' => true, 'report' => 'new_users',
            'run' => function ($c) {
                [$fw, $fp] = $c->q->where(['cohort' => 'u.id', 'studentemail' => 'u.email']);
                $from = "FROM {user} u WHERE u.deleted = 0 AND u.id > 2 $fw";
                return self::trend($c, $from, $fp, 'u.timecreated', '1', 'count', 'growth', 'sum', 'higher');
            }];
        $d[] = ['id' => 'topcourses', 'section' => 'growth', 'type' => 'hbar', 'report' => 'course_summary',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'enr.userid', 'enr.courseid');
                $sql = "SELECT enr.courseid, COUNT(1) AS n
                          FROM (" . catalogue::live_enrolments() . ") enr
                          JOIN {course} c ON c.id = enr.courseid AND c.id <> 1
                          JOIN {user} u ON u.id = enr.userid
                         WHERE 1 = 1 $fw
                      GROUP BY enr.courseid
                      ORDER BY n DESC";
                $recs = $c->DB->get_records_sql($sql, ['now' => $c->now] + $fp, 0, 10);
                $names = self::course_names($c->DB, array_keys($recs));
                $labels = [];
                $vals = [];
                foreach ($recs as $cid => $r) {
                    $labels[] = $names[$cid] ?? '#' . $cid;
                    $vals[] = (int) $r->n;
                }
                $total = $c->DB->count_records_sql("SELECT COUNT(1) FROM (" . catalogue::live_enrolments() . ") enr
                          JOIN {course} c ON c.id = enr.courseid AND c.id <> 1
                          JOIN {user} u ON u.id = enr.userid WHERE 1 = 1 $fw", ['now' => $c->now] + $fp);
                return self::single($labels, $vals, 'col_learners', 'growth',
                    ['value' => self::num($total), 'note' => get_string('chart_note_liveenrolments', 'local_beacon')]);
            }];
        $d[] = ['id' => 'enrolmethods', 'section' => 'growth', 'type' => 'doughnut', 'report' => 'enrolment_details',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'u.id', 'e.courseid');
                $sql = "SELECT e.enrol, COUNT(1) AS n
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid AND c.id <> 1
                          JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                         WHERE ue.status = 0 AND e.status = 0 AND (ue.timeend = 0 OR ue.timeend > :now) $fw
                      GROUP BY e.enrol
                      ORDER BY n DESC";
                $labels = [];
                $vals = [];
                $sm = get_string_manager();
                foreach ($c->DB->get_records_sql($sql, ['now' => $c->now] + $fp) as $r) {
                    $labels[] = $sm->string_exists('pluginname', 'enrol_' . $r->enrol)
                        ? get_string('pluginname', 'enrol_' . $r->enrol) : ucfirst($r->enrol);
                    $vals[] = (int) $r->n;
                }
                return self::single($labels, $vals, 'col_learners', 'growth',
                    ['value' => self::num(array_sum($vals)), 'note' => get_string('chart_note_liveenrolments', 'local_beacon')]);
            }];

        // Engagement.
        $d[] = ['id' => 'activelearners', 'section' => 'engagement', 'type' => 'line', 'report' => 'course_access',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'au.userid', 'au.courseid');
                $from = "FROM (" . catalogue::activity_union($c->DB) . ") au
                         JOIN {user} u ON u.id = au.userid AND u.deleted = 0
                        WHERE au.courseid <> 1 $fw";
                return self::trend($c, $from, $fp, 'au.ts', 'au.userid', 'distinct', 'engagement', 'avg', 'higher');
            }];
        $d[] = ['id' => 'dailyactivity', 'section' => 'engagement', 'type' => 'line',
            'run' => function ($c) {
                $days = 90;
                $start = usergetmidnight($c->now) - ($days - 1) * DAYSECS;
                $hours = self::hourly($c, $start);
                $bykey = [];
                foreach ($hours as $h => $n) {
                    $key = userdate($h * HOURSECS, '%Y%m%d', 99, false, false);
                    $bykey[$key] = ($bykey[$key] ?? 0) + $n;
                }
                $labels = [];
                $vals = [];
                for ($i = 0; $i < $days; $i++) {
                    $ts = $start + $i * DAYSECS + 12 * HOURSECS;
                    $labels[] = userdate($ts, get_string('chart_fmt_day', 'local_beacon'));
                    $vals[] = $bykey[userdate($ts, '%Y%m%d', 99, false, false)] ?? 0;
                }
                $total = array_sum($vals);
                return self::single($labels, $vals, 'chart_s_interactions', 'engagement',
                    ['value' => self::num(round($total / $days)), 'note' => get_string('chart_note_perday90', 'local_beacon')]);
            }];
        $d[] = ['id' => 'weekday', 'section' => 'engagement', 'type' => 'bar',
            'run' => function ($c) {
                $hours = self::hourly($c, $c->start);
                $vals = array_fill(1, 7, 0);
                foreach ($hours as $h => $n) {
                    $vals[(int) userdate($h * HOURSECS, '%u', 99, false, false)] += $n;
                }
                $labels = [];
                foreach (range(1, 7) as $dow) {
                    // Day 4 since the epoch (5 Jan 1970) was a Monday; name each
                    // weekday from it at midday UTC, read in UTC so no offset shifts it.
                    $labels[] = userdate((3 + $dow) * DAYSECS + 12 * HOURSECS, '%a', 'UTC', false, false);
                }
                $vals = array_values($vals);
                $best = $vals ? array_search(max($vals), $vals) : false;
                return self::single($labels, $vals, 'chart_s_interactions', 'engagement',
                    ['value' => ($best !== false && max($vals) > 0) ? $labels[$best] : '—',
                     'note' => get_string('chart_note_busiestday', 'local_beacon')]);
            }];
        $d[] = ['id' => 'hourofday', 'section' => 'engagement', 'type' => 'bar',
            'run' => function ($c) {
                $hours = self::hourly($c, $c->start);
                $vals = array_fill(0, 24, 0);
                foreach ($hours as $h => $n) {
                    $vals[(int) userdate($h * HOURSECS, '%H', 99, false, false)] += $n;
                }
                $labels = [];
                for ($i = 0; $i < 24; $i++) {
                    $labels[] = sprintf('%02d:00', $i);
                }
                $best = max($vals) > 0 ? array_search(max($vals), $vals) : false;
                return self::single($labels, $vals, 'chart_s_interactions', 'engagement',
                    ['value' => $best !== false ? $labels[$best] : '—',
                     'note' => get_string('chart_note_busiesthour', 'local_beacon')]);
            }];
        $d[] = ['id' => 'lastaccess', 'section' => 'engagement', 'type' => 'bar', 'report' => 'inactive_learners',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'enr.userid', 'enr.courseid');
                $params = ['now' => $c->now, 'la7' => $c->now - 7 * DAYSECS, 'la30' => $c->now - 30 * DAYSECS,
                           'la90' => $c->now - 90 * DAYSECS] + $fp;
                $sql = "SELECT t.band, COUNT(1) AS n FROM (
                            SELECT CASE WHEN ula.timeaccess IS NULL OR ula.timeaccess = 0 THEN 5
                                        WHEN ula.timeaccess >= :la7 THEN 1
                                        WHEN ula.timeaccess >= :la30 THEN 2
                                        WHEN ula.timeaccess >= :la90 THEN 3
                                        ELSE 4 END AS band
                              FROM (" . catalogue::live_enrolments() . ") enr
                              JOIN {course} c ON c.id = enr.courseid AND c.id <> 1
                              JOIN {user} u ON u.id = enr.userid
                         LEFT JOIN {user_lastaccess} ula ON ula.userid = enr.userid AND ula.courseid = enr.courseid
                             WHERE 1 = 1 $fw) t
                      GROUP BY t.band";
                $recs = $c->DB->get_records_sql($sql, $params);
                $labels = [];
                $vals = [];
                foreach ([1, 2, 3, 4, 5] as $band) {
                    $labels[] = get_string('chart_band_access' . $band, 'local_beacon');
                    $vals[] = isset($recs[$band]) ? (int) $recs[$band]->n : 0;
                }
                $total = array_sum($vals);
                $stale = $vals[2] + $vals[3] + $vals[4];
                return self::single($labels, $vals, 'col_learners', 'engagement',
                    ['value' => $total ? round(100 * $stale / $total) . '%' : '—',
                     'note' => get_string('chart_note_notseen30', 'local_beacon')]);
            }];

        // Progress.
        $d[] = ['id' => 'completions', 'section' => 'progress', 'type' => 'line', 'report' => 'course_completion',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'cc.userid', 'cc.course');
                $from = "FROM {course_completions} cc
                         JOIN {course} c ON c.id = cc.course AND c.id <> 1
                         JOIN {user} u ON u.id = cc.userid AND u.deleted = 0
                        WHERE cc.timecompleted IS NOT NULL $fw";
                return self::trend($c, $from, $fp, 'cc.timecompleted', '1', 'count', 'progress', 'sum', 'higher');
            }];
        $d[] = ['id' => 'progressstatus', 'section' => 'progress', 'type' => 'doughnut', 'report' => 'course_progress',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'enr.userid', 'enr.courseid');
                $sql = "SELECT t.st, COUNT(1) AS n FROM (
                            SELECT CASE WHEN cc.timecompleted IS NOT NULL THEN 1
                                        WHEN EXISTS (SELECT 1 FROM {course_modules_completion} cmc
                                                       JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                                                      WHERE cm.course = enr.courseid AND cmc.userid = enr.userid
                                                        AND cmc.completionstate > 0) THEN 2
                                        ELSE 3 END AS st
                              FROM (" . catalogue::live_enrolments(true) . ") enr
                              JOIN {user} u ON u.id = enr.userid
                         LEFT JOIN {course_completions} cc ON cc.userid = enr.userid AND cc.course = enr.courseid
                             WHERE 1 = 1 $fw) t
                      GROUP BY t.st";
                $recs = $c->DB->get_records_sql($sql, ['now' => $c->now] + $fp);
                $labels = [get_string('status_complete', 'local_beacon'), get_string('status_inprogress', 'local_beacon'),
                           get_string('chart_status_notstarted', 'local_beacon')];
                $vals = [];
                foreach ([1, 2, 3] as $st) {
                    $vals[] = isset($recs[$st]) ? (int) $recs[$st]->n : 0;
                }
                $total = array_sum($vals);
                $data = self::single($labels, $vals, 'col_learners', 'progress',
                    ['value' => $total ? round(100 * $vals[0] / $total) . '%' : '—',
                     'note' => get_string('chart_note_completedshare', 'local_beacon')]);
                $data['series'][0]['colors'] = ['#0e9c7b', '#b7791f', '#c7cdd6'];
                return $data;
            }];
        $d[] = ['id' => 'completionrate', 'section' => 'progress', 'type' => 'hbar', 'report' => 'course_completion',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'enr.userid', 'enr.courseid');
                $sql = "SELECT enr.courseid, COUNT(1) AS total, COUNT(cc.id) AS done
                          FROM (" . catalogue::live_enrolments(true) . ") enr
                          JOIN {user} u ON u.id = enr.userid
                     LEFT JOIN {course_completions} cc ON cc.userid = enr.userid AND cc.course = enr.courseid
                               AND cc.timecompleted IS NOT NULL
                         WHERE 1 = 1 $fw
                      GROUP BY enr.courseid
                      ORDER BY total DESC";
                $recs = $c->DB->get_records_sql($sql, ['now' => $c->now] + $fp, 0, 15);
                $names = self::course_names($c->DB, array_keys($recs));
                $rows = [];
                $alltotal = 0;
                $alldone = 0;
                foreach ($recs as $cid => $r) {
                    $alltotal += (int) $r->total;
                    $alldone += (int) $r->done;
                    $rows[] = [$names[$cid] ?? '#' . $cid, (int) $r->total ? round(100 * $r->done / $r->total, 1) : 0];
                }
                usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
                $data = self::single(array_column($rows, 0), array_column($rows, 1), 'chart_s_completionrate', 'progress',
                    ['value' => $alltotal ? round(100 * $alldone / $alltotal) . '%' : '—',
                     'note' => get_string('chart_note_acrosscourses', 'local_beacon')]);
                $data['percent'] = true;
                return $data;
            }];

        // Assessment.
        $d[] = ['id' => 'submissions', 'section' => 'assessment', 'type' => 'bar', 'requirestable' => 'assign_submission',
            'report' => 'assignment_status',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'u.id', 'a.course');
                $from = "FROM {assign_submission} s
                         JOIN {assign} a ON a.id = s.assignment
                         JOIN {course} c ON c.id = a.course AND c.id <> 1
                         JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                        WHERE s.status = 'submitted' $fw";
                $sub = self::monthly($c, $from, $fp, 's.timemodified', '1', 'count');
                [$gw, $gp] = self::learner_where($c, 'u.id', 'a.course');
                $from = "FROM {assign_grades} g
                         JOIN {assign} a ON a.id = g.assignment
                         JOIN {course} c ON c.id = a.course AND c.id <> 1
                         JOIN {user} u ON u.id = g.userid AND u.deleted = 0
                        WHERE g.grade >= 0 $gw";
                $grd = self::monthly($c, $from, $gp, 'g.timemodified', '1', 'count');
                $cursub = array_slice($sub, $c->n);
                $curgrd = array_slice($grd, $c->n);
                return [
                    'labels' => $c->labels,
                    'series' => [
                        ['name' => get_string('chart_s_submitted', 'local_beacon'), 'values' => $cursub, 'color' => '#93b4ea'],
                        ['name' => get_string('chart_s_graded', 'local_beacon'), 'values' => $curgrd, 'color' => '#2563c9'],
                    ],
                    'headline' => ['value' => self::num(array_sum($curgrd)),
                        'delta' => self::delta(array_sum($curgrd), array_sum(array_slice($grd, 0, $c->n))),
                        'better' => 'higher', 'note' => get_string('chart_note_graded', 'local_beacon')],
                ];
            }];
        $d[] = ['id' => 'turnaround', 'section' => 'assessment', 'type' => 'line', 'requirestable' => 'assign_submission',
            'report' => 'assignment_status',
            'run' => function ($c) {
                $build = function () use ($c) {
                    [$fw, $fp] = self::learner_where($c, 'u.id', 'a.course');
                    $from = "FROM {assign_submission} s
                             JOIN {assign} a ON a.id = s.assignment
                             JOIN {course} c ON c.id = a.course AND c.id <> 1
                             JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                             JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                                  AND g.attemptnumber = s.attemptnumber
                            WHERE s.status = 'submitted' AND g.grade >= 0 AND g.timemodified >= s.timemodified $fw";
                    return [$from, $fp];
                };
                [$from, $fp] = $build();
                $avg = self::monthly($c, $from, $fp, 'g.timemodified', 'g.timemodified - s.timemodified', 'avg');
                [$from, $fp] = $build();
                $cnt = self::monthly($c, $from, $fp, 'g.timemodified', '1', 'count');
                $days = array_map(fn($v) => round($v / DAYSECS, 1), $avg);
                $weighted = function (int $from, int $len) use ($avg, $cnt) {
                    $sum = 0;
                    $n = 0;
                    for ($i = $from; $i < $from + $len; $i++) {
                        $sum += $avg[$i] * $cnt[$i];
                        $n += $cnt[$i];
                    }
                    return $n ? $sum / $n / DAYSECS : null;
                };
                $cur = $weighted($c->n, $c->n);
                $prev = $weighted(0, $c->n);
                return [
                    'labels' => $c->labels,
                    'series' => [
                        ['name' => get_string('chart_s_thisperiod', 'local_beacon'), 'values' => array_slice($days, $c->n),
                         'color' => self::COLOURS['assessment']],
                        ['name' => get_string('chart_s_previousperiod', 'local_beacon'), 'values' => array_slice($days, 0, $c->n),
                         'color' => self::PREVIOUS],
                    ],
                    'headline' => ['value' => $cur === null ? '—' : get_string('chart_days', 'local_beacon', round($cur, 1)),
                        'delta' => ($cur !== null && $prev) ? self::delta($cur, $prev) : null, 'better' => 'lower',
                        'note' => get_string('chart_note_turnaround', 'local_beacon')],
                    'yunit' => get_string('chart_unit_days', 'local_beacon'),
                ];
            }];
        $d[] = ['id' => 'backlogage', 'section' => 'assessment', 'type' => 'bar', 'requirestable' => 'assign_submission',
            'report' => 'marking_queue',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 's.userid', 'a.course');
                $params = ['ba7' => $c->now - 7 * DAYSECS, 'ba14' => $c->now - 14 * DAYSECS,
                           'ba30' => $c->now - 30 * DAYSECS] + $fp;
                $sql = "SELECT t.band, COUNT(1) AS n FROM (
                            SELECT CASE WHEN s.timemodified >= :ba7 THEN 1 WHEN s.timemodified >= :ba14 THEN 2
                                        WHEN s.timemodified >= :ba30 THEN 3 ELSE 4 END AS band
                              " . self::backlog_from($fw) . ") t
                      GROUP BY t.band";
                $recs = $c->DB->get_records_sql($sql, $params);
                $labels = [];
                $vals = [];
                foreach ([1, 2, 3, 4] as $band) {
                    $labels[] = get_string('chart_band_wait' . $band, 'local_beacon');
                    $vals[] = isset($recs[$band]) ? (int) $recs[$band]->n : 0;
                }
                $data = self::single($labels, $vals, 'chart_s_waiting', 'assessment',
                    ['value' => self::num(array_sum($vals)), 'note' => get_string('chart_note_waiting', 'local_beacon')]);
                $data['series'][0]['colors'] = ['#0e9c7b', '#b7791f', '#c2410c', '#b91c1c'];
                return $data;
            }];
        $d[] = ['id' => 'backlogcourse', 'section' => 'assessment', 'type' => 'hbar', 'requirestable' => 'assign_submission',
            'report' => 'marking_queue',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 's.userid', 'a.course');
                $sql = "SELECT a.course, COUNT(1) AS n " . self::backlog_from($fw) . "
                      GROUP BY a.course ORDER BY n DESC";
                $recs = $c->DB->get_records_sql($sql, $fp, 0, 10);
                $names = self::course_names($c->DB, array_keys($recs));
                $labels = [];
                $vals = [];
                foreach ($recs as $cid => $r) {
                    $labels[] = $names[$cid] ?? '#' . $cid;
                    $vals[] = (int) $r->n;
                }
                return self::single($labels, $vals, 'chart_s_waiting', 'assessment',
                    ['value' => (string) count($labels), 'note' => get_string('chart_note_coursesbacklog', 'local_beacon')]);
            }];
        $d[] = ['id' => 'gradedist', 'section' => 'assessment', 'type' => 'bar', 'report' => 'grade_summary',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'enr.userid', 'enr.courseid');
                // Percent of the course total exactly as the gradebook shows it:
                // course totals are measured against the learner's own raw
                // min/max unless the total is a calculated item.
                $pct = "CASE WHEN (gi.calculation IS NULL OR gi.calculation = '') AND gg.rawgrademax > gg.rawgrademin
                             THEN (gg.finalgrade - gg.rawgrademin) * 100 / (gg.rawgrademax - gg.rawgrademin)
                             WHEN gi.grademax > gi.grademin
                             THEN (gg.finalgrade - gi.grademin) * 100 / (gi.grademax - gi.grademin)
                             ELSE NULL END";
                $sql = "SELECT t.band, COUNT(1) AS n FROM (
                            SELECT CASE WHEN p.pct < 50 THEN 1 WHEN p.pct < 65 THEN 2 WHEN p.pct < 75 THEN 3
                                        WHEN p.pct < 85 THEN 4 ELSE 5 END AS band
                              FROM (SELECT $pct AS pct
                                      FROM (" . catalogue::live_enrolments() . ") enr
                                      JOIN {user} u ON u.id = enr.userid
                                      JOIN {grade_items} gi ON gi.courseid = enr.courseid AND gi.itemtype = 'course'
                                           AND gi.gradetype = 1
                                      JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = enr.userid
                                     WHERE gg.finalgrade IS NOT NULL $fw) p
                             WHERE p.pct IS NOT NULL) t
                      GROUP BY t.band";
                $recs = $c->DB->get_records_sql($sql, ['now' => $c->now] + $fp);
                $labels = [];
                $vals = [];
                foreach ([1, 2, 3, 4, 5] as $band) {
                    $labels[] = get_string('chart_band_grade' . $band, 'local_beacon');
                    $vals[] = isset($recs[$band]) ? (int) $recs[$band]->n : 0;
                }
                $total = array_sum($vals);
                $data = self::single($labels, $vals, 'col_learners', 'assessment',
                    ['value' => $total ? round(100 * ($total - $vals[0]) / $total) . '%' : '—',
                     'note' => get_string('chart_note_atleast50', 'local_beacon')]);
                $data['series'][0]['colors'] = ['#b91c1c', '#b7791f', '#0e7c93', '#2563c9', '#0e9c7b'];
                return $data;
            }];
        $d[] = ['id' => 'quizscores', 'section' => 'assessment', 'type' => 'hbar', 'requirestable' => 'quiz_attempts',
            'report' => 'quiz_performance',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'qa.userid', 'qz.course');
                $params = ['qs' => $c->start, 'qe' => $c->end] + $fp;
                $sql = "SELECT qz.course, COUNT(1) AS attempts, AVG(qa.sumgrades * 100 / qz.sumgrades) AS avgpct
                          FROM {quiz_attempts} qa
                          JOIN {quiz} qz ON qz.id = qa.quiz AND qz.sumgrades > 0
                          JOIN {course} c ON c.id = qz.course AND c.id <> 1
                          JOIN {user} u ON u.id = qa.userid AND u.deleted = 0
                         WHERE qa.state = 'finished' AND qa.preview = 0 AND qa.sumgrades IS NOT NULL
                           AND qa.timefinish >= :qs AND qa.timefinish < :qe $fw
                      GROUP BY qz.course
                      ORDER BY attempts DESC";
                $recs = $c->DB->get_records_sql($sql, $params, 0, 15);
                $names = self::course_names($c->DB, array_keys($recs));
                $rows = [];
                $wsum = 0;
                $wn = 0;
                foreach ($recs as $cid => $r) {
                    $rows[] = [$names[$cid] ?? '#' . $cid, round((float) $r->avgpct, 1)];
                    $wsum += (float) $r->avgpct * (int) $r->attempts;
                    $wn += (int) $r->attempts;
                }
                usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
                $data = self::single(array_column($rows, 0), array_column($rows, 1), 'chart_s_avgscore', 'assessment',
                    ['value' => $wn ? round($wsum / $wn) . '%' : '—', 'note' => get_string('chart_note_avgscore', 'local_beacon')]);
                $data['percent'] = true;
                return $data;
            }];
        $d[] = ['id' => 'quizattempts', 'section' => 'assessment', 'type' => 'line', 'requirestable' => 'quiz_attempts',
            'report' => 'quiz_performance',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'qa.userid', 'qz.course');
                $from = "FROM {quiz_attempts} qa
                         JOIN {quiz} qz ON qz.id = qa.quiz
                         JOIN {course} c ON c.id = qz.course AND c.id <> 1
                         JOIN {user} u ON u.id = qa.userid AND u.deleted = 0
                        WHERE qa.state = 'finished' AND qa.preview = 0 $fw";
                return self::trend($c, $from, $fp, 'qa.timefinish', '1', 'count', 'assessment', 'sum', 'higher');
            }];

        // Compliance.
        $d[] = ['id' => 'certificates', 'section' => 'compliance', 'type' => 'line', 'requirestable' => 'tool_certificate_issues',
            'report' => 'certification_status',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'ci.userid', 'ci.courseid');
                $from = "FROM {tool_certificate_issues} ci
                         JOIN {user} u ON u.id = ci.userid AND u.deleted = 0
                        WHERE 1 = 1 $fw";
                return self::trend($c, $from, $fp, 'ci.timecreated', '1', 'count', 'compliance', 'sum', 'higher');
            }];
        $d[] = ['id' => 'badges', 'section' => 'compliance', 'type' => 'line', 'requirestable' => 'badge_issued',
            'report' => 'badges_awarded',
            'run' => function ($c) {
                [$fw, $fp] = self::learner_where($c, 'bi.userid', 'b.courseid');
                $from = "FROM {badge_issued} bi
                         JOIN {badge} b ON b.id = bi.badgeid
                         JOIN {user} u ON u.id = bi.userid AND u.deleted = 0
                        WHERE 1 = 1 $fw";
                return self::trend($c, $from, $fp, 'bi.dateissued', '1', 'count', 'compliance', 'sum', 'higher');
            }];

        self::$defs = [];
        foreach ($d as $def) {
            self::$defs[$def['id']] = $def;
        }
        return self::$defs;
    }

    /**
     * The charts this viewer gets: enabled by the admin, available on this site,
     * and — for viewers without site-wide access — not a site-wide-only chart.
     *
     * @param bool $seesall Viewer sees site-wide data.
     * @return array[]
     */
    public static function enabled(bool $seesall): array {
        global $DB;
        $raw = get_config('local_beacon', 'enabledcharts');
        $selected = ($raw === false || $raw === null) ? null : array_filter(explode(',', (string) $raw));
        $man = $DB->get_manager();
        $out = [];
        foreach (self::definitions() as $id => $def) {
            if ($selected !== null && !in_array($id, $selected, true)) {
                continue;
            }
            if (!empty($def['requirestable']) && !$man->table_exists($def['requirestable'])) {
                continue;
            }
            if (!empty($def['sitewide']) && !$seesall) {
                continue;
            }
            $out[$id] = $def;
        }
        return $out;
    }

    /**
     * Compute one chart's data, through the short-lived result cache.
     *
     * @param array $def Chart definition.
     * @param filterset $q Active filters (carries viewer scope).
     * @param int $period Months.
     * @return array|null Data, or null when the query failed.
     */
    public static function data(array $def, filterset $q, int $period): ?array {
        global $DB;
        $cache = \cache::make('local_beacon', 'reports');
        $key = 'chart_' . $def['id'] . '_' . $q->context->id . '_' . $period . '_' . $q->signature();
        $hit = $cache->get($key);
        if ($hit !== false) {
            return $hit;
        }
        try {
            $data = ($def['run'])(self::context($DB, $q, $period));
        } catch (\Throwable $e) {
            debugging('Beacon chart ' . $def['id'] . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        $cache->set($key, $data);
        return $data;
    }

    /**
     * Build the Moodle chart object for one chart's data.
     *
     * @param array $def Chart definition.
     * @param array $data Chart data.
     * @return \core\chart_base
     */
    public static function chart(array $def, array $data): \core\chart_base {
        switch ($def['type']) {
            case 'doughnut':
                $chart = new \core\chart_pie();
                $chart->set_doughnut(true);
                break;
            case 'line':
                $chart = new \core\chart_line();
                $chart->set_smooth(true);
                break;
            case 'hbar':
                $chart = new \core\chart_bar();
                $chart->set_horizontal(true);
                break;
            default:
                $chart = new \core\chart_bar();
        }
        foreach ($data['series'] as $s) {
            $series = new \core\chart_series($s['name'], array_values($s['values']));
            if (!empty($s['colors'])) {
                $series->set_colors($s['colors']);
            } else if ($def['type'] === 'doughnut') {
                $series->set_colors(array_slice(self::PALETTE, 0, max(1, count($s['values']))));
            } else if (!empty($s['color'])) {
                $series->set_color($s['color']);
            }
            $chart->add_series($series);
        }
        $chart->set_labels(array_values($data['labels']));
        if ($def['type'] !== 'doughnut') {
            $axis = $def['type'] === 'hbar' ? $chart->get_xaxis(0, true) : $chart->get_yaxis(0, true);
            $axis->set_min(0);
            if (!empty($data['percent'])) {
                $axis->set_max(100);
                $axis->set_label('%');
            } else if (!empty($data['yunit'])) {
                $axis->set_label($data['yunit']);
            }
        }
        if (count($data['series']) < 2 && $def['type'] !== 'doughnut') {
            $chart->set_legend_options(['display' => false]);
        }
        return $chart;
    }

    /**
     * Whether the data has anything worth drawing.
     *
     * @param array $data Chart data.
     * @return bool
     */
    public static function has_data(array $data): bool {
        foreach ($data['series'] as $s) {
            foreach ($s['values'] as $v) {
                if ((float) $v != 0.0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The chart's data as CSV rows (header first).
     *
     * @param array $data Chart data.
     * @return array[]
     */
    public static function csv_rows(array $data): array {
        $rows = [array_merge([get_string('chart_csv_label', 'local_beacon')], array_column($data['series'], 'name'))];
        foreach (array_values($data['labels']) as $i => $label) {
            $row = [$label];
            foreach ($data['series'] as $s) {
                $row[] = array_values($s['values'])[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // Helpers.

    /**
     * The run context: DB, filters, now, and the month buckets for the period.
     *
     * @param \moodle_database $DB Database.
     * @param filterset $q Filters.
     * @param int $period Months.
     * @return \stdClass
     */
    private static function context(\moodle_database $DB, filterset $q, int $period): \stdClass {
        $now = time();
        $y = (int) userdate($now, '%Y', 99, false, false);
        $m = (int) userdate($now, '%m', 99, false, false);
        // Boundaries: start of the month 2n-1 months ago … start of next month,
        // i.e. 2n consecutive calendar months (previous period + this period).
        $bounds = [];
        for ($k = 2 * $period - 1; $k >= -1; $k--) {
            $bounds[] = make_timestamp($y, $m - $k, 1, 0, 0, 0);
        }
        $labels = [];
        for ($i = $period; $i < 2 * $period; $i++) {
            $labels[] = userdate($bounds[$i] + 12 * HOURSECS, get_string('chart_fmt_month', 'local_beacon'));
        }
        return (object) ['DB' => $DB, 'q' => $q, 'now' => $now, 'n' => $period, 'bounds' => $bounds,
            'labels' => $labels, 'start' => $bounds[$period], 'end' => $bounds[2 * $period]];
    }

    /**
     * Filter fragment for a learner-in-course chart: cohort, course, category,
     * student-email prefix, and the teacher binding the viewer scope uses.
     *
     * @param \stdClass $c Run context.
     * @param string $ucol Learner user-id column.
     * @param string $ccol Course-id column.
     * @return array{0:string,1:array}
     */
    private static function learner_where(\stdClass $c, string $ucol, string $ccol): array {
        return $c->q->where(['cohort' => $ucol, 'course' => $ccol, 'category' => $ccol,
            'studentemail' => 'u.email', 'trainer' => ['user' => $ucol, 'course' => $ccol]]);
    }

    /**
     * One value per calendar month over the previous + current period (2n values).
     *
     * @param \stdClass $c Run context.
     * @param string $from FROM … WHERE … clause (must end inside the WHERE).
     * @param array $params Its parameters.
     * @param string $tscol Timestamp column.
     * @param string $valexpr Value expression aggregated per month.
     * @param string $agg count | distinct | avg.
     * @return float[]
     */
    private static function monthly(\stdClass $c, string $from, array $params, string $tscol, string $valexpr,
            string $agg): array {
        $p = 'cb' . (self::$seq++) . '_';
        $bounds = $c->bounds;
        $last = count($bounds) - 1;
        $case = 'CASE';
        for ($i = $last - 1; $i >= 1; $i--) {
            $case .= " WHEN $tscol >= :{$p}{$i} THEN $i";
            $params[$p . $i] = $bounds[$i];
        }
        $case .= ' ELSE 0 END';
        $params[$p . 's'] = $bounds[0];
        $params[$p . 'e'] = $bounds[$last];
        $fn = ['count' => 'COUNT(t.v)', 'distinct' => 'COUNT(DISTINCT t.v)', 'avg' => 'AVG(t.v)'][$agg];
        $sql = "SELECT t.bkt, $fn AS val
                  FROM (SELECT $case AS bkt, $valexpr AS v
                        $from AND $tscol >= :{$p}s AND $tscol < :{$p}e) t
              GROUP BY t.bkt";
        $out = array_fill(0, $last, 0.0);
        foreach ($c->DB->get_records_sql($sql, $params) as $r) {
            $out[(int) $r->bkt] = (float) $r->val;
        }
        return $out;
    }

    /**
     * A trend line: this period with the previous period overlaid, and a
     * headline comparing the two.
     *
     * @param \stdClass $c Run context.
     * @param string $from FROM … WHERE clause.
     * @param array $params Parameters.
     * @param string $tscol Timestamp column.
     * @param string $valexpr Value expression.
     * @param string $agg count | distinct | avg.
     * @param string $section Section (colour).
     * @param string $headline sum (period total) | avg (monthly average).
     * @param string $better higher | lower.
     * @return array
     */
    private static function trend(\stdClass $c, string $from, array $params, string $tscol, string $valexpr, string $agg,
            string $section, string $headline, string $better): array {
        $vals = self::monthly($c, $from, $params, $tscol, $valexpr, $agg);
        $prev = array_slice($vals, 0, $c->n);
        $cur = array_slice($vals, $c->n);
        $curv = $headline === 'avg' ? array_sum($cur) / $c->n : array_sum($cur);
        $prevv = $headline === 'avg' ? array_sum($prev) / $c->n : array_sum($prev);
        return [
            'labels' => $c->labels,
            'series' => [
                ['name' => get_string('chart_s_thisperiod', 'local_beacon'), 'values' => $cur, 'color' => self::COLOURS[$section]],
                ['name' => get_string('chart_s_previousperiod', 'local_beacon'), 'values' => $prev, 'color' => self::PREVIOUS],
            ],
            'headline' => ['value' => self::num(round($curv)), 'delta' => self::delta($curv, $prevv), 'better' => $better,
                'note' => get_string($headline === 'avg' ? 'chart_note_avgpermonth' : 'chart_note_inperiod', 'local_beacon')],
        ];
    }

    /**
     * Hour-bucketed interaction counts from the durable activity sources since
     * $start: [hour number since epoch => count]. Converted to the viewer's local
     * day / weekday / hour in PHP, so timezones and DST are exact.
     *
     * @param \stdClass $c Run context.
     * @param int $start Window start.
     * @return array<int,int>
     */
    private static function hourly(\stdClass $c, int $start): array {
        $memo = $c->q->signature() . '_' . $start;
        if (isset(self::$hourly[$memo])) {
            return self::$hourly[$memo];
        }
        [$fw, $fp] = self::learner_where($c, 'au.userid', 'au.courseid');
        $sql = "SELECT FLOOR(t.ts / 3600) AS h, COUNT(1) AS n
                  FROM (SELECT au.ts
                          FROM (" . catalogue::activity_union($c->DB) . ") au
                          JOIN {user} u ON u.id = au.userid AND u.deleted = 0
                         WHERE au.courseid <> 1 AND au.ts >= :hstart AND au.ts < :hend $fw) t
              GROUP BY FLOOR(t.ts / 3600)";
        $out = [];
        foreach ($c->DB->get_records_sql($sql, ['hstart' => $start, 'hend' => $c->now + 1] + $fp) as $r) {
            $out[(int) $r->h] = (int) $r->n;
        }
        return self::$hourly[$memo] = $out;
    }

    /**
     * FROM … WHERE for assignment submissions still waiting to be graded (the
     * same rule as the marking queue).
     *
     * @param string $fw Filter fragment.
     * @return string
     */
    private static function backlog_from(string $fw): string {
        return "FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment
                JOIN {course} c ON c.id = a.course AND c.id <> 1
                JOIN {user} u ON u.id = s.userid AND u.deleted = 0
           LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                     AND g.attemptnumber = s.attemptnumber
               WHERE s.latest = 1 AND s.status = 'submitted'
                 AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0) $fw";
    }

    /**
     * A one-series chart.
     *
     * @param array $labels Labels.
     * @param array $values Values.
     * @param string $namekey Series name string key.
     * @param string $section Section (colour).
     * @param array $headline Headline.
     * @return array
     */
    private static function single(array $labels, array $values, string $namekey, string $section, array $headline): array {
        return [
            'labels' => $labels,
            'series' => [['name' => get_string($namekey, 'local_beacon'), 'values' => $values,
                          'color' => self::COLOURS[$section]]],
            'headline' => $headline + ['delta' => null, 'better' => 'higher'],
        ];
    }

    /**
     * Course full names by id.
     *
     * @param \moodle_database $DB Database.
     * @param int[] $ids Course ids.
     * @return array<int,string>
     */
    private static function course_names(\moodle_database $DB, array $ids): array {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($DB->get_records_list('course', 'id', $ids, '', 'id, shortname, fullname') as $r) {
            $out[(int) $r->id] = format_string($r->shortname ?: $r->fullname);
        }
        return $out;
    }

    /**
     * Percentage change, or null when there is no baseline.
     *
     * @param float $cur Current.
     * @param float $prev Previous.
     * @return float|null
     */
    private static function delta(float $cur, float $prev): ?float {
        return $prev > 0 ? round(100 * ($cur - $prev) / $prev, 1) : null;
    }

    /**
     * A formatted whole number.
     *
     * @param float|int $n Number.
     * @return string
     */
    private static function num($n): string {
        return number_format((float) $n, 0);
    }
}
