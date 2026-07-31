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
 * A branded PDF (or CSV) export of a report.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_beacon\local\catalogue;
use local_beacon\local\export;
use local_beacon\local\filterset;

$contextid = optional_param('contextid', 0, PARAM_INT);
$id        = required_param('id', PARAM_ALPHANUMEXT);
$format    = optional_param('format', 'pdf', PARAM_ALPHA);
$type      = optional_param('type', 'report', PARAM_ALPHA);

$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();

require_login();

if ($type === 'mine') {
    // Learner self-view export: system scope, personal report bound to $USER.
    if (isguestuser()) {
        throw new require_login_exception('Guests cannot export personal reports');
    }
    $context = context_system::instance();
    require_capability('local/beacon:viewmine', $context);
    $report = catalogue::personal_report($id);
    if ($report === null || !$report->is_available()) {
        throw new moodle_exception('itemnotfound', 'local_beacon');
    }
    // The closure binds itself to $USER; the filterset is irrelevant here.
    $result = $report->run(new filterset($context, []), 5000);
} else {
    if ($context instanceof context_course) {
        require_login($context->instanceid);
    }
    require_capability('local/beacon:view', $context);

    $report = catalogue::report($id);
    if ($report === null || !$report->is_available()) {
        throw new moodle_exception('itemnotfound', 'local_beacon');
    }

    // The export honours the same filters the on-screen report was viewed with,
    // carried on the download URL — so what you see is what you download.
    $filters = filterset::from_request($context);
    // Same course hard-lock as the on-screen report, so an export can't leak
    // another course's data via URL tampering.
    if ($context instanceof context_course) {
        $filters->lock_course($context->instanceid);
    }

    // A generous cap for an export, well above the on-screen preview.
    $result = $report->run($filters, 5000);
}

if ($format === 'csv') {
    export::csv($report, $result['rows']);
} else {
    export::pdf($report, $result['rows'], $context);
}
