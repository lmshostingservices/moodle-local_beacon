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
 * Harvests activity participation from the standard log into the durable table.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\task;

use local_beacon\local\participation_harvester;

/**
 * Runs the participation harvester each hour. It picks up where it left off, so
 * on a fresh install it backfills from whatever log rows still exist and then
 * keeps pace with new activity.
 */
class harvest_participation extends \core\task\scheduled_task {
    /**
     * Name shown in the scheduled tasks report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_participation', 'local_beacon');
    }

    /**
     * Harvest a batch. A large backlog is caught up over several runs.
     *
     * @return void
     */
    public function execute(): void {
        $log = participation_harvester::harvest();
        $cmc = participation_harvester::harvest_completion();
        $att = participation_harvester::harvest_attempts();
        mtrace("local_beacon: harvested {$log} log row(s), {$cmc} completion record(s) " .
               "and {$att} attempt/submission record(s).");
    }
}
