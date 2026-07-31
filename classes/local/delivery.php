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
 * Scheduled email delivery of a report (with saved filters) to recipients.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Persists delivery schedules and sends them when due.
 */
class delivery {

    /** The frequencies a delivery may use. */
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];
    /** The export formats a delivery may use. */
    public const FORMATS = ['pdf', 'csv'];

    /**
     * A user's delivery schedules for a report.
     *
     * @param int $userid Owner.
     * @param string $reportid Report id.
     * @return \stdClass[]
     */
    public static function for_user(int $userid, string $reportid): array {
        global $DB;
        return $DB->get_records('local_beacon_delivery',
            ['userid' => $userid, 'reportid' => $reportid], 'name ASC');
    }

    /**
     * Create a schedule. Its first run is one interval from now.
     *
     * @param int $userid Owner.
     * @param string $reportid Report id.
     * @param int $contextid Context id.
     * @param string $name Delivery name.
     * @param array $params Filter parameters.
     * @param string $format pdf or csv.
     * @param string $frequency daily, weekly or monthly.
     * @param string $recipients Raw recipient list.
     * @return int New id.
     */
    public static function create(int $userid, string $reportid, int $contextid, string $name,
            array $params, string $format, string $frequency, string $recipients): int {
        global $DB;
        $format = in_array($format, self::FORMATS, true) ? $format : 'pdf';
        $frequency = in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'weekly';
        $now = time();
        return (int) $DB->insert_record('local_beacon_delivery', (object) [
            'userid'      => $userid,
            'reportid'    => $reportid,
            'contextid'   => $contextid,
            'name'        => \core_text::substr(trim($name), 0, 255),
            'params'      => http_build_query($params),
            'format'      => $format,
            'frequency'   => $frequency,
            'recipients'  => implode(', ', self::recipient_list($recipients)),
            'nextrun'     => self::compute_nextrun($frequency, $now),
            'lastrun'     => 0,
            'timecreated' => $now,
        ]);
    }

    /**
     * Delete one of the user's own schedules.
     *
     * @param int $id Delivery id.
     * @param int $userid Owner.
     * @return void
     */
    public static function delete(int $id, int $userid): void {
        global $DB;
        $DB->delete_records('local_beacon_delivery', ['id' => $id, 'userid' => $userid]);
    }

    /**
     * Schedules that are due to send.
     *
     * @param int $now Reference time.
     * @return \stdClass[]
     */
    public static function due(int $now): array {
        global $DB;
        return $DB->get_records_select('local_beacon_delivery',
            'nextrun > 0 AND nextrun <= :now', ['now' => $now], 'nextrun ASC');
    }

    /**
     * The next fire time after a base time for a frequency.
     *
     * @param string $frequency Frequency.
     * @param int $from Base time.
     * @return int
     */
    public static function compute_nextrun(string $frequency, int $from): int {
        switch ($frequency) {
            case 'daily':
                return $from + DAYSECS;
            case 'monthly':
                return strtotime('+1 month', $from) ?: ($from + 30 * DAYSECS);
            case 'weekly':
            default:
                return $from + 7 * DAYSECS;
        }
    }

    /**
     * Parse and validate a raw recipient list into unique valid addresses.
     *
     * @param string $raw Comma / semicolon / whitespace separated addresses.
     * @return string[]
     */
    public static function recipient_list(string $raw): array {
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && validate_email($p)) {
                $out[strtolower($p)] = $p;
            }
        }
        return array_values($out);
    }

    /**
     * Run a schedule's report and email it to every recipient.
     *
     * @param \stdClass $d Delivery record.
     * @return bool True if all recipients were mailed.
     */
    public static function send(\stdClass $d): bool {
        global $CFG, $SITE;

        try {
            $context = \context::instance_by_id($d->contextid, IGNORE_MISSING) ?: \context_system::instance();
        } catch (\Throwable $e) {
            $context = \context_system::instance();
        }

        $report = catalogue::report($d->reportid);
        if ($report === null || !$report->is_available()) {
            return false;
        }

        $emails = self::recipient_list((string) $d->recipients);
        if (empty($emails)) {
            return false;
        }

        $params = [];
        parse_str((string) $d->params, $params);
        $filters = filterset::from_params($context, $params);
        $result = $report->run($filters, 5000);
        $rows = $result['rows'];

        $iscsv = ($d->format === 'csv');
        $content = $iscsv ? export::csv_string($report, $rows)
            : export::pdf_string($report, $rows, $context);
        $ext = $iscsv ? 'csv' : 'pdf';

        $tmpdir = make_temp_directory('local_beacon');
        $abspath = $tmpdir . '/' . uniqid('bc_', true) . '.' . $ext;
        file_put_contents($abspath, $content);
        // email_to_user wants a path relative to dataroot where possible.
        $attach = (strpos($abspath, $CFG->dataroot . '/') === 0)
            ? substr($abspath, strlen($CFG->dataroot . '/')) : $abspath;
        $attachname = export::filename_for($report) . '.' . $ext;

        $from = \core_user::get_noreply_user();
        $a = (object) [
            'name'   => $d->name ?: $report->name(),
            'report' => $report->name(),
            'site'   => format_string($SITE->fullname),
        ];
        $subject = get_string('delivery_email_subject', 'local_beacon', $a);
        $text = get_string('delivery_email_body', 'local_beacon', $a);
        $html = text_to_html($text, false, false, true);

        $ok = true;
        foreach ($emails as $email) {
            $to = clone \core_user::get_noreply_user();
            $to->email = $email;
            $to->firstname = '';
            $to->lastname = $email;
            $to->maildisplay = 1;
            $to->mailformat = 1;
            $to->id = -99;
            try {
                $ok = email_to_user($to, $from, $subject, $text, $html, $attach, $attachname) && $ok;
            } catch (\Throwable $e) {
                debugging('Beacon delivery email failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $ok = false;
            }
        }

        @unlink($abspath);
        return $ok;
    }
}
