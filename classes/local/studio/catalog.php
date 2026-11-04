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
 * Report Studio — the curated catalogue of safe building blocks.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon\local\studio;

/**
 * The bounded, hand-curated vocabulary the Report Studio is allowed to build
 * from. A teacher's plain-language request is only ever mapped ONTO these
 * pre-written blocks — the engine never turns free text into SQL. Each subject
 * carries a fixed FROM/JOIN skeleton with a known grain, plus a whitelist of
 * dimensions (group-by), measures (aggregates) and the columns filters may use.
 * Because every identifier originates here and every value is bound, the query
 * surface is safe by construction, and the numbers are auditable.
 */
class catalog {

    /**
     * Every subject Beacon Studio can report on. Keyed by id.
     *
     * Each subject:
     *  - label/grain  : human description of one row.
     *  - syn          : words that select this subject.
     *  - from         : the fixed FROM/JOIN skeleton ({table} placeholders).
     *  - basewhere    : always-applied WHERE (the "live/valid" conditions).
     *  - cols         : named column expressions filters & scoping reference.
     *  - dimensions   : group-by blocks (category | ordinal | temporal).
     *  - measures     : aggregate blocks (int | percent | decimal).
     *  - filters      : ids of filter recognisers that apply to this subject.
     *
     * @return array
     */
    public static function subjects(): array {
        return [
            // ---- Enrolments: one row per learner-in-a-course (the richest) ----
            'enrolments' => [
                'label' => 'Enrolments',
                'grain' => 'one row per learner in a course',
                'syn' => ['enrolment', 'enrolments', 'enrollment', 'enrollments', 'enrol', 'enrolled',
                          'course completion', 'completions', 'completion', 'progress',
                          'not started', 'never opened', 'not begun', 'completed', 'in progress'],
                'from' => "FROM {user_enrolments} ue
                            JOIN {enrol} e ON e.id = ue.enrolid
                            JOIN {course} c ON c.id = e.courseid AND c.id <> 1
                            JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                       LEFT JOIN {course_completions} cc ON cc.userid = ue.userid AND cc.course = e.courseid",
                'basewhere' => "ue.status = 0 AND e.status = 0 AND (ue.timeend = 0 OR ue.timeend > :now)",
                'cols' => ['user' => 'ue.userid', 'course' => 'e.courseid',
                           'enrolled' => 'ue.timecreated', 'completed' => 'cc.timecompleted'],
                'dimensions' => [
                    'course' => ['label' => 'Course', 'type' => 'category', 'sql' => 'c.shortname',
                                 'syn' => ['course', 'courses', 'subject', 'unit']],
                    'status' => ['label' => 'Status', 'type' => 'category',
                                 'sql' => "CASE WHEN cc.timecompleted IS NOT NULL THEN 'Complete' ELSE 'In progress' END",
                                 'syn' => ['status', 'completion status', 'complete or not', 'state']],
                    'month'  => ['label' => 'Month enrolled', 'type' => 'temporal', 'tscol' => 'ue.timecreated',
                                 'syn' => ['month', 'monthly', 'over time', 'per month', 'by month', 'trend', 'each month']],
                ],
                'measures' => [
                    'enrolments' => ['label' => 'Enrolments', 'format' => 'int', 'sql' => 'COUNT(*)',
                                     'default' => true, 'syn' => ['enrolments', 'enrollments', 'count', 'how many', 'number of']],
                    'learners'   => ['label' => 'Learners', 'format' => 'int', 'sql' => 'COUNT(DISTINCT ue.userid)',
                                     'syn' => ['learners', 'students', 'people', 'users', 'distinct learners', 'headcount']],
                    'completion' => ['label' => 'Completion rate', 'format' => 'percent',
                                     'sql' => '100.0 * COUNT(DISTINCT CASE WHEN cc.timecompleted IS NOT NULL THEN ue.userid END)
                                               / NULLIF(COUNT(DISTINCT ue.userid), 0)',
                                     'syn' => ['completion rate', 'percent complete', 'completion percentage',
                                               'how many completed', 'completion']],
                ],
                'filters' => ['course', 'completed', 'inprogress', 'notstarted', 'notloggedin', 'enrolledsince'],
            ],

            // ---- Learners: one row per person ----
            'learners' => [
                'label' => 'Learners',
                'grain' => 'one row per learner',
                'syn' => ['learner', 'learners', 'student', 'students', 'people', 'users', 'headcount',
                          'dormant', 'inactive', 'never logged in', 'never signed in', 'active learners'],
                'from' => "FROM {user} u",
                'basewhere' => "u.deleted = 0 AND u.suspended = 0 AND u.id > 2",
                'cols' => ['user' => 'u.id', 'signup' => 'u.timecreated', 'lastaccess' => 'u.lastaccess'],
                'dimensions' => [
                    'signupmonth' => ['label' => 'Signup month', 'type' => 'temporal', 'tscol' => 'u.timecreated',
                                      'syn' => ['month', 'signup', 'signups', 'joined', 'over time', 'per month', 'by month']],
                    'activity'    => ['label' => 'Activity', 'type' => 'category',
                                      'sql' => "CASE WHEN u.lastaccess = 0 THEN 'Never' WHEN u.lastaccess > :actcut THEN 'Active' ELSE 'Dormant' END",
                                      'params' => ['actcut' => 'DAYS:30'],
                                      'syn' => ['activity', 'active or dormant', 'engagement', 'activity status']],
                ],
                'measures' => [
                    'learners' => ['label' => 'Learners', 'format' => 'int', 'sql' => 'COUNT(DISTINCT u.id)',
                                   'default' => true, 'syn' => ['learners', 'students', 'people', 'count', 'how many', 'headcount']],
                ],
                'filters' => ['dormant', 'activelearner', 'neverloggedin', 'course'],
            ],

            // ---- Quiz attempts: one row per finished attempt ----
            'quizzes' => [
                'label' => 'Quiz attempts',
                'grain' => 'one row per finished quiz attempt',
                'syn' => ['quiz', 'quizzes', 'test', 'tests', 'exam', 'exams', 'attempt', 'attempts', 'score', 'scores', 'marks'],
                'from' => "FROM {quiz_attempts} qa
                            JOIN {quiz} q ON q.id = qa.quiz
                            JOIN {course} c ON c.id = q.course AND c.id <> 1
                            JOIN {user} u ON u.id = qa.userid AND u.deleted = 0 AND u.suspended = 0",
                'basewhere' => "qa.state = 'finished' AND qa.preview = 0 AND q.sumgrades > 0",
                'cols' => ['user' => 'qa.userid', 'course' => 'q.course', 'finished' => 'qa.timefinish'],
                'dimensions' => [
                    'course' => ['label' => 'Course', 'type' => 'category', 'sql' => 'c.shortname',
                                 'syn' => ['course', 'courses', 'subject', 'unit']],
                    'quiz'   => ['label' => 'Quiz', 'type' => 'category', 'sql' => 'q.name',
                                 'syn' => ['quiz', 'quizzes', 'test', 'tests', 'assessment', 'by quiz']],
                    'month'  => ['label' => 'Month', 'type' => 'temporal', 'tscol' => 'qa.timefinish',
                                 'syn' => ['month', 'monthly', 'over time', 'per month', 'by month', 'trend']],
                ],
                'measures' => [
                    'attempts' => ['label' => 'Attempts', 'format' => 'int', 'sql' => 'COUNT(*)',
                                   'default' => true, 'syn' => ['attempts', 'count', 'how many']],
                    'avgscore' => ['label' => 'Average score', 'format' => 'percent',
                                   'sql' => 'AVG(100.0 * qa.sumgrades / NULLIF(q.sumgrades, 0))',
                                   'syn' => ['average score', 'avg score', 'average grade', 'mean score', 'average mark', 'score']],
                    'passrate' => ['label' => 'Pass rate', 'format' => 'percent',
                                   'sql' => '100.0 * AVG(CASE WHEN qa.sumgrades >= q.sumgrades * 0.5 THEN 1.0 ELSE 0.0 END)',
                                   'syn' => ['pass rate', 'passing rate', 'percent passing', 'how many passed', 'pass']],
                ],
                'filters' => ['course'],
            ],

            // ---- Activity completions: one row per completion ----
            'activities' => [
                'label' => 'Activity completions',
                'grain' => 'one row per completed activity',
                'syn' => ['activity', 'activities', 'activity completion', 'activity completions',
                          'completed activities', 'modules', 'resources'],
                'from' => "FROM {course_modules_completion} cmc
                            JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                            JOIN {modules} md ON md.id = cm.module
                            JOIN {course} c ON c.id = cm.course AND c.id <> 1
                            JOIN {user} u ON u.id = cmc.userid AND u.deleted = 0 AND u.suspended = 0",
                'basewhere' => "cmc.completionstate > 0",
                'cols' => ['user' => 'cmc.userid', 'course' => 'cm.course', 'completed' => 'cmc.timemodified'],
                'dimensions' => [
                    'type'   => ['label' => 'Activity type', 'type' => 'category', 'sql' => 'md.name',
                                 'syn' => ['type', 'activity type', 'kind', 'module', 'module type']],
                    'course' => ['label' => 'Course', 'type' => 'category', 'sql' => 'c.shortname',
                                 'syn' => ['course', 'courses', 'subject', 'unit']],
                    'month'  => ['label' => 'Month', 'type' => 'temporal', 'tscol' => 'cmc.timemodified',
                                 'syn' => ['month', 'monthly', 'over time', 'per month', 'by month', 'trend']],
                ],
                'measures' => [
                    'completions' => ['label' => 'Completions', 'format' => 'int', 'sql' => 'COUNT(*)',
                                      'default' => true, 'syn' => ['completions', 'completed', 'count', 'how many']],
                    'learners'    => ['label' => 'Learners', 'format' => 'int', 'sql' => 'COUNT(DISTINCT cmc.userid)',
                                      'syn' => ['learners', 'students', 'people', 'distinct learners']],
                ],
                'filters' => ['course'],
            ],
        ];
    }

    /**
     * One subject by id, or null.
     *
     * @param string $id
     * @return array|null
     */
    public static function subject(string $id): ?array {
        return self::subjects()[$id] ?? null;
    }
}
