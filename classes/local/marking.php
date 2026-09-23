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
 * Per-trainer marking-queue counts (submitted-but-unmarked assessments).
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Precomputes, for every trainer, how many submissions they may mark that are
 * still unmarked, and caches it in `local_beacon_marking`.
 *
 * The daily digest reads one indexed row per user from that cache — it never
 * runs the marking query on page load. The expensive work happens in the
 * scheduled task (off the request path), which is why the core Grade Me block's
 * page-load cost is avoided entirely.
 *
 * Safety: each trainer's count is computed with that trainer's own user id
 * bound into the scope predicate, so a trainer's cached figure can only ever
 * reflect what that trainer is entitled to mark. There is no "see everything"
 * path here — unlike a report a viewer runs, this runs unattended in cron, so
 * every count is explicitly per-trainer-scoped.
 */
class marking {
    /**
     * The scope predicate for one trainer, mirroring the My marking queue report:
     * an editing teacher counts their whole course; a non-editing teacher counts
     * only learners in the groups they belong to. Aliases assume the query below
     * (assign {a}, submission {s}).
     *
     * @param int $uid Trainer user id.
     * @return array [string $sqlfragment, array $params]
     */
    private static function scope(int $uid): array {
        global $DB;
        // Uses the site's admin-configured teacher roles (so custom roles count),
        // with the same no-groups fallback as the reports: a course-level role
        // counts the whole course; a group-level role counts shared-group learners,
        // or the whole course when it has no groups.
        $courseroles = roles::course_roleids();
        $grouproles = roles::group_roleids();
        $clauses = [];
        $params = [];
        if ($courseroles) {
            [$cin, $cp] = $DB->get_in_or_equal($courseroles, SQL_PARAMS_NAMED, 'mkcr');
            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                           AND ctx.contextlevel = 50 AND ctx.instanceid = a.course
                     WHERE ra.userid = :meed AND ra.roleid $cin)";
            $params += ['meed' => $uid] + $cp;
        }
        if ($grouproles) {
            [$gin, $gp] = $DB->get_in_or_equal($grouproles, SQL_PARAMS_NAMED, 'mkgr');
            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                           AND ctx.contextlevel = 50 AND ctx.instanceid = a.course
                     WHERE ra.userid = :ment AND ra.roleid $gin
                       AND (EXISTS (SELECT 1 FROM {groups_members} gmme
                                      JOIN {groups} grp ON grp.id = gmme.groupid
                                           AND grp.courseid = a.course
                                      JOIN {groups_members} gml ON gml.groupid = grp.id
                                           AND gml.userid = s.userid
                                     WHERE gmme.userid = :mgrp)
                            OR NOT EXISTS (SELECT 1 FROM {groups} g2 WHERE g2.courseid = a.course)))";
            $params += ['ment' => $uid, 'mgrp' => $uid] + $gp;
        }
        if (!$clauses) {
            return ['1 = 0', []];
        }
        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    /**
     * Every user who holds a teacher or editing-teacher role at any course
     * context — the population the badge and digest apply to.
     *
     * @param \moodle_database $DB
     * @return int[] Distinct trainer user ids.
     */
    public static function trainer_ids(\moodle_database $DB): array {
        $roleids = roles::all_roleids();
        if (!$roleids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'trole');
        $sql = "SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                  JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                 WHERE ra.roleid $in";
        return array_keys($DB->get_records_sql($sql, $params));
    }

    /**
     * Count the unmarked submissions one trainer may mark, and the oldest one's
     * submission time. Filters to the small unmarked set first, then scopes.
     *
     * @param \moodle_database $DB
     * @param int $uid Trainer user id.
     * @return array [int $count, int $oldest] $oldest is 0 when the count is 0.
     */
    public static function count_for_trainer(\moodle_database $DB, int $uid): array {
        [$scope, $params] = self::scope($uid);
        $sql = "SELECT COUNT(*) AS cnt, COALESCE(MIN(s.timemodified), 0) AS oldest
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
             LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                       AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                 WHERE s.latest = 1 AND s.status = 'submitted'
                   AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0)
                   AND $scope";
        $rec = $DB->get_record_sql($sql, $params);
        $cnt = $rec ? (int) $rec->cnt : 0;
        return [$cnt, $cnt ? (int) $rec->oldest : 0];
    }

    /**
     * Recompute and store the marking count for every trainer. Called by the
     * refresh scheduled task. No-op if the assign subsystem is not installed.
     *
     * @return int Number of trainers processed.
     */
    public static function refresh_all(): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('assign_submission')) {
            return 0;
        }
        $now = time();
        $trainers = self::trainer_ids($DB);
        $keep = [];
        foreach ($trainers as $uid) {
            [$cnt, $oldest] = self::count_for_trainer($DB, (int) $uid);
            $existing = $DB->get_record('local_beacon_marking', ['userid' => $uid]);
            if ($existing) {
                $existing->tocount = $cnt;
                $existing->oldest = $oldest;
                $existing->timecomputed = $now;
                $DB->update_record('local_beacon_marking', $existing);
            } else {
                $DB->insert_record('local_beacon_marking', (object) [
                    'userid'       => $uid,
                    'tocount'      => $cnt,
                    'oldest'       => $oldest,
                    'lastnotified' => 0,
                    'timecomputed' => $now,
                ]);
            }
            $keep[] = (int) $uid;
        }
        // Drop rows for people who are no longer trainers, so the cache does not
        // grow unbounded and a demoted user stops getting a badge.
        if ($keep) {
            [$insql, $inparams] = $DB->get_in_or_equal($keep, SQL_PARAMS_NAMED, 'k', false);
            $DB->delete_records_select('local_beacon_marking', "userid $insql", $inparams);
        } else {
            $DB->delete_records('local_beacon_marking');
        }
        return count($trainers);
    }
}
