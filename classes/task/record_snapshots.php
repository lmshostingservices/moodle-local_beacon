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
 * Records today's value of each enabled metric, building trend history.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\task;

use local_beacon\local\metric_cache;

/**
 * Warms the metric cache (so the library never computes on page load) and
 * records the daily trend snapshot — computing each metric exactly once.
 */
class record_snapshots extends \core\task\scheduled_task {
    /**
     * Name shown in the scheduled tasks report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_snapshots', 'local_beacon');
    }

    /**
     * Refresh the cache and today's snapshot at the system context.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $context = \context_system::instance();
        $daykey = (int) userdate(time(), '%Y%m%d');
        $now = time();

        // One computation per metric: writes the read-through cache and returns
        // the values so the snapshot below reuses them (no second query).
        $results = metric_cache::refresh_all($context);

        foreach ($results as $metricid => $result) {
            if ($result['value'] === null) {
                continue;
            }

            $existing = $DB->get_record(
                'local_beacon_snapshot',
                ['metric' => $metricid, 'contextid' => $context->id, 'daykey' => $daykey]
            );

            if ($existing) {
                $existing->value = $result['value'];
                $existing->timecreated = $now;
                $DB->update_record('local_beacon_snapshot', $existing);
            } else {
                $DB->insert_record('local_beacon_snapshot', (object) [
                    'metric'      => $metricid,
                    'contextid'   => $context->id,
                    'value'       => $result['value'],
                    'daykey'      => $daykey,
                    'timecreated' => $now,
                ]);
            }
        }

        // Keep history bounded: drop snapshots older than ~13 months.
        $DB->delete_records_select(
            'local_beacon_snapshot',
            'daykey < :cut',
            ['cut' => (int) userdate($now - 400 * DAYSECS, '%Y%m%d')]
        );
    }
}
