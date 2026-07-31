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
 * Create or delete a scheduled email delivery, then return to the report.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_beacon\local\catalogue;
use local_beacon\local\delivery;
use local_beacon\local\filterset;

$contextid = optional_param('contextid', 0, PARAM_INT);
$reportid  = required_param('reportid', PARAM_ALPHANUMEXT);
$action    = required_param('action', PARAM_ALPHA);

$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();
if ($context instanceof context_course) {
    require_login($context->instanceid);
}
require_capability('local/beacon:view', $context);
require_sesskey();

$report = catalogue::report($reportid);
if ($report === null || !$report->is_available()) {
    throw new moodle_exception('itemnotfound', 'local_beacon');
}

$reporturl = new moodle_url('/local/beacon/view.php',
    ['contextid' => $contextid, 'type' => 'report', 'id' => $reportid]);

if ($action === 'save') {
    $name       = trim(required_param('name', PARAM_TEXT));
    $format     = required_param('format', PARAM_ALPHA);
    $frequency  = required_param('frequency', PARAM_ALPHA);
    $recipients = required_param('recipients', PARAM_RAW_TRIMMED);

    $emails = delivery::recipient_list($recipients);
    if (empty($emails)) {
        redirect($reporturl, get_string('delivery_norecipients', 'local_beacon'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $filters = filterset::from_request($context);
    delivery::create((int) $USER->id, $reportid, (int) $context->id,
        $name !== '' ? $name : $report->name(), $filters->url_params(), $format, $frequency, $recipients);

    redirect($reporturl, get_string('delivery_saved', 'local_beacon', count($emails)), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete') {
    $id = required_param('id', PARAM_INT);
    delivery::delete($id, (int) $USER->id);
    redirect($reporturl, get_string('delivery_deleted', 'local_beacon'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

redirect($reporturl);
