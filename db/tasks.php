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
 * Scheduled tasks.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_beacon\task\record_snapshots',
        'blocking'  => 0,
        'minute'    => '20',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_beacon\task\send_deliveries',
        'blocking'  => 0,
        'minute'    => '35',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        // Recompute each trainer's marking-queue count, hourly. Cheap and off the
        // page-load path; the dashboard badge reads the result, never this query.
        'classname' => 'local_beacon\task\refresh_marking_queues',
        'blocking'  => 0,
        'minute'    => '50',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        // Daily digest to each trainer with items waiting (07:10 site time).
        'classname' => 'local_beacon\task\send_marking_digest',
        'blocking'  => 0,
        'minute'    => '10',
        'hour'      => '7',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        // Harvest activity participation from the log into the durable table,
        // hourly, before the log purges. Catches up a backlog over several runs.
        'classname' => 'local_beacon\task\harvest_participation',
        'blocking'  => 0,
        'minute'    => '40',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
