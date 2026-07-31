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
 * Saved views: a named filter set a user can reopen in one click.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * CRUD for a user's saved report views. Views are private to their owner.
 */
class savedview {

    /**
     * A user's saved views for a report, alphabetical.
     *
     * @param int $userid User id.
     * @param string $reportid Report id.
     * @return \stdClass[]
     */
    public static function for_user(int $userid, string $reportid): array {
        global $DB;
        return $DB->get_records('local_beacon_savedview',
            ['userid' => $userid, 'reportid' => $reportid], 'name ASC');
    }

    /**
     * Save a new view.
     *
     * @param int $userid Owner.
     * @param string $reportid Report id.
     * @param string $name View name.
     * @param array $params Filter parameters (flat).
     * @return int New id.
     */
    public static function create(int $userid, string $reportid, string $name, array $params): int {
        global $DB;
        return (int) $DB->insert_record('local_beacon_savedview', (object) [
            'userid'      => $userid,
            'reportid'    => $reportid,
            'name'        => \core_text::substr(trim($name), 0, 255),
            'params'      => http_build_query($params),
            'timecreated' => time(),
        ]);
    }

    /**
     * Delete one of the user's own views.
     *
     * @param int $id View id.
     * @param int $userid Owner (guards against deleting another user's view).
     * @return void
     */
    public static function delete(int $id, int $userid): void {
        global $DB;
        $DB->delete_records('local_beacon_savedview', ['id' => $id, 'userid' => $userid]);
    }

    /**
     * The stored parameters of a view as a flat array.
     *
     * @param \stdClass $view View record.
     * @return array
     */
    public static function params(\stdClass $view): array {
        $out = [];
        parse_str((string) $view->params, $out);
        return $out;
    }
}
