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
        $site = (int) SITEID;
        // Words that ask about teaching staff rather than learners.
        $staff = ['teacher', 'teachers', 'trainer', 'trainers', 'assessor', 'assessors', 'tutor', 'tutors',
                  'instructor', 'instructors', 'facilitator', 'facilitators', 'lecturer', 'lecturers', 'staff',
                  'educator', 'educators'];
        return [
            // ---- Enrolments: one row per person in a course (active enrolments only) ----
            // A person enrolled twice in one course (e.g. manual + self) is one row.
            'enrolments' => [
                'label' => 'Enrolments',
                'grain' => 'one row per person in a course',
                'roles' => ['learners', 'teachers', 'everyone'],
                'syn' => ['enrolment', 'enrolments', 'enrollment', 'enrollments', 'enrol', 'enrolled',
                          'course completion', 'completions', 'completion', 'progress',
                          'not started', 'never opened', 'not begun', 'completed', 'in progress'],
                'from' => "FROM (SELECT ue.userid, e.courseid, MIN(ue.timecreated) AS timeenrolled
                                   FROM {user_enrolments} ue
                                   JOIN {enrol} e ON e.id = ue.enrolid
                                  WHERE ue.status = 0 AND e.status = 0
                                        AND ue.timestart <= :nowstart
                                        AND (ue.timeend = 0 OR ue.timeend > :nowend)
                               GROUP BY ue.userid, e.courseid) en
                            JOIN {course} c ON c.id = en.courseid AND c.id <> $site
                            JOIN {user} u ON u.id = en.userid AND u.deleted = 0 AND u.suspended = 0
                       LEFT JOIN {course_completions} cc ON cc.userid = en.userid AND cc.course = en.courseid",
                'basewhere' => "1 = 1",
                'cols' => ['user' => 'en.userid', 'course' => 'en.courseid',
                           'enrolled' => 'en.timeenrolled', 'completed' => 'cc.timecompleted'],
                'dimensions' => [
                    'course' => ['label' => 'Course', 'type' => 'category', 'sql' => 'c.shortname',
                                 'syn' => ['course', 'courses', 'subject', 'unit']],
                    'status' => ['label' => 'Status', 'type' => 'category',
                                 'sql' => "CASE WHEN cc.timecompleted IS NOT NULL THEN 'Complete' ELSE 'In progress' END",
                                 'syn' => ['status', 'completion status', 'complete or not', 'state']],
                    'month'  => ['label' => 'Month enrolled', 'type' => 'temporal', 'tscol' => 'en.timeenrolled',
                                 'syn' => ['month', 'monthly', 'over time', 'per month', 'by month', 'trend', 'each month']],
                ],
                'measures' => [
                    'enrolments' => ['label' => 'Enrolments', 'format' => 'int', 'sql' => 'COUNT(*)',
                                     'default' => true, 'syn' => ['enrolments', 'enrollments', 'count', 'how many', 'number of']],
                    'people'     => ['label' => '{who}', 'format' => 'int', 'sql' => 'COUNT(DISTINCT en.userid)',
                                     'syn' => array_merge(['learners', 'students', 'people', 'users', 'distinct learners',
                                                           'headcount'], $staff)],
                    'completed'  => ['label' => 'Completions', 'format' => 'int',
                                     'sql' => 'SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END)',
                                     'syn' => ['completions', 'number completed', 'how many completed', 'number of completions']],
                    'completion' => ['label' => 'Completion rate', 'format' => 'percent',
                                     'sql' => '100.0 * SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END)
                                               / NULLIF(COUNT(*), 0)',
                                     'syn' => ['completion rate', 'percent complete', 'completion percentage', 'rate of completion',
                                               'completion', 'percentage completed']],
                ],
                'filters' => ['course', 'completed', 'inprogress', 'notstarted', 'notloggedin', 'enrolledsince'],
            ],

            // ---- People: one row per person ----
            // Learners/teachers = people with that role in a course they are actively
            // enrolled in. "Users" = every active account.
            'people' => [
                'label' => 'People',
                'grain' => 'one row per person',
                'roles' => ['learners', 'teachers', 'everyone'],
                'syn' => array_merge(['learner', 'learners', 'student', 'students', 'people', 'users', 'user',
                          'accounts', 'headcount', 'dormant', 'inactive', 'never logged in', 'never signed in',
                          'active learners', 'logged in', 'not logged in', 'signups', 'signup', 'joined'], $staff),
                'from' => "FROM {user} u",
                'basewhere' => "u.deleted = 0 AND u.suspended = 0 AND u.id <> :guestid",
                'cols' => ['user' => 'u.id', 'signup' => 'u.timecreated', 'lastaccess' => 'u.lastaccess'],
                'dimensions' => [
                    'signupmonth' => ['label' => 'Signup month', 'type' => 'temporal', 'tscol' => 'u.timecreated',
                                      'syn' => ['month', 'signup', 'signups', 'joined', 'over time', 'per month', 'by month']],
                    'activity'    => ['label' => 'Activity', 'type' => 'category',
                                      'sql' => "CASE WHEN u.lastaccess = 0 THEN 'Never' WHEN u.lastaccess > {DAYS:30} THEN 'Active'
                                                ELSE 'Dormant' END",
                                      'syn' => ['activity', 'active or dormant', 'engagement', 'activity status']],
                ],
                'measures' => [
                    'people' => ['label' => '{who}', 'format' => 'int', 'sql' => 'COUNT(*)',
                                 'default' => true, 'syn' => ['learners', 'students', 'people', 'count', 'how many', 'headcount']],
                ],
                'filters' => ['dormant', 'notloggedinsite', 'activelearner', 'neverloggedin', 'course'],
            ],

            // ---- Quiz results: one row per learner per quiz (their final quiz grade) ----
            'quizresults' => [
                'label' => 'Quiz results',
                'grain' => 'one row per learner per quiz (final grade)',
                'roles' => ['learners'],
                'syn' => ['quiz', 'quizzes', 'test', 'tests', 'exam', 'exams', 'score', 'scores', 'marks', 'grade',
                          'grades', 'pass', 'passed', 'pass rate', 'results', 'quiz results'],
                'from' => "FROM {quiz_grades} qg
                            JOIN {quiz} q ON q.id = qg.quiz
                            JOIN {course} c ON c.id = q.course AND c.id <> $site
                            JOIN {user} u ON u.id = qg.userid AND u.deleted = 0 AND u.suspended = 0
                       LEFT JOIN {grade_items} gi ON gi.itemtype = 'mod' AND gi.itemmodule = 'quiz'
                                 AND gi.iteminstance = q.id AND gi.itemnumber = 0",
                'basewhere' => "q.grade > 0",
                'cols' => ['user' => 'qg.userid', 'course' => 'q.course'],
                'dimensions' => [
                    'course' => ['label' => 'Course', 'type' => 'category', 'sql' => 'c.shortname',
                                 'syn' => ['course', 'courses', 'subject', 'unit']],
                    'quiz'   => ['label' => 'Quiz', 'type' => 'category', 'sql' => 'q.name',
                                 'syn' => ['quiz', 'quizzes', 'test', 'tests', 'exam', 'exams', 'assessment']],
                ],
                'measures' => [
                    'results'  => ['label' => 'Learners with a quiz grade', 'format' => 'int', 'sql' => 'COUNT(*)',
                                   'default' => true, 'syn' => ['results', 'count', 'how many', 'number of']],
                    'avgscore' => ['label' => 'Average score', 'format' => 'percent',
                                   'sql' => 'AVG(100.0 * qg.grade / q.grade)',
                                   'syn' => ['average score', 'avg score', 'average grade', 'mean score', 'average mark',
                                             'score', 'average']],
                    // Pass = the quiz's own pass mark; 50% when the quiz has none.
                    'passrate' => ['label' => 'Pass rate', 'format' => 'percent',
                                   'sql' => "100.0 * AVG(CASE WHEN (CASE WHEN gi.gradepass > 0 AND gi.grademax > 0
                                                  THEN qg.grade / q.grade - gi.gradepass / gi.grademax
                                                  ELSE qg.grade / q.grade - 0.5 END) >= -0.0000001 THEN 1.0 ELSE 0.0 END)",
                                   'syn' => ['pass rate', 'passing rate', 'percent passing', 'how many passed', 'pass', 'passed']],
                ],
                'filters' => ['course'],
            ],

            // ---- Quiz attempts: one row per finished (non-preview) attempt ----
            'quizattempts' => [
                'label' => 'Quiz attempts',
                'grain' => 'one row per finished quiz attempt',
                'roles' => ['learners'],
                'syn' => ['quiz', 'quizzes', 'attempt', 'attempts', 'quiz attempts', 'tries'],
                'from' => "FROM {quiz_attempts} qa
                            JOIN {quiz} q ON q.id = qa.quiz
                            JOIN {course} c ON c.id = q.course AND c.id <> $site
                            JOIN {user} u ON u.id = qa.userid AND u.deleted = 0 AND u.suspended = 0",
                'basewhere' => "qa.state = 'finished' AND qa.preview = 0",
                'cols' => ['user' => 'qa.userid', 'course' => 'q.course', 'finished' => 'qa.timefinish'],
                'dimensions' => [
                    'course' => ['label' => 'Course', 'type' => 'category', 'sql' => 'c.shortname',
                                 'syn' => ['course', 'courses', 'subject', 'unit']],
                    'quiz'   => ['label' => 'Quiz', 'type' => 'category', 'sql' => 'q.name',
                                 'syn' => ['quiz', 'quizzes', 'test', 'tests', 'exam', 'exams', 'assessment']],
                    'month'  => ['label' => 'Month', 'type' => 'temporal', 'tscol' => 'qa.timefinish',
                                 'syn' => ['month', 'monthly', 'over time', 'per month', 'by month', 'trend']],
                ],
                'measures' => [
                    'attempts' => ['label' => 'Attempts', 'format' => 'int', 'sql' => 'COUNT(*)',
                                   'default' => true, 'syn' => ['attempts', 'count', 'how many', 'number of']],
                    'learners' => ['label' => 'Learners', 'format' => 'int', 'sql' => 'COUNT(DISTINCT qa.userid)',
                                   'syn' => ['learners', 'students', 'people']],
                ],
                'filters' => ['course'],
            ],

            // ---- Activity completions: one row per completed activity ----
            // Complete or complete-pass only (a failed completion is not a completion),
            // and never modules that are being deleted.
            'activities' => [
                'label' => 'Activity completions',
                'grain' => 'one row per completed activity',
                'roles' => ['learners'],
                'syn' => ['activity', 'activities', 'activity completion', 'activity completions',
                          'completed activities', 'completed an activity', 'an activity', 'modules', 'resources'],
                'from' => "FROM {course_modules_completion} cmc
                            JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid AND cm.deletioninprogress = 0
                            JOIN {modules} md ON md.id = cm.module
                            JOIN {course} c ON c.id = cm.course AND c.id <> $site
                            JOIN {user} u ON u.id = cmc.userid AND u.deleted = 0 AND u.suspended = 0",
                'basewhere' => "cmc.completionstate IN (1, 2)",
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
                                      'default' => true, 'syn' => ['completions', 'count', 'how many', 'total']],
                    'learners'    => ['label' => 'Learners', 'format' => 'int', 'sql' => 'COUNT(DISTINCT cmc.userid)',
                                      'syn' => ['learners', 'students', 'people', 'distinct learners']],
                ],
                'filters' => ['course'],
            ],
        ];
    }

    /**
     * Words that ask about teaching staff rather than learners.
     *
     * @return string[]
     */
    public static function staff_words(): array {
        return ['teacher', 'teachers', 'trainer', 'trainers', 'assessor', 'assessors', 'tutor', 'tutors',
                'instructor', 'instructors', 'facilitator', 'facilitators', 'lecturer', 'lecturers', 'staff',
                'educator', 'educators'];
    }

    /**
     * Words that ask about every account, whatever their role.
     *
     * @return string[]
     */
    public static function everyone_words(): array {
        return ['users', 'user', 'accounts', 'user accounts', 'everyone', 'all people', 'signups', 'signup', 'joined'];
    }

    /**
     * Words that explicitly ask about learners.
     *
     * @return string[]
     */
    public static function learner_words(): array {
        return ['learner', 'learners', 'student', 'students', 'trainee', 'trainees', 'participant', 'participants'];
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
