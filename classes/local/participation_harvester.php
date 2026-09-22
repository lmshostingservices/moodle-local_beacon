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
 * Harvests durable per-activity participation from the standard log.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Copies activity participation out of the standard log into a durable Beacon
 * table before Moodle purges the log, so the evidence survives beyond the log
 * retention window (typically 12 months).
 *
 * It works for EVERY activity type — core and contrib alike — because it reads
 * the log at module context (contextlevel 70), where every view or interaction
 * of any module is recorded. For each (user, course module) it keeps the first
 * and last participation date and a running count of interactions, upserted
 * incrementally from a stored high-water mark (the last log id processed), so a
 * run is cheap and never double-counts.
 */
class participation_harvester {
    /** @var string Config name holding the last processed log id. */
    private const HWM = 'participation_lastlogid';

    /** @var string Config name holding the last processed completion-record id. */
    private const HWM_CMC = 'participation_lastcmcid';

    /**
     * Process a batch of new log rows into the durable table.
     *
     * @param int $maxrows Maximum log rows to read this run.
     * @return int Number of log rows processed.
     */
    public static function harvest(int $maxrows = 50000): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            return 0;
        }

        $last = (int) get_config('local_beacon', self::HWM);
        // Every module-context event by a real user in a real course is a sign
        // the learner participated in that activity. contextinstanceid at module
        // context is the course module id.
        $sql = "SELECT id, userid, courseid, contextinstanceid AS cmid, timecreated
                  FROM {logstore_standard_log}
                 WHERE id > :last
                   AND contextlevel = 70
                   AND userid > 0
                   AND courseid > 1
                   AND contextinstanceid > 0
              ORDER BY id ASC";
        $rows = $DB->get_records_sql($sql, ['last' => $last], 0, $maxrows);
        if (!$rows) {
            return 0;
        }

        // Aggregate this batch in memory first, so we touch each (user, cm) once.
        $agg = [];
        $maxid = $last;
        foreach ($rows as $r) {
            $id = (int) $r->id;
            if ($id > $maxid) {
                $maxid = $id;
            }
            $key = $r->userid . ':' . $r->cmid;
            $ts = (int) $r->timecreated;
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'userid'   => (int) $r->userid,
                    'courseid' => (int) $r->courseid,
                    'cmid'     => (int) $r->cmid,
                    'first'    => $ts,
                    'last'     => $ts,
                    'count'    => 0,
                ];
            }
            if ($ts > 0 && $ts < $agg[$key]['first']) {
                $agg[$key]['first'] = $ts;
            }
            if ($ts > $agg[$key]['last']) {
                $agg[$key]['last'] = $ts;
            }
            $agg[$key]['count']++;
        }

        foreach ($agg as $a) {
            self::upsert($a['userid'], $a['courseid'], $a['cmid'], $a['first'], $a['last'], $a['count']);
        }

        set_config(self::HWM, $maxid, 'local_beacon');
        return count($rows);
    }

    /**
     * Harvest durable completion records into the participation table.
     *
     * Unlike the standard log, `course_modules_completion` is never purged — it
     * holds the date each learner completed (or first engaged with, for
     * view-completion) an activity, for the life of the course. Reading it means
     * a completion from years ago still appears as participation evidence, for
     * every completion-tracked activity type, core and contrib alike. Keyed
     * directly by the course module, so no per-plugin mapping is needed.
     *
     * @param int $maxrows Maximum completion rows to read this run.
     * @return int Number of completion rows processed.
     */
    public static function harvest_completion(int $maxrows = 50000): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('course_modules_completion')) {
            return 0;
        }

        $last = (int) get_config('local_beacon', self::HWM_CMC);
        $sql = "SELECT cmc.id, cmc.userid, cm.course AS courseid,
                       cmc.coursemoduleid AS cmid, cmc.timemodified
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                 WHERE cmc.id > :last
                   AND cmc.userid > 0
                   AND cmc.timemodified > 0
                   AND cm.course > 1
              ORDER BY cmc.id ASC";
        $rows = $DB->get_records_sql($sql, ['last' => $last], 0, $maxrows);
        if (!$rows) {
            return 0;
        }

        $maxid = $last;
        foreach ($rows as $r) {
            $id = (int) $r->id;
            if ($id > $maxid) {
                $maxid = $id;
            }
            // A completion is a single durable milestone: extend the first/last
            // participation dates and count it as one interaction. Each row is
            // processed exactly once (id high-water mark), so no double counting.
            $ts = (int) $r->timemodified;
            self::upsert((int) $r->userid, (int) $r->courseid, (int) $r->cmid, $ts, $ts, 1);
        }

        set_config(self::HWM_CMC, $maxid, 'local_beacon');
        return count($rows);
    }

    /** @var array<string,array{0:string,1:string,2:string,3:string}>|null Cached source map. */
    private static ?array $sources = null;

    /**
     * The durable per-activity source map: modname => [table, usercol, datecol,
     * joincol, joinmode]. joinmode is 'instance' (join course_modules on
     * cm.instance = t.joincol AND cm.module = <modid>) or 'cmid' (join on
     * cm.id = t.joincol). Core modules are curated; any other installed module is
     * discovered generically — and the instance column is found by testing which
     * candidate column actually resolves to a course module, so it works whatever
     * the column is named (e.g. scenarioid, workbookid) with no per-plugin
     * knowledge and no risk of picking a wrong column.
     *
     * @return array<string,array>
     */
    private static function attempt_sources(): array {
        global $DB;
        if (self::$sources !== null) {
            return self::$sources;
        }
        // Curated core sources.
        $map = [
            'assign'      => ['assign_submission', 'userid', 'timecreated', 'assignment', 'instance'],
            'quiz'        => ['quiz_attempts', 'userid', 'timestart', 'quiz', 'instance'],
            'lesson'      => ['lesson_timer', 'userid', 'starttime', 'lessonid', 'instance'],
            'data'        => ['data_records', 'userid', 'timecreated', 'dataid', 'instance'],
            'glossary'    => ['glossary_entries', 'userid', 'timecreated', 'glossaryid', 'instance'],
            'choice'      => ['choice_answers', 'userid', 'timemodified', 'choiceid', 'instance'],
            'feedback'    => ['feedback_completed', 'userid', 'timemodified', 'feedback', 'instance'],
            'h5pactivity' => ['h5pactivity_attempts', 'userid', 'timecreated', 'h5pactivityid', 'instance'],
            'workshop'    => ['workshop_submissions', 'authorid', 'timecreated', 'workshopid', 'instance'],
            // Legacy H5P "Interactive content": its xAPI event store is durable and
            // uses user_id / created_at, and content_id is the activity instance.
            'hvp'         => ['hvp_events', 'user_id', 'created_at', 'content_id', 'instance'],
        ];
        // Modules covered well enough by completion + the log, or with no clean
        // per-user attempt table.
        $skip = ['page', 'resource', 'url', 'folder', 'book', 'imscp', 'label',
                 'lti', 'wiki', 'scorm', 'forum', 'survey', 'chat'];
        $dbman = $DB->get_manager();
        $alltables = $DB->get_tables();
        $datecandidates = ['timecreated', 'timestarted', 'timemodified', 'timeseen', 'timeacknowledged'];
        foreach ($DB->get_records('modules', null, '', 'id, name') as $mod) {
            $name = $mod->name;
            $modid = (int) $mod->id;
            if (isset($map[$name]) || in_array($name, $skip, true) || !self::valid_ident($name)) {
                continue;
            }
            foreach ($alltables as $table) {
                // Only this module's own tables.
                if ($table !== $name && strpos($table, $name . '_') !== 0) {
                    continue;
                }
                if (!self::valid_ident($table)) {
                    continue;
                }
                $cols = $DB->get_columns($table);
                if (!isset($cols['userid'])) {
                    continue;
                }
                $datecol = null;
                foreach ($datecandidates as $d) {
                    if (isset($cols[$d])) {
                        $datecol = $d;
                        break;
                    }
                }
                if ($datecol === null) {
                    continue;
                }
                // A table keyed directly by course-module id needs no instance test.
                if (isset($cols['cmid'])) {
                    $map[$name] = [$table, 'userid', $datecol, 'cmid', 'cmid'];
                    break;
                }
                // Otherwise find the instance column by testing which *-id column
                // actually joins to a course module for this module.
                $joincol = null;
                foreach ($cols as $cn => $unused) {
                    if (in_array($cn, ['id', 'userid', 'groupid', 'usermodified'], true)) {
                        continue;
                    }
                    if (substr($cn, -2) !== 'id' || !self::valid_ident($cn)) {
                        continue;
                    }
                    $exists = $DB->record_exists_sql(
                        "SELECT 1 FROM {{$table}} zt
                           JOIN {course_modules} cm ON cm.instance = zt.{$cn} AND cm.module = :m",
                        ['m' => $modid]
                    );
                    if ($exists) {
                        $joincol = $cn;
                        break;
                    }
                }
                if ($joincol !== null) {
                    $map[$name] = [$table, 'userid', $datecol, $joincol, 'instance'];
                    break;
                }
            }
        }
        self::$sources = $map;
        return $map;
    }

    /**
     * A safe SQL identifier (lower-case, starts with a letter). Used to gate any
     * table/column name before it is interpolated into a query.
     *
     * @param string $s
     * @return bool
     */
    private static function valid_ident(string $s): bool {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $s);
    }

    /**
     * Harvest durable attempt/submission records from each activity module's own
     * table. These persist for the life of the course, so an attempt from years
     * ago still counts as participation — even for activities that do not track
     * completion. Each source has its own high-water mark, so rows are processed
     * exactly once.
     *
     * @param int $maxrows Maximum rows per source this run.
     * @return int Total rows processed across all sources.
     */
    public static function harvest_attempts(int $maxrows = 20000): int {
        global $DB;
        $dbman = $DB->get_manager();
        $processed = 0;
        foreach (self::attempt_sources() as $modname => $src) {
            [$table, $usercol, $datecol, $joincol, $joinmode] = $src;
            if (!self::valid_ident($table) || !self::valid_ident($usercol)
                    || !self::valid_ident($datecol) || !self::valid_ident($joincol)) {
                continue;
            }
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $modid = (int) $DB->get_field('modules', 'id', ['name' => $modname]);
            if (!$modid) {
                continue;
            }
            // Join to the course module either directly by cmid, or by the
            // activity instance for this module.
            if ($joinmode === 'cmid') {
                $cmjoin = "JOIN {course_modules} cm ON cm.id = t.{$joincol}";
                $jparams = [];
            } else {
                $cmjoin = "JOIN {course_modules} cm ON cm.instance = t.{$joincol} AND cm.module = :modid";
                $jparams = ['modid' => $modid];
            }
            $hwmkey = 'participation_src_' . $modname;
            $last = (int) get_config('local_beacon', $hwmkey);
            $sql = "SELECT t.id, t.{$usercol} AS userid, cm.course AS courseid,
                           cm.id AS cmid, t.{$datecol} AS ts
                      FROM {{$table}} t
                      $cmjoin
                     WHERE t.id > :last AND t.{$usercol} > 0 AND t.{$datecol} > 0 AND cm.course > 1
                  ORDER BY t.id ASC";
            $rows = $DB->get_records_sql($sql, ['last' => $last] + $jparams, 0, $maxrows);
            if (!$rows) {
                continue;
            }
            $maxid = $last;
            foreach ($rows as $r) {
                if ((int) $r->id > $maxid) {
                    $maxid = (int) $r->id;
                }
                $ts = (int) $r->ts;
                self::upsert((int) $r->userid, (int) $r->courseid, (int) $r->cmid, $ts, $ts, 1);
            }
            set_config($hwmkey, $maxid, 'local_beacon');
            $processed += count($rows);
        }
        return $processed;
    }

    /**
     * Merge one batch aggregate into the durable row for a (user, course module).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $cmid
     * @param int $first Earliest epoch in this batch.
     * @param int $last Latest epoch in this batch.
     * @param int $count Interactions in this batch.
     * @return void
     */
    private static function upsert(int $userid, int $courseid, int $cmid, int $first, int $last, int $count): void {
        global $DB;
        if ($cmid <= 0 || $userid <= 0) {
            return;
        }
        $now = time();
        $existing = $DB->get_record('local_beacon_participation', ['userid' => $userid, 'cmid' => $cmid]);
        if ($existing) {
            if ($first > 0 && ($existing->firstdate == 0 || $first < $existing->firstdate)) {
                $existing->firstdate = $first;
            }
            if ($last > $existing->lastdate) {
                $existing->lastdate = $last;
            }
            $existing->engagements += $count;
            if ($courseid > 0) {
                $existing->courseid = $courseid;
            }
            $existing->timemodified = $now;
            $DB->update_record('local_beacon_participation', $existing);
        } else {
            $DB->insert_record('local_beacon_participation', (object) [
                'userid'       => $userid,
                'courseid'     => $courseid,
                'cmid'         => $cmid,
                'firstdate'    => $first,
                'lastdate'     => $last,
                'engagements'  => $count,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * The durable participation rows for one learner in one course, keyed by cmid.
     *
     * @param int $userid
     * @param int $courseid
     * @return array<int,\stdClass> cmid => row
     */
    public static function for_user_course(int $userid, int $courseid): array {
        global $DB;
        $rows = $DB->get_records(
            'local_beacon_participation',
            ['userid' => $userid, 'courseid' => $courseid]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->cmid] = $r;
        }
        return $out;
    }
}
