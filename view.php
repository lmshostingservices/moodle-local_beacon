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
 * A single stat / KPI / report detail page.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$contextid = optional_param('contextid', 0, PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);
$id   = optional_param('id', '', PARAM_ALPHANUMEXT);

$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
if ($context instanceof context_course) {
    require_login($context->instanceid);
}

if ($type === 'mine') {
    // Learner self-view: any authenticated (non-guest) user with the capability;
    // always at system scope, since a personal report is bound to the user.
    if (isguestuser()) {
        throw new require_login_exception('Guests cannot view personal reports');
    }
    $context = context_system::instance();
    $contextid = $context->id;
    require_capability('local/beacon:viewmine', $context);
} else {
    require_capability('local/beacon:view', $context);
    if (!in_array($type, ['stat', 'kpi', 'report'], true)) {
        throw new moodle_exception('invalidparameter', 'error');
    }
}

$page = new \local_beacon\output\detail($context, $type, $id);
if (!$page->is_valid()) {
    throw new moodle_exception('itemnotfound', 'local_beacon');
}

$url = new moodle_url('/local/beacon/view.php',
    ['contextid' => $contextid, 'type' => $type, 'id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('pluginname', 'local_beacon') . ': ' . $page->title());
$PAGE->set_heading($context instanceof context_course
    ? format_string($COURSE->fullname) : format_string($SITE->fullname));
$PAGE->add_body_class('local-beacon');

// Reachable via the reports item; add a breadcrumb back to the right library.
$homeurl = ($type === 'mine')
    ? new moodle_url('/local/beacon/myreports.php')
    : new moodle_url('/local/beacon/index.php', ['contextid' => $contextid]);
$PAGE->navbar->add(get_string($type === 'mine' ? 'myreports' : 'pluginname', 'local_beacon'), $homeurl);
$PAGE->navbar->add($page->title());

$output = $PAGE->get_renderer('local_beacon');

echo $output->header();
echo $output->render($page);
echo $output->footer();
