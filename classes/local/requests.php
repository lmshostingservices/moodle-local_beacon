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
 * Handles "build me a report" requests: stored, then emailed to support.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Persists a request and notifies the support inbox.
 */
class requests {
    /** Where new-report requests are delivered. */
    public const SUPPORT_EMAIL = 'support@lmshostingservices.com';

    /**
     * Store the request and email support. Never throws to the page: a failed
     * email is logged and recorded, the user still gets their thank-you.
     *
     * @param \context $context Context it came from.
     * @param string $kind stat, kpi or report.
     * @param string $title Requested name.
     * @param string $detail What it should show.
     * @param string $email Reply-to address.
     * @return void
     */
    public static function submit(
        \context $context,
        string $kind,
        string $title,
        string $detail,
        string $email
    ): void {
        global $DB, $USER, $CFG, $SITE;

        $record = (object) [
            'kind'           => $kind,
            'title'          => $title,
            'detail'         => $detail,
            'requestername'  => fullname($USER),
            'requesteremail' => $email,
            'userid'         => (int) $USER->id,
            'siteurl'        => $CFG->wwwroot,
            'status'         => 'sent',
            'timecreated'    => time(),
        ];

        $sent = self::notify($record);
        $record->status = $sent ? 'sent' : 'failed';

        try {
            $DB->insert_record('local_beacon_request', $record);
        } catch (\dml_exception $e) {
            debugging('Beacon could not store request: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Send the notification email to support, replying to the requester.
     *
     * @param \stdClass $record The request.
     * @return bool True if handed to the mailer.
     */
    private static function notify(\stdClass $record): bool {
        global $SITE;

        $kindlabel = get_string('kind_' . $record->kind, 'local_beacon');

        // A recipient user object for the support inbox.
        $to = \core_user::get_noreply_user();
        $to = clone $to;
        $to->email = self::SUPPORT_EMAIL;
        $to->firstname = 'LMS Hosting';
        $to->lastname = 'Support';
        $to->maildisplay = 1;
        $to->mailformat = 1;
        $to->id = -99;

        $from = \core_user::get_noreply_user();

        $a = (object) [
            'kind'    => $kindlabel,
            'title'   => $record->title,
            'detail'  => $record->detail,
            'name'    => $record->requestername,
            'email'   => $record->requesteremail,
            'site'    => format_string($SITE->fullname),
            'siteurl' => $record->siteurl,
        ];

        $subject = get_string('email_subject', 'local_beacon', $a);
        $text = get_string('email_body', 'local_beacon', $a);
        $html = text_to_html($text, false, false, true);

        try {
            return email_to_user(
                $to,
                $from,
                $subject,
                $text,
                $html,
                '',
                '',
                true,
                $record->requesteremail,
                $record->requestername
            );
        } catch (\Throwable $e) {
            debugging('Beacon request email failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
