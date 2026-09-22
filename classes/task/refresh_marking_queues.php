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
 * Recomputes each trainer's marking-queue count into the cache table.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\task;

use local_beacon\local\marking;

/**
 * Warms `local_beacon_marking` so the dashboard badge is a one-row read.
 */
class refresh_marking_queues extends \core\task\scheduled_task {
    /**
     * Name shown in the scheduled tasks report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_marking_refresh', 'local_beacon');
    }

    /**
     * Recompute every trainer's count.
     *
     * @return void
     */
    public function execute(): void {
        $n = marking::refresh_all();
        mtrace("local_beacon: refreshed marking-queue counts for {$n} trainer(s).");
    }
}
