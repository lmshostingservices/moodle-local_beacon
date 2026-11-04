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
 * Report Studio — the deterministic language-to-report engine.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local\studio;

use local_beacon\local\roles;

/**
 * Turns a teacher's plain-language request into a safe, scoped report — with no
 * AI, no model, and no free-form SQL. The request is only ever MAPPED onto the
 * curated building blocks in {@see catalog}: a subject, one measure, an optional
 * group-by dimension, and recognised filters. Every identifier comes from the
 * catalogue and every value is a bound parameter, so the result is injection-safe
 * by construction, always read-only, always teacher-scoped, and always capped.
 */
class engine {

    /** @var int Hard row cap on any result. */
    private const MAXROWS = 500;

    /** @var int Max distinct categories before we trim to a top-N. */
    private const MAXCATS = 40;

    /**
     * Parse a plain-language request into a structured spec. Never throws for
     * bad input — it falls back to a sensible default and records a note.
     *
     * @param string $text The teacher's request.
     * @return \stdClass Spec: subjectid, subject, measureid, dimensionid|null, filters[], explanation, notes[].
     */
    public static function parse(string $text): \stdClass {
        $norm = self::normalise($text);
        $subjects = catalog::subjects();

        // Detect an explicit "group by" noun ("by course", "per month", "in each
        // course", "broken down by status") so we can both bias the subject choice
        // toward one that can satisfy it and pick that dimension.
        $groupnoun = null;
        if (preg_match('/\b(?:by|per|grouped by|split by|broken down by|for each|in each|across each|each)\s+([a-z]+)/', $norm, $gm)) {
            $groupnoun = $gm[1];
            // "by completion", "by pass rate" name a measure, not a group-by — don't
            // let them be misread as a dimension (e.g. "rank courses by completion").
            if (in_array($groupnoun, ['completion', 'completions', 'pass', 'score', 'scores',
                                      'rate', 'rates', 'average', 'count', 'total', 'number'], true)) {
                $groupnoun = null;
            }
        }

        // 1. Score each subject by how many of its words (subject/measure/dimension
        //    synonyms) appear in the request, plus a strong bonus if it can satisfy
        //    the requested group-by. Enrolments is the default when nothing hits.
        $best = null; $bestscore = 0;
        foreach ($subjects as $sid => $s) {
            $score = self::phrase_hits($norm, $s['syn']) * 2;
            foreach ($s['measures'] as $m) {
                $score += self::phrase_hits($norm, $m['syn']);
            }
            foreach ($s['dimensions'] as $d) {
                $score += self::phrase_hits($norm, $d['syn']);
            }
            if ($groupnoun !== null && self::subject_dim_for_noun($s, $groupnoun) !== null) {
                $score += 3;
            }
            if ($score > $bestscore) {
                $bestscore = $score; $best = $sid;
            }
        }
        $notes = [];
        if ($best === null) {
            $best = 'enrolments';
            $notes[] = get_string('studio_note_defaulted', 'local_beacon');
        }
        $subject = $subjects[$best];

        // 2. Measure: prefer a specific (non-default) measure whose synonym is
        //    present; fall back to the subject's default. This keeps generic words
        //    like "how many" from overriding "pass rate" or "learners".
        $measureid = null; $mscore = 0; $defaultid = null;
        foreach ($subject['measures'] as $mid => $m) {
            if (!empty($m['default'])) {
                $defaultid = $mid;
                continue;
            }
            $h = self::phrase_weight($norm, $m['syn']);
            if ($h > $mscore) { $mscore = $h; $measureid = $mid; }
        }
        if ($measureid === null) {
            // No specific measure named: honour an explicit default-measure synonym,
            // else just use the default.
            $measureid = $defaultid ?? array_key_first($subject['measures']);
        }

        // 3. Dimension (group-by): an explicit group-by noun wins; otherwise the
        //    strongest dimension synonym match, if any.
        $dimensionid = null; $dscore = 0;
        foreach ($subject['dimensions'] as $did => $d) {
            $h = self::phrase_weight($norm, $d['syn']);
            if ($h > $dscore) { $dscore = $h; $dimensionid = $did; }
        }
        if ($groupnoun !== null) {
            $byn = self::subject_dim_for_noun($subject, $groupnoun);
            if ($byn !== null) {
                $dimensionid = $byn;
            }
        }

        // 4. Filters: run each recogniser the subject allows.
        $filters = [];
        foreach ($subject['filters'] as $fid) {
            $f = self::recognise_filter($fid, $norm, $subject);
            if ($f !== null) {
                $filters[] = $f;
            }
        }

        // 5. Honesty check: if the request asks for something Beacon Studio cannot
        //    express yet, say so plainly rather than returning a confident number
        //    for a question it didn't actually answer.
        $un = self::unsupported_note($norm);
        if ($un !== null) {
            $notes[] = $un;
        }

        $spec = new \stdClass();
        $spec->subjectid = $best;
        $spec->subject = $subject;
        $spec->measureid = $measureid;
        $spec->dimensionid = $dimensionid;
        $spec->filters = $filters;
        $spec->notes = $notes;
        $spec->explanation = self::explain($spec);
        return $spec;
    }

    /**
     * Build and run the report described by a spec. Returns everything the page
     * needs: columns, rows, a headline number, the recommended chart, and a
     * plain-English description of the query that ran.
     *
     * @param \stdClass $spec From {@see parse()}.
     * @param \context $context Page context (for scoping).
     * @return \stdClass Result bundle.
     */
    public static function run(\stdClass $spec, \context $context): \stdClass {
        global $DB, $USER;

        $subject = $spec->subject;
        $measure = $subject['measures'][$spec->measureid];
        $dim = $spec->dimensionid ? $subject['dimensions'][$spec->dimensionid] : null;

        $params = ['now' => time()];
        $select = [];
        $groupby = [];
        $orderby = '';

        // Dimension select / group-by.
        if ($dim) {
            if ($dim['type'] === 'temporal') {
                $dexpr = self::month_expr($dim['tscol']);
                $orderby = 'd ASC';
            } else {
                $dexpr = $dim['sql'];
                $orderby = 'm DESC';
            }
            if (!empty($dim['params'])) {
                foreach ($dim['params'] as $k => $v) {
                    $params[$k] = self::resolve_param($v);
                }
            }
            $select[] = "$dexpr AS d";
            $groupby[] = $dexpr;
        }
        $select[] = $measure['sql'] . ' AS m';

        // WHERE = base conditions + filters + mandatory viewer scope.
        $where = ['(' . $subject['basewhere'] . ')'];
        foreach ($spec->filters as $f) {
            $where[] = '(' . $f->sql . ')';
            foreach ($f->params as $k => $v) {
                $params[$k] = $v;
            }
        }
        [$scopesql, $scopeparams] = self::scope_where($subject, $context, $USER->id);
        if ($scopesql !== '') {
            $where[] = $scopesql;
            $params += $scopeparams;
        }

        $sql = 'SELECT ' . implode(', ', $select) . "\n" . $subject['from']
             . "\n WHERE " . implode("\n   AND ", $where);
        if ($groupby) {
            $sql .= "\n GROUP BY " . implode(', ', $groupby);
        }
        if ($orderby) {
            $sql .= "\n ORDER BY $orderby";
        }

        $result = new \stdClass();
        $result->spec = $spec;
        $result->explanation = $spec->explanation;
        $result->notes = $spec->notes;
        $result->measurelabel = $measure['label'];
        $result->measureformat = $measure['format'];
        $result->dimensionlabel = $dim['label'] ?? null;
        $result->grain = $subject['grain'];
        $result->sqlpreview = self::preview_sql($sql, $params);
        $result->rows = [];
        $result->labels = [];
        $result->values = [];
        $result->headline = null;
        $result->error = null;

        try {
            $records = $DB->get_records_sql($sql, $params, 0, self::MAXROWS);
        } catch (\Throwable $ex) {
            $result->error = $ex->getMessage();
            return $result;
        }

        if (!$dim) {
            // Single total → one headline number.
            $row = reset($records);
            $val = $row ? (float) $row->m : 0;
            $result->headline = present_value($val, $measure['format']);
            $result->rows = [[$measure['label'], $result->headline]];
            $result->columns = [$measure['label']];
            $result->chart = null;
            return $result;
        }

        foreach ($records as $r) {
            $label = self::format_dim_label($r->d, $dim);
            $val = (float) $r->m;
            $result->labels[] = $label;
            $result->values[] = $val;
            $result->rows[] = [$label, present_value($val, $measure['format'])];
        }
        $result->columns = [$dim['label'], $measure['label']];

        // Trim to a readable top-N for wide categorical breakdowns.
        if ($dim['type'] !== 'temporal' && count($result->labels) > self::MAXCATS) {
            $result->labels = array_slice($result->labels, 0, self::MAXCATS);
            $result->values = array_slice($result->values, 0, self::MAXCATS);
            $result->rows = array_slice($result->rows, 0, self::MAXCATS);
            $result->notes[] = get_string('studio_note_topn', 'local_beacon', self::MAXCATS);
        }

        $result->chart = viz::recommend($dim, $measure, $result->labels, $result->values);
        return $result;
    }

    // ---------------------------------------------------------------- parsing --

    /**
     * Lower-case, de-punctuate (keeping digits) and collapse whitespace.
     *
     * @param string $text
     * @return string Padded with spaces so " word " containment works at edges.
     */
    private static function normalise(string $text): string {
        $t = \core_text::strtolower($text);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
        return ' ' . trim(preg_replace('/\s+/', ' ', $t)) . ' ';
    }

    /**
     * Count how many of the given phrases occur in the normalised text.
     *
     * @param string $norm
     * @param string[] $phrases
     * @return int
     */
    private static function phrase_hits(string $norm, array $phrases): int {
        $n = 0;
        foreach ($phrases as $p) {
            if (strpos($norm, ' ' . trim($p) . ' ') !== false) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Best (longest) matching phrase weight — longer phrases beat single words so
     * "pass rate" wins over "pass". Returns the word-count of the longest hit.
     *
     * @param string $norm
     * @param string[] $phrases
     * @return int
     */
    private static function phrase_weight(string $norm, array $phrases): int {
        $best = 0;
        foreach ($phrases as $p) {
            $p = trim($p);
            if (strpos($norm, ' ' . $p . ' ') !== false) {
                $best = max($best, 1 + substr_count($p, ' '));
            }
        }
        return $best;
    }

    /**
     * The id of the subject dimension whose synonyms include the given group-by
     * noun (e.g. "course" → the course dimension), or null.
     *
     * @param array $subject
     * @param string $noun
     * @return string|null
     */
    private static function subject_dim_for_noun(array $subject, string $noun): ?string {
        foreach ($subject['dimensions'] as $did => $d) {
            foreach ($d['syn'] as $p) {
                $p = trim($p);
                if ($p === $noun || strpos(' ' . $p . ' ', ' ' . $noun . ' ') !== false) {
                    return $did;
                }
            }
        }
        return null;
    }

    /**
     * If the request clearly asks for something the Studio catalogue cannot
     * express yet, return a plain note naming it, so the result carries an honest
     * caveat instead of a confident number for an unanswered question. Else null.
     *
     * @param string $norm
     * @return string|null
     */
    private static function unsupported_note(string $norm): ?string {
        $map = [
            'learner demographics (gender, age, location)' =>
                ['gender', 'age', 'ages', 'how old', 'located', 'location', 'city', 'country', 'address', 'postcode', 'suburb'],
            'device or login channel' => ['mobile', 'device', 'desktop', 'browser'],
            'badges' => ['badge', 'badges'],
            'certificates' => ['certificate', 'certificates', 'certification'],
            'payments or revenue' => ['revenue', 'payment', 'paid', 'invoice', 'price', 'cost', 'fees'],
            'forum activity' => ['forum', 'posts', 'discussion'],
            'page or resource views' => ['page view', 'pageview', 'views', 'viewed', 'clicks'],
            'attendance' => ['attendance', 'present', 'absent'],
            'time taken or duration' => ['time to complete', 'how long', 'duration', 'time spent', 'hours spent'],
            'survey or satisfaction scores' => ['satisfaction', 'survey', 'net promoter', 'rating'],
            'per-learner lists or rankings' =>
                ['who failed', 'who passed', 'highest scoring', 'lowest scoring', 'top performers', 'list of learners',
                 'which learners', 'name the', 'falling behind', 'at risk'],
            'per-learner grades or score cut-offs' =>
                ['grade distribution', 'distribution of grades', 'score below', 'scored below', 'below 50', 'failed', 'failing'],
            'marking workload' =>
                ['marking', 'awaiting grading', 'awaiting marking', 'unmarked', 'to be marked', 'needs grading', 'waiting to be graded'],
            'learners across multiple courses' =>
                ['more than one course', 'multiple courses', 'how many courses', 'across courses'],
            'ranked lists or side-by-side comparisons' =>
                ['vs', 'versus', 'top 5', 'top 10', 'top ten', 'top five', 'list learners', 'list of learners', 'name the'],
        ];
        $hits = [];
        foreach ($map as $concept => $phrases) {
            if (self::has_any($norm, $phrases)) {
                $hits[] = $concept;
            }
        }
        if (!$hits) {
            return null;
        }
        return get_string('studio_note_unsupported', 'local_beacon', implode('; ', $hits));
    }

    /**
     * Recognise one named filter in the request. Returns an object with a SQL
     * fragment and bound params, or null when the request does not ask for it.
     * Every fragment uses only catalogue column expressions and bound values.
     *
     * @param string $fid Filter id.
     * @param string $norm Normalised request.
     * @param array $subject Subject definition (for its column map).
     * @return \stdClass|null {label, sql, params}
     */
    private static function recognise_filter(string $fid, string $norm, array $subject): ?\stdClass {
        $cols = $subject['cols'];
        $mk = function (string $label, string $sql, array $params = []): \stdClass {
            $o = new \stdClass(); $o->label = $label; $o->sql = $sql; $o->params = $params; return $o;
        };
        switch ($fid) {
            case 'completed':
                if (self::has_any($norm, ['completed', 'finished', 'complete'])
                        && !self::has_any($norm, ['not completed', 'incomplete', 'in progress'])) {
                    return $mk(get_string('studio_f_completed', 'local_beacon'),
                              $cols['completed'] . ' IS NOT NULL');
                }
                return null;
            case 'inprogress':
                if (self::has_any($norm, ['in progress', 'incomplete', 'not completed', 'not finished', 'still going'])) {
                    return $mk(get_string('studio_f_inprogress', 'local_beacon'),
                              $cols['completed'] . ' IS NULL');
                }
                return null;
            case 'notstarted':
                if (self::has_any($norm, ['not started', 'havent started', 'haven t started', 'never opened',
                                          'not begun', 'havent begun', 'yet to start', 'never started',
                                          'not yet started', 'havent begun'])) {
                    return $mk(get_string('studio_f_notstarted', 'local_beacon'),
                              'NOT EXISTS (SELECT 1 FROM {user_lastaccess} la
                                            WHERE la.userid = ' . $cols['user'] . ' AND la.courseid = ' . $cols['course'] . ')');
                }
                return null;
            case 'notloggedin':
                if (preg_match('/(?:not|havent|hasnt) (?:logged|signed) in(?: for)?(?: the last)? (\d+) days?/', $norm, $mt)
                        || preg_match('/(?:inactive|dormant)(?: for)?(?: the last)? (\d+) days?/', $norm, $mt)) {
                    $days = (int) $mt[1];
                    $ph = self::ph('cut');
                    return $mk(get_string('studio_f_notloggedin', 'local_beacon', $days),
                              'NOT EXISTS (SELECT 1 FROM {user_lastaccess} la
                                            WHERE la.userid = ' . $cols['user'] . ' AND la.courseid = ' . $cols['course'] . "
                                              AND la.timeaccess > :$ph)",
                              [$ph => time() - $days * DAYSECS]);
                }
                return null;
            case 'enrolledsince':
                if (preg_match('/enrolled (?:in )?(?:the )?last (\d+) days?/', $norm, $mt)
                        || preg_match('/new (?:enrolments|enrollments|enrolment|enrollment).* (\d+) days?/', $norm, $mt)) {
                    $days = (int) $mt[1];
                    $ph = self::ph('since');
                    return $mk(get_string('studio_f_enrolledsince', 'local_beacon', $days),
                              $cols['enrolled'] . " > :$ph", [$ph => time() - $days * DAYSECS]);
                }
                return null;
            case 'dormant':
                if (preg_match('/(?:inactive|dormant|not (?:logged|signed) in)(?: for)?(?: the last)? (\d+) days?/', $norm, $mt)) {
                    $days = (int) $mt[1];
                } else if (self::has_any($norm, ['dormant', 'inactive learners', 'inactive students', 'gone quiet'])) {
                    $days = 30; // Bare "dormant"/"inactive learners": default to 30 days.
                } else {
                    return null;
                }
                $ph = self::ph('cut');
                return $mk(get_string('studio_f_dormant', 'local_beacon', $days),
                          $cols['lastaccess'] . " > 0 AND " . $cols['lastaccess'] . " < :$ph",
                          [$ph => time() - $days * DAYSECS]);
            case 'activelearner':
                if (self::has_any($norm, ['active learners', 'active students', 'currently active', 'recently active'])) {
                    $ph = self::ph('act');
                    return $mk(get_string('studio_f_active', 'local_beacon'),
                              $cols['lastaccess'] . " > :$ph", [$ph => time() - 30 * DAYSECS]);
                }
                return null;
            case 'neverloggedin':
                if (self::has_any($norm, ['never logged in', 'never signed in', 'never logged on'])) {
                    return $mk(get_string('studio_f_never', 'local_beacon'), $cols['lastaccess'] . ' = 0');
                }
                return null;
            case 'course':
                return self::recognise_course($norm, $subject);
        }
        return null;
    }

    /**
     * Resolve a course named in the request (fuzzy, scope-unaware here — the
     * mandatory scope filter still constrains results) to a bound courseid filter.
     *
     * @param string $norm
     * @param array $subject The subject (for its course/user columns).
     * @return \stdClass|null
     */
    private static function recognise_course(string $norm, array $subject): ?\stdClass {
        global $DB;
        $courses = $DB->get_records_select('course', 'id <> 1', null, '', 'id, shortname, fullname');
        $bestid = 0; $bestname = ''; $bestscore = 0;
        foreach ($courses as $c) {
            $short = ' ' . \core_text::strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $c->shortname)) . ' ';
            $full  = \core_text::strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $c->fullname));
            $score = 0;
            if (trim($short) !== '' && strpos($norm, trim($short)) !== false) {
                $score += 5;
            }
            // Fullname tokens: a distinctive word (>=7 chars, e.g. "coxswain",
            // "shipboard", "coastal", "navigation") is worth 2 — enough to resolve
            // a course on its own — while short/common words count 1. Generic words
            // are ignored so a quiz named "Safety ..." can't spuriously pick a course.
            $stop = ['grade', 'near', 'elements', 'and', 'the', 'for', 'of'];
            foreach (array_filter(explode(' ', $full), fn($w) => \core_text::strlen($w) >= 5) as $w) {
                if (in_array($w, $stop, true)) {
                    continue;
                }
                if (strpos($norm, ' ' . $w . ' ') !== false) {
                    $score += (\core_text::strlen($w) >= 7) ? 2 : 1;
                }
            }
            if ($score > $bestscore) { $bestscore = $score; $bestid = (int) $c->id; $bestname = $c->shortname; }
        }
        if ($bestscore < 2) {
            return null;
        }
        $ph = self::ph('crs');
        $o = new \stdClass();
        $o->label = get_string('studio_f_course', 'local_beacon', $bestname);
        if (!empty($subject['cols']['course'])) {
            // Subject already carries a course column (enrolments, quizzes, activities).
            $o->sql = $subject['cols']['course'] . " = :$ph";
        } else {
            // Learner-grained subject: scope to learners enrolled in that course.
            $o->sql = "EXISTS (SELECT 1 FROM {user_enrolments} lcue
                                JOIN {enrol} lcen ON lcen.id = lcue.enrolid
                               WHERE lcue.userid = " . $subject['cols']['user'] . " AND lcen.courseid = :$ph)";
        }
        $o->params = [$ph => $bestid];
        return $o;
    }

    // ------------------------------------------------------------- query parts --

    /**
     * The mandatory, non-removable viewer-scope predicate. Site viewers (admins
     * and holders of local/beacon:viewall at system level) see everything;
     * everyone else is hard-limited to courses where they hold a Beacon teacher
     * role. This can never be switched off by the request.
     *
     * @param array $subject
     * @param \context $context
     * @param int $viewerid
     * @return array{0:string,1:array}
     */
    private static function scope_where(array $subject, \context $context, int $viewerid): array {
        if (has_capability('local/beacon:viewall', \context_system::instance())
                || is_siteadmin($viewerid)) {
            return ['', []];
        }
        $roleids = roles::all_roleids();
        if (!$roleids) {
            // No teacher roles configured: fail safe to nothing.
            return ['1 = 0', []];
        }
        global $DB;
        [$rin, $rp] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'scr');
        $vph = self::ph('viewer');
        $rp[$vph] = $viewerid;
        $cols = $subject['cols'];
        if (!empty($cols['course'])) {
            $sql = "EXISTS (SELECT 1 FROM {role_assignments} bra
                             JOIN {context} bctx ON bctx.id = bra.contextid
                                  AND bctx.contextlevel = " . CONTEXT_COURSE . "
                                  AND bctx.instanceid = " . $cols['course'] . "
                            WHERE bra.userid = :$vph AND bra.roleid $rin)";
            return [$sql, $rp];
        }
        // Subject keyed only on the learner (e.g. the learners subject): scope to
        // learners enrolled in a course the viewer teaches.
        $sql = "EXISTS (SELECT 1 FROM {user_enrolments} sue
                         JOIN {enrol} sen ON sen.id = sue.enrolid
                         JOIN {role_assignments} bra ON bra.userid = :$vph AND bra.roleid $rin
                         JOIN {context} bctx ON bctx.id = bra.contextid
                              AND bctx.contextlevel = " . CONTEXT_COURSE . "
                              AND bctx.instanceid = sen.courseid
                        WHERE sue.userid = " . $cols['user'] . ")";
        return [$sql, $rp];
    }

    /**
     * A cross-database "YYYY-MM" bucket expression for a unix-timestamp column.
     *
     * @param string $col
     * @return string
     */
    private static function month_expr(string $col): string {
        global $DB;
        if ($DB->get_dbfamily() === 'postgres') {
            return "to_char(to_timestamp($col), 'YYYY-MM')";
        }
        // MySQL / MariaDB.
        return "DATE_FORMAT(FROM_UNIXTIME($col), '%Y-%m')";
    }

    /**
     * Format a dimension value for display (month buckets become "Mon YYYY").
     *
     * @param mixed $raw
     * @param array $dim
     * @return string
     */
    private static function format_dim_label($raw, array $dim): string {
        $raw = (string) ($raw ?? '');
        if ($raw === '') {
            return get_string('studio_none', 'local_beacon');
        }
        if ($dim['type'] === 'temporal' && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
            return userdate(make_timestamp((int) $m[1], (int) $m[2], 1), '%b %Y');
        }
        return $raw;
    }

    /**
     * Resolve a catalogue parameter token such as "DAYS:30" to a concrete value.
     *
     * @param string $token
     * @return mixed
     */
    private static function resolve_param(string $token) {
        if (preg_match('/^DAYS:(\d+)$/', $token, $m)) {
            return time() - ((int) $m[1]) * DAYSECS;
        }
        return $token;
    }

    /** @var int Monotonic placeholder counter for unique bound-param names. */
    private static int $phseq = 0;

    /**
     * A unique named-parameter key.
     *
     * @param string $stem
     * @return string
     */
    private static function ph(string $stem): string {
        return 'st' . $stem . (self::$phseq++);
    }

    /**
     * Whether any of the phrases appears in the normalised request.
     *
     * @param string $norm
     * @param string[] $phrases
     * @return bool
     */
    private static function has_any(string $norm, array $phrases): bool {
        foreach ($phrases as $p) {
            if (strpos($norm, ' ' . trim($p) . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * A one-sentence plain-English description of what the spec will do.
     *
     * @param \stdClass $spec
     * @return string
     */
    private static function explain(\stdClass $spec): string {
        $subject = $spec->subject;
        $measure = $subject['measures'][$spec->measureid];
        $a = new \stdClass();
        $a->measure = \core_text::strtolower($measure['label']);
        $a->subject = \core_text::strtolower($subject['label']);
        if ($spec->dimensionid) {
            $a->dimension = \core_text::strtolower($subject['dimensions'][$spec->dimensionid]['label']);
            $base = get_string('studio_explain_grouped', 'local_beacon', $a);
        } else {
            $base = get_string('studio_explain_total', 'local_beacon', $a);
        }
        if ($spec->filters) {
            $labels = array_map(fn($f) => $f->label, $spec->filters);
            $base .= ' ' . get_string('studio_explain_where', 'local_beacon', implode(', ', $labels));
        }
        return $base;
    }

    /**
     * A readable (non-executable) preview of the generated SQL with values inlined,
     * so the teacher can see exactly what will run. For display only.
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    private static function preview_sql(string $sql, array $params): string {
        $sql = preg_replace('/\{(\w+)\}/', 'mdl_$1', $sql);
        foreach ($params as $k => $v) {
            $val = is_numeric($v) ? (string) $v : "'" . addslashes((string) $v) . "'";
            $sql = preg_replace('/:' . preg_quote($k, '/') . '\b/', $val, $sql);
        }
        return trim($sql);
    }
}

/**
 * Format a numeric value for the given measure format. Free function kept local
 * to the engine file to avoid a tiny extra class.
 *
 * @param float $value
 * @param string $format int|percent|decimal
 * @return string
 */
function present_value(float $value, string $format): string {
    switch ($format) {
        case 'percent':
            return round($value) . '%';
        case 'decimal':
            return number_format($value, 1);
        default:
            return number_format($value);
    }
}
