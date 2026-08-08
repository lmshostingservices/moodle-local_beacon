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
 * The reports library.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
if ($context instanceof context_course) {
    require_login($context->instanceid);
}
// Learners (no staff dashboard access) are sent to their own self-view.
if (
    !has_capability('local/beacon:view', $context)
    && $context instanceof context_system
    && !isguestuser()
    && has_capability('local/beacon:viewmine', $context)
) {
    redirect(new moodle_url('/local/beacon/myreports.php'));
}
require_capability('local/beacon:view', $context);

$url = new moodle_url('/local/beacon/index.php', $contextid ? ['contextid' => $contextid] : []);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('pluginname', 'local_beacon'));
$PAGE->set_heading($context instanceof context_course
    ? format_string($COURSE->fullname) : format_string($SITE->fullname));
$PAGE->add_body_class('local-beacon');

$output = $PAGE->get_renderer('local_beacon');
$page = new \local_beacon\output\library($context);

echo $output->header();
echo $output->render($page);
echo $output->footer();
