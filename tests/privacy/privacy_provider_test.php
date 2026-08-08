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
 * Tests for the Beacon privacy provider.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;

/**
 * Tests for the Beacon privacy provider.
 *
 * @covers \local_beacon\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Metadata describes the tables Beacon stores.
     */
    public function test_get_metadata(): void {
        $collection = new collection('local_beacon');
        $result = provider::get_metadata($collection);
        $this->assertSame($collection, $result);
        $this->assertNotEmpty($result->get_collection());
    }

    /**
     * A user's stored records are found, exported and deleted.
     */
    public function test_context_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_beacon_request', (object) [
            'kind' => 'report', 'title' => 'Test', 'detail' => 'Detail',
            'requestername' => 'Tester', 'requesteremail' => 'tester@example.com',
            'userid' => $user->id, 'siteurl' => 'https://example.com',
            'status' => 'sent', 'timecreated' => time(),
        ]);
        $DB->insert_record('local_beacon_savedview', (object) [
            'userid' => $user->id, 'reportid' => 'learner_roster',
            'name' => 'My view', 'params' => 'f_cohort%5B0%5D=1', 'timecreated' => time(),
        ]);

        // The user has data at the system context.
        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertNotEmpty($contextlist->get_contextids());

        // Export runs without error.
        $this->export_context_data_for_user($user->id, \context_system::instance(), 'local_beacon');

        // Deletion removes every trace of the user.
        $approved = new approved_contextlist($user, 'local_beacon', $contextlist->get_contextids());
        provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records('local_beacon_request', ['userid' => $user->id]));
        $this->assertEquals(0, $DB->count_records('local_beacon_savedview', ['userid' => $user->id]));
    }
}
