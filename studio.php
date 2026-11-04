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
 * Report Studio — describe a report in plain language; Beacon builds it (no AI).
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$contextid = optional_param('contextid', 0, PARAM_INT);
$q = optional_param('q', '', PARAM_TEXT);

$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
if ($context instanceof context_course) {
    require_login($context->instanceid);
}

// Staff only: a manager (system capability) or a teacher in any course. A
// teacher's data is hard-limited to their own learners by the engine's scope.
if (!\local_beacon\local\access::is_staff($context)) {
    require_capability('local/beacon:view', $context);
}

$url = new moodle_url('/local/beacon/studio.php', ['contextid' => $contextid]);
if ($q !== '') {
    $url->param('q', $q);
}
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('pluginname', 'local_beacon') . ': ' . get_string('studio', 'local_beacon'));
$PAGE->set_heading(
    $context instanceof context_course
        ? format_string($COURSE->fullname) : format_string($SITE->fullname)
);
$PAGE->add_body_class('local-beacon');
$PAGE->navbar->add(get_string('pluginname', 'local_beacon'),
    new moodle_url('/local/beacon/index.php', ['contextid' => $contextid]));
$PAGE->navbar->add(get_string('studio', 'local_beacon'));

$page = new \local_beacon\output\studio($context, $q);
$output = $PAGE->get_renderer('local_beacon');

echo $output->header();
echo $output->render($page);
echo $output->footer();
