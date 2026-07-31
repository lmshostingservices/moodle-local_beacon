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
 * The learner self-view: "My reports". Shows only the viewer's own data.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
if (isguestuser()) {
    throw new require_login_exception('Guests cannot view personal reports');
}

$context = context_system::instance();
require_capability('local/beacon:viewmine', $context);

$PAGE->set_url(new moodle_url('/local/beacon/myreports.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('myreports', 'local_beacon'));
$PAGE->set_heading(get_string('myreports', 'local_beacon'));
$PAGE->add_body_class('local-beacon');

$output = $PAGE->get_renderer('local_beacon');
$page = new \local_beacon\output\mylibrary();

echo $output->header();
echo $output->render($page);
echo $output->footer();
