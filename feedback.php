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
 * Student feedback report: AI Knowledge Check survey results by course, linked
 * workshop, workshop date, customer and cohort — summary charts, comments and a
 * participant-level table, with Excel / CSV download and an option to hide names.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_beacon\local\feedback;
use local_beacon\local\filterset;
use local_beacon\output\library;

$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
if ($context instanceof context_course) {
    require_login($context->instanceid);
}
if (!\local_beacon\local\access::is_staff($context)) {
    require_capability('local/beacon:view', $context);
}

$syscontext = context_system::instance();
$seesall = has_capability('local/beacon:viewall', $syscontext);
$iscourse = $context instanceof context_course;

$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('r_student_feedback', 'local_beacon') . ' | ' . get_string('pluginname', 'local_beacon'));
$PAGE->set_heading($iscourse ? format_string($COURSE->fullname) : format_string($SITE->fullname));
$PAGE->add_body_class('local-beacon');

if (!feedback::available()) {
    $PAGE->set_url(new moodle_url('/local/beacon/feedback.php'));
    throw new moodle_exception('fb_unavailable', 'local_beacon');
}

// Filters. Cohort and the workshop-date range use Beacon's own filter set; a teacher is
// hard-scoped to the learners they teach, exactly as on every other Beacon report.
$q = filterset::from_request($context);
if (!$seesall) {
    $q->enable_viewer_scope((int) $USER->id);
}

// Which courses this viewer may report on.
$allowed = null;
if ($iscourse) {
    $allowed = [(int) $context->instanceid];
} else if (!$seesall) {
    $allowed = $q->viewer_scope_courseids();
}
$surveys = feedback::surveys($allowed);

$courseid = optional_param('course', 0, PARAM_INT);
$kid = optional_param('survey', 0, PARAM_INT);
$workshopid = optional_param('workshop', 0, PARAM_INT);
$customer = trim(optional_param('customer', '', PARAM_TEXT));
$anon = optional_param('anon', 0, PARAM_BOOL);
$download = optional_param('download', '', PARAM_ALPHA);

// Courses that have a survey, in name order.
$courses = [];
foreach ($surveys as $s) {
    $courses[(int) $s->course] = $s->coursename;
}
if ($iscourse) {
    $courseid = (int) $context->instanceid;
} else if (!isset($courses[$courseid])) {
    $courseid = $courses ? (int) array_key_first($courses) : 0;
}
$coursesurveys = array_filter($surveys, fn($s) => (int) $s->course === $courseid);
if (!isset($coursesurveys[$kid])) {
    $kid = $coursesurveys ? (int) array_key_first($coursesurveys) : 0;
}
$survey = $kid ? $coursesurveys[$kid] : null;

$workshops = $courseid ? feedback::workshops($courseid) : [];
if (!isset($workshops[$workshopid])) {
    $workshopid = 0;
}
$customerlist = [];
foreach ($workshops as $w) {
    $c = trim((string) $w->customername);
    if ($c !== '') {
        $customerlist[$c] = $c;
    }
}
core_collator::asort($customerlist);
if (!isset($customerlist[$customer])) {
    $customer = '';
}

// Self URL carrying every filter.
$dr = $q->daterange();
$baseparams = ($iscourse ? ['contextid' => $context->id] : [])
    + ['course' => $courseid, 'survey' => $kid]
    + ($workshopid ? ['workshop' => $workshopid] : [])
    + ($customer !== '' ? ['customer' => $customer] : [])
    + ($anon ? ['anon' => 1] : [])
    + $q->url_params();
$PAGE->set_url(new moodle_url('/local/beacon/feedback.php', $baseparams));

// Load.
$questions = $survey ? feedback::questions((int) $survey->id) : [];
$result = $survey
    ? feedback::responses($survey, $questions, $q, $workshopid ? [$workshopid] : [], $customer !== '' ? [$customer] : [])
    : ['rows' => [], 'booked' => 0, 'attended' => 0, 'scoped' => false];
$rows = $result['rows'];

// Names shown, or "Participant 1, 2 …" when hidden.
$who = function (int $i, array $r) use ($anon): string {
    return $anon ? get_string('fb_participant', 'local_beacon', $i + 1) : $r['fullname'];
};
$datefmt = get_string('strftimedate', 'langconfig');

// Download: participant-level table, one column per question.
if ($download !== '' && $survey) {
    require_sesskey();
    $format = $download === 'xlsx' ? 'excel' : 'csv';
    $columns = [get_string('col_course', 'local_beacon'), get_string('fb_col_workshopcode', 'local_beacon'),
                get_string('fb_col_workshop', 'local_beacon'), get_string('fb_col_workshopdate', 'local_beacon'),
                get_string('fb_col_customer', 'local_beacon')];
    if ($anon) {
        $columns[] = get_string('fb_col_participant', 'local_beacon');
    } else {
        $columns[] = get_string('firstname');
        $columns[] = get_string('lastname');
        $columns[] = get_string('email');
    }
    $columns[] = get_string('fb_col_completed', 'local_beacon');
    foreach ($questions as $qq) {
        $columns[] = $qq['text'];
    }
    $coursename = format_string($survey->coursename);
    $out = [];
    foreach ($rows as $i => $r) {
        $w = $r['workshop'];
        $line = [$coursename, $w['code'] ?? '', $w['name'] ?? '',
                 $w ? userdate($w['date'], $datefmt) : '', $w['customer'] ?? ''];
        if ($anon) {
            $line[] = $who($i, $r);
        } else {
            $line[] = $r['firstname'];
            $line[] = $r['lastname'];
            $line[] = $r['email'];
        }
        $line[] = $r['completed'] ? userdate($r['completed'], $datefmt) : '';
        foreach ($questions as $qq) {
            $v = $r['answers'][$qq['id']] ?? null;
            $line[] = $qq['kind'] === 'freetext' ? (string) $v : ($v === null ? '' : $qq['options'][$v]);
        }
        $out[] = $line;
    }
    $name = clean_filename(get_string('r_student_feedback', 'local_beacon') . ' - ' . $survey->coursename
        . ($workshopid ? ' - ' . $workshops[$workshopid]->workshopcode : '') . ' - ' . userdate(time(), '%Y%m%d'));
    \core\dataformat::download_data($name, $format, $columns, new ArrayIterator($out));
    exit;
}

$output = $PAGE->get_renderer('local_beacon');

// ---- Template context. ----
$pct = fn(float $f): string => format_float($f * 100, 0) . '%';

// Colours: options that read as neutral are grey; the rest run green → red in order
// (AI Knowledge Check stores scales most-positive first). Rated scales run light → dark blue.
$palette = function (array $q): array {
    $out = [];
    if ($q['kind'] === 'rated') {
        $blues = ['#dbe7f6', '#a9c6ea', '#6b9fd8', '#3a74bd', '#1d4f8f', '#123566'];
        $scores = $q['scores'];
        asort($scores);
        $n = count($scores);
        $rank = 0;
        foreach ($scores as $i => $s) {
            $pos = $n > 1 ? (int) round($rank * 5 / ($n - 1)) : 3;
            $out[$i] = ['bg' => $blues[$pos], 'fg' => $pos >= 3 ? '#fff' : '#12263f'];
            $rank++;
        }
        return $out;
    }
    $neutral = '/\b(unsure|uncertain|neither|neutral|not sure|undecided|n\/a|no opinion)\b/i';
    $ramp = [
        1 => ['#1f8a55'], 2 => ['#1f8a55', '#d1495b'], 3 => ['#1f8a55', '#e8a33d', '#d1495b'],
        4 => ['#1f8a55', '#7cc496', '#f09a73', '#d1495b'], 5 => ['#1f8a55', '#7cc496', '#e8c35a', '#f09a73', '#d1495b'],
    ];
    $graded = array_filter($q['options'], fn($l) => !preg_match($neutral, $l));
    $cols = $ramp[max(1, min(5, count($graded)))];
    $k = 0;
    foreach ($q['options'] as $i => $label) {
        if (preg_match($neutral, $label)) {
            $out[$i] = ['bg' => '#c3c8d0', 'fg' => '#1d2433'];
        } else {
            $bg = $cols[min($k, count($cols) - 1)];
            $out[$i] = ['bg' => $bg, 'fg' => in_array($bg, ['#7cc496', '#e8c35a', '#f09a73'], true) ? '#14211a' : '#fff'];
            $k++;
        }
    }
    return $out;
};

$bar = function (array $s) use ($palette, $pct): array {
    $colours = $palette($s);
    $segs = [];
    foreach ($s['options'] as $i => $label) {
        $n = $s['counts'][$i] ?? 0;
        if (!$n) {
            continue;
        }
        $share = $n / max(1, $s['answered']);
        $segs[] = ['label' => $label, 'count' => $n, 'pct' => $pct($share),
                   'width' => format_float($share * 100, 2, false), 'showpct' => $share >= 0.07,
                   'bg' => $colours[$i]['bg'], 'fg' => $colours[$i]['fg']];
    }
    return $segs;
};

// Long descriptive options ("2 = Basic Awareness – I am aware of …") are shown by their
// short name on screen; the full wording stays in the hover title and the downloads.
$shortlabel = fn(string $label): string => preg_split('/\s+[–—-]\s+|\s+\?\s+/u', $label, 2)[0];

$legend = function (array $s) use ($palette, $shortlabel): array {
    $colours = $palette($s);
    $out = [];
    foreach ($s['options'] as $i => $label) {
        $out[] = ['label' => $shortlabel($label), 'full' => $label, 'bg' => $colours[$i]['bg']];
    }
    return $out;
};

$summary = feedback::summarise($questions, $rows);
$pair = feedback::before_after($summary, $rows);
$pairids = $pair ? [$pair['before']['id'], $pair['after']['id']] : [];

// Charts: scale questions grouped by identical answer options, one card per group.
$groups = [];
$totalpos = 0.0;
$posq = 0;
foreach ($summary as $s) {
    if ($s['kind'] === 'freetext' || in_array($s['id'], $pairids, true)) {
        continue;
    }
    $key = md5(json_encode(array_values($s['options'])) . $s['kind']);
    if (!isset($groups[$key])) {
        $groups[$key] = ['legend' => $legend($s), 'questions' => [], 'rated' => $s['kind'] === 'rated'];
    }
    $item = ['num' => $s['num'], 'text' => $s['text'], 'answered' => $s['answered'], 'segs' => $bar($s),
             'hasdata' => $s['answered'] > 0];
    if ($s['kind'] === 'rated') {
        $item['figure'] = $s['average'] === null ? '–' : format_float($s['average'], 1);
        $item['figurenote'] = get_string('fb_average', 'local_beacon');
    } else {
        $item['figure'] = $s['positive'] === null ? '–' : $pct($s['positive']);
        $item['figurenote'] = count($s['options']) >= 4 ? get_string('fb_toptwo', 'local_beacon')
            : get_string('fb_topone', 'local_beacon', reset($s['options']));
        if ($s['positive'] !== null && count($s['options']) >= 4) {
            $totalpos += $s['positive'];
            $posq++;
        }
    }
    $groups[$key]['questions'][] = $item;
}
$chartgroups = array_values($groups);
foreach ($chartgroups as $gi => $g) {
    $title = get_string($g['rated'] ? 'fb_ratedgroup' : 'fb_scalegroup', 'local_beacon');
    if (count($chartgroups) > 1) {
        $labels = array_column($g['legend'], 'label');
        $title .= ': ' . (count($labels) <= 3 ? implode(' / ', $labels) : reset($labels) . ' – ' . end($labels));
    }
    $chartgroups[$gi]['title'] = $title;
}

$beforeafter = null;
if ($pair) {
    $b = $pair['before'];
    $a = $pair['after'];
    $change = ($a['average'] !== null && $b['average'] !== null) ? $a['average'] - $b['average'] : null;
    $avg = fn(array $x): string => $x['average'] === null ? '–' : format_float($x['average'], 1);
    $beforeafter = [
        'legend' => $legend($b),
        'before' => ['text' => $b['text'], 'segs' => $bar($b), 'avg' => $avg($b), 'answered' => $b['answered']],
        'after' => ['text' => $a['text'], 'segs' => $bar($a), 'avg' => $avg($a), 'answered' => $a['answered']],
        'change' => $change === null ? '–' : (($change >= 0 ? '+' : '') . format_float($change, 1)),
        'changeup' => $change !== null && $change > 0,
        'improved' => get_string('fb_improved', 'local_beacon', (object) [
            'n' => $pair['improved'], 'of' => $pair['paired'],
            'pct' => $pair['paired'] ? $pct($pair['improved'] / $pair['paired']) : '0%']),
        'maxscore' => format_float(max($b['scores']), 0),
    ];
}

// Comments.
$comments = [];
foreach ($summary as $s) {
    if ($s['kind'] !== 'freetext') {
        continue;
    }
    $items = [];
    foreach ($s['comments'] as $c) {
        $r = $rows[$c['row']];
        $items[] = ['who' => $who($c['row'], $r), 'text' => $c['text'],
                    'workshop' => $r['workshop'] ? ($r['workshop']['code'] ?: $r['workshop']['name']) : ''];
    }
    $comments[] = ['num' => $s['num'], 'text' => $s['text'], 'count' => count($items), 'items' => $items,
                   'hasitems' => !empty($items)];
}

// Participant table.
$thead = [];
foreach ($questions as $qq) {
    $thead[] = ['num' => $qq['num'], 'text' => $qq['text']];
}
$trows = [];
foreach ($rows as $i => $r) {
    $cells = [];
    foreach ($questions as $qq) {
        $v = $r['answers'][$qq['id']] ?? null;
        $text = $qq['kind'] === 'freetext' ? (string) $v : ($v === null ? '' : $qq['options'][$v]);
        $cells[] = ['v' => $qq['kind'] === 'freetext' ? $text : $shortlabel($text), 'full' => $text,
                    'long' => $qq['kind'] === 'freetext'];
    }
    $w = $r['workshop'];
    $trows[] = ['who' => $who($i, $r), 'email' => $anon ? '' : $r['email'],
                'workshop' => $w ? ($w['code'] !== '' ? $w['code'] . ' · ' : '') . $w['name'] : '—',
                'wdate' => $w ? userdate($w['date'], $datefmt) : '',
                'completed' => $r['completed'] ? userdate($r['completed'], $datefmt) : '',
                'cells' => $cells];
}

// Tiles.
$n = count($rows);
$tiles = [['k' => get_string('fb_tile_responses', 'local_beacon'), 'v' => $n]];
$denom = $result['attended'] ?: $result['booked'];
if ($denom) {
    $withbooking = count(array_filter($rows, fn($r) => $r['workshop'] !== null));
    $tiles[] = ['k' => get_string('fb_tile_rate', 'local_beacon'), 'v' => $pct(min(1, $withbooking / $denom)),
                'note' => get_string($result['attended'] ? 'fb_rate_attended' : 'fb_rate_booked', 'local_beacon',
                    (object) ['n' => $withbooking, 'of' => $denom])];
}
if ($posq) {
    $tiles[] = ['k' => get_string('fb_tile_positive', 'local_beacon'), 'v' => $pct($totalpos / $posq),
                'note' => get_string('fb_tile_positive_note', 'local_beacon')];
}
if ($beforeafter) {
    $tiles[] = ['k' => get_string('fb_tile_change', 'local_beacon'), 'v' => $beforeafter['change'],
                'note' => get_string('fb_tile_change_note', 'local_beacon',
                    (object) ['b' => $beforeafter['before']['avg'], 'a' => $beforeafter['after']['avg'],
                              'max' => $beforeafter['maxscore']])];
}

// Controls.
$opt = function (array $list, $selected): array {
    $out = [];
    foreach ($list as $v => $label) {
        $out[] = ['value' => $v, 'label' => $label, 'selected' => (string) $v === (string) $selected];
    }
    return $out;
};
$courseopts = [];
foreach ($courses as $cid => $cname) {
    $courseopts[$cid] = format_string($cname);
}
$surveyopts = [];
foreach ($coursesurveys as $s) {
    $surveyopts[$s->id] = format_string($s->name);
}
$workshopopts = [];
foreach ($workshops as $w) {
    $workshopopts[$w->id] = feedback::workshop_label($w)
        . (trim((string) $w->customername) !== '' ? ' · ' . format_string($w->customername) : '');
}
$cohortsel = $q->selected('cohort');
$controls = [
    'iscourse' => $iscourse,
    'contextid' => $iscourse ? $context->id : 0,
    'courses' => $opt($courseopts, $courseid),
    'surveys' => $opt($surveyopts, $kid),
    'hassurveychoice' => count($surveyopts) > 1,
    'workshops' => $opt($workshopopts, $workshopid),
    'hasworkshops' => !empty($workshopopts),
    'customers' => $opt($customerlist, $customer),
    'hascustomers' => !empty($customerlist),
    'cohorts' => $seesall ? $opt($q->options('cohort'), $cohortsel[0] ?? '') : [],
    'from' => !empty($dr['from']) ? userdate($dr['from'], '%Y-%m-%d') : '',
    'to' => !empty($dr['to']) ? userdate($dr['to'], '%Y-%m-%d') : '',
    'anon' => (bool) $anon,
    'reseturl' => (new moodle_url('/local/beacon/feedback.php',
        ($iscourse ? ['contextid' => $context->id] : []) + ['course' => $courseid]))->out(false),
];
$controls['hascohorts'] = !empty($controls['cohorts']);

// The "what this report covers" line, also the printed header.
$scope = [];
if ($survey) {
    $scope[] = format_string($survey->coursename);
}
if ($workshopid) {
    $scope[] = feedback::workshop_label($workshops[$workshopid]);
}
if ($customer !== '') {
    $scope[] = format_string($customer);
}
if (!empty($dr['from']) || !empty($dr['to'])) {
    $scope[] = get_string('fb_scope_dates', 'local_beacon', (object) [
        'from' => !empty($dr['from']) ? userdate($dr['from'], $datefmt) : '…',
        'to' => !empty($dr['to']) ? userdate($dr['to'], $datefmt) : '…']);
}
if ($cohortsel) {
    $cohortnames = $q->options('cohort');
    $scope[] = implode(', ', array_map(fn($id) => $cohortnames[$id] ?? '', $cohortsel));
}

$dl = function (string $fmt) use ($baseparams): string {
    return (new moodle_url('/local/beacon/feedback.php', $baseparams + ['download' => $fmt, 'sesskey' => sesskey()]))->out(false);
};

$tabs = library::tabs($context, 'reports');
$template = [
    'heading' => get_string('r_student_feedback', 'local_beacon'),
    'sub' => get_string('fb_sub', 'local_beacon'),
    'hastabs' => !empty($tabs),
    'tabs' => $tabs,
    'backurl' => library::view_url('reports', $context)->out(false),
    'scoped' => !$seesall && !$iscourse,
    'nosurveys' => empty($surveys),
    'hassurvey' => $survey !== null,
    'controls' => $controls,
    'scopeline' => implode(' · ', $scope),
    'printedon' => get_string('fb_printedon', 'local_beacon', userdate(time(), get_string('strftimedatetime', 'langconfig'))),
    'anon' => (bool) $anon,
    'hasrows' => $n > 0,
    'tiles' => $tiles,
    'beforeafter' => $beforeafter,
    'hasbeforeafter' => $beforeafter !== null,
    'chartgroups' => $chartgroups,
    'hascharts' => !empty($chartgroups) || $beforeafter !== null,
    'comments' => $comments,
    'hascomments' => !empty($comments),
    'thead' => $thead,
    'trows' => $trows,
    'xlsxurl' => $survey ? $dl('xlsx') : '',
    'csvurl' => $survey ? $dl('csv') : '',
];

echo $output->header();
echo $output->render_from_template('local_beacon/feedback', $template);
echo $output->footer();
