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
 * Sends any report email deliveries that are due.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\task;

use local_beacon\local\delivery;

/**
 * Fires each due delivery, then reschedules it for its next interval. One
 * failure never blocks the others.
 */
class send_deliveries extends \core\task\scheduled_task {

    /**
     * Name shown in the scheduled tasks report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_deliveries', 'local_beacon');
    }

    /**
     * Send everything due at this run.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $due = delivery::due($now);

        foreach ($due as $d) {
            try {
                delivery::send($d);
            } catch (\Throwable $e) {
                mtrace('Beacon delivery ' . $d->id . ' failed: ' . $e->getMessage());
            }
            // Reschedule regardless, so a broken one never floods or blocks.
            $d->lastrun = $now;
            $d->nextrun = delivery::compute_nextrun($d->frequency, $now);
            $DB->update_record('local_beacon_delivery', $d);
        }
    }
}
