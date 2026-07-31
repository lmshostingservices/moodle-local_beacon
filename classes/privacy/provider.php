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
 * Privacy provider.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Beacon stores report requests, which reference the submitting user.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe the data this plugin stores and shares.
     *
     * @param collection $collection Collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_beacon_request', [
            'userid'         => 'privacy:metadata:local_beacon_request:userid',
            'requesteremail' => 'privacy:metadata:local_beacon_request:requesteremail',
            'detail'         => 'privacy:metadata:local_beacon_request:detail',
            'timecreated'    => 'privacy:metadata:local_beacon_request:timecreated',
        ], 'privacy:metadata:local_beacon_request');

        $collection->add_external_location_link('support', [
            'title'  => 'privacy:metadata:support:title',
            'detail' => 'privacy:metadata:support:detail',
            'email'  => 'privacy:metadata:support:email',
        ], 'privacy:metadata:support');

        $collection->add_database_table('local_beacon_savedview', [
            'userid'      => 'privacy:metadata:local_beacon_savedview:userid',
            'name'        => 'privacy:metadata:local_beacon_savedview:name',
            'params'      => 'privacy:metadata:local_beacon_savedview:params',
            'timecreated' => 'privacy:metadata:local_beacon_savedview:timecreated',
        ], 'privacy:metadata:local_beacon_savedview');

        $collection->add_database_table('local_beacon_delivery', [
            'userid'      => 'privacy:metadata:local_beacon_delivery:userid',
            'name'        => 'privacy:metadata:local_beacon_delivery:name',
            'recipients'  => 'privacy:metadata:local_beacon_delivery:recipients',
            'timecreated' => 'privacy:metadata:local_beacon_delivery:timecreated',
        ], 'privacy:metadata:local_beacon_delivery');

        return $collection;
    }

    /**
     * Contexts holding data for a user. Requests are always at system context.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        global $DB;
        if ($DB->record_exists('local_beacon_request', ['userid' => $userid])
                || $DB->record_exists('local_beacon_savedview', ['userid' => $userid])
                || $DB->record_exists('local_beacon_delivery', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users within a context.
     *
     * @param userlist $userlist Userlist.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        global $DB;
        foreach (['local_beacon_request', 'local_beacon_savedview', 'local_beacon_delivery'] as $table) {
            $userids = $DB->get_fieldset_select($table, 'DISTINCT userid', 'userid > 0');
            if ($userids) {
                $userlist->add_users($userids);
            }
        }
    }

    /**
     * Export a user's requests.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $records = $DB->get_records('local_beacon_request', ['userid' => $user->id]);
            $data = array_map(fn($r) => (object) [
                'kind'        => $r->kind,
                'title'       => $r->title,
                'detail'      => $r->detail,
                'email'       => $r->requesteremail,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], array_values($records));
            $views = array_map(fn($r) => (object) [
                'name'        => $r->name,
                'params'      => $r->params,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], array_values($DB->get_records('local_beacon_savedview', ['userid' => $user->id])));

            $deliveries = array_map(fn($r) => (object) [
                'name'        => $r->name,
                'recipients'  => $r->recipients,
                'frequency'   => $r->frequency,
                'format'      => $r->format,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], array_values($DB->get_records('local_beacon_delivery', ['userid' => $user->id])));

            if ($data || $views || $deliveries) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_beacon')],
                    (object) ['requests' => $data, 'savedviews' => $views, 'deliveries' => $deliveries]);
            }
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_system) {
            global $DB;
            $DB->delete_records('local_beacon_request');
            $DB->delete_records('local_beacon_savedview');
            $DB->delete_records('local_beacon_delivery');
        }
    }

    /**
     * Delete data for one user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $userid = $contextlist->get_user()->id;
                $DB->delete_records('local_beacon_request', ['userid' => $userid]);
                $DB->delete_records('local_beacon_savedview', ['userid' => $userid]);
                $DB->delete_records('local_beacon_delivery', ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete data for a set of users.
     *
     * @param approved_userlist $userlist Approved userlist.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        foreach (['local_beacon_request', 'local_beacon_savedview', 'local_beacon_delivery'] as $table) {
            $DB->delete_records_select($table, "userid $insql", $params);
        }
    }
}
