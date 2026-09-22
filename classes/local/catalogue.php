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
 * The curated catalogue of metrics and reports.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Every stat card, KPI gauge and report Beacon ships, each with one concrete,
 * validated query. Curated to the metrics Moodle admins actually ask for.
 */
class catalogue {
    /** @var metric[]|null */
    private static ?array $metrics = null;
    /** @var report[]|null */
    private static ?array $reports = null;

    /**
     * The distinct live-enrolment subquery every rate is anchored on.
     *
     * Returns distinct (userid, courseid) pairs, applying all five conditions
     * that decide whether an enrolment is live. :now must be supplied.
     *
     * @param bool $trackingonly Restrict to completion-tracking courses.
     * @return string
     */
    private static function live_enrolments(bool $trackingonly = false): string {
        $extra = $trackingonly ? 'JOIN {course} c ON c.id = e.courseid AND c.enablecompletion = 1' : '';
        return "SELECT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  $extra
                  JOIN {user} u ON u.id = ue.userid
                 WHERE ue.status = 0 AND e.status = 0
                   AND (ue.timeend = 0 OR ue.timeend > :now)
                   AND u.deleted = 0 AND u.suspended = 0
                 GROUP BY ue.userid, e.courseid";
    }

    /**
     * Full name for a result row with every Moodle name field present, so
     * fullname() never emits "missing name fields" developer debugging.
     *
     * @param object $r Row containing at least firstname and lastname.
     * @return string
     */
    private static function fullname_of($r): string {
        $user = (object) [
            'firstname' => $r->firstname ?? '',
            'lastname' => $r->lastname ?? '',
            'firstnamephonetic' => $r->firstnamephonetic ?? '',
            'lastnamephonetic' => $r->lastnamephonetic ?? '',
            'middlename' => $r->middlename ?? '',
            'alternatename' => $r->alternatename ?? '',
        ];
        return fullname($user);
    }

    /**
     * Deep links into Moodle for a marking-queue row. Returns four target URLs
     * (as strings, or null where the row lacks the ids to build one):
     *   [learner profile, course, assignment, grade-this-submission].
     * The learner name, course and assignment open the relevant Moodle page; the
     * submission date opens the grading screen for that learner, so a trainer can
     * click straight through to mark it.
     *
     * @param object $r Row with userid, courseid and (optionally) cmid.
     * @return array{0:?string,1:?string,2:?string,3:?string}
     */
    private static function marking_links($r): array {
        $userid = (int) ($r->userid ?? 0);
        $courseid = (int) ($r->courseid ?? 0);
        $cmid = (int) ($r->cmid ?? 0);
        $profile = ($userid && $courseid)
            ? (new \moodle_url('/user/view.php', ['id' => $userid, 'course' => $courseid]))->out(false)
            : ($userid ? (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false) : null);
        $course = $courseid ? (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false) : null;
        $assign = $cmid ? (new \moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false) : null;
        $grade = ($cmid && $userid)
            ? (new \moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grader', 'userid' => $userid]))->out(false)
            : null;
        return [$profile, $course, $assign, $grade];
    }

    /**
     * Whether the current viewer may see one learner's activity in a course:
     * a site admin may see anyone; an editing teacher on the course may see any
     * of its learners; a non-editing teacher may see only learners in a group
     * they share on that course.
     *
     * @param \moodle_database $DB Database.
     * @param int $viewerid Current user id.
     * @param int $studentid Learner being viewed.
     * @param int $courseid Course.
     * @return bool
     */
    private static function may_view_student(\moodle_database $DB, int $viewerid, int $studentid, int $courseid): bool {
        if (has_capability('moodle/site:config', \context_system::instance())) {
            return true;
        }
        $ected = $DB->record_exists_sql(
            "SELECT 1 FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
                    AND ctx.contextlevel = 50 AND ctx.instanceid = :cid
               JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'editingteacher'
              WHERE ra.userid = :uid",
            ['cid' => $courseid, 'uid' => $viewerid]
        );
        if ($ected) {
            return true;
        }
        return $DB->record_exists_sql(
            "SELECT 1 FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
                    AND ctx.contextlevel = 50 AND ctx.instanceid = :cid
               JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'teacher'
               JOIN {groups_members} gmt ON gmt.userid = ra.userid
               JOIN {groups} grp ON grp.id = gmt.groupid AND grp.courseid = :cid2
               JOIN {groups_members} gls ON gls.groupid = grp.id AND gls.userid = :sid
              WHERE ra.userid = :uid",
            ['cid' => $courseid, 'cid2' => $courseid, 'uid' => $viewerid, 'sid' => $studentid]
        );
    }

    /**
     * A bound WHERE fragment narrowing a marking query (aliases assign a,
     * submission s) to the submissions the given trainer(s) are responsible for:
     * an editing teacher on the course, or a non-editing teacher who shares a
     * group with the learner on that course. OR-ed across the selected trainers.
     *
     * @param \moodle_database $DB Database.
     * @param int[] $trainerids Selected trainer user ids.
     * @return array{0:string,1:array}
     */
    private static function marking_trainer_filter(\moodle_database $DB, array $trainerids): array {
        [$in1, $p1] = $DB->get_in_or_equal($trainerids, SQL_PARAMS_NAMED, 'trfa');
        [$in2, $p2] = $DB->get_in_or_equal($trainerids, SQL_PARAMS_NAMED, 'trfb');
        $sql = " AND (
            EXISTS (SELECT 1 FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                           AND ctx.contextlevel = 50 AND ctx.instanceid = a.course
                      JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'editingteacher'
                     WHERE ra.userid $in1)
            OR EXISTS (SELECT 1 FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                              AND ctx.contextlevel = 50 AND ctx.instanceid = a.course
                         JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'teacher'
                         JOIN {groups_members} gmt ON gmt.userid = ra.userid
                         JOIN {groups} grp ON grp.id = gmt.groupid AND grp.courseid = a.course
                         JOIN {groups_members} gls ON gls.groupid = grp.id AND gls.userid = s.userid
                        WHERE ra.userid $in2))";
        return [$sql, $p1 + $p2];
    }

    /**
     * A UNION of durable, non-purgeable activity records as (userid, courseid, ts).
     *
     * Unlike the standard log (retained only for loglifetime), completion,
     * submission, attempt and post records persist for the life of the course, so
     * a report built on them works as long-term funding-participation evidence.
     * Only sources whose tables exist on this site are included.
     *
     * @param \moodle_database $DB Database.
     * @return string A SQL sub-select producing columns userid, courseid, ts.
     */
    private static function activity_union(\moodle_database $DB): string {
        $man = $DB->get_manager();
        $parts = [];
        // Activity completions — core, always present.
        $parts[] = "SELECT cmc.userid, cm.course AS courseid, cmc.timemodified AS ts
                      FROM {course_modules_completion} cmc
                      JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                     WHERE cmc.completionstate >= 1 AND cmc.timemodified > 0";
        if ($man->table_exists('assign_submission')) {
            $parts[] = "SELECT s.userid, a.course AS courseid, s.timemodified AS ts
                          FROM {assign_submission} s
                          JOIN {assign} a ON a.id = s.assignment
                         WHERE s.status = 'submitted' AND s.timemodified > 0";
        }
        if ($man->table_exists('quiz_attempts')) {
            $parts[] = "SELECT qa.userid, q.course AS courseid, qa.timefinish AS ts
                          FROM {quiz_attempts} qa
                          JOIN {quiz} q ON q.id = qa.quiz
                         WHERE qa.timefinish > 0";
        }
        if ($man->table_exists('forum_posts')) {
            $parts[] = "SELECT fp.userid, fd.course AS courseid, fp.created AS ts
                          FROM {forum_posts} fp
                          JOIN {forum_discussions} fd ON fd.id = fp.discussion
                         WHERE fp.created > 0";
        }
        return implode("\n                UNION ALL\n                ", $parts);
    }

    /**
     * All metrics, keyed by id.
     *
     * @return metric[]
     */
    public static function metrics(): array {
        if (self::$metrics !== null) {
            return self::$metrics;
        }
        $now = fn() => time();
        $defs = [];

        // Stats metrics.

        $defs[] = [
            'id' => 'total_learners', 'kind' => 'stat', 'family' => 'people',
            'icon' => 'users', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) {
                return [$DB->count_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 2'), null];
            },
        ];

        $defs[] = [
            'id' => 'live_enrolments', 'kind' => 'stat', 'family' => 'people',
            'icon' => 'login', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) use ($now) {
                $sql = "SELECT COUNT(*) FROM (" . self::live_enrolments() . ") d";
                return [$DB->count_records_sql($sql, ['now' => $now()]), null];
            },
        ];

        $defs[] = [
            'id' => 'active_learners', 'kind' => 'stat', 'family' => 'engagement',
            'icon' => 'pulse', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) {
                $cut = time() - 30 * DAYSECS;
                $count = $DB->count_records_select(
                    'user',
                    'deleted = 0 AND suspended = 0 AND id > 2 AND lastaccess > :cut',
                    ['cut' => $cut]
                );
                return [$count, null];
            },
        ];

        $defs[] = [
            'id' => 'new_enrolments', 'kind' => 'stat', 'family' => 'progress',
            'icon' => 'plus', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) {
                $cut = time() - 30 * DAYSECS;
                $sql = "SELECT COUNT(*)
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {user} u ON u.id = ue.userid
                         WHERE u.deleted = 0 AND ue.timecreated > :cut";
                return [$DB->count_records_sql($sql, ['cut' => $cut]), null];
            },
        ];

        $defs[] = [
            'id' => 'completions', 'kind' => 'stat', 'family' => 'progress',
            'icon' => 'flag', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) {
                $cut = time() - 30 * DAYSECS;
                $sql = "SELECT COUNT(*)
                          FROM {course_completions} cc
                          JOIN {course} c ON c.id = cc.course AND c.enablecompletion = 1
                          JOIN {user} u ON u.id = cc.userid AND u.deleted = 0
                         WHERE cc.timecompleted IS NOT NULL AND cc.timecompleted > :cut";
                return [$DB->count_records_sql($sql, ['cut' => $cut]), null];
            },
        ];

        $defs[] = [
            'id' => 'in_progress', 'kind' => 'stat', 'family' => 'progress',
            'icon' => 'play', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) use ($now) {
                $sql = "SELECT COUNT(*) FROM (" . self::live_enrolments(true) . ") enr
                        LEFT JOIN (SELECT DISTINCT userid, course AS courseid
                                     FROM {course_completions}
                                    WHERE timecompleted IS NOT NULL) done
                               ON done.userid = enr.userid AND done.courseid = enr.courseid
                        WHERE done.userid IS NULL";
                return [$DB->count_records_sql($sql, ['now' => $now()]), null];
            },
        ];

        $defs[] = [
            'id' => 'awaiting_marking', 'kind' => 'stat', 'family' => 'assessment',
            'icon' => 'pen', 'format' => 'number', 'better' => 'lower', 'requirestable' => 'assign_submission',
            'compute' => function ($DB) {
                $sql = "SELECT COUNT(*)
                          FROM {assign_submission} s
                          JOIN {assign} a ON a.id = s.assignment
                     LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                               AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                         WHERE s.latest = 1 AND s.status = 'submitted'
                           AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0)";
                return [$DB->count_records_sql($sql), null];
            },
        ];

        $defs[] = [
            'id' => 'average_grade', 'kind' => 'stat', 'family' => 'assessment',
            'icon' => 'star', 'format' => 'percent1', 'better' => 'higher',
            'compute' => function ($DB) {
                $sql = "SELECT AVG(100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0))
                          FROM {grade_grades} gg
                          JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                          JOIN {user} u ON u.id = gg.userid AND u.deleted = 0
                         WHERE gg.finalgrade IS NOT NULL AND gg.hidden = 0 AND gg.excluded = 0";
                $v = $DB->get_field_sql($sql);
                return [$v === null || $v === false ? null : $v, null];
            },
        ];

        $defs[] = [
            'id' => 'dormant_learners', 'kind' => 'stat', 'family' => 'engagement',
            'icon' => 'moon', 'format' => 'number', 'better' => 'lower',
            'compute' => function ($DB) {
                $cut = time() - 90 * DAYSECS;
                $count = $DB->count_records_select(
                    'user',
                    'deleted = 0 AND suspended = 0 AND id > 2 AND lastaccess > 0 AND lastaccess < :cut',
                    ['cut' => $cut]
                );
                return [$count, null];
            },
        ];

        $defs[] = [
            'id' => 'expiring_soon', 'kind' => 'stat', 'family' => 'compliance',
            'icon' => 'cert', 'format' => 'number', 'better' => 'lower',
            'requirestable' => 'tool_certificate_issues',
            'compute' => function ($DB) {
                $now = time();
                $soon = $now + 30 * DAYSECS;
                $sql = "SELECT COUNT(*)
                          FROM {tool_certificate_issues} ci
                          JOIN {user} u ON u.id = ci.userid AND u.deleted = 0
                         WHERE ci.expires > :now AND ci.expires <= :soon";
                return [$DB->count_records_sql($sql, ['now' => $now, 'soon' => $soon]), null];
            },
        ];

        // KPI gauges.

        $defs[] = [
            'id' => 'course_completion_rate', 'kind' => 'kpi', 'family' => 'progress',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 80, 'amber' => 60, 'green' => 78,
            'compute' => function ($DB) use ($now) {
                $sql = "SELECT
                          SUM(CASE WHEN done.userid IS NOT NULL THEN 1 ELSE 0 END) AS num,
                          COUNT(*) AS den
                        FROM (" . self::live_enrolments(true) . ") enr
                        LEFT JOIN (SELECT DISTINCT userid, course AS courseid
                                     FROM {course_completions}
                                    WHERE timecompleted IS NOT NULL) done
                               ON done.userid = enr.userid AND done.courseid = enr.courseid";
                $r = $DB->get_record_sql($sql, ['now' => $now()]);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int)$r->num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'activity_completion_rate', 'kind' => 'kpi', 'family' => 'progress',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 75, 'amber' => 55, 'green' => 75,
            'compute' => function ($DB) use ($now) {
                $sql = "SELECT
                          SUM(CASE WHEN act.userid IS NOT NULL THEN 1 ELSE 0 END) AS num,
                          COUNT(*) AS den
                        FROM (" . self::live_enrolments() . ") enr
                        LEFT JOIN (
                            SELECT DISTINCT cmc.userid, cm.course AS courseid
                              FROM {course_modules_completion} cmc
                              JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid AND cm.deletioninprogress = 0
                             WHERE cmc.completionstate IN (1,2,3)
                        ) act ON act.userid = enr.userid AND act.courseid = enr.courseid";
                $r = $DB->get_record_sql($sql, ['now' => $now()]);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int)$r->num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'monthly_active_rate', 'kind' => 'kpi', 'family' => 'engagement',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 60, 'amber' => 45, 'green' => 60,
            'compute' => function ($DB) {
                $cut = time() - 30 * DAYSECS;
                $den = $DB->count_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 2');
                $num = $DB->count_records_select(
                    'user',
                    'deleted = 0 AND suspended = 0 AND id > 2 AND lastaccess > :cut',
                    ['cut' => $cut]
                );
                return [$den ? 100.0 * $num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'pass_rate', 'kind' => 'kpi', 'family' => 'assessment',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 75, 'amber' => 60, 'green' => 75,
            'compute' => function ($DB) {
                $sql = "SELECT
                          SUM(CASE WHEN gg.finalgrade >= gi.gradepass AND gi.gradepass > 0 THEN 1 ELSE 0 END) AS num,
                          SUM(CASE WHEN gi.gradepass > 0 THEN 1 ELSE 0 END) AS den
                        FROM {grade_grades} gg
                        JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                        JOIN {user} u ON u.id = gg.userid AND u.deleted = 0
                       WHERE gg.finalgrade IS NOT NULL AND gg.hidden = 0 AND gg.excluded = 0";
                $r = $DB->get_record_sql($sql);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int)$r->num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'feedback_rate', 'kind' => 'kpi', 'family' => 'assessment',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 90, 'amber' => 75, 'green' => 90, 'requirestable' => 'assign_submission',
            'compute' => function ($DB) {
                $sql = "SELECT
                          SUM(CASE WHEN g.grade IS NOT NULL AND g.grade >= 0 THEN 1 ELSE 0 END) AS num,
                          COUNT(*) AS den
                        FROM {assign_submission} s
                   LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                             AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                       WHERE s.latest = 1 AND s.status = 'submitted'";
                $r = $DB->get_record_sql($sql);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int)$r->num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'marking_backlog_days', 'kind' => 'stat', 'family' => 'assessment',
            'icon' => 'clock', 'format' => 'number', 'better' => 'lower',
            'requirestable' => 'assign_submission',
            'compute' => function ($DB) {
                // How long the oldest still-unmarked submission has been waiting.
                $sql = "SELECT MIN(s.timemodified) AS oldest
                          FROM {assign_submission} s
                     LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                               AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                         WHERE s.latest = 1 AND s.status = 'submitted'
                           AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0)";
                $oldest = $DB->get_field_sql($sql);
                if ($oldest === null || $oldest === false || (int) $oldest === 0) {
                    return [0, null];
                }
                return [(int) floor((time() - (int) $oldest) / DAYSECS), null];
            },
        ];

        $defs[] = [
            'id' => 'marking_timeliness', 'kind' => 'kpi', 'family' => 'assessment',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 80, 'amber' => 60, 'green' => 80, 'requirestable' => 'assign_submission',
            'compute' => function ($DB) {
                // Share of graded submissions that were marked within seven days
                // of being submitted — the marking-queue service level.
                $window = 7 * DAYSECS;
                $sql = "SELECT
                          SUM(CASE WHEN g.timemodified > 0
                                    AND g.timemodified - s.timemodified <= :win THEN 1 ELSE 0 END) AS num,
                          COUNT(*) AS den
                        FROM {assign_submission} s
                        JOIN {assign_grades} g ON g.assignment = s.assignment
                             AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                       WHERE s.latest = 1 AND s.status = 'submitted'
                         AND g.grade IS NOT NULL AND g.grade >= 0";
                $r = $DB->get_record_sql($sql, ['win' => $window]);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int) $r->num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'certification_currency', 'kind' => 'kpi', 'family' => 'compliance',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 95, 'amber' => 85, 'green' => 95, 'requirestable' => 'tool_certificate_issues',
            'compute' => function ($DB) {
                $now = time();
                $sql = "SELECT
                          SUM(CASE WHEN ci.expires = 0 OR ci.expires > :now THEN 1 ELSE 0 END) AS num,
                          COUNT(*) AS den
                        FROM {tool_certificate_issues} ci
                        JOIN {user} u ON u.id = ci.userid AND u.deleted = 0";
                $r = $DB->get_record_sql($sql, ['now' => $now]);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int)$r->num / $den : null, $den];
            },
        ];

        $defs[] = [
            'id' => 'fp_active_learners', 'kind' => 'stat', 'family' => 'compliance',
            'icon' => 'pulse', 'format' => 'number', 'better' => 'higher',
            'compute' => function ($DB) {
                $cut = time() - 30 * DAYSECS;
                $union = self::activity_union($DB);
                $sql = "SELECT COUNT(DISTINCT act.userid)
                          FROM ($union) act
                         WHERE act.ts > :cut AND act.courseid > 1 AND act.userid > 0
                           AND EXISTS (SELECT 1 FROM {user_enrolments} ue
                                         JOIN {enrol} e ON e.id = ue.enrolid
                                         JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                                        WHERE ue.userid = act.userid AND e.courseid = act.courseid
                                          AND ue.status = 0 AND e.status = 0)";
                return [$DB->count_records_sql($sql, ['cut' => $cut]), null];
            },
        ];

        $defs[] = [
            'id' => 'fp_participation_rate', 'kind' => 'kpi', 'family' => 'compliance',
            'icon' => 'gauge', 'format' => 'percent', 'better' => 'higher',
            'target' => 80, 'amber' => 55, 'green' => 75,
            'compute' => function ($DB) use ($now) {
                $cut = time() - 30 * DAYSECS;
                $union = self::activity_union($DB);
                $sql = "SELECT
                          SUM(CASE WHEN a.userid IS NOT NULL THEN 1 ELSE 0 END) AS num,
                          COUNT(*) AS den
                        FROM (" . self::live_enrolments() . ") enr
                        LEFT JOIN (SELECT DISTINCT act.userid, act.courseid
                                     FROM ($union) act
                                    WHERE act.ts > :cut) a
                               ON a.userid = enr.userid AND a.courseid = enr.courseid";
                $r = $DB->get_record_sql($sql, ['now' => $now(), 'cut' => $cut]);
                $den = (int) ($r->den ?? 0);
                return [$den ? 100.0 * (int) $r->num / $den : null, $den];
            },
        ];

        self::$metrics = [];
        foreach ($defs as $d) {
            self::$metrics[$d['id']] = new metric($d);
        }
        return self::$metrics;
    }

    /**
     * All reports, keyed by id.
     *
     * @return report[]
     */
    public static function reports(): array {
        if (self::$reports !== null) {
            return self::$reports;
        }
        $defs = [];

        $defs[] = [
            'id' => 'learner_roster', 'family' => 'people', 'icon' => 'users', 'grain' => 'learner',
            'filters' => ['daterange', 'cohort', 'role', 'auth'], 'datelabel' => 'col_lastaccess',
            'columns' => [['learner', 'col_learner', 'text'], ['email', 'col_email', 'text'],
                          ['dept', 'col_department', 'text'], ['last', 'col_lastaccess', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'role' => 'u.id', 'auth' => 'u.auth',
                    'daterange' => ['col' => 'u.lastaccess', 'label' => 'col_lastaccess']]);
                $where = "u.deleted = 0 AND u.suspended = 0 AND u.id > 2 $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.department, u.lastaccess
                          FROM {user} u
                         WHERE $where
                      ORDER BY u.lastaccess DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $u) {
                    $rows[] = [
                        cell::text(self::fullname_of($u)),
                        cell::text($u->email),
                        cell::text($u->department ?: '—'),
                        cell::when($u->lastaccess),
                    ];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {user} u WHERE $where", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'enrolment_details', 'family' => 'people', 'icon' => 'login', 'grain' => 'enrolment',
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'enrolmethod', 'daterange'],
            'datelabel' => 'col_joined',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['method', 'col_method', 'text'], ['joined', 'col_joined', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'group' => 'u.id', 'course' => 'e.courseid',
                    'category' => 'e.courseid', 'enrolmethod' => 'e.enrol',
                    'trainer' => ['user' => 'u.id', 'course' => 'e.courseid'],
                    'daterange' => ['col' => 'ue.timecreated', 'label' => 'col_joined']]);
                $params = ['now' => time()] + $fp;
                $body = "FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                          JOIN {user} u ON u.id = ue.userid
                         WHERE ue.status = 0 AND e.status = 0
                           AND (ue.timeend = 0 OR ue.timeend > :now)
                           AND u.deleted = 0 AND u.suspended = 0 $fw";
                $sql = "SELECT ue.id, u.firstname, u.lastname, c.fullname AS course,
                               e.enrol AS method, ue.timecreated $body ORDER BY ue.timecreated DESC";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               cell::text(ucfirst($r->method)), cell::when($r->timecreated)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $params)];
            },
        ];

        $defs[] = [
            'id' => 'course_completion', 'family' => 'progress', 'icon' => 'flag', 'grain' => 'enrolment',
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'daterange'],
            'datelabel' => 'col_completed',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['status', 'col_status', 'status'], ['completed', 'col_completed', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'enr.userid', 'group' => 'enr.userid',
                    'course' => 'enr.courseid', 'category' => 'enr.courseid',
                    'trainer' => ['user' => 'enr.userid', 'course' => 'enr.courseid'],
                    'daterange' => ['col' => 'cc.timecompleted', 'label' => 'col_completed']]);
                $params = ['now' => time()] + $fp;
                $body = "FROM (" . self::live_enrolments(true) . ") enr
                          JOIN {user} u ON u.id = enr.userid
                          JOIN {course} c ON c.id = enr.courseid
                     LEFT JOIN {course_completions} cc ON cc.userid = enr.userid AND cc.course = enr.courseid
                         WHERE 1 = 1 $fw";
                $sql = "SELECT " . $DB->sql_concat('enr.userid', "'-'", 'enr.courseid') . " AS bcrowid, enr.userid, enr.courseid, u.firstname, u.lastname, c.fullname AS course,
                               cc.timecompleted $body ORDER BY cc.timecompleted DESC, c.fullname";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $done = !empty($r->timecompleted);
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               $done ? cell::status(get_string('status_complete', 'local_beacon'), 'g')
                                     : cell::status(get_string('status_inprogress', 'local_beacon'), 'w'),
                               cell::when($r->timecompleted)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $params)];
            },
        ];

        $defs[] = [
            'id' => 'activity_completion', 'family' => 'progress', 'icon' => 'check', 'grain' => 'enrolment',
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort'],
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['done', 'col_done', 'number'], ['total', 'col_oftotal', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'enr.userid', 'group' => 'enr.userid',
                    'course' => 'enr.courseid', 'category' => 'enr.courseid',
                    'trainer' => ['user' => 'enr.userid', 'course' => 'enr.courseid']]);
                $params = ['now' => time()] + $fp;
                $body = "FROM (" . self::live_enrolments() . ") enr
                          JOIN {user} u ON u.id = enr.userid
                          JOIN {course} c ON c.id = enr.courseid
                     LEFT JOIN (SELECT cmc.userid, cm.course AS courseid, COUNT(*) AS cnt
                                  FROM {course_modules_completion} cmc
                                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid AND cm.deletioninprogress = 0
                                 WHERE cmc.completionstate IN (1,2,3)
                              GROUP BY cmc.userid, cm.course) d ON d.userid = enr.userid AND d.courseid = enr.courseid
                     LEFT JOIN (SELECT course, COUNT(*) AS cnt FROM {course_modules}
                                 WHERE completion > 0 AND deletioninprogress = 0
                              GROUP BY course) t ON t.course = enr.courseid
                         WHERE 1 = 1 $fw";
                $sql = "SELECT " . $DB->sql_concat('enr.userid', "'-'", 'enr.courseid') . " AS bcrowid, enr.userid, enr.courseid, u.firstname, u.lastname, c.fullname AS course,
                               COALESCE(d.cnt, 0) AS done, COALESCE(t.cnt, 0) AS total $body ORDER BY c.fullname";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               cell::text((string)(int)$r->done), cell::text((string)(int)$r->total)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $params)];
            },
        ];

        $defs[] = [
            'id' => 'not_started', 'family' => 'engagement', 'icon' => 'moon', 'grain' => 'enrolment',
            'filters' => ['category', 'course', 'trainer', 'cohort', 'daterange'], 'datelabel' => 'col_enrolled',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['enrolled', 'col_enrolled', 'text'], ['status', 'col_status', 'status']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'course' => 'e.courseid',
                    'category' => 'e.courseid',
                    'trainer' => ['user' => 'u.id', 'course' => 'e.courseid'],
                    'daterange' => ['col' => 'ue.timecreated', 'label' => 'col_enrolled']]);
                $body = "FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                          JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                     LEFT JOIN {user_lastaccess} la ON la.userid = ue.userid AND la.courseid = e.courseid
                         WHERE ue.status = 0 AND e.status = 0 AND la.id IS NULL $fw";
                $sql = "SELECT ue.id, u.firstname, u.lastname, c.fullname AS course, ue.timecreated
                        $body ORDER BY ue.timecreated DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course), cell::when($r->timecreated),
                               cell::status(get_string('status_neveropened', 'local_beacon'), 'b')];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'login_activity', 'family' => 'engagement', 'icon' => 'clock', 'grain' => 'learner',
            'filters' => ['daterange', 'cohort', 'role', 'idle'], 'datelabel' => 'col_lastaccess',
            'columns' => [['learner', 'col_learner', 'text'], ['first', 'col_firstseen', 'text'],
                          ['last', 'col_lastaccess', 'text'], ['idle', 'col_idle', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'role' => 'u.id', 'idle' => 'u.lastaccess',
                    'daterange' => ['col' => 'u.lastaccess', 'label' => 'col_lastaccess']]);
                $where = "u.deleted = 0 AND u.suspended = 0 AND u.id > 2 $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname, u.firstaccess, u.lastaccess
                          FROM {user} u
                         WHERE $where
                      ORDER BY u.lastaccess DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                $now = time();
                foreach ($recs as $u) {
                    $idle = $u->lastaccess ? floor(($now - $u->lastaccess) / DAYSECS) . ' ' .
                            get_string('days', 'local_beacon') : '—';
                    $rows[] = [cell::text(self::fullname_of($u)), cell::when($u->firstaccess),
                               cell::when($u->lastaccess), cell::text($idle)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {user} u WHERE $where", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'grade_summary', 'family' => 'assessment', 'icon' => 'doc', 'grain' => 'enrolment',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['grade', 'col_grade', 'text']],
            'filters' => ['category', 'course', 'trainer', 'cohort', 'gradeband'],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'course' => 'gi.courseid', 'category' => 'gi.courseid',
                    'trainer' => ['user' => 'u.id', 'course' => 'gi.courseid'],
                    'gradeband' => '(100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0))']);
                $body = "FROM {grade_grades} gg
                          JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                          JOIN {course} c ON c.id = gi.courseid
                          JOIN {user} u ON u.id = gg.userid AND u.deleted = 0
                         WHERE gg.finalgrade IS NOT NULL AND gg.hidden = 0 AND gg.excluded = 0 $fw";
                $sql = "SELECT gg.id, u.firstname, u.lastname, c.fullname AS course,
                               (100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0)) AS pct $body ORDER BY pct DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $pct = $r->pct === null ? '—' : round($r->pct, 1) . '%';
                    $badge = $r->pct === null ? null : ($r->pct >= 50 ? 'g' : 'b');
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               $badge ? cell::status($pct, $badge) : cell::text($pct)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'quiz_performance', 'family' => 'assessment', 'icon' => 'star', 'grain' => 'learner',
            'requirestable' => 'quiz_attempts',
            'filters' => ['category', 'course', 'trainer', 'cohort'],
            'columns' => [['learner', 'col_learner', 'text'], ['attempts', 'col_attempts', 'number'],
                          ['best', 'col_best', 'text'], ['avg', 'col_avg', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'course' => 'qz.course', 'category' => 'qz.course',
                    'trainer' => ['user' => 'u.id', 'course' => 'qz.course']]);
                $body = "FROM {quiz_attempts} qa
                          JOIN {quiz} qz ON qz.id = qa.quiz
                          JOIN {user} u ON u.id = qa.userid AND u.deleted = 0
                         WHERE qa.state = 'finished' AND qa.preview = 0 $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname,
                               COUNT(qa.id) AS attempts,
                               MAX(100.0 * qa.sumgrades / NULLIF(qz.sumgrades,0)) AS best,
                               AVG(100.0 * qa.sumgrades / NULLIF(qz.sumgrades,0)) AS av
                        $body
                      GROUP BY u.id, u.firstname, u.lastname
                      ORDER BY av DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text((string)(int)$r->attempts),
                               cell::text($r->best === null ? '—' : round($r->best) . '%'),
                               cell::text($r->av === null ? '—' : round($r->av) . '%')];
                }
                // Total distinct learners with attempts (matching the active filters).
                $total = $DB->count_records_sql("SELECT COUNT(DISTINCT qa.userid) $body", $fp);
                return [$rows, $total];
            },
        ];

        $defs[] = [
            'id' => 'marking_queue', 'family' => 'assessment', 'icon' => 'pen', 'grain' => 'submission',
            'requirestable' => 'assign_submission',
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'daterange'],
            'datelabel' => 'col_submitted',
            'columns' => [['learner', 'col_learner', 'text'], ['assignment', 'col_assignment', 'text'],
                          ['submitted', 'col_submitted', 'text'], ['waiting', 'col_waiting', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'course' => 'a.course', 'category' => 'a.course',
                    'group' => 's.userid',
                    'daterange' => ['col' => 's.timemodified', 'label' => 'col_submitted']]);
                $scope = '';
                $trainers = $q->selected('trainer');
                if ($trainers) {
                    [$trsql, $trparams] = self::marking_trainer_filter($DB, $trainers);
                    $scope = $trsql;
                    $fp += $trparams;
                }
                $body = "FROM {assign_submission} s
                          JOIN {assign} a ON a.id = s.assignment
                          JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                     LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                               AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                     LEFT JOIN {modules} md ON md.name = 'assign'
                     LEFT JOIN {course_modules} cm ON cm.instance = a.id
                               AND cm.module = md.id AND cm.course = a.course
                         WHERE s.latest = 1 AND s.status = 'submitted'
                           AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0)$scope $fw";
                $sql = "SELECT s.id, s.userid, a.course AS courseid, cm.id AS cmid,
                               u.firstname, u.lastname, a.name AS assignment, s.timemodified
                        $body ORDER BY s.timemodified ASC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                $now = time();
                foreach ($recs as $r) {
                    $wait = $r->timemodified ? floor(($now - $r->timemodified) / DAYSECS) . ' ' .
                            get_string('days', 'local_beacon') : '—';
                    [$plink, , $alink, $glink] = self::marking_links($r);
                    $rows[] = [cell::text(self::fullname_of($r), $plink), cell::text($r->assignment, $alink),
                               cell::when($r->timemodified, $glink), cell::status($wait, 'w')];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'certification_status', 'family' => 'compliance', 'icon' => 'cert', 'grain' => 'certificate',
            'requirestable' => 'tool_certificate_issues',
            'filters' => ['daterange', 'cohort', 'certstatus'], 'datelabel' => 'col_issued',
            'columns' => [['learner', 'col_learner', 'text'], ['cert', 'col_certificate', 'text'],
                          ['issued', 'col_issued', 'text'], ['expires', 'col_expires', 'text'],
                          ['status', 'col_status', 'status']],
            'run' => function ($DB, $q, $limit) {
                $now = time();
                $soon = $now + 30 * DAYSECS;
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'certstatus' => 'ci.expires',
                    'daterange' => ['col' => 'ci.timecreated', 'label' => 'col_issued']]);
                // Template name lives in tool_certificate_templates; left join defensively.
                $body = "FROM {tool_certificate_issues} ci
                          JOIN {user} u ON u.id = ci.userid AND u.deleted = 0
                     LEFT JOIN {tool_certificate_templates} t ON t.id = ci.templateid
                         WHERE 1 = 1 $fw";
                $sql = "SELECT ci.id, u.firstname, u.lastname, ci.timecreated, ci.expires,
                               t.name AS template $body ORDER BY ci.expires ASC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    if (!$r->expires) {
                        $status = cell::status(get_string('cert_current', 'local_beacon'), 'g');
                    } else if ($r->expires <= $now) {
                        $status = cell::status(get_string('cert_lapsed', 'local_beacon'), 'b');
                    } else if ($r->expires <= $soon) {
                        $status = cell::status(get_string('cert_expiring', 'local_beacon'), 'w');
                    } else {
                        $status = cell::status(get_string('cert_current', 'local_beacon'), 'g');
                    }
                    $expiry = $r->expires
                        ? userdate($r->expires, get_string('strftimedate', 'langconfig'))
                        : get_string('cert_noexpiry', 'local_beacon');
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->template ?: '—'),
                               cell::when($r->timecreated), cell::text($expiry), $status];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'forum_engagement', 'family' => 'engagement', 'icon' => 'pulse', 'grain' => 'learner',
            'requirestable' => 'forum_posts',
            'filters' => ['daterange', 'cohort'], 'datelabel' => 'col_lastpost',
            'columns' => [['learner', 'col_learner', 'text'], ['posts', 'col_posts', 'number'],
                          ['last', 'col_lastpost', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id',
                    'daterange' => ['col' => 'fp.created', 'label' => 'col_lastpost']]);
                $body = "FROM {forum_posts} fp
                          JOIN {user} u ON u.id = fp.userid AND u.deleted = 0
                         WHERE 1 = 1 $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname, COUNT(fp.id) AS posts, MAX(fp.created) AS lastpost
                        $body
                      GROUP BY u.id, u.firstname, u.lastname
                      ORDER BY posts DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text((string)(int)$r->posts),
                               cell::when($r->lastpost)];
                }
                $total = $DB->count_records_sql("SELECT COUNT(DISTINCT fp.userid) $body", $fp);
                return [$rows, $total];
            },
        ];

        $defs[] = [
            'id' => 'course_health', 'family' => 'operations', 'icon' => 'grid', 'grain' => 'course',
            'filters' => ['category', 'daterange'], 'datelabel' => 'col_updated',
            'columns' => [['course', 'col_course', 'text'], ['enrolled', 'col_enrolled', 'number'],
                          ['tracks', 'col_tracks', 'status'], ['updated', 'col_updated', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['category' => 'c.id',
                    'daterange' => ['col' => 'c.timemodified', 'label' => 'col_updated']]);
                $body = "FROM {course} c
                     LEFT JOIN (SELECT e.courseid, COUNT(DISTINCT ue.userid) AS cnt
                                  FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                                 WHERE ue.status = 0 GROUP BY e.courseid) en ON en.courseid = c.id
                         WHERE c.id > 1 $fw";
                $sql = "SELECT c.id, c.fullname, c.enablecompletion, c.timemodified,
                               COALESCE(en.cnt, 0) AS enrolled $body ORDER BY enrolled DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $c) {
                    $rows[] = [cell::text(format_string($c->fullname)), cell::text((string)(int)$c->enrolled),
                               $c->enablecompletion
                                   ? cell::status(get_string('yes'), 'g')
                                   : cell::status(get_string('no'), 'w'),
                               cell::when($c->timemodified)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {course} c WHERE c.id > 1 $fw", $fp)];
            },
        ];

        // Learner / people.

        $defs[] = [
            'id' => 'learner_progress', 'family' => 'progress', 'icon' => 'check', 'grain' => 'learner',
            'filters' => ['cohort', 'role'],
            'columns' => [['learner', 'col_learner', 'text'], ['enrolled', 'col_enrolledn', 'number'],
                          ['completed', 'col_completedn', 'number'], ['grade', 'col_avggrade', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'role' => 'u.id']);
                $sql = "SELECT u.id, u.firstname, u.lastname,
                               COALESCE(en.cnt, 0) AS enrolled,
                               COALESCE(cc.cnt, 0) AS completed,
                               gr.avgpct AS avgpct
                          FROM {user} u
                     LEFT JOIN (SELECT ue.userid, COUNT(DISTINCT e.courseid) AS cnt
                                  FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                                 WHERE ue.status = 0 AND e.status = 0
                              GROUP BY ue.userid) en ON en.userid = u.id
                     LEFT JOIN (SELECT userid, COUNT(*) AS cnt FROM {course_completions}
                                 WHERE timecompleted IS NOT NULL GROUP BY userid) cc ON cc.userid = u.id
                     LEFT JOIN (SELECT gg.userid, AVG(100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0)) AS avgpct
                                  FROM {grade_grades} gg
                                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                                 WHERE gg.finalgrade IS NOT NULL AND gg.hidden = 0
                              GROUP BY gg.userid) gr ON gr.userid = u.id
                         WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2 $fw
                      ORDER BY completed DESC, u.lastname";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::number((int)$r->enrolled),
                               cell::number((int)$r->completed),
                               $r->avgpct === null
                                   ? cell::text('—')
                                   : cell::number(round($r->avgpct, 1), round($r->avgpct, 1) . '%')];
                }
                $count = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {user} u WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2 $fw",
                    $fp
                );
                return [$rows, $count];
            },
        ];

        $defs[] = [
            'id' => 'inactive_learners', 'family' => 'engagement', 'icon' => 'moon', 'grain' => 'learner',
            'filters' => ['cohort', 'idle'],
            'columns' => [['learner', 'col_learner', 'text'], ['email', 'col_email', 'text'],
                          ['last', 'col_lastaccess', 'date'], ['idle', 'col_idle', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'idle' => 'u.lastaccess']);
                $params = ['cut' => time() - 30 * DAYSECS] + $fp;
                $where = "u.deleted = 0 AND u.suspended = 0 AND u.id > 2
                           AND u.lastaccess > 0 AND u.lastaccess < :cut $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.lastaccess
                          FROM {user} u
                         WHERE $where
                      ORDER BY u.lastaccess ASC";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                $now = time();
                foreach ($recs as $u) {
                    $days = (int) floor(($now - $u->lastaccess) / DAYSECS);
                    $rows[] = [cell::text(self::fullname_of($u)), cell::text($u->email), cell::when($u->lastaccess),
                               cell::number($days, $days . ' ' . get_string('days', 'local_beacon'))];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {user} u WHERE $where", $params)];
            },
        ];

        $defs[] = [
            'id' => 'never_logged_in', 'family' => 'people', 'icon' => 'users', 'grain' => 'learner',
            'defaulton' => true,
            'filters' => ['daterange', 'cohort', 'auth'], 'datelabel' => 'col_created',
            'columns' => [['learner', 'col_learner', 'text'], ['email', 'col_email', 'text'],
                          ['created', 'col_created', 'date'], ['auth', 'col_auth', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'auth' => 'u.auth',
                    'daterange' => ['col' => 'u.timecreated', 'label' => 'col_created']]);
                $where = "u.deleted = 0 AND u.suspended = 0 AND u.id > 2 AND u.lastaccess = 0 $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.timecreated, u.auth
                          FROM {user} u
                         WHERE $where
                      ORDER BY u.timecreated DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $u) {
                    $rows[] = [cell::text(self::fullname_of($u)), cell::text($u->email), cell::when($u->timecreated),
                               cell::text($u->auth)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {user} u WHERE $where", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'new_users', 'family' => 'people', 'icon' => 'plus', 'grain' => 'learner',
            'defaulton' => true,
            'filters' => ['cohort', 'auth'],
            'columns' => [['learner', 'col_learner', 'text'], ['email', 'col_email', 'text'],
                          ['created', 'col_created', 'date'], ['auth', 'col_auth', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'auth' => 'u.auth']);
                $params = ['cut' => time() - 30 * DAYSECS] + $fp;
                $where = "u.deleted = 0 AND u.id > 2 AND u.timecreated > :cut $fw";
                $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.timecreated, u.auth
                          FROM {user} u
                         WHERE $where
                      ORDER BY u.timecreated DESC";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $u) {
                    $rows[] = [cell::text(self::fullname_of($u)), cell::text($u->email), cell::when($u->timecreated),
                               cell::text($u->auth)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {user} u WHERE $where", $params)];
            },
        ];

        $defs[] = [
            'id' => 'cohort_membership', 'family' => 'people', 'icon' => 'users', 'grain' => 'cohort',
            'defaulton' => true, 'requirestable' => 'cohort_members',
            'filters' => ['daterange', 'cohortid'], 'datelabel' => 'col_added',
            'columns' => [['learner', 'col_learner', 'text'], ['cohort', 'col_cohort', 'text'],
                          ['added', 'col_added', 'date']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohortid' => 'cm.cohortid',
                    'daterange' => ['col' => 'cm.timeadded', 'label' => 'col_added']]);
                $body = "FROM {cohort_members} cm
                          JOIN {cohort} co ON co.id = cm.cohortid
                          JOIN {user} u ON u.id = cm.userid AND u.deleted = 0
                         WHERE 1 = 1 $fw";
                $sql = "SELECT cm.id, u.firstname, u.lastname, co.name AS cohort, cm.timeadded
                        $body ORDER BY co.name, u.lastname";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text(format_string($r->cohort)), cell::when($r->timeadded)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'role_assignments', 'family' => 'operations', 'icon' => 'shield', 'grain' => 'role',
            'defaulton' => true, 'requirestable' => 'role_assignments',
            'filters' => ['roleid', 'contextlevel'],
            'columns' => [['learner', 'col_learner', 'text'], ['role', 'col_role', 'text'],
                          ['scope', 'col_scope', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['roleid' => 'ra.roleid', 'contextlevel' => 'ctx.contextlevel']);
                $body = "FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid
                          JOIN {context} ctx ON ctx.id = ra.contextid
                          JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                         WHERE r.shortname IN ('editingteacher','teacher','manager','coursecreator') $fw";
                $sql = "SELECT ra.id, u.firstname, u.lastname, r.shortname AS role, ctx.contextlevel
                        $body ORDER BY r.shortname, u.lastname";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $levels = [10 => 'System', 40 => 'Category', 50 => 'Course', 70 => 'Activity', 30 => 'User'];
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text(ucfirst($r->role)),
                               cell::text($levels[(int)$r->contextlevel] ?? (string)$r->contextlevel)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        // Progress.

        $defs[] = [
            'id' => 'course_progress', 'family' => 'progress', 'icon' => 'play', 'grain' => 'enrolment',
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'progressband'],
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['progress', 'col_progress', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'enr.userid', 'group' => 'enr.userid',
                    'course' => 'enr.courseid', 'category' => 'enr.courseid',
                    'trainer' => ['user' => 'enr.userid', 'course' => 'enr.courseid'],
                    'progressband' => 'CASE WHEN COALESCE(t.cnt,0) > 0 THEN 100.0 * COALESCE(d.cnt,0) / t.cnt ELSE 0 END']);
                $params = ['now' => time()] + $fp;
                $body = "FROM (" . self::live_enrolments() . ") enr
                          JOIN {user} u ON u.id = enr.userid
                          JOIN {course} c ON c.id = enr.courseid
                     LEFT JOIN (SELECT cmc.userid, cm.course AS courseid, COUNT(*) AS cnt
                                  FROM {course_modules_completion} cmc
                                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid AND cm.deletioninprogress = 0
                                 WHERE cmc.completionstate IN (1,2,3)
                              GROUP BY cmc.userid, cm.course) d ON d.userid = enr.userid AND d.courseid = enr.courseid
                     LEFT JOIN (SELECT course, COUNT(*) AS cnt FROM {course_modules}
                                 WHERE completion > 0 AND deletioninprogress = 0
                              GROUP BY course) t ON t.course = enr.courseid
                         WHERE 1 = 1 $fw";
                $sql = "SELECT " . $DB->sql_concat('enr.userid', "'-'", 'enr.courseid') . " AS bcrowid, enr.userid, enr.courseid, u.firstname, u.lastname, c.fullname AS course,
                               COALESCE(d.cnt, 0) AS done, COALESCE(t.cnt, 0) AS total $body ORDER BY c.fullname";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $total = (int) $r->total;
                    $pct = $total ? round(100 * (int)$r->done / $total) : 0;
                    $badge = $pct >= 100 ? 'g' : ($pct > 0 ? 'w' : 'b');
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               cell::number($pct, $pct . '%')];
                    $rows[count($rows) - 1][2]['badge'] = $badge;
                    $rows[count($rows) - 1][2]['isstatus'] = true;
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $params)];
            },
        ];

        $defs[] = [
            'id' => 'competency_progress', 'family' => 'progress', 'icon' => 'check', 'grain' => 'competency',
            'defaulton' => true, 'requirestable' => 'competency_usercomp',
            'filters' => ['cohort', 'proficiency'],
            'columns' => [['learner', 'col_learner', 'text'], ['competency', 'col_competency', 'text'],
                          ['status', 'col_status', 'status']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'proficiency' => 'uc.proficiency']);
                $body = "FROM {competency_usercomp} uc
                          JOIN {competency} comp ON comp.id = uc.competencyid
                          JOIN {user} u ON u.id = uc.userid AND u.deleted = 0
                         WHERE 1 = 1 $fw";
                $sql = "SELECT uc.id, u.firstname, u.lastname, comp.shortname AS competency, uc.proficiency
                        $body ORDER BY u.lastname, comp.shortname";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text(format_string($r->competency)),
                               $r->proficiency
                                   ? cell::status(get_string('comp_proficient', 'local_beacon'), 'g')
                                   : cell::status(get_string('comp_notyet', 'local_beacon'), 'w')];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        // Assessment.

        $defs[] = [
            'id' => 'quiz_grades', 'family' => 'assessment', 'icon' => 'star', 'grain' => 'quiz',
            'requirestable' => 'quiz_attempts',
            'filters' => ['category', 'course'],
            'columns' => [['quiz', 'col_quiz', 'text'], ['course', 'col_course', 'text'],
                          ['attempts', 'col_attempts', 'number'], ['learners', 'col_learners', 'number'],
                          ['avg', 'col_avg', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['course' => 'qz.course', 'category' => 'qz.course']);
                $body = "FROM {quiz} qz
                          JOIN {course} c ON c.id = qz.course
                          JOIN {quiz_attempts} qa ON qa.quiz = qz.id AND qa.state = 'finished' AND qa.preview = 0
                         WHERE 1 = 1 $fw";
                $sql = "SELECT qz.id, qz.name AS quiz, c.fullname AS course,
                               COUNT(qa.id) AS attempts, COUNT(DISTINCT qa.userid) AS learners,
                               AVG(100.0 * qa.sumgrades / NULLIF(qz.sumgrades,0)) AS avgpct
                        $body
                      GROUP BY qz.id, qz.name, c.fullname
                      ORDER BY attempts DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(format_string($r->quiz)), cell::text($r->course),
                               cell::number((int)$r->attempts), cell::number((int)$r->learners),
                               $r->avgpct === null ? cell::text('—') : cell::number(round($r->avgpct), round($r->avgpct) . '%')];
                }
                $total = $DB->count_records_sql("SELECT COUNT(DISTINCT qz.id) $body", $fp);
                return [$rows, $total];
            },
        ];

        $defs[] = [
            'id' => 'assignment_status', 'family' => 'assessment', 'icon' => 'pen', 'grain' => 'assignment',
            'requirestable' => 'assign',
            'filters' => ['category', 'course'],
            'columns' => [['assignment', 'col_assignment', 'text'], ['course', 'col_course', 'text'],
                          ['submitted', 'col_submitted_n', 'number'], ['graded', 'col_graded', 'number'],
                          ['pending', 'col_pending', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['course' => 'a.course', 'category' => 'a.course']);
                $sql = "SELECT a.id, a.name AS assignment, c.fullname AS course,
                          (SELECT COUNT(*) FROM {assign_submission} s
                            WHERE s.assignment = a.id AND s.latest = 1 AND s.status = 'submitted') AS submitted,
                          (SELECT COUNT(*) FROM {assign_submission} s
                             JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                                  AND g.attemptnumber = s.attemptnumber
                            WHERE s.assignment = a.id AND s.latest = 1 AND s.status = 'submitted'
                              AND g.grade IS NOT NULL AND g.grade >= 0) AS graded
                          FROM {assign} a
                          JOIN {course} c ON c.id = a.course
                         WHERE 1 = 1 $fw
                      ORDER BY submitted DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $pending = (int)$r->submitted - (int)$r->graded;
                    $rows[] = [cell::text(format_string($r->assignment)), cell::text($r->course),
                               cell::number((int)$r->submitted), cell::number((int)$r->graded),
                               $pending > 0 ? array_merge(cell::number($pending), ['badge' => 'w', 'isstatus' => true])
                                            : cell::number($pending)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {assign} a WHERE 1 = 1 $fw", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'scorm_attempts', 'family' => 'assessment', 'icon' => 'play', 'grain' => 'learner',
            'defaulton' => true, 'requirestable' => 'scorm_attempt',
            'filters' => ['category', 'course', 'trainer', 'cohort'],
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['scorm', 'col_scorm', 'text'], ['attempts', 'col_attempts', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'course' => 's.course', 'category' => 's.course',
                    'trainer' => ['user' => 'u.id', 'course' => 's.course']]);
                // Moodle 4.3+ SCORM tracking schema: one {scorm_attempt} row per attempt.
                // Only the columns proven to exist on the target schema are used
                // (userid, scormid) — attempt count is COUNT(*) of the rows.
                $sql = "SELECT MIN(sa.id) AS id, u.firstname, u.lastname, c.fullname AS course, s.name AS scorm,
                               COUNT(*) AS attempts
                          FROM {scorm_attempt} sa
                          JOIN {scorm} s ON s.id = sa.scormid
                          JOIN {course} c ON c.id = s.course
                          JOIN {user} u ON u.id = sa.userid AND u.deleted = 0
                         WHERE 1 = 1 $fw
                      GROUP BY u.id, u.firstname, u.lastname, c.fullname, s.name
                      ORDER BY attempts DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course), cell::text(format_string($r->scorm)),
                               cell::number((int)$r->attempts)];
                }
                return [$rows, count($rows)];
            },
        ];

        // Operations.

        $defs[] = [
            'id' => 'course_summary', 'family' => 'operations', 'icon' => 'grid', 'grain' => 'course',
            'filters' => ['category'],
            'columns' => [['course', 'col_course', 'text'], ['enrolled', 'col_enrolledn', 'number'],
                          ['completed', 'col_completedn', 'number'], ['grade', 'col_avggrade', 'text']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['category' => 'c.id']);
                $sql = "SELECT c.id, c.fullname,
                               COALESCE(en.cnt, 0) AS enrolled,
                               COALESCE(cc.cnt, 0) AS completed,
                               gr.avgpct AS avgpct
                          FROM {course} c
                     LEFT JOIN (SELECT e.courseid, COUNT(DISTINCT ue.userid) AS cnt
                                  FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                                 WHERE ue.status = 0 GROUP BY e.courseid) en ON en.courseid = c.id
                     LEFT JOIN (SELECT course, COUNT(*) AS cnt FROM {course_completions}
                                 WHERE timecompleted IS NOT NULL GROUP BY course) cc ON cc.course = c.id
                     LEFT JOIN (SELECT gi.courseid, AVG(100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0)) AS avgpct
                                  FROM {grade_grades} gg
                                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                                 WHERE gg.finalgrade IS NOT NULL AND gg.hidden = 0
                              GROUP BY gi.courseid) gr ON gr.courseid = c.id
                         WHERE c.id > 1 $fw
                      ORDER BY enrolled DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $c) {
                    $rows[] = [cell::text(format_string($c->fullname)), cell::number((int)$c->enrolled),
                               cell::number((int)$c->completed),
                               $c->avgpct === null
                                   ? cell::text('—')
                                   : cell::number(round($c->avgpct, 1), round($c->avgpct, 1) . '%')];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) FROM {course} c WHERE c.id > 1 $fw", $fp)];
            },
        ];

        // Compliance / engagement extras.

        $defs[] = [
            'id' => 'badges_awarded', 'family' => 'engagement', 'icon' => 'star', 'grain' => 'badge',
            'defaulton' => true, 'requirestable' => 'badge_issued',
            'filters' => ['daterange', 'cohort'], 'datelabel' => 'col_issued',
            'columns' => [['learner', 'col_learner', 'text'], ['badge', 'col_badge', 'text'],
                          ['issued', 'col_issued', 'date']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id',
                    'daterange' => ['col' => 'bi.dateissued', 'label' => 'col_issued']]);
                $body = "FROM {badge_issued} bi
                          JOIN {badge} b ON b.id = bi.badgeid
                          JOIN {user} u ON u.id = bi.userid AND u.deleted = 0
                         WHERE 1 = 1 $fw";
                $sql = "SELECT bi.id, u.firstname, u.lastname, b.name AS badge, bi.dateissued
                        $body ORDER BY bi.dateissued DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text(format_string($r->badge)), cell::when($r->dateissued)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'policy_acceptance', 'family' => 'compliance', 'icon' => 'shield', 'grain' => 'policy',
            'defaulton' => true, 'requirestable' => 'tool_policy_acceptances',
            'filters' => ['daterange', 'cohort', 'policystatus'], 'datelabel' => 'col_agreed',
            'columns' => [['learner', 'col_learner', 'text'], ['policy', 'col_policy', 'text'],
                          ['status', 'col_status', 'status'], ['when', 'col_agreed', 'date']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'u.id', 'policystatus' => 'pa.status',
                    'daterange' => ['col' => 'pa.timemodified', 'label' => 'col_agreed']]);
                $body = "FROM {tool_policy_acceptances} pa
                          JOIN {user} u ON u.id = pa.userid AND u.deleted = 0
                     LEFT JOIN {tool_policy_versions} pv ON pv.id = pa.policyversionid
                         WHERE 1 = 1 $fw";
                $sql = "SELECT pa.id, u.firstname, u.lastname, pv.name AS policy, pa.status, pa.timemodified
                        $body ORDER BY pa.timemodified DESC";
                $recs = $DB->get_records_sql($sql, $fp, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->policy ? format_string($r->policy) : '—'),
                               (int)$r->status === 1
                                   ? cell::status(get_string('policy_accepted', 'local_beacon'), 'g')
                                   : cell::status(get_string('policy_declined', 'local_beacon'), 'b'),
                               cell::when($r->timemodified)];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $fp)];
            },
        ];

        $defs[] = [
            'id' => 'funding_participation', 'family' => 'compliance', 'icon' => 'pulse', 'grain' => 'enrolment',
            'defaulton' => true,
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'daterange'], 'datelabel' => 'col_lastact',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['firstact', 'col_firstact', 'text'], ['lastact', 'col_lastact', 'text'],
                          ['activedays', 'col_activedays', 'number'], ['activities', 'col_activities', 'number'],
                          ['views', 'col_courseviews', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'act.userid', 'group' => 'act.userid',
                    'course' => 'act.courseid', 'category' => 'act.courseid',
                    'trainer' => ['user' => 'act.userid', 'course' => 'act.courseid'],
                    'daterange' => ['col' => 'act.ts', 'label' => 'col_lastact']]);
                $union = self::activity_union($DB);
                $rk = $DB->sql_concat('act.userid', "'-'", 'act.courseid');
                $joins = "FROM ($union) act
                          JOIN {user} u ON u.id = act.userid AND u.deleted = 0
                          JOIN {course} c ON c.id = act.courseid";
                $countbody = "$joins WHERE act.courseid > 1 AND act.userid > 0 $fw";
                $haslog = $DB->get_manager()->table_exists('logstore_standard_log');
                $vsel = '0';
                $vjoin = '';
                $params = $fp;
                if ($haslog) {
                    $vsel = 'MAX(COALESCE(v.vc, 0))';
                    $vjoin = "LEFT JOIN (SELECT l.userid, l.courseid, COUNT(*) AS vc
                                           FROM {logstore_standard_log} l
                                          WHERE l.eventname = :cvevent AND l.timecreated > :logcut
                                          GROUP BY l.userid, l.courseid) v
                                     ON v.userid = act.userid AND v.courseid = act.courseid";
                    $params = ['cvevent' => '\\core\\event\\course_viewed',
                               'logcut' => time() - 365 * DAYSECS] + $fp;
                }
                // Activities count = distinct activities in the durable
                // participation table, so it matches the Student activity
                // drill-down exactly. Falls back to the raw record count when the
                // participation table is not present.
                $haspart = $DB->get_manager()->table_exists('local_beacon_participation');
                $actsel = 'COUNT(*)';
                $pjoin = '';
                if ($haspart) {
                    $actsel = 'MAX(COALESCE(pp.pc, 0))';
                    $pjoin = "LEFT JOIN (SELECT userid, courseid, COUNT(*) AS pc
                                           FROM {local_beacon_participation}
                                       GROUP BY userid, courseid) pp
                                     ON pp.userid = act.userid AND pp.courseid = act.courseid";
                }
                $body = "$joins $vjoin $pjoin WHERE act.courseid > 1 AND act.userid > 0 $fw";
                $days = "COUNT(DISTINCT FLOOR(act.ts / 86400.0))";
                $sql = "SELECT $rk AS bcrowid, act.userid, act.courseid, u.firstname, u.lastname, c.fullname AS course,
                               MIN(act.ts) AS firstact, MAX(act.ts) AS lastact,
                               $days AS activedays, $actsel AS activities, $vsel AS views $body
                          GROUP BY act.userid, act.courseid, u.firstname, u.lastname, c.fullname
                          ORDER BY activedays DESC, lastact DESC";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    // The activities count drills into the durable Student activity
                    // report for this learner in this course.
                    $saurl = (new \moodle_url(
                        '/local/beacon/view.php',
                        ['type' => 'report', 'id' => 'student_activity', 'userid' => (int) $r->userid, 'courseid' => (int) $r->courseid]
                    ))->out(false);
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               cell::when((int) $r->firstact), cell::when((int) $r->lastact),
                               cell::number((int) $r->activedays),
                               cell::number((int) $r->activities, null, $saurl),
                               cell::number((int) $r->views)];
                }
                $total = $DB->count_records_sql("SELECT COUNT(DISTINCT $rk) $countbody", $fp);
                return [$rows, $total];
            },
        ];

        $defs[] = [
            'id' => 'student_activity', 'family' => 'compliance', 'icon' => 'pulse', 'grain' => 'activity',
            'defaulton' => false, 'schedulable' => false, 'requestscoped' => true,
            'requirestable' => 'local_beacon_participation',
            'columns' => [['activity', 'col_activity', 'text'], ['type', 'col_acttype', 'text'],
                          ['firstpart', 'col_firstpart', 'date'], ['lastpart', 'col_lastpart', 'date'],
                          ['engagements', 'col_engagements', 'number']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                $userid = optional_param('userid', 0, PARAM_INT);
                $courseid = optional_param('courseid', 0, PARAM_INT);
                if ($userid <= 0 || $courseid <= 1) {
                    return [[], 0];
                }
                // Scope: only view a learner you are entitled to see.
                if (!self::may_view_student($DB, (int) $USER->id, $userid, $courseid)) {
                    return [[], 0];
                }
                $rows = \local_beacon\local\participation_harvester::for_user_course($userid, $courseid);
                if (!$rows) {
                    return [[], 0];
                }
                try {
                    $modinfo = get_fast_modinfo($courseid);
                    $cms = $modinfo->get_cms();
                } catch (\moodle_exception $e) {
                    return [[], 0];
                }
                $out = [];
                foreach ($rows as $cmid => $r) {
                    $cm = $cms[$cmid] ?? null;
                    if ($cm === null) {
                        continue;
                    }
                    $url = $cm->url ? $cm->url->out(false) : null;
                    $out[] = [
                        'sortlast' => (int) $r->lastdate,
                        'cells' => [
                            cell::text($cm->get_formatted_name(), $url),
                            cell::text(get_string('pluginname', 'mod_' . $cm->modname)),
                            cell::when((int) $r->firstdate),
                            cell::when((int) $r->lastdate),
                            cell::number((int) $r->engagements),
                        ],
                    ];
                }
                // Most-recently-active first.
                usort($out, fn($a, $b) => $b['sortlast'] <=> $a['sortlast']);
                $total = count($out);
                $out = array_slice($out, 0, $limit);
                return [array_map(fn($r) => $r['cells'], $out), $total];
            },
        ];

        $defs[] = [
            'id' => 'course_access', 'family' => 'engagement', 'icon' => 'clock', 'grain' => 'enrolment',
            'defaulton' => true, 'requirestable' => 'logstore_standard_log',
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'daterange'], 'datelabel' => 'col_lastaccess',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['firstseen', 'col_firstseen', 'text'], ['lastseen', 'col_lastaccess', 'text'],
                          ['activedays', 'col_activedays', 'number'], ['views', 'col_courseviews', 'number'],
                          ['events', 'col_events', 'number']],
            'run' => function ($DB, $q, $limit) {
                [$fw, $fp] = $q->where(['cohort' => 'l.userid', 'group' => 'l.userid',
                    'course' => 'l.courseid', 'category' => 'l.courseid',
                    'trainer' => ['user' => 'l.userid', 'course' => 'l.courseid'],
                    'daterange' => ['col' => 'l.timecreated', 'label' => 'col_lastaccess']]);
                $rk = $DB->sql_concat('l.userid', "'-'", 'l.courseid');
                $body = "FROM {logstore_standard_log} l
                          JOIN {user} u ON u.id = l.userid AND u.deleted = 0
                          JOIN {course} c ON c.id = l.courseid
                         WHERE l.courseid > 1 AND l.userid > 0
                           AND EXISTS (SELECT 1 FROM {user_enrolments} ue
                                         JOIN {enrol} e ON e.id = ue.enrolid
                                        WHERE ue.userid = l.userid AND e.courseid = l.courseid
                                          AND ue.status = 0 AND e.status = 0) $fw";
                $views = "SUM(CASE WHEN l.eventname = :cvevent THEN 1 ELSE 0 END)";
                $sql = "SELECT $rk AS bcrowid, l.userid, l.courseid, u.firstname, u.lastname, c.fullname AS course,
                               MIN(l.timecreated) AS firstseen, MAX(l.timecreated) AS lastseen,
                               COUNT(DISTINCT FLOOR(l.timecreated / 86400.0)) AS activedays,
                               $views AS views, COUNT(*) AS events $body
                          GROUP BY l.userid, l.courseid, u.firstname, u.lastname, c.fullname
                          ORDER BY activedays DESC, lastseen DESC";
                $params = ['cvevent' => '\\core\\event\\course_viewed'] + $fp;
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(self::fullname_of($r)), cell::text($r->course),
                               cell::when((int) $r->firstseen), cell::when((int) $r->lastseen),
                               cell::number((int) $r->activedays), cell::number((int) $r->views),
                               cell::number((int) $r->events)];
                }
                $total = $DB->count_records_sql("SELECT COUNT(DISTINCT $rk) $body", $fp);
                return [$rows, $total];
            },
        ];

        $defs[] = [
            'id' => 'my_marking_queue', 'family' => 'assessment', 'icon' => 'pen', 'grain' => 'submission',
            'defaulton' => true, 'requirestable' => 'assign_submission', 'schedulable' => false,
            'scopedfilters' => true,
            'filters' => ['category', 'course', 'group', 'trainer', 'cohort', 'daterange'],
            'datelabel' => 'col_submitted',
            'columns' => [['learner', 'col_learner', 'text'], ['course', 'col_course', 'text'],
                          ['assignment', 'col_assignment', 'text'], ['submitted', 'col_submitted', 'text'],
                          ['waiting', 'col_waiting', 'text']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                [$fw, $fp] = $q->where(['course' => 'a.course', 'category' => 'a.course',
                    'group' => 's.userid', 'cohort' => 's.userid',
                    'daterange' => ['col' => 's.timemodified', 'label' => 'col_submitted']]);
                // Scope to what the viewer may mark: admins/managers see all; editing
                // teachers see their whole course; non-editing teachers see only learners
                // in the groups they belong to. Applied to the small unmarked set only.
                $scope = '';
                $params = $fp;
                if (!has_capability('moodle/site:config', \context_system::instance())) {
                    $scope = " AND (
                        EXISTS (SELECT 1 FROM {role_assignments} ra
                                  JOIN {context} ctx ON ctx.id = ra.contextid
                                       AND ctx.contextlevel = 50 AND ctx.instanceid = a.course
                                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'editingteacher'
                                 WHERE ra.userid = :meed)
                        OR (
                          EXISTS (SELECT 1 FROM {role_assignments} ra
                                    JOIN {context} ctx ON ctx.id = ra.contextid
                                         AND ctx.contextlevel = 50 AND ctx.instanceid = a.course
                                    JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'teacher'
                                   WHERE ra.userid = :ment)
                          AND EXISTS (SELECT 1 FROM {groups_members} gmme
                                        JOIN {groups} grp ON grp.id = gmme.groupid AND grp.courseid = a.course
                                        JOIN {groups_members} gml ON gml.groupid = grp.id AND gml.userid = s.userid
                                       WHERE gmme.userid = :mgrp)
                        )
                    )";
                    $params = ['meed' => $USER->id, 'ment' => $USER->id, 'mgrp' => $USER->id] + $fp;
                }
                // Trainer filter (admin only, enforced by the option list): narrow
                // to the submissions the chosen trainer(s) are responsible for.
                $trainers = $q->selected('trainer');
                if ($trainers) {
                    [$trsql, $trparams] = self::marking_trainer_filter($DB, $trainers);
                    $scope .= $trsql;
                    $params += $trparams;
                }
                $body = "FROM {assign_submission} s
                          JOIN {assign} a ON a.id = s.assignment
                          JOIN {course} c ON c.id = a.course
                          JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                     LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                               AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                     LEFT JOIN {modules} md ON md.name = 'assign'
                     LEFT JOIN {course_modules} cm ON cm.instance = a.id
                               AND cm.module = md.id AND cm.course = a.course
                         WHERE s.latest = 1 AND s.status = 'submitted'
                           AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0)$scope $fw";
                $sql = "SELECT s.id, s.userid, a.course AS courseid, cm.id AS cmid,
                               u.firstname, u.lastname, c.fullname AS course, a.name AS assignment,
                               s.timemodified $body ORDER BY s.timemodified ASC";
                $recs = $DB->get_records_sql($sql, $params, 0, $limit);
                $rows = [];
                $now = time();
                foreach ($recs as $r) {
                    $wait = $r->timemodified ? floor(($now - $r->timemodified) / DAYSECS) . ' ' .
                            get_string('days', 'local_beacon') : '—';
                    [$plink, $clink, $alink, $glink] = self::marking_links($r);
                    $rows[] = [cell::text(self::fullname_of($r), $plink), cell::text($r->course, $clink),
                               cell::text($r->assignment, $alink), cell::when($r->timemodified, $glink),
                               cell::status($wait, 'w')];
                }
                return [$rows, $DB->count_records_sql("SELECT COUNT(*) $body", $params)];
            },
        ];

        self::$reports = [];
        foreach ($defs as $d) {
            self::$reports[$d['id']] = new report($d);
        }
        return self::$reports;
    }

    /**
     * One metric by id, or null.
     *
     * @param string $id Metric id.
     * @return metric|null
     */
    public static function metric(string $id): ?metric {
        return self::metrics()[$id] ?? null;
    }

    /**
     * One report by id, or null.
     *
     * @param string $id Report id.
     * @return report|null
     */
    public static function report(string $id): ?report {
        return self::reports()[$id] ?? null;
    }

    /** @var report[]|null Personal (learner self-view) reports. */
    private static ?array $personal = null;

    /**
     * The learner self-view reports. Every query is hard-bound to the CURRENT
     * user ($USER->id) inside the closure, so a learner can only ever see their
     * own data — there is no user parameter a learner could tamper with.
     *
     * @return report[]
     */
    public static function personal_reports(): array {
        if (self::$personal !== null) {
            return self::$personal;
        }
        $defs = [];

        $defs[] = [
            'id' => 'my_courses', 'family' => 'progress', 'icon' => 'play', 'grain' => 'course',
            'columns' => [['course', 'col_course', 'text'], ['progress', 'col_progress', 'number'],
                          ['status', 'col_status', 'status'], ['last', 'col_lastaccess', 'text']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                $now = time();
                $sql = "SELECT c.id, c.fullname AS course,
                               COALESCE(d.cnt,0) AS done, COALESCE(t.cnt,0) AS total,
                               cc.timecompleted, la.timeaccess
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                     LEFT JOIN {course_completions} cc ON cc.userid = ue.userid AND cc.course = c.id
                     LEFT JOIN {user_lastaccess} la ON la.userid = ue.userid AND la.courseid = c.id
                     LEFT JOIN (SELECT cmc.userid, cm.course AS courseid, COUNT(*) AS cnt
                                  FROM {course_modules_completion} cmc
                                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid AND cm.deletioninprogress = 0
                                 WHERE cmc.completionstate IN (1,2,3)
                              GROUP BY cmc.userid, cm.course) d ON d.userid = ue.userid AND d.courseid = c.id
                     LEFT JOIN (SELECT course, COUNT(*) AS cnt FROM {course_modules}
                                 WHERE completion > 0 AND deletioninprogress = 0 GROUP BY course) t ON t.course = c.id
                         WHERE ue.userid = :me AND ue.status = 0 AND e.status = 0
                           AND (ue.timeend = 0 OR ue.timeend > :now)
                      ORDER BY c.fullname";
                $recs = $DB->get_records_sql($sql, ['me' => $USER->id, 'now' => $now], 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $total = (int) $r->total;
                    $pct = $total ? (int) round(100 * (int)$r->done / $total) : 0;
                    if (!empty($r->timecompleted)) {
                        $st = cell::status(get_string('status_complete', 'local_beacon'), 'g');
                    } else if ($pct > 0) {
                        $st = cell::status(get_string('status_inprogress', 'local_beacon'), 'w');
                    } else {
                        $st = cell::status(get_string('status_neveropened', 'local_beacon'), 'b');
                    }
                    $prog = cell::number($pct, $pct . '%');
                    $prog['badge'] = $pct >= 100 ? 'g' : ($pct > 0 ? 'w' : 'b');
                    $prog['isstatus'] = true;
                    $rows[] = [cell::text(format_string($r->course)), $prog, $st, cell::when($r->timeaccess)];
                }
                return [$rows, count($rows)];
            },
        ];

        $defs[] = [
            'id' => 'my_completions', 'family' => 'progress', 'icon' => 'flag', 'grain' => 'course',
            'columns' => [['course', 'col_course', 'text'], ['completed', 'col_completed', 'date']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                $sql = "SELECT cc.id, c.fullname AS course, cc.timecompleted
                          FROM {course_completions} cc
                          JOIN {course} c ON c.id = cc.course
                         WHERE cc.userid = :me AND cc.timecompleted IS NOT NULL
                      ORDER BY cc.timecompleted DESC";
                $recs = $DB->get_records_sql($sql, ['me' => $USER->id], 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(format_string($r->course)), cell::when($r->timecompleted)];
                }
                return [$rows, count($rows)];
            },
        ];

        $defs[] = [
            'id' => 'my_grades', 'family' => 'assessment', 'icon' => 'doc', 'grain' => 'course',
            'columns' => [['course', 'col_course', 'text'], ['grade', 'col_grade', 'text']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                $sql = "SELECT gg.id, c.fullname AS course,
                               (100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0)) AS pct
                          FROM {grade_grades} gg
                          JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                          JOIN {course} c ON c.id = gi.courseid
                         WHERE gg.userid = :me AND gg.finalgrade IS NOT NULL AND gg.hidden = 0
                      ORDER BY c.fullname";
                $recs = $DB->get_records_sql($sql, ['me' => $USER->id], 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $pct = $r->pct === null ? '—' : round($r->pct, 1) . '%';
                    $badge = $r->pct === null ? null : ($r->pct >= 50 ? 'g' : 'b');
                    $rows[] = [cell::text(format_string($r->course)),
                               $badge ? cell::status($pct, $badge) : cell::text($pct)];
                }
                return [$rows, count($rows)];
            },
        ];

        $defs[] = [
            'id' => 'my_certificates', 'family' => 'compliance', 'icon' => 'cert', 'grain' => 'certificate',
            'requirestable' => 'tool_certificate_issues',
            'columns' => [['cert', 'col_certificate', 'text'], ['issued', 'col_issued', 'date'],
                          ['expires', 'col_expires', 'text'], ['status', 'col_status', 'status']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                $now = time();
                $soon = $now + 30 * DAYSECS;
                $sql = "SELECT ci.id, t.name AS template, ci.timecreated, ci.expires
                          FROM {tool_certificate_issues} ci
                     LEFT JOIN {tool_certificate_templates} t ON t.id = ci.templateid
                         WHERE ci.userid = :me
                      ORDER BY ci.timecreated DESC";
                $recs = $DB->get_records_sql($sql, ['me' => $USER->id], 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    if (!$r->expires) {
                        $st = cell::status(get_string('cert_current', 'local_beacon'), 'g');
                    } else if ($r->expires <= $now) {
                        $st = cell::status(get_string('cert_lapsed', 'local_beacon'), 'b');
                    } else if ($r->expires <= $soon) {
                        $st = cell::status(get_string('cert_expiring', 'local_beacon'), 'w');
                    } else {
                        $st = cell::status(get_string('cert_current', 'local_beacon'), 'g');
                    }
                    $expiry = $r->expires
                        ? userdate($r->expires, get_string('strftimedate', 'langconfig'))
                        : get_string('cert_noexpiry', 'local_beacon');
                    $rows[] = [cell::text($r->template ?: '—'), cell::when($r->timecreated),
                               cell::text($expiry), $st];
                }
                return [$rows, count($rows)];
            },
        ];

        $defs[] = [
            'id' => 'my_badges', 'family' => 'engagement', 'icon' => 'star', 'grain' => 'badge',
            'requirestable' => 'badge_issued',
            'columns' => [['badge', 'col_badge', 'text'], ['issued', 'col_issued', 'date']],
            'run' => function ($DB, $q, $limit) {
                global $USER;
                $sql = "SELECT bi.id, b.name AS badge, bi.dateissued
                          FROM {badge_issued} bi
                          JOIN {badge} b ON b.id = bi.badgeid
                         WHERE bi.userid = :me
                      ORDER BY bi.dateissued DESC";
                $recs = $DB->get_records_sql($sql, ['me' => $USER->id], 0, $limit);
                $rows = [];
                foreach ($recs as $r) {
                    $rows[] = [cell::text(format_string($r->badge)), cell::when($r->dateissued)];
                }
                return [$rows, count($rows)];
            },
        ];

        self::$personal = [];
        foreach ($defs as $d) {
            $d['filters'] = [];
            $d['personal'] = true;
            self::$personal[$d['id']] = new report($d);
        }
        return self::$personal;
    }

    /**
     * One personal report by id, or null.
     *
     * @param string $id Report id.
     * @return report|null
     */
    public static function personal_report(string $id): ?report {
        return self::personal_reports()[$id] ?? null;
    }

    /**
     * Headline tiles for the current learner's self-view (their own figures).
     *
     * @return array<int,array{label:string,value:string}>
     */
    public static function personal_stats(): array {
        global $DB, $USER;
        $now = time();
        $me = ['me' => $USER->id];

        $enrolled = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT e.courseid) FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :me AND ue.status = 0 AND e.status = 0
                AND (ue.timeend = 0 OR ue.timeend > :now)",
            $me + ['now' => $now]
        );
        $completed = (int) $DB->count_records_select(
            'course_completions',
            'userid = :me AND timecompleted IS NOT NULL',
            $me
        );
        $inprogress = max(0, $enrolled - $completed);
        $avg = $DB->get_field_sql(
            "SELECT AVG(100.0 * gg.finalgrade / NULLIF(gg.rawgrademax,0))
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
              WHERE gg.userid = :me AND gg.finalgrade IS NOT NULL AND gg.hidden = 0",
            $me
        );

        return [
            ['label' => get_string('my_enrolled', 'local_beacon'), 'value' => number_format($enrolled)],
            ['label' => get_string('my_completed', 'local_beacon'), 'value' => number_format($completed)],
            ['label' => get_string('my_inprogress', 'local_beacon'), 'value' => number_format($inprogress)],
            ['label' => get_string('my_avggrade', 'local_beacon'),
             'value' => ($avg === null || $avg === false) ? '—' : round($avg) . '%'],
        ];
    }
}
