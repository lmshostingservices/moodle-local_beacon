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
 * Which of the site's roles Beacon treats as teachers.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local;

/**
 * Resolves the roles that count as "teachers" for Beacon's scoping — read from
 * the site's real roles so custom roles (e.g. "Trainer", "Assessor") work, not
 * just Moodle's built-in archetypes.
 *
 * Two buckets, both admin-configurable on the Beacon settings page:
 *  - COURSE-level teacher roles: see every learner in a course they hold the role
 *    on (like an editing teacher). Default: every role with the editingteacher
 *    archetype.
 *  - GROUP-level teacher roles: see only learners in a group they share on that
 *    course. Default: every role with the teacher (non-editing) archetype.
 *
 * A role may sit in both buckets; a course-level grant always wins (whole course).
 * When a site uses no groups, put its trainer role in the COURSE bucket so trainers
 * see their whole course rather than nobody.
 */
class roles {
    /** @var string Config: comma-separated course-level teacher role ids. */
    private const CFG_COURSE = 'teacherroles_course';

    /** @var string Config: comma-separated group-level teacher role ids. */
    private const CFG_GROUP = 'teacherroles_group';

    /**
     * Role ids whose holders see every learner in their course.
     *
     * @return int[]
     */
    public static function course_roleids(): array {
        $cfg = get_config('local_beacon', self::CFG_COURSE);
        if ($cfg !== false && $cfg !== '') {
            return self::parse_ids($cfg);
        }
        return self::roleids_for_archetypes(['editingteacher']);
    }

    /**
     * Role ids whose holders see only the learners in groups they share.
     *
     * @return int[]
     */
    public static function group_roleids(): array {
        $cfg = get_config('local_beacon', self::CFG_GROUP);
        if ($cfg !== false && $cfg !== '') {
            return self::parse_ids($cfg);
        }
        return self::roleids_for_archetypes(['teacher']);
    }

    /**
     * Every teacher role id (course- and group-level combined). Used where the
     * distinction does not matter — e.g. "is this user a teacher anywhere?".
     *
     * @return int[]
     */
    public static function all_roleids(): array {
        return array_values(array_unique(array_merge(self::course_roleids(), self::group_roleids())));
    }

    /**
     * The full list of assignable roles on the site, id => display name, for the
     * settings multi-selects. Read live so custom roles appear.
     *
     * @return array<int,string>
     */
    public static function menu(): array {
        $out = [];
        foreach (role_get_names(\context_system::instance()) as $r) {
            $out[(int) $r->id] = $r->localname;
        }
        return $out;
    }

    /**
     * The default selection for a bucket, as role ids, used to pre-fill the
     * setting so a fresh site behaves exactly as before it was configurable.
     *
     * @param string $bucket 'course' or 'group'.
     * @return int[]
     */
    public static function defaults(string $bucket): array {
        return $bucket === 'group'
            ? self::roleids_for_archetypes(['teacher'])
            : self::roleids_for_archetypes(['editingteacher']);
    }

    /**
     * Role ids matching any of the given archetypes.
     *
     * @param string[] $archetypes
     * @return int[]
     */
    private static function roleids_for_archetypes(array $archetypes): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($archetypes, SQL_PARAMS_NAMED, 'arch');
        $ids = $DB->get_fieldset_select('role', 'id', "archetype $insql", $params);
        return array_map('intval', $ids);
    }

    /**
     * Parse a stored comma-separated id list into clean positive ints.
     *
     * @param string $csv
     * @return int[]
     */
    private static function parse_ids(string $csv): array {
        $ids = array_filter(array_map('intval', explode(',', $csv)), fn($v) => $v > 0);
        return array_values(array_unique($ids));
    }
}
