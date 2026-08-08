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
        $fromraw = optional_param('f_from', '', PARAM_RAW);
        $toraw = optional_param('f_to', '', PARAM_RAW);

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
                'course' => 'f_course', 'category' => 'f_cat', 'role' => 'f_role',
                'roleid' => 'f_roleid', 'auth' => 'f_auth', 'enrolmethod' => 'f_enrol'];
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
        if (empty($this->active)) {
            return 'none';
        }
        return md5(json_encode($this->active));
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

        $where = $frags ? ' AND (' . implode(') AND (', $frags) . ')' : '';
        return [$where, $params];
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
                $recs = $DB->get_records_sql("SELECT g.id, g.name, c.shortname
                          FROM {groups} g JOIN {course} c ON c.id = g.courseid
                      ORDER BY c.shortname, g.name", [], 0, 500);
                foreach ($recs as $r) {
                    $opts[$r->id] = format_string($r->shortname . ' · ' . $r->name);
                }
                break;
            case 'course':
                $recs = $DB->get_records_select(
                    'course',
                    'id > 1',
                    null,
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
                foreach ($DB->get_records('course_categories', null, 'name ASC', 'id, name') as $r) {
                    $opts[$r->id] = format_string($r->name);
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
