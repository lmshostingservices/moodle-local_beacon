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
 * Tests for the Beacon catalogue and report engine.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon;

use local_beacon\local\catalogue;
use local_beacon\local\filterset;

/**
 * Tests for the Beacon catalogue.
 *
 * @covers \local_beacon\local\catalogue
 */
final class catalogue_test extends \advanced_testcase {
    /**
     * The catalogue defines metrics, reports and personal reports.
     */
    public function test_catalogue_is_populated(): void {
        $this->resetAfterTest();
        $this->assertNotEmpty(catalogue::metrics());
        $this->assertNotEmpty(catalogue::reports());
        $this->assertNotEmpty(catalogue::personal_reports());
        // Ids are unique and non-empty.
        foreach (catalogue::reports() as $id => $rep) {
            $this->assertNotEmpty($id);
            $this->assertSame($id, $rep->id);
        }
    }

    /**
     * Every available report's SQL executes against a real (empty) Moodle DB
     * without error — the strongest guard that the hand-written queries are
     * valid and cross-DB safe.
     */
    public function test_every_report_runs_cleanly(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        foreach (catalogue::reports() as $rep) {
            if (!$rep->is_available()) {
                continue;
            }
            $result = $rep->run(new filterset($context, []), 10);
            $this->assertIsArray($result);
            $this->assertFalse($result['error'], "Report {$rep->id} returned an error");
            $this->assertIsArray($result['rows']);
        }
    }

    /**
     * Reports run cleanly with a representative filter set applied.
     */
    public function test_reports_run_with_filters(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $cohort = $this->getDataGenerator()->create_cohort();
        // A filter set exercising the entity + date filters.
        $filters = filterset::from_params($context, [
            'f_cohort' => [$cohort->id],
            'f_preset' => '30',
        ]);
        foreach (catalogue::reports() as $rep) {
            if (!$rep->is_available()) {
                continue;
            }
            $result = $rep->run($filters, 10);
            $this->assertFalse($result['error'], "Filtered report {$rep->id} errored");
        }
    }

    /**
     * Personal reports run bound to the current user, without error.
     */
    public function test_personal_reports_run_for_current_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $context = \context_system::instance();
        foreach (catalogue::personal_reports() as $rep) {
            if (!$rep->is_available()) {
                continue;
            }
            $result = $rep->run(new filterset($context, []), 10);
            $this->assertFalse($result['error'], "Personal report {$rep->id} errored");
            $this->assertTrue($rep->personal);
        }
    }
}
