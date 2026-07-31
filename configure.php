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
 * "Set up your library" — the checklist that chooses what shows.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_beacon\local\catalogue;
use local_beacon\local\icons;
use local_beacon\local\metric_cache;
use local_beacon\local\present;

require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_beacon_setup');

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/beacon/configure.php'));
$PAGE->add_body_class('local-beacon');

$currentstats   = get_config('local_beacon', 'enabledstats');
$currentkpis    = get_config('local_beacon', 'enabledkpis');
$currentreports = get_config('local_beacon', 'enabledreports');

if (data_submitted() && confirm_sesskey()) {
    $stats   = optional_param_array('enabledstats', [], PARAM_ALPHANUMEXT);
    $kpis    = optional_param_array('enabledkpis', [], PARAM_ALPHANUMEXT);
    $reports = optional_param_array('enabledreports', [], PARAM_ALPHANUMEXT);
    set_config('enabledstats', implode(',', $stats), 'local_beacon');
    set_config('enabledkpis', implode(',', $kpis), 'local_beacon');
    set_config('enabledreports', implode(',', $reports), 'local_beacon');
    redirect(new moodle_url('/local/beacon/configure.php'),
        get_string('setup_saved', 'local_beacon'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Helper: is an item enabled right now (respecting "never configured = default").
$ison = function(?string $raw, string $id, bool $defaulton): bool {
    if ($raw === false || $raw === null) {
        return $defaulton;
    }
    if ($raw === '') {
        return false;
    }
    return in_array($id, array_map('trim', explode(',', $raw)), true);
};

// This page must stay cheap on large sites: it never runs a report query or
// computes a metric live (doing so for all 42 items could exceed the web
// timeout). Reports are described by their grain; metric figures come from the
// warmed cache, showing a dash until the scheduled task first fills it.
$build = function($m, $raw) use ($ison, $context) {
    $isreport = $m instanceof \local_beacon\local\report;
    if ($isreport) {
        $example = $m->grainlabel();
        $icon = $m->icon;
        $expl = $m->description();
        $available = $m->is_available();
    } else {
        $cached = metric_cache::peek($m, $context);
        $val = $cached === null ? null : $cached['value'];
        $example = $val === null
            ? get_string('setup_example_none', 'local_beacon')
            : present::value($val, $m->format) . present::unit($m->format);
        $icon = $m->icon;
        $expl = $m->explanation();
        $available = $m->is_available();
    }
    return [
        'id'          => $m->id,
        'name'        => $m->name(),
        'expl'        => $expl,
        'example'     => $example,
        'family'      => $m->family,
        'familylabel' => get_string('family_' . $m->family, 'local_beacon'),
        'icon'        => icons::svg($icon),
        'checked'     => $ison($raw, $m->id, $m->defaulton),
        'available'   => $available,
        'unavailable' => !$available,
    ];
};

// One indexed read loads every warmed metric value for the peek() calls below.
metric_cache::prefetch($context->id);

$stats = [];
$kpis = [];
foreach (catalogue::metrics() as $m) {
    if ($m->kind === 'kpi') {
        $kpis[] = $build($m, $currentkpis);
    } else {
        $stats[] = $build($m, $currentstats);
    }
}
$reports = [];
foreach (catalogue::reports() as $r) {
    $reports[] = $build($r, $currentreports);
}

$countsel = fn($items) => count(array_filter($items, fn($i) => $i['checked']));

$templatecontext = [
    'actionurl'    => (new moodle_url('/local/beacon/configure.php'))->out(false),
    'sesskey'      => sesskey(),
    'libraryurl'   => (new moodle_url('/local/beacon/index.php'))->out(false),
    'stats'        => $stats,
    'kpis'         => $kpis,
    'reports'      => $reports,
    'statsel'      => $countsel($stats),
    'kpisel'       => $countsel($kpis),
    'reportsel'    => $countsel($reports),
    'statstotal'   => count($stats),
    'kpistotal'    => count($kpis),
    'reportstotal' => count($reports),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_beacon/setup', $templatecontext);
echo $OUTPUT->footer();
