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
 * "Request a report" — a styled form that emails support and thanks the user.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_beacon\local\requests;

$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
// Requesting a new report is an admin-only action.
require_capability('moodle/site:config', context_system::instance());

$url = new moodle_url('/local/beacon/request.php', $contextid ? ['contextid' => $contextid] : []);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('requestareport', 'local_beacon'));
$PAGE->set_heading(format_string($SITE->fullname));
$PAGE->add_body_class('local-beacon');
$PAGE->navbar->add(
    get_string('pluginname', 'local_beacon'),
    new moodle_url('/local/beacon/index.php', ['contextid' => $contextid])
);
$PAGE->navbar->add(get_string('requestareport', 'local_beacon'));

$backurl = (new moodle_url('/local/beacon/index.php', ['contextid' => $contextid]))->out(false);

$errors = [];
$values = ['kind' => 'stat', 'title' => '', 'detail' => '', 'email' => $USER->email];
$sent = false;

if (data_submitted() && confirm_sesskey()) {
    $values['kind']   = optional_param('kind', 'stat', PARAM_ALPHA);
    $values['title']  = trim(optional_param('title', '', PARAM_TEXT));
    $values['detail'] = trim(optional_param('detail', '', PARAM_TEXT));
    $values['email']  = trim(optional_param('email', '', PARAM_TEXT));

    if (!in_array($values['kind'], ['stat', 'kpi', 'report'], true)) {
        $values['kind'] = 'stat';
    }
    if ($values['title'] === '') {
        $errors['title'] = get_string('error_required', 'local_beacon');
    }
    if ($values['detail'] === '') {
        $errors['detail'] = get_string('error_required', 'local_beacon');
    }
    if (!validate_email($values['email'])) {
        $errors['email'] = get_string('error_email', 'local_beacon');
    }

    if (empty($errors)) {
        requests::submit($context, $values['kind'], $values['title'], $values['detail'], $values['email']);
        $sent = true;
    }
}

$kinds = [
    [
        'value'    => 'stat',
        'label'    => get_string('kind_stat', 'local_beacon'),
        'hint'     => get_string('kind_stat_hint', 'local_beacon'),
        'selected' => $values['kind'] === 'stat',
    ],
    [
        'value'    => 'kpi',
        'label'    => get_string('kind_kpi', 'local_beacon'),
        'hint'     => get_string('kind_kpi_hint', 'local_beacon'),
        'selected' => $values['kind'] === 'kpi',
    ],
    [
        'value'    => 'report',
        'label'    => get_string('kind_report', 'local_beacon'),
        'hint'     => get_string('kind_report_hint', 'local_beacon'),
        'selected' => $values['kind'] === 'report',
    ],
];

$templatecontext = [
    'backurl'    => $backurl,
    'sent'       => $sent,
    'sesskey'    => sesskey(),
    'actionurl'  => $url->out(false),
    'supportemail' => requests::SUPPORT_EMAIL,
    'kinds'      => $kinds,
    'title'      => s($values['title']),
    'detail'     => s($values['detail']),
    'email'      => s($values['email']),
    'err_title'  => $errors['title'] ?? '',
    'err_detail' => $errors['detail'] ?? '',
    'err_email'  => $errors['email'] ?? '',
    'sentkind'   => $sent ? get_string('kind_' . $values['kind'], 'local_beacon') : '',
    'senttitle'  => s($values['title']),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_beacon/request', $templatecontext);
echo $OUTPUT->footer();
