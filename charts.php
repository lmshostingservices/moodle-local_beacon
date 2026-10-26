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
 * The Charts page.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use local_beacon\local\charts;
use local_beacon\local\filterset;
use local_beacon\output\library;

$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
if ($context instanceof context_course) {
    require_login($context->instanceid);
}
// Charts are for staff, exactly like the reports library.
if (!\local_beacon\local\access::is_staff($context)) {
    require_capability('local/beacon:view', $context);
}

$period = optional_param('period', charts::DEFAULT_PERIOD, PARAM_INT);
if (!in_array($period, charts::PERIODS, true)) {
    $period = charts::DEFAULT_PERIOD;
}
$download = optional_param('download', '', PARAM_ALPHANUMEXT);

$syscontext = context_system::instance();
$seesall = has_capability('local/beacon:viewall', $syscontext);
$iscourse = $context instanceof context_course;

// Filters: the same engine as the reports. A teacher is hard-scoped to the
// learners they teach (rows) and offered only their own courses (options).
$q = filterset::from_request($context);
if ($iscourse) {
    $q->lock_course((int) $context->instanceid);
}
if (!$seesall) {
    $q->enable_viewer_scope((int) $USER->id);
    $q->enable_option_scope();
}

$defs = charts::enabled($seesall);

// CSV download of one chart's data.
if ($download !== '') {
    require_sesskey();
    if (!isset($defs[$download])) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    $data = charts::data($defs[$download], $q, $period);
    $rows = $data ? charts::csv_rows($data) : [];
    $filename = clean_filename('beacon-' . $download . '-' . userdate(time(), '%Y%m%d'));
    csv_export_writer::download_array($filename, $rows);
    exit;
}

$baseparams = ['period' => $period] + ($iscourse ? ['contextid' => $context->id] : []) + $q->url_params();
$url = new moodle_url('/local/beacon/charts.php', $baseparams);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('sec_charts', 'local_beacon') . ' | ' . get_string('pluginname', 'local_beacon'));
$PAGE->set_heading($iscourse ? format_string($COURSE->fullname) : format_string($SITE->fullname));
$PAGE->add_body_class('local-beacon');

$output = $PAGE->get_renderer('local_beacon');

// Related reports the viewer can actually open, for the "Open report" links.
$reportsok = [];
foreach (\local_beacon\local\config::reports() as $r) {
    if (($iscourse || !$seesall) && !$r->scopeable_for_teacher()) {
        continue;
    }
    $reportsok[$r->id] = true;
}

$sections = [];
foreach (charts::SECTIONS as $sec) {
    $cards = [];
    foreach ($defs as $id => $def) {
        if ($def['section'] !== $sec) {
            continue;
        }
        $data = charts::data($def, $q, $period);
        $card = [
            'id' => $id,
            'title' => get_string('chart_' . $id, 'local_beacon'),
            'desc' => get_string('chart_' . $id . '_desc', 'local_beacon'),
            'family' => $sec === 'growth' ? 'people' : $sec,
            'error' => $data === null,
            'empty' => $data !== null && !charts::has_data($data),
            'html' => '',
            'wide' => in_array($def['type'], ['line'], true) || in_array($id, ['hourofday'], true),
            'tall' => $def['type'] === 'hbar',
            'section' => $sec,
            'search' => core_text::strtolower(get_string('chart_' . $id, 'local_beacon') . ' '
                . get_string('chart_' . $id . '_desc', 'local_beacon') . ' ' . get_string('chartsec_' . $sec, 'local_beacon')),
        ];
        if ($data !== null) {
            $h = $data['headline'];
            $card['headline'] = $h['value'];
            $card['note'] = $h['note'];
            if ($h['delta'] !== null) {
                $up = $h['delta'] >= 0;
                $good = ($h['better'] === 'lower') ? !$up : $up;
                $card['hasdelta'] = true;
                $card['delta'] = ($up ? '+' : '') . format_float($h['delta'], 1) . '%';
                $card['deltaclass'] = $h['delta'] == 0 ? 'flat' : ($good ? 'up' : 'down');
                $card['deltanote'] = get_string('chart_vsprevious', 'local_beacon');
            }
            if (!$card['empty']) {
                $card['html'] = $OUTPUT->render_chart(charts::chart($def, $data), true);
                $card['csvurl'] = (new moodle_url('/local/beacon/charts.php',
                    $baseparams + ['download' => $id, 'sesskey' => sesskey()]))->out(false);
            }
        }
        if (!empty($def['report']) && isset($reportsok[$def['report']])) {
            $card['reporturl'] = (new moodle_url('/local/beacon/view.php',
                ['contextid' => $context->id, 'type' => 'report', 'id' => $def['report']] + $q->url_params()))->out(false);
        }
        $cards[] = $card;
    }
    if ($cards) {
        $sections[] = ['key' => $sec, 'family' => $sec === 'growth' ? 'people' : $sec, 'label' => get_string('chartsec_' . $sec, 'local_beacon'),
                       'count' => count($cards), 'charts' => $cards];
    }
}

// Controls: period + (outside a course) category, course and cohort.
$periods = [];
foreach (charts::PERIODS as $p) {
    $periods[] = ['value' => $p, 'label' => get_string('chart_period', 'local_beacon', $p), 'selected' => $p === $period];
}
$optionlist = function (string $type) use ($q) {
    $sel = $q->selected($type);
    $out = [];
    foreach ($q->options($type) as $id => $label) {
        $out[] = ['value' => $id, 'label' => $label, 'selected' => in_array($id, $sel)];
    }
    return $out;
};
$controls = [
    'period' => $periods,
    'showentity' => !$iscourse,
    'categories' => $iscourse ? [] : $optionlist('category'),
    'courses' => $iscourse ? [] : $optionlist('course'),
    'cohorts' => ($iscourse || !$seesall) ? [] : $optionlist('cohort'),
    'contextid' => $iscourse ? $context->id : 0,
    'reseturl' => library::view_url('charts', $context)->out(false),
];
$controls['hascohorts'] = !empty($controls['cohorts']);

$tabs = library::tabs($context, 'charts');
$chips = [];
$totalcharts = 0;
foreach ($sections as $sect) {
    $chips[] = ['family' => $sect['key'], 'label' => $sect['label'], 'count' => $sect['count']];
    $totalcharts += $sect['count'];
}
$finder = ['placeholder' => get_string('finder_placeholder_charts', 'local_beacon'), 'chips' => $chips,
           'haschips' => count($chips) > 1, 'total' => $totalcharts];
$template = [
    'heading' => get_string('sec_charts', 'local_beacon'),
    'sub' => get_string('view_charts_sub', 'local_beacon'),
    'hastabs' => !empty($tabs),
    'tabs' => $tabs,
    'iscourse' => $iscourse,
    'scopelabel' => $iscourse ? format_string($COURSE->fullname) : '',
    'scoped' => !$seesall && !$iscourse,
    'controls' => $controls,
    'sections' => $sections,
    'hassections' => !empty($sections),
    'finder' => $finder,
    'hasfinder' => $totalcharts > 1,
    'cansettings' => has_capability('moodle/site:config', $syscontext),
    'settingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'local_beacon_settings'],
        'admin-enabledcharts'))->out(false),
];

echo $output->header();
echo $output->render_from_template('local_beacon/charts', $template);
echo $output->footer();
