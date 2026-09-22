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
 * One-off backfill of the durable participation table.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\task;

use local_beacon\local\participation_harvester;

/**
 * An adhoc task queued the moment the durable participation table is created, so
 * the harvest runs at the very next cron pass instead of waiting up to an hour
 * for the hourly scheduled task. Without it, a freshly upgraded site shows an
 * empty participation table (every Activities count 0, an empty drill-down)
 * until the first scheduled harvest lands.
 *
 * It catches up a whole batch per run and, while records remain, re-queues
 * itself so a large historic backlog is cleared over consecutive cron passes
 * without any admin action. Each source uses a stored high-water mark, so
 * re-running never double-counts.
 */
class backfill_participation extends \core\task\adhoc_task {
    /**
     * Run one backfill pass; re-queue while there is still more to harvest.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_beacon_participation')) {
            return;
        }
        $log = participation_harvester::harvest();
        $cmc = participation_harvester::harvest_completion();
        $att = participation_harvester::harvest_attempts();
        $done = $log + $cmc + $att;
        mtrace("local_beacon: backfill harvested {$log} log row(s), {$cmc} completion " .
               "record(s) and {$att} attempt/submission record(s).");

        // If this pass moved a full batch, more may remain — come back next cron
        // pass. A pass that harvested little or nothing means we have caught up.
        if ($done >= 20000) {
            $next = new self();
            \core\task\manager::queue_adhoc_task($next);
            mtrace('local_beacon: more participation to harvest — re-queued backfill.');
        }
    }
}
