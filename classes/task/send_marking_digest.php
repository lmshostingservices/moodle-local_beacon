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
 * Once a day, emails every trainer who has submissions waiting a digest: the
 * count, their own outstanding marking queue, and a link into My marking queue.
 * The list is produced by running the My marking queue report with the trainer's
 * own viewer scope forced on (their user id bound into the scope predicate), so a
 * trainer can only ever be shown their own learners' items — never anyone else's.
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
            $html = get_string('digest_body_html', 'local_beacon', $a) . self::queue_table((int) $row->userid);

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

    /**
     * Build an HTML table of one trainer's own outstanding marking queue, scoped
     * to exactly what they are responsible for (their courses / groups), so the
     * digest email lists the actual items — not just a count. Returns '' if the
     * report can't be built or the trainer has nothing (the count path handles
     * the "you have X to mark" line either way).
     *
     * @param int $uid Trainer user id.
     * @return string HTML fragment (empty on error / no rows).
     */
    private static function queue_table(int $uid): string {
        $context = \context_system::instance();
        $filters = new \local_beacon\local\filterset($context, []);
        // Hard-scope the report to this trainer's own learners (group-aware), the
        // same scope the trainer sees in My marking queue. Runs under cron, so the
        // viewer id is passed explicitly and forced.
        $filters->enable_viewer_scope($uid, true);
        $report = \local_beacon\local\catalogue::report('my_marking_queue');
        if ($report === null || !$report->is_available()) {
            return '';
        }
        $cap = 200;
        $result = $report->run($filters, $cap);
        if (!empty($result['error']) || empty($result['rows'])) {
            return '';
        }
        $rows = $result['rows'];
        $total = (int) ($result['total'] ?? count($rows));

        $cell = 'padding:6px 9px;border-bottom:1px solid #e4e8f0;text-align:left;font-size:13px;';
        $html = '<table style="border-collapse:collapse;margin-top:14px;width:100%;max-width:760px;">';
        $html .= '<thead><tr>';
        foreach ($report->columns as $c) {
            $html .= '<th style="' . $cell . 'background:#12324f;color:#fff;">'
                . s($report->columnlabel($c)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $c) {
                $html .= '<td style="' . $cell . '">' . s((string) ($c['v'] ?? '')) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        if ($total > count($rows)) {
            $html .= '<p style="font-size:12px;color:#8a94a6;">'
                . s(get_string('digest_more', 'local_beacon', $total - count($rows))) . '</p>';
        }
        return $html;
    }
}
