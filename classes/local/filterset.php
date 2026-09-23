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
 * The server-side report filter engine.
 *
 * Parses the filter parameters off the URL, loads the site's real filter
 * options (cohorts, groups, courses, …) and turns a report's declared filter
 * map into safe, bound WHERE fragments. Every value is validated and every
 * fragment uses placeholders, so nothing user-supplied ever reaches SQL raw.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * A resolved set of active filters, plus the machinery to apply them.
 *
 * Filter *types* Beacon understands (a report opts in per type by binding it to
 * a column in its filter map):
 *   entity multi-selects  cohort · group · course · category · role · auth · enrolmethod
 *   date range            daterange (with one-click presets)
 *   single-column bands   idle · certstatus · policystatus · proficiency · contextlevel
 */
class filterset {
    /** @var \context The report context. */
    public \context $context;

    /**
     * Active values keyed by type.
     *  - entity/band types  => int[]|string[]
     *  - daterange          => ['from'=>?int, 'to'=>?int, 'preset'=>string]
     * @var array
     */
    private array $active;

    /** @var int|null When set, the course filter is locked to this course id. */
    private ?int $lockedcourse = null;

    /** @var int Monotonic counter guaranteeing unique bound-param names. */
    private int $seq = 0;

    /** @var array<string,array<int|string,string>> Lazily loaded option lists. */
    private array $optioncache = [];

    /**
     * When true, the course/category/group/trainer option lists are limited to
     * what the current viewer is entitled to (the courses they teach, those
     * courses' categories, the groups they can mark in, and — trainer — no one,
     * unless they are a site admin). Set by a report whose data is viewer-scoped,
     * so the filter dropdowns match the data the viewer can actually see.
     *
     * @var bool
     */
    private bool $scopeoptions = false;

    /** The band tokens each band type accepts, so nothing else can be injected. */
    private const BANDS = [
        'idle'         => ['30', '60', '90'],
        'certstatus'   => ['current', 'expiring', 'lapsed'],
        'policystatus' => ['accepted', 'declined'],
        'proficiency'  => ['proficient', 'notyet'],
        'contextlevel' => ['10', '40', '50', '70'],
        'gradeband'    => ['high', 'mid', 'low'],
        'progressband' => ['complete', 'inprogress', 'notstarted'],
    ];

    /** Preset tokens for the date-range filter. */
    private const PRESETS = ['7', '30', '90', '365', 'ytd'];

    /**
     * Constructor.
     *
     * @param \context $context Report context.
     * @param array $active Resolved active values.
     */
    public function __construct(\context $context, array $active) {
        $this->context = $context;
        $this->active = $active;
    }

    /**
     * Build the active set from the current request.
     *
     * @param \context $context Report context.
     * @return self
     */
    public static function from_request(\context $context): self {
        $ints = function (string $name): array {
            $vals = optional_param_array($name, [], PARAM_INT);
            return array_values(array_unique(array_filter($vals, fn($v) => $v > 0)));
        };
        $strs = function (string $name): array {
            $vals = optional_param_array($name, [], PARAM_ALPHANUMEXT);
            return array_values(array_unique(array_filter($vals, fn($v) => $v !== '')));
        };
        $preset = optional_param('f_preset', '', PARAM_ALPHANUMEXT);
        $fromraw = optional_param('f_from', '', PARAM_TEXT);
        $toraw = optional_param('f_to', '', PARAM_TEXT);

        return self::assemble($context, $ints, $strs, $preset, $fromraw, $toraw);
    }

    /**
     * Build the active set from a stored parameter array (used by scheduled
     * deliveries, which have no live request to read).
     *
     * @param \context $context Report context.
     * @param array $src Flat parameter array (e.g. from parse_str).
     * @return self
     */
    public static function from_params(\context $context, array $src): self {
        $ints = function (string $name) use ($src): array {
            $vals = is_array($src[$name] ?? null) ? $src[$name] : [];
            $vals = array_map(fn($v) => (int) clean_param((string) $v, PARAM_INT), $vals);
            return array_values(array_unique(array_filter($vals, fn($v) => $v > 0)));
        };
        $strs = function (string $name) use ($src): array {
            $vals = is_array($src[$name] ?? null) ? $src[$name] : [];
            $vals = array_map(fn($v) => clean_param((string) $v, PARAM_ALPHANUMEXT), $vals);
            return array_values(array_unique(array_filter($vals, fn($v) => $v !== '')));
        };
        $preset = clean_param($src['f_preset'] ?? '', PARAM_ALPHANUMEXT);
        $fromraw = (string) ($src['f_from'] ?? '');
        $toraw = (string) ($src['f_to'] ?? '');

        return self::assemble($context, $ints, $strs, $preset, $fromraw, $toraw);
    }

    /**
     * Shared resolver used by both from_request() and from_params().
     *
     * @param \context $context Context.
     * @param callable $ints Reads an int[] param by name.
     * @param callable $strs Reads a string[] param by name.
     * @param string $preset Date preset token.
     * @param string $fromraw Raw custom "from" date.
     * @param string $toraw Raw custom "to" date.
     * @return self
     */
    private static function assemble(
        \context $context,
        callable $ints,
        callable $strs,
        string $preset,
        string $fromraw,
        string $toraw
    ): self {
        $active = [];
        foreach (
            [
            'cohort' => 'f_cohort',
            'cohortid' => 'f_cohortid',
            'group' => 'f_group',
            'course' => 'f_course',
            'category' => 'f_cat',
            'trainer' => 'f_trainer',
            'role' => 'f_role',
            'roleid' => 'f_roleid',
            ] as $type => $param
        ) {
            $v = $ints($param);
            if ($v) {
                $active[$type] = $v;
            }
        }
        foreach (['auth' => 'f_auth', 'enrolmethod' => 'f_enrol'] as $type => $param) {
            $v = $strs($param);
            if ($v) {
                $active[$type] = $v;
            }
        }
        foreach (self::BANDS as $type => $allowed) {
            $raw = $strs('f_' . $type);
            $v = array_values(array_intersect($raw, $allowed));
            if ($v) {
                $active[$type] = $v;
            }
        }

        // Date range: an explicit preset wins; otherwise a custom from/to pair.
        $from = self::parse_date($fromraw, false);
        $to = self::parse_date($toraw, true);
        if (in_array($preset, self::PRESETS, true)) {
            [$from, $to] = self::preset_range($preset);
        } else {
            $preset = '';
        }
        if ($from !== null || $to !== null) {
            $active['daterange'] = ['from' => $from, 'to' => $to, 'preset' => $preset];
        }

        return new self($context, $active);
    }

    /**
     * Parse a yyyy-mm-dd string into a timestamp, or null.
     *
     * @param string $s Raw value.
     * @param bool $endofday Push to 23:59:59 for an inclusive upper bound.
     * @return int|null
     */
    private static function parse_date(string $s, bool $endofday): ?int {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($s), $m)) {
            return null;
        }
        $ts = make_timestamp(
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            $endofday ? 23 : 0,
            $endofday ? 59 : 0,
            $endofday ? 59 : 0
        );
        return $ts ?: null;
    }

    /**
     * The [from, to] timestamps for a named preset.
     *
     * @param string $preset One of PRESETS.
     * @return array{0:int,1:int}
     */
    private static function preset_range(string $preset): array {
        $now = time();
        if ($preset === 'ytd') {
            $y = (int) userdate($now, '%Y');
            return [make_timestamp($y, 1, 1, 0, 0, 0), $now];
        }
        return [$now - ((int) $preset) * DAYSECS, $now];
    }

    /**
     * Is any filter active?
     *
     * @return bool
     */
    public function has_any(): bool {
        return !empty($this->active);
    }

    /**
     * Lock the filter set to a single course (used when a report is opened from
     * a course's own Reports menu). Reports that support a course filter pick
     * this up; others simply ignore it.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public function scope_to_course(int $courseid): void {
        if ($courseid > 1 && empty($this->active['course'])) {
            $this->active['course'] = [$courseid];
        }
    }

    /**
     * Pre-select values for a filter type when the viewer has not chosen any —
     * used to tick all of a teacher's own groups by default on first load. Never
     * overrides an explicit selection (or an explicit "cleared all").
     *
     * @param string $type Filter type.
     * @param array $ids Values to pre-select.
     * @return void
     */
    public function default_select(string $type, array $ids): void {
        if (!empty($ids) && !isset($this->active[$type])) {
            $this->active[$type] = array_values($ids);
        }
    }

    /**
     * HARD-lock the filter set to a single course. Unlike scope_to_course this
     * OVERRIDES any user-supplied course/category filter and cannot be removed,
     * so a teacher opening Beacon in their course can only ever see that
     * course's data — even by editing the URL. Also drops category (redundant
     * once a single course is fixed).
     *
     * @param int $courseid Course id.
     * @return void
     */
    public function lock_course(int $courseid): void {
        if ($courseid <= 1) {
            return;
        }
        $this->lockedcourse = $courseid;
        $this->active['course'] = [$courseid];
        unset($this->active['category']);
    }

    /**
     * The locked course id, or null when the set is not course-locked.
     *
     * @return int|null
     */
    public function locked_course(): ?int {
        return $this->lockedcourse;
    }

    /**
     * Limit the entity option lists (course, category, group, trainer) to what
     * the current viewer is entitled to. Site admins are unaffected (they see
     * everything); any other viewer sees only the courses they teach, those
     * courses' categories, the groups they can mark in, and no trainers.
     *
     * @return void
     */
    public function enable_option_scope(): void {
        $this->scopeoptions = true;
    }

    /** @var int|null When set, rows are hard-restricted to this viewer's learners. */
    private ?int $viewerscopeuid = null;

    /** @var bool Force viewer scope even when the ambient user would see all. */
    private bool $viewerscopeforced = false;

    /**
     * Hard-scope the report's ROWS (not just the option lists) to the learners a
     * non-admin viewer teaches — applied automatically on every load, before any
     * filter is chosen, so a teacher never sees a course they don't teach. A
     * viewer who holds local/beacon:viewall (managers/admins) is never scoped.
     *
     * @param int $userid The viewer to scope to.
     * @param bool $force Apply the scope regardless of the *ambient* user's
     *                    capability — used when running as someone else (a
     *                    scheduled email delivery runs under cron, not the owner),
     *                    where the caller has already checked the owner's rights.
     * @return void
     */
    public function enable_viewer_scope(int $userid, bool $force = false): void {
        $this->viewerscopeuid = $userid;
        $this->viewerscopeforced = $force;
    }

    /**
     * Whether row-level viewer scoping is in force for this run.
     *
     * @return bool
     */
    public function viewer_scope_active(): bool {
        return $this->viewerscopeuid !== null && ($this->viewerscopeforced || !$this->viewer_sees_all());
    }

    /**
     * The ids of the courses the scoped viewer teaches (for the "you are seeing
     * only these courses" banner).
     *
     * @return int[]
     */
    public function viewer_scope_courseids(): array {
        return $this->viewer_course_ids();
    }

    /**
     * Whether the current viewer sees the full, unscoped option lists.
     *
     * @return bool
     */
    private function viewer_sees_all(): bool {
        return has_capability('local/beacon:viewall', \context_system::instance());
    }

    /**
     * A bound WHERE fragment restricting a course-id column to the scoped viewer's
     * taught courses — the fallback row scope for a report that has a course
     * column but no (learner, course) trainer binding.
     *
     * @param string $col Course-id column.
     * @return array{0:string,1:array}
     */
    private function viewer_course_fragment(string $col): array {
        global $DB;
        $ids = $this->viewer_course_ids();
        if (!$ids) {
            return ['1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'vscope' . ($this->seq++) . '_');
        return ["$col $insql", $params];
    }

    /**
     * The ids of the courses the current viewer teaches (holds an editing- or
     * non-editing-teacher role at the course context).
     *
     * @return int[]
     */
    private function viewer_course_ids(): array {
        global $DB, $USER;
        $teacherroles = roles::all_roleids();
        if (!$teacherroles) {
            return [];
        }
        // Scope to the explicit viewer when one is set (a scheduled delivery runs
        // under cron, not the owner), otherwise the current user.
        $uid = $this->viewerscopeuid ?? (int) $USER->id;
        [$in, $params] = $DB->get_in_or_equal($teacherroles, SQL_PARAMS_NAMED, 'vrol');
        $sql = "SELECT DISTINCT ctx.instanceid AS courseid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                 WHERE ra.userid = :uid AND ra.roleid $in AND ctx.instanceid > 1";
        return array_map('intval', array_keys($DB->get_records_sql($sql, ['uid' => $uid] + $params)));
    }

    /**
     * A bound WHERE fragment restricting a course-id column to the viewer's
     * taught courses, when option scoping is on and the viewer is not an admin.
     *
     * @param string $col Course-id column.
     * @return array{0:string,1:array} [' AND …' | '', params]
     */
    private function course_scope_sql(string $col): array {
        global $DB;
        if (!$this->scopeoptions || $this->viewer_sees_all()) {
            return ['', []];
        }
        $ids = $this->viewer_course_ids();
        if (!$ids) {
            return [' AND 1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'vsc' . ($this->seq++) . '_');
        return [" AND $col $insql", $params];
    }

    /**
     * A bound WHERE fragment restricting a category-id column to the categories
     * that contain the viewer's taught courses, under the same conditions.
     *
     * @param string $col Category-id column.
     * @return array{0:string,1:array}
     */
    private function category_scope_sql(string $col): array {
        global $DB;
        if (!$this->scopeoptions || $this->viewer_sees_all()) {
            return ['', []];
        }
        $ids = $this->viewer_course_ids();
        if (!$ids) {
            return [' AND 1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'vscat' . ($this->seq++) . '_');
        return [" AND $col IN (SELECT category FROM {course} WHERE id $insql)", $params];
    }

    /**
     * A bound WHERE fragment restricting the groups list to those the viewer can
     * mark: every group in a course where they are an editing teacher, plus the
     * groups they belong to in a course where they are a non-editing teacher.
     * Assumes the query aliases the groups row as g.
     *
     * @return array{0:string,1:array}
     */
    private function group_scope_sql(): array {
        global $USER, $DB;
        if (!$this->scopeoptions || $this->viewer_sees_all()) {
            return ['', []];
        }
        $courseroles = roles::course_roleids();
        $grouproles = roles::group_roleids();
        $clauses = [];
        $params = ['vsg1' => $USER->id, 'vsg2' => $USER->id, 'vsg3' => $USER->id];
        if ($courseroles) {
            [$cin, $cp] = $DB->get_in_or_equal($courseroles, SQL_PARAMS_NAMED, 'vsgc');
            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                           AND ctx.contextlevel = 50 AND ctx.instanceid = g.courseid
                     WHERE ra.userid = :vsg1 AND ra.roleid $cin)";
            $params += $cp;
        }
        if ($grouproles) {
            [$gin, $gp] = $DB->get_in_or_equal($grouproles, SQL_PARAMS_NAMED, 'vsgg');
            $clauses[] = "(EXISTS (SELECT 1 FROM {role_assignments} ra
                        JOIN {context} ctx ON ctx.id = ra.contextid
                             AND ctx.contextlevel = 50 AND ctx.instanceid = g.courseid
                       WHERE ra.userid = :vsg2 AND ra.roleid $gin)
              AND EXISTS (SELECT 1 FROM {groups_members} gm
                           WHERE gm.groupid = g.id AND gm.userid = :vsg3))";
            $params += $gp;
        }
        if (!$clauses) {
            return [' AND 1 = 0', []];
        }
        return [' AND (' . implode(' OR ', $clauses) . ')', $params];
    }

    /**
     * The parent id of each option, for the dynamic dependent dropdowns: a
     * course's category, or a group's course. Returns value => parentid; empty
     * for types with no parent.
     *
     * @param string $type Filter type.
     * @return array<int,int>
     */
    public function option_parent(string $type): array {
        global $DB;
        $out = [];
        if ($type === 'course') {
            [$where, $params] = $this->course_scope_sql('id');
            foreach (
                $DB->get_records_select('course', "id > 1 $where", $params, '', 'id, category') as $r
            ) {
                $out[(int) $r->id] = (int) $r->category;
            }
        } else if ($type === 'group') {
            [$scopesql, $scopeparams] = $this->group_scope_sql();
            $recs = $DB->get_records_sql(
                "SELECT g.id, g.courseid FROM {groups} g WHERE 1 = 1 $scopesql",
                $scopeparams,
                0,
                2000
            );
            foreach ($recs as $r) {
                $out[(int) $r->id] = (int) $r->courseid;
            }
        }
        return $out;
    }

    /**
     * Whether a filter type is locked (cannot be changed by the viewer).
     *
     * @param string $type Filter type.
     * @return bool
     */
    public function is_locked(string $type): bool {
        return $this->lockedcourse !== null && in_array($type, ['course', 'category'], true);
    }

    /**
     * The active values as flat URL parameters, for carrying filters onto
     * export links and self-referential URLs.
     *
     * @return array
     */
    public function url_params(): array {
        $out = [];
        $map = ['cohort' => 'f_cohort', 'cohortid' => 'f_cohortid', 'group' => 'f_group',
                'course' => 'f_course', 'category' => 'f_cat', 'trainer' => 'f_trainer',
                'role' => 'f_role', 'roleid' => 'f_roleid', 'auth' => 'f_auth',
                'enrolmethod' => 'f_enrol'];
        foreach ($map as $type => $param) {
            foreach ($this->active[$type] ?? [] as $i => $v) {
                $out[$param . '[' . $i . ']'] = $v;
            }
        }
        foreach (array_keys(self::BANDS) as $type) {
            foreach ($this->active[$type] ?? [] as $i => $v) {
                $out['f_' . $type . '[' . $i . ']'] = $v;
            }
        }
        if (isset($this->active['daterange'])) {
            $dr = $this->active['daterange'];
            if ($dr['preset'] !== '') {
                $out['f_preset'] = $dr['preset'];
            } else {
                if ($dr['from'] !== null) {
                    $out['f_from'] = userdate($dr['from'], '%Y-%m-%d');
                }
                if ($dr['to'] !== null) {
                    $out['f_to'] = userdate($dr['to'], '%Y-%m-%d');
                }
            }
        }
        // Sentinel marking that the viewer has interacted with the filters, so a
        // deliberate "clear all" is respected and not re-defaulted (e.g. the
        // default pre-tick of a teacher's own groups on first load).
        $out['f_touched'] = 1;
        return $out;
    }

    /**
     * The URL parameters that would remain after removing one active value
     * (or the whole date range). Powers the no-JS chip removal links.
     *
     * @param string $type Filter type.
     * @param int|string|null $value Value to drop, or null for daterange.
     * @return array
     */
    public function without(string $type, $value): array {
        $active = $this->active;
        if ($type === 'daterange') {
            unset($active['daterange']);
        } else if (isset($active[$type])) {
            $active[$type] = array_values(array_filter(
                $active[$type],
                fn($v) => (string) $v !== (string) $value
            ));
            if (empty($active[$type])) {
                unset($active[$type]);
            }
        }
        return (new self($this->context, $active))->url_params();
    }

    /**
     * A short, stable signature of the active set, for cache keys.
     *
     * @return string
     */
    public function signature(): string {
        // Viewer scoping restricts the rows but is not part of $active, so it MUST
        // be folded into the signature — otherwise the shared result cache could
        // serve one viewer's scoped (or an admin's unscoped) rows to another.
        $sig = $this->active;
        if ($this->viewer_scope_active()) {
            $sig['__viewerscope'] = (int) $this->viewerscopeuid;
        }
        if (empty($sig)) {
            return 'none';
        }
        return md5(json_encode($sig));
    }

    /**
     * Turn a report's filter map into a bound WHERE fragment.
     *
     * The map binds each supported type to the column (or, for daterange, the
     * column plus a label) it constrains in this report, e.g.:
     *   ['cohort' => 'enr.userid', 'course' => 'enr.courseid',
     *    'daterange' => ['col' => 'cc.timecompleted', 'label' => 'col_completed']]
     *
     * Call this ONCE per report run and reuse the result for both the data and
     * the count query, so their bound-param names line up.
     *
     * @param array $map type => column | ['col'=>..,'label'=>..]
     * @return array{0:string,1:array} [' AND …', params]
     */
    public function where(array $map): array {
        global $DB;
        $frags = [];
        $params = [];

        foreach ($map as $type => $binding) {
            if (!isset($this->active[$type])) {
                continue;
            }

            // Teacher / non-editing-teacher filter: restrict the report's
            // (learner, course) rows to those a selected teacher is responsible
            // for. Binding is ['user' => <userid col>, 'course' => <courseid col>].
            if ($type === 'trainer') {
                $ucol = is_array($binding) ? ($binding['user'] ?? '') : '';
                $ccol = is_array($binding) ? ($binding['course'] ?? '') : '';
                if ($ucol !== '' && $ccol !== '') {
                    [$f, $p] = $this->trainer_fragment($ucol, $ccol, $this->active[$type]);
                    if ($f !== '') {
                        $frags[] = $f;
                        $params += $p;
                    }
                }
                continue;
            }

            $col = is_array($binding) ? ($binding['col'] ?? '') : $binding;

            if ($type === 'daterange') {
                $dr = $this->active[$type];
                if ($dr['from'] !== null) {
                    $p = 'bcf' . ($this->seq++);
                    $frags[] = "$col >= :$p";
                    $params[$p] = $dr['from'];
                }
                if ($dr['to'] !== null) {
                    $p = 'bcf' . ($this->seq++);
                    $frags[] = "$col <= :$p";
                    $params[$p] = $dr['to'];
                }
                continue;
            }

            if (isset(self::BANDS[$type])) {
                [$f, $p] = $this->band_fragment($type, $col, $this->active[$type]);
                if ($f !== '') {
                    $frags[] = $f;
                    $params += $p;
                }
                continue;
            }

            // Entity multi-selects.
            $vals = $this->active[$type];
            [$insql, $inparams] = $DB->get_in_or_equal($vals, SQL_PARAMS_NAMED, 'bcf' . ($this->seq++) . '_');
            switch ($type) {
                case 'cohort':
                    $frags[] = "$col IN (SELECT cm.userid FROM {cohort_members} cm WHERE cm.cohortid $insql)";
                    break;
                case 'group':
                    $frags[] = "$col IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid $insql)";
                    break;
                case 'role':
                    $frags[] = "$col IN (SELECT ra.userid FROM {role_assignments} ra WHERE ra.roleid $insql)";
                    break;
                case 'category':
                    $frags[] = "$col IN (SELECT c2.id FROM {course} c2 WHERE c2.category $insql)";
                    break;
                case 'course':
                case 'auth':
                case 'enrolmethod':
                default:
                    $frags[] = "$col $insql";
                    break;
            }
            $params += $inparams;
        }

        // Automatic viewer scope: a non-admin viewer is hard-limited to the
        // learners they teach on EVERY load, before any filter is chosen. Applied
        // through the report's own trainer binding (learner + course columns); a
        // report that has only a course column is limited to their taught courses.
        // This is the security floor — the visible filters can only narrow within
        // it, never widen past it.
        if ($this->viewer_scope_active()) {
            if (isset($map['trainer']) && is_array($map['trainer'])) {
                $ucol = $map['trainer']['user'] ?? '';
                $ccol = $map['trainer']['course'] ?? '';
                if ($ucol !== '' && $ccol !== '') {
                    [$vf, $vp] = $this->trainer_fragment($ucol, $ccol, [$this->viewerscopeuid]);
                    $frags[] = $vf;
                    $params += $vp;
                }
            } else if (isset($map['course']) || isset($map['category'])) {
                // Fall back to the report's course-id column (both the course and
                // category filters bind the same course-id column in every report),
                // limiting it to the viewer's taught courses.
                $binding = $map['course'] ?? $map['category'];
                $ccol = is_array($binding) ? ($binding['col'] ?? '') : $binding;
                if ($ccol !== '') {
                    [$cf, $cp] = $this->viewer_course_fragment($ccol);
                    $frags[] = $cf;
                    $params += $cp;
                }
            }
        }

        $where = $frags ? ' AND (' . implode(') AND (', $frags) . ')' : '';
        return [$where, $params];
    }

    /**
     * Build the Teacher / non-editing-teacher fragment: keep only rows whose
     * (learner, course) the selected teacher(s) are responsible for — an editing
     * teacher on that course (whole course), or a non-editing teacher who shares a
     * group with the learner on that course. OR-ed across the selected teachers.
     * Mirrors the marking-queue trainer scoping, generalised to any report's own
     * learner-id and course-id columns.
     *
     * @param string $ucol Learner user-id column.
     * @param string $ccol Course-id column.
     * @param array $trainerids Selected teacher user ids.
     * @return array{0:string,1:array}
     */
    private function trainer_fragment(string $ucol, string $ccol, array $trainerids): array {
        global $DB;
        $courseroles = roles::course_roleids();
        $grouproles = roles::group_roleids();
        $clauses = [];
        $params = [];
        // Course-level teacher role on the row's course → whole course.
        if ($courseroles) {
            [$in1, $p1] = $DB->get_in_or_equal($trainerids, SQL_PARAMS_NAMED, 'trf' . ($this->seq++) . '_');
            [$cin, $cp] = $DB->get_in_or_equal($courseroles, SQL_PARAMS_NAMED, 'trf' . ($this->seq++) . '_');
            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                           AND ctx.contextlevel = 50 AND ctx.instanceid = $ccol
                     WHERE ra.userid $in1 AND ra.roleid $cin)";
            $params += $p1 + $cp;
        }
        // Group-level teacher role → learners in a shared group, or the whole
        // course when it has no groups.
        if ($grouproles) {
            [$in2, $p2] = $DB->get_in_or_equal($trainerids, SQL_PARAMS_NAMED, 'trf' . ($this->seq++) . '_');
            [$gin, $gp] = $DB->get_in_or_equal($grouproles, SQL_PARAMS_NAMED, 'trf' . ($this->seq++) . '_');
            $clauses[] = "EXISTS (SELECT 1 FROM {role_assignments} ra
                         JOIN {context} ctx ON ctx.id = ra.contextid
                              AND ctx.contextlevel = 50 AND ctx.instanceid = $ccol
                        WHERE ra.userid $in2 AND ra.roleid $gin
                          AND (EXISTS (SELECT 1 FROM {groups_members} gmt
                                         JOIN {groups} grp ON grp.id = gmt.groupid
                                              AND grp.courseid = $ccol
                                         JOIN {groups_members} gls ON gls.groupid = grp.id
                                              AND gls.userid = $ucol
                                        WHERE gmt.userid = ra.userid)
                               OR NOT EXISTS (SELECT 1 FROM {groups} g2 WHERE g2.courseid = $ccol)))";
            $params += $p2 + $gp;
        }
        if (!$clauses) {
            return ['1 = 0', []];
        }
        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    /**
     * Build a single-column band fragment (OR-joined across chosen tokens).
     *
     * @param string $type Band type.
     * @param string $col Column it reads.
     * @param array $tokens Chosen tokens.
     * @return array{0:string,1:array}
     */
    private function band_fragment(string $type, string $col, array $tokens): array {
        $or = [];
        $params = [];
        $now = time();
        foreach ($tokens as $t) {
            switch ($type) {
                case 'idle':
                    $p = 'bcf' . ($this->seq++);
                    $or[] = "($col > 0 AND $col < :$p)";
                    $params[$p] = $now - ((int) $t) * DAYSECS;
                    break;
                case 'certstatus':
                    if ($t === 'current') {
                        $p = 'bcf' . ($this->seq++);
                        $or[] = "($col = 0 OR $col > :$p)";
                        $params[$p] = $now;
                    } else if ($t === 'expiring') {
                        $a = 'bcf' . ($this->seq++);
                        $b = 'bcf' . ($this->seq++);
                        $or[] = "($col > :$a AND $col <= :$b)";
                        $params[$a] = $now;
                        $params[$b] = $now + 30 * DAYSECS;
                    } else { // Lapsed.
                        $p = 'bcf' . ($this->seq++);
                        $or[] = "($col > 0 AND $col <= :$p)";
                        $params[$p] = $now;
                    }
                    break;
                case 'policystatus':
                    $p = 'bcf' . ($this->seq++);
                    $or[] = ($t === 'accepted') ? "$col = :$p" : "$col <> :$p";
                    $params[$p] = 1;
                    break;
                case 'proficiency':
                    if ($t === 'proficient') {
                        $p = 'bcf' . ($this->seq++);
                        $or[] = "$col = :$p";
                        $params[$p] = 1;
                    } else {
                        $p = 'bcf' . ($this->seq++);
                        $or[] = "($col IS NULL OR $col <> :$p)";
                        $params[$p] = 1;
                    }
                    break;
                case 'contextlevel':
                    $p = 'bcf' . ($this->seq++);
                    $or[] = "$col = :$p";
                    $params[$p] = (int) $t;
                    break;
                case 'gradeband':
                    // Column $col is a percentage expression; thresholds are constants.
                    if ($t === 'high') {
                        $or[] = "$col >= 80";
                    } else if ($t === 'mid') {
                        $or[] = "($col >= 50 AND $col < 80)";
                    } else { // Low.
                        $or[] = "$col < 50";
                    }
                    break;
                case 'progressband':
                    // Column $col is a 0–100 progress expression; thresholds are constants.
                    if ($t === 'complete') {
                        $or[] = "$col >= 100";
                    } else if ($t === 'inprogress') {
                        $or[] = "($col > 0 AND $col < 100)";
                    } else { // Not started.
                        $or[] = "$col = 0";
                    }
                    break;
            }
        }
        return [$or ? '(' . implode(' OR ', $or) . ')' : '', $params];
    }

    // Options.

    /**
     * The option list (id/token => label) for a filter type.
     *
     * @param string $type Filter type.
     * @return array<int|string,string>
     */
    public function options(string $type): array {
        if (isset($this->optioncache[$type])) {
            return $this->optioncache[$type];
        }
        global $DB;
        $opts = [];
        switch ($type) {
            case 'cohort':
            case 'cohortid':
                foreach ($DB->get_records('cohort', null, 'name ASC', 'id, name') as $r) {
                    $opts[$r->id] = format_string($r->name);
                }
                break;
            case 'group':
                // When locked to a course, only that course's groups are offered.
                if ($this->lockedcourse !== null) {
                    foreach (
                        $DB->get_records(
                            'groups',
                            ['courseid' => $this->lockedcourse],
                            'name ASC',
                            'id, name'
                        ) as $r
                    ) {
                        $opts[$r->id] = format_string($r->name);
                    }
                    break;
                }
                [$scopesql, $scopeparams] = $this->group_scope_sql();
                $recs = $DB->get_records_sql("SELECT g.id, g.name, c.shortname
                          FROM {groups} g JOIN {course} c ON c.id = g.courseid
                         WHERE 1 = 1 $scopesql
                      ORDER BY c.shortname, g.name", $scopeparams, 0, 500);
                foreach ($recs as $r) {
                    $opts[$r->id] = format_string($r->shortname . ' · ' . $r->name);
                }
                break;
            case 'course':
                [$where, $params] = $this->course_scope_sql('id');
                $recs = $DB->get_records_select(
                    'course',
                    "id > 1 $where",
                    $params,
                    'fullname ASC',
                    'id, fullname',
                    0,
                    1000
                );
                foreach ($recs as $r) {
                    $opts[$r->id] = format_string($r->fullname);
                }
                break;
            case 'category':
                [$catwhere, $catparams] = $this->category_scope_sql('id');
                foreach (
                    $DB->get_records_select(
                        'course_categories',
                        "1 = 1 $catwhere",
                        $catparams,
                        'name ASC',
                        'id, name'
                    ) as $r
                ) {
                    $opts[$r->id] = format_string($r->name);
                }
                break;
            case 'trainer':
                // Filtering by trainer is for users who see all learners (admins /
                // managers); anyone else gets an empty list, so the pill is hidden.
                if (!$this->viewer_sees_all()) {
                    break;
                }
                $teacherroles = roles::all_roleids();
                if (!$teacherroles) {
                    break;
                }
                [$rin, $rp] = $DB->get_in_or_equal($teacherroles, SQL_PARAMS_NAMED, 'trole');
                $recs = $DB->get_records_sql(
                    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                            u.lastnamephonetic, u.middlename, u.alternatename
                       FROM {role_assignments} ra
                       JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                       JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                      WHERE ra.roleid $rin
                   ORDER BY u.lastname, u.firstname",
                    $rp,
                    0,
                    1000
                );
                foreach ($recs as $r) {
                    $opts[$r->id] = fullname($r);
                }
                break;
            case 'role':
            case 'roleid':
                $roles = role_fix_names($DB->get_records('role', null, 'sortorder ASC'), $this->context);
                foreach ($roles as $r) {
                    $opts[$r->id] = $r->localname;
                }
                break;
            case 'auth':
                foreach (
                    $DB->get_records_sql("SELECT DISTINCT auth FROM {user}
                          WHERE deleted = 0 AND auth <> '' ORDER BY auth") as $r
                ) {
                    $opts[$r->auth] = get_string('pluginname', 'auth_' . $r->auth) !== '[[pluginname]]'
                        ? get_string('pluginname', 'auth_' . $r->auth) : $r->auth;
                }
                break;
            case 'enrolmethod':
                foreach ($DB->get_records_sql("SELECT DISTINCT enrol FROM {enrol} ORDER BY enrol") as $r) {
                    $name = get_string('pluginname', 'enrol_' . $r->enrol);
                    $opts[$r->enrol] = $name !== '[[pluginname]]' ? $name : ucfirst($r->enrol);
                }
                break;
            default:
                // Bands have fixed, translated option lists.
                if (isset(self::BANDS[$type])) {
                    foreach (self::BANDS[$type] as $tok) {
                        $opts[$tok] = get_string('band_' . $type . '_' . $tok, 'local_beacon');
                    }
                }
                break;
        }
        $this->optioncache[$type] = $opts;
        return $opts;
    }

    /**
     * The currently-selected values for a type (for pre-checking the UI).
     *
     * @param string $type Filter type.
     * @return array
     */
    public function selected(string $type): array {
        return $this->active[$type] ?? [];
    }

    /**
     * The active date range as ['from'=>?ts,'to'=>?ts,'preset'=>str], or null.
     *
     * @return array|null
     */
    public function daterange(): ?array {
        return $this->active['daterange'] ?? null;
    }

    /**
     * Whether a given type has any active selection.
     *
     * @param string $type Filter type.
     * @return bool
     */
    public function is_active(string $type): bool {
        return isset($this->active[$type]);
    }
}
