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
 * Student feedback: survey responses from AI Knowledge Check (survey mode),
 * matched to the workshop each respondent was booked into (local_workshops).
 *
 * Data sources (read-only):
 *  - aiknowledgecheck (surveymode = 1), aiknowledgecheck_questions — the survey and its questions.
 *    A scale answer is stored as the zero-based index of the option; its label is the
 *    question's own answer{index + 1} column. Free text is stored as ['answer' => -1, 'freetext' => …].
 *  - aiknowledgecheck_attempts (status = 1 = finished) — one or more per learner; the latest
 *    finished attempt per learner is the one reported (same rule as a re-submitted survey).
 *  - local_workshops / local_workshop_participants — the workshop booking that links a learner
 *    to an individual workshop. Cancelled bookings are ignored. Bookings carry a Moodle user id
 *    (local_workshops 1.0.33+); older rows without one are matched on email.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Loads and summarises survey feedback by course, workshop, workshop date, customer and cohort.
 */
class feedback {
    /** @var string[] Tables this feature reads; all must exist. */
    public const TABLES = [
        'aiknowledgecheck', 'aiknowledgecheck_questions', 'aiknowledgecheck_attempts',
        'local_workshops', 'local_workshop_participants',
    ];

    /** @var string Booking status that never counts. */
    private const CANCELLED = 'Cancelled';

    /** @var string Booking status that marks a learner as having attended. */
    private const ATTENDED = 'Attended';

    /**
     * Whether both plugins are installed on this site.
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (self::TABLES as $t) {
            if (!$dbman->table_exists($t)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every survey-mode AI Knowledge Check on the site (live course modules only),
     * optionally limited to some courses.
     *
     * @param int[]|null $courseids Allowed courses, or null for all.
     * @return \stdClass[] keyed by aiknowledgecheck id: id, name, course, cmid, coursename.
     */
    public static function surveys(?array $courseids = null): array {
        global $DB;
        if ($courseids !== null && !$courseids) {
            return [];
        }
        $where = '';
        $params = ['modname' => 'aiknowledgecheck'];
        if ($courseids !== null) {
            [$in, $p] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'fbc');
            $where = " AND k.course $in";
            $params += $p;
        }
        $sql = "SELECT k.id, k.name, k.course, cm.id AS cmid, c.fullname AS coursename
                  FROM {aiknowledgecheck} k
                  JOIN {modules} m ON m.name = :modname
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = k.id AND cm.deletioninprogress = 0
                  JOIN {course} c ON c.id = k.course
                 WHERE k.surveymode = 1 $where
              ORDER BY c.fullname, c.id, cm.id";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * The workshops linked to a course, newest first.
     *
     * @param int $courseid Course id.
     * @return \stdClass[] keyed by workshop id.
     */
    public static function workshops(int $courseid): array {
        global $DB;
        return $DB->get_records(
            'local_workshops',
            ['courseid' => $courseid],
            'startdatetime DESC, id DESC',
            'id, workshopcode, workshopname, customername, startdatetime, status, deliverytype'
        );
    }

    /**
     * A workshop's label for menus: code · name · date.
     *
     * @param \stdClass $w Workshop record.
     * @return string
     */
    public static function workshop_label(\stdClass $w): string {
        $parts = [];
        if (trim((string) $w->workshopcode) !== '') {
            $parts[] = trim($w->workshopcode);
        }
        $parts[] = format_string($w->workshopname, true, ['context' => \context_system::instance()]);
        if (!empty($w->startdatetime)) {
            $parts[] = userdate((int) $w->startdatetime, get_string('strftimedate', 'langconfig'));
        }
        return implode(' · ', $parts);
    }

    /**
     * A survey's questions, in the order learners answer them, with option labels and
     * how each is charted.
     *
     * kind: 'freetext'; 'rated' (every option starts with a number, e.g. "1 = Very limited
     * knowledge" — scored by that number); or 'scale' (an ordered list running from most
     * positive to most negative, as AI Knowledge Check stores it).
     *
     * @param int $kid aiknowledgecheck id.
     * @return array[] list of question arrays: id, num, text, kind, options (index => label),
     *                 scores (index => number|null).
     */
    public static function questions(int $kid): array {
        global $DB;
        $recs = $DB->get_records(
            'aiknowledgecheck_questions',
            ['aiknowledgecheckid' => $kid],
            'questionnumber ASC, id ASC',
            'id, questionnumber, questiontext, questiontype, answer1, answer2, answer3, answer4, answer5'
        );
        $out = [];
        $num = 0;
        foreach ($recs as $r) {
            $num++;
            $text = self::plain_text((string) $r->questiontext);
            $q = ['id' => (int) $r->id, 'num' => $num, 'text' => $text, 'kind' => 'freetext',
                  'options' => [], 'scores' => []];
            if (($r->questiontype ?? 'scale') !== 'freetext') {
                for ($i = 0; $i < 5; $i++) {
                    $f = 'answer' . ($i + 1);
                    $label = trim((string) ($r->$f ?? ''));
                    if ($label !== '') {
                        $q['options'][$i] = $label;
                    }
                }
                $rated = !empty($q['options']);
                foreach ($q['options'] as $i => $label) {
                    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:=|-|–|—|:|\.|\))/u', $label, $m)) {
                        $q['scores'][$i] = (float) $m[1];
                    } else {
                        $rated = false;
                    }
                }
                if ($rated) {
                    $q['kind'] = 'rated';
                } else {
                    // Positional: the first option is the most positive.
                    $n = count($q['options']);
                    $q['scores'] = [];
                    $pos = 0;
                    foreach ($q['options'] as $i => $label) {
                        $q['scores'][$i] = (float) ($n - $pos);
                        $pos++;
                    }
                    $q['kind'] = 'scale';
                }
            }
            $out[] = $q;
        }
        return $out;
    }

    /**
     * A question's text as one plain line: tags removed (block tags become spaces), entities
     * decoded, whitespace collapsed. No filters run, so it is the same on screen, in downloads
     * and in scheduled runs, and needs no page context.
     *
     * @param string $html Question text.
     * @return string
     */
    private static function plain_text(string $html): string {
        $html = preg_replace('~<(/?(p|div|br|li|ul|ol|h\d|tr|td|th)\b[^>]*)>~i', ' <$1> ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Load the responses for one survey under the given filters.
     *
     * @param \stdClass $survey Survey record from surveys().
     * @param array $questions From questions().
     * @param filterset $q Filters: cohort, date range (applied to the workshop date) and the
     *                     viewer scope (a teacher sees only their own learners).
     * @param int[] $workshopids Only these workshops (empty = any).
     * @param string[] $customers Only workshops for these customers (empty = any).
     * @return array{rows: array[], booked: int, attended: int, scoped: bool}
     *   rows: one per respondent — userid, firstname, lastname, email, completed (ts),
     *         workshop (null or code/name/date/customer/status), answers (qid => idx|text).
     *   booked / attended: learners booked into / marked Attended at the workshops in scope
     *   (cohort and viewer scope applied), for the response rate.
     *   scoped: whether a workshop, customer or date filter restricted respondents to bookings.
     */
    public static function responses(\stdClass $survey, array $questions, filterset $q,
            array $workshopids = [], array $customers = []): array {
        global $DB;

        $courseid = (int) $survey->course;
        $dr = $q->daterange();
        $from = $dr['from'] ?? null;
        $to = $dr['to'] ?? null;
        $scoped = $workshopids || $customers || $from !== null || $to !== null;

        // 1. Respondents: finished attempts, latest per learner, cohort + viewer scope applied.
        [$fw, $fp] = $q->where([
            'cohort' => 'a.userid',
            'trainer' => ['user' => 'a.userid', 'course' => 'k.course'],
        ]);
        $sql = "SELECT a.id, a.userid, a.answers, a.timeended, a.timemodified,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email
                  FROM {aiknowledgecheck_attempts} a
                  JOIN {aiknowledgecheck} k ON k.id = a.aiknowledgecheckid
                  JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                 WHERE a.aiknowledgecheckid = :kid AND a.status = 1 $fw
              ORDER BY a.userid, a.id";
        $latest = [];
        foreach ($DB->get_recordset_sql($sql, ['kid' => (int) $survey->id] + $fp) as $a) {
            $latest[(int) $a->userid] = $a; // Ordered by id: the last one wins.
        }

        // 2. Bookings at this course's workshops (not cancelled), within the workshop filters.
        $bwhere = ['w.courseid = :cid', 'p.bookingstatus <> :cancelled'];
        $bparams = ['cid' => $courseid, 'cancelled' => self::CANCELLED];
        if ($workshopids) {
            [$in, $p] = $DB->get_in_or_equal($workshopids, SQL_PARAMS_NAMED, 'fbw');
            $bwhere[] = "w.id $in";
            $bparams += $p;
        }
        if ($customers) {
            [$in, $p] = $DB->get_in_or_equal($customers, SQL_PARAMS_NAMED, 'fbcu');
            $bwhere[] = "w.customername $in";
            $bparams += $p;
        }
        if ($from !== null) {
            $bwhere[] = 'w.startdatetime >= :wfrom';
            $bparams['wfrom'] = $from;
        }
        if ($to !== null) {
            $bwhere[] = 'w.startdatetime <= :wto';
            $bparams['wto'] = $to;
        }
        $bsql = "SELECT p.id, p.userid, p.email, p.bookingstatus,
                        w.id AS workshopid, w.workshopcode, w.workshopname, w.customername,
                        w.startdatetime, w.status AS workshopstatus
                   FROM {local_workshop_participants} p
                   JOIN {local_workshops} w ON w.id = p.workshopid
                  WHERE " . implode(' AND ', $bwhere);
        $bookings = $DB->get_records_sql($bsql, $bparams);

        // Older bookings may lack a user id: match them on email (case-insensitive).
        $needemail = [];
        foreach ($bookings as $b) {
            if (empty($b->userid) && trim((string) $b->email) !== '') {
                $needemail[\core_text::strtolower(trim($b->email))] = true;
            }
        }
        $emailmap = [];
        if ($needemail) {
            foreach (array_chunk(array_keys($needemail), 500) as $chunk) {
                [$in, $p] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'fbe');
                $recs = $DB->get_records_sql(
                    "SELECT id, email FROM {user} WHERE deleted = 0 AND LOWER(email) $in ORDER BY id",
                    $p
                );
                foreach ($recs as $u) {
                    $k = \core_text::strtolower(trim($u->email));
                    if (!isset($emailmap[$k])) {
                        $emailmap[$k] = (int) $u->id;
                    }
                }
            }
        }

        // The learners the response rate is measured against: the same cohort + viewer scope.
        $inscope = null;
        if ($q->is_active('cohort') || $q->viewer_scope_active()) {
            $inscope = self::scoped_users($q, $courseid);
        }

        // One booking per learner: Attended first, then the most recent workshop.
        $booking = [];
        $booked = [];
        $attended = [];
        foreach ($bookings as $b) {
            $uid = (int) $b->userid;
            if (!$uid) {
                $uid = $emailmap[\core_text::strtolower(trim((string) $b->email))] ?? 0;
            }
            if ($uid && $inscope !== null && !isset($inscope[$uid])) {
                continue;
            }
            $key = $uid ?: 'p' . $b->id;
            $booked[$key] = true;
            if ($b->bookingstatus === self::ATTENDED) {
                $attended[$key] = true;
            }
            if (!$uid) {
                continue;
            }
            $cur = $booking[$uid] ?? null;
            if ($cur === null || self::better_booking($b, $cur)) {
                $booking[$uid] = $b;
            }
        }

        // 3. Rows.
        $rows = [];
        foreach ($latest as $uid => $a) {
            $b = $booking[$uid] ?? null;
            if ($scoped && $b === null) {
                continue;
            }
            $decoded = json_decode((string) $a->answers, true);
            $decoded = is_array($decoded) ? $decoded : [];
            $answers = [];
            foreach ($questions as $qq) {
                $ans = $decoded[$qq['id']] ?? ($decoded[(string) $qq['id']] ?? null);
                if ($qq['kind'] === 'freetext') {
                    $answers[$qq['id']] = is_array($ans) ? trim((string) ($ans['freetext'] ?? '')) : '';
                } else {
                    $idx = is_array($ans) ? ($ans['answer'] ?? null) : $ans;
                    $answers[$qq['id']] = ($idx !== null && $idx !== '' && (int) $idx >= 0
                        && isset($qq['options'][(int) $idx])) ? (int) $idx : null;
                }
            }
            $rows[] = [
                'userid' => $uid,
                'firstname' => (string) $a->firstname,
                'lastname' => (string) $a->lastname,
                'fullname' => fullname($a),
                'email' => (string) $a->email,
                'completed' => (int) ($a->timeended ?: $a->timemodified),
                'workshop' => $b === null ? null : [
                    'id' => (int) $b->workshopid,
                    'code' => (string) $b->workshopcode,
                    'name' => format_string($b->workshopname, true, ['context' => \context_system::instance()]),
                    'date' => (int) $b->startdatetime,
                    'customer' => (string) $b->customername,
                    'booking' => (string) $b->bookingstatus,
                ],
                'answers' => $answers,
            ];
        }
        usort($rows, function ($x, $y) {
            $dx = $x['workshop']['date'] ?? 0;
            $dy = $y['workshop']['date'] ?? 0;
            if ($dx !== $dy) {
                return $dx <=> $dy;
            }
            return [\core_text::strtolower($x['lastname']), \core_text::strtolower($x['firstname'])]
                <=> [\core_text::strtolower($y['lastname']), \core_text::strtolower($y['firstname'])];
        });

        return ['rows' => $rows, 'booked' => count($booked), 'attended' => count($attended), 'scoped' => $scoped];
    }

    /**
     * Whether booking $a should be preferred over $b for the same learner.
     *
     * @param \stdClass $a Candidate.
     * @param \stdClass $b Current.
     * @return bool
     */
    private static function better_booking(\stdClass $a, \stdClass $b): bool {
        $aa = $a->bookingstatus === self::ATTENDED;
        $ba = $b->bookingstatus === self::ATTENDED;
        if ($aa !== $ba) {
            return $aa;
        }
        return (int) $a->startdatetime > (int) $b->startdatetime;
    }

    /**
     * The learners on a course that the cohort filter and viewer scope allow, as a set.
     *
     * @param filterset $q Filters.
     * @param int $courseid Course id.
     * @return array<int,bool>
     */
    private static function scoped_users(filterset $q, int $courseid): array {
        global $DB;
        [$fw, $fp] = $q->where([
            'cohort' => 'u.id',
            'trainer' => ['user' => 'u.id', 'course' => 'c.id'],
        ]);
        $sql = "SELECT u.id
                  FROM {user} u
                  JOIN {course} c ON c.id = :cid
                 WHERE u.deleted = 0 $fw";
        $out = [];
        foreach ($DB->get_fieldset_sql($sql, ['cid' => $courseid] + $fp) as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    /**
     * Per-question results for the summary view.
     *
     * @param array $questions From questions().
     * @param array $rows From responses()['rows'].
     * @return array[] one per question: the question plus answered, counts (index => n),
     *                 average (rated/scale), positive (share in the top two options of a
     *                 4+ option scale, or the first option of a shorter one), comments (free text).
     */
    public static function summarise(array $questions, array $rows): array {
        $out = [];
        foreach ($questions as $qq) {
            $s = $qq + ['answered' => 0, 'counts' => [], 'average' => null, 'positive' => null, 'comments' => []];
            if ($qq['kind'] === 'freetext') {
                foreach ($rows as $ri => $r) {
                    $t = $r['answers'][$qq['id']] ?? '';
                    if ($t !== '') {
                        $s['answered']++;
                        $s['comments'][] = ['row' => $ri, 'text' => $t];
                    }
                }
                $out[] = $s;
                continue;
            }
            foreach ($qq['options'] as $i => $label) {
                $s['counts'][$i] = 0;
            }
            $sum = 0.0;
            foreach ($rows as $r) {
                $idx = $r['answers'][$qq['id']] ?? null;
                if ($idx === null) {
                    continue;
                }
                $s['answered']++;
                $s['counts'][$idx]++;
                $sum += $qq['scores'][$idx] ?? 0;
            }
            if ($s['answered']) {
                $s['average'] = $sum / $s['answered'];
                if ($qq['kind'] === 'scale') {
                    $keys = array_keys($qq['options']);
                    $top = count($keys) >= 4 ? array_slice($keys, 0, 2) : array_slice($keys, 0, 1);
                    $pos = 0;
                    foreach ($top as $k) {
                        $pos += $s['counts'][$k];
                    }
                    $s['positive'] = $pos / $s['answered'];
                }
            }
            $out[] = $s;
        }
        return $out;
    }

    /**
     * A before / after pair: exactly two rated questions sharing the same scale (e.g.
     * "Before the workshop my knowledge was…" and "Since the workshop my knowledge is…").
     *
     * @param array $summary From summarise().
     * @param array $rows Respondent rows.
     * @return array|null before, after (summary entries), improved / same / lower counts, paired.
     */
    public static function before_after(array $summary, array $rows): ?array {
        $rated = array_values(array_filter($summary, fn($s) => $s['kind'] === 'rated'));
        if (count($rated) !== 2 || array_values($rated[0]['scores']) !== array_values($rated[1]['scores'])) {
            return null;
        }
        [$b, $a] = $rated;
        $improved = $same = $lower = 0;
        foreach ($rows as $r) {
            $bi = $r['answers'][$b['id']] ?? null;
            $ai = $r['answers'][$a['id']] ?? null;
            if ($bi === null || $ai === null) {
                continue;
            }
            $d = ($a['scores'][$ai] ?? 0) - ($b['scores'][$bi] ?? 0);
            if ($d > 0) {
                $improved++;
            } else if ($d < 0) {
                $lower++;
            } else {
                $same++;
            }
        }
        return ['before' => $b, 'after' => $a, 'improved' => $improved, 'same' => $same, 'lower' => $lower,
                'paired' => $improved + $same + $lower];
    }
}
