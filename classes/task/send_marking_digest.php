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
 * Emails each trainer a daily digest of how many assessments await marking.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\task;

/**
 * Once a day, emails every trainer who has submissions waiting a short digest:
 * the count and a link into their My marking queue report. The email carries
 * only the count and a link — never learner data — so no per-row capability
 * check is needed and there is no risk of showing a trainer someone else's data.
 */
class send_marking_digest extends \core\task\scheduled_task {
    /**
     * Name shown in the scheduled tasks report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_marking_digest', 'local_beacon');
    }

    /**
     * Send today's digest to each trainer with a non-zero, not-yet-notified count.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        // Admin switch: the digest can be turned off entirely.
        if (!get_config('local_beacon', 'markingdigest')) {
            mtrace('local_beacon: marking digest disabled by admin setting.');
            return;
        }

        $today = (int) userdate(time(), '%Y%m%d');
        $rows = $DB->get_records_select(
            'local_beacon_marking',
            'tocount > 0 AND lastnotified < :today',
            ['today' => $today]
        );
        if (!$rows) {
            mtrace('local_beacon: no trainers to notify.');
            return;
        }

        $from = \core_user::get_noreply_user();
        $url = new \moodle_url('/local/beacon/view.php', ['type' => 'report', 'id' => 'my_marking_queue']);
        $link = $url->out(false);
        $sent = 0;

        foreach ($rows as $row) {
            $user = \core_user::get_user($row->userid);
            if (!$user || $user->deleted || $user->suspended || empty($user->email)) {
                continue;
            }

            $count = (int) $row->tocount;
            $a = (object) [
                'count' => $count,
                'oldest' => $row->oldest ? format_time(time() - (int) $row->oldest) : '',
                'link' => $link,
                'sitename' => format_string($DB->get_field('course', 'fullname', ['id' => SITEID])),
            ];
            $subject = get_string('digest_subject', 'local_beacon', $count);
            $text = get_string('digest_body', 'local_beacon', $a);
            $html = get_string('digest_body_html', 'local_beacon', $a);

            if (email_to_user($user, $from, $subject, $text, $html)) {
                $row->lastnotified = $today;
                $DB->update_record('local_beacon_marking', $row);
                $sent++;
            } else {
                mtrace('local_beacon: digest email to user ' . $row->userid . ' failed.');
            }
        }

        mtrace("local_beacon: sent {$sent} marking digest email(s).");
    }
}
