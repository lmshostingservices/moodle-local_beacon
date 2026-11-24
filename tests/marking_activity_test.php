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
 * Tests for the Marking activity / Marked assessments reports and the Grade
 * changes percentage fix.
 *
 * @package    local_beacon
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_beacon;

use local_beacon\local\catalogue;
use local_beacon\local\filterset;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Marking activity tests.
 *
 * @covers \local_beacon\local\catalogue
 */
final class marking_activity_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass Editing teacher. */
    private $t1;
    /** @var \stdClass Non-editing teacher (group A only). */
    private $t2;
    /** @var \stdClass[] Learners a, b, c. */
    private $s = [];
    /** @var \stdClass */
    private $quiz;

    /**
     * Build the scenario.
     *
     *  - t1 grades learner a's assignment twice (NYC, then C on a resubmission),
     *    hand-marks learner a's quiz essay and enters a gradebook grade for c.
     *  - t2 grades learner b's assignment and hand-marks b's quiz essay.
     *  - The admin then edits the quiz maximum grade (100 → 200 → 100), which
     *    recalculates every learner's quiz grade: that is NOT marking.
     */
    private function seed(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $this->setAdminUser();
        $this->course = $gen->create_course(['fullname' => 'BSBCRT511 Develop critical thinking in others']);
        $this->t1 = $gen->create_user(['firstname' => 'Tina', 'lastname' => 'Editing']);
        $this->t2 = $gen->create_user(['firstname' => 'Tom', 'lastname' => 'Grouped']);
        foreach (['a', 'b', 'c'] as $k) {
            $this->s[$k] = $gen->create_user(['firstname' => 'Learner', 'lastname' => strtoupper($k),
                'email' => "aiop{$k}@example.com"]);
            $gen->enrol_user($this->s[$k]->id, $this->course->id, 'student');
        }
        $gen->enrol_user($this->t1->id, $this->course->id, 'editingteacher');
        $gen->enrol_user($this->t2->id, $this->course->id, 'teacher');
        // T2 shares a group with learner b only.
        $g = $gen->create_group(['courseid' => $this->course->id]);
        $gen->create_group_member(['groupid' => $g->id, 'userid' => $this->t2->id]);
        $gen->create_group_member(['groupid' => $g->id, 'userid' => $this->s['b']->id]);

        // Assignment graded on a NYC / C scale, resubmissions reopened manually.
        $scale = $gen->create_scale(['scale' => 'NYC,C', 'courseid' => 0]);
        $asg = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Assessment Task 2',
            'grade' => -$scale->id, 'attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $cm = get_coursemodule_from_instance('assign', $asg->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $this->course);
        $save = function (int $userid, int $grade, int $attempt) use ($assign) {
            $assign->get_user_grade($userid, true, $attempt);
            $assign->save_grade($userid, (object) ['grade' => $grade, 'attemptnumber' => $attempt,
                'applytoall' => 0, 'addattempt' => 0, 'sendstudentnotifications' => false]);
        };
        $this->setUser($this->t1);
        $save($this->s['a']->id, 1, 0);   // NYC.
        $save($this->s['a']->id, 2, 1);   // C on the resubmission.
        $this->setUser($this->t2);
        $save($this->s['b']->id, 2, 0);

        // Quiz with one auto-marked and one essay question.
        $this->setAdminUser();
        $qgen = $gen->get_plugin_generator('mod_quiz');
        $this->quiz = $qgen->create_instance(['course' => $this->course->id, 'name' => 'Assessment Task 1 - Knowledge Quiz',
            'questionsperpage' => 0, 'grade' => 100.0, 'sumgrades' => 2]);
        $questiongen = $gen->get_plugin_generator('core_question');
        $cat = $questiongen->create_question_category();
        $tf = $questiongen->create_question('truefalse', null, ['category' => $cat->id]);
        $essay = $questiongen->create_question('essay', null, ['category' => $cat->id]);
        quiz_add_quiz_question($tf->id, $this->quiz);
        quiz_add_quiz_question($essay->id, $this->quiz);
        $quizobj = quiz_settings::create($this->quiz->id);
        foreach (['a' => $this->t1, 'b' => $this->t2] as $k => $marker) {
            $this->setUser($this->s[$k]);
            $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
            $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
            $now = time();
            $attempt = quiz_create_attempt($quizobj, 1, false, $now, false, $this->s[$k]->id);
            quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $now);
            quiz_attempt_save_started($quizobj, $quba, $attempt);
            $obj = quiz_attempt::create($attempt->id);
            $obj->process_submitted_actions($now, false, [1 => ['answer' => 1],
                2 => ['answer' => 'My essay', 'answerformat' => FORMAT_HTML]]);
            $obj->process_finish($now, false);
            // The trainer grades the essay by hand.
            $this->setUser($marker);
            $obj = quiz_attempt::create($attempt->id);
            $obj->get_question_usage()->manual_grade(2, 'Good', 0.86, FORMAT_HTML);
            \question_engine::save_questions_usage_by_activity($obj->get_question_usage());
            $DB->set_field('quiz_attempts', 'sumgrades', $obj->get_question_usage()->get_total_mark(),
                ['id' => $attempt->id]);
            $quizobj->get_grade_calculator()->recompute_final_grade($this->s[$k]->id);
        }

        // Gradebook (manual) item, entered by t1 for learner c.
        $this->setUser($this->t1);
        $gi = new \grade_item($gen->create_grade_item(['courseid' => $this->course->id,
            'itemname' => 'Observation checklist', 'grademax' => 10]), false);
        $gi->update_final_grade($this->s['c']->id, 8, 'gradebook');

        // A quiz edit by the admin recalculates every grade — not marking.
        $this->setAdminUser();
        $calc = quiz_settings::create($this->quiz->id)->get_grade_calculator();
        $calc->update_quiz_maximum_grade(200);
        $calc->update_quiz_maximum_grade(100);
        \cache::make('local_beacon', 'reports')->purge();
    }

    /**
     * Index report rows by the text of their first column.
     *
     * @param array $rows Rows.
     * @return array
     */
    private function by_first(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[$r[0]['v']][] = array_map(fn($c) => $c['v'], $r);
        }
        return $out;
    }

    /**
     * Counts per trainer exclude recalculations, course totals and quiz edits.
     */
    public function test_marking_activity_counts(): void {
        $this->resetAfterTest();
        $this->seed();
        $res = catalogue::report('marking_activity')->run(new filterset(\context_system::instance(), []), 100);
        $this->assertFalse($res['error']);
        $rows = $this->by_first($res['rows']);
        $this->assertSame(2, $res['total']);
        // Columns: trainer, assignments, quizzes, gradebook, total, learners, last marked.
        $this->assertSame(['2', '1', '1', '4', '2'], array_slice($rows['Tina Editing'][0], 1, 5));
        $this->assertSame(['1', '1', '0', '2', '1'], array_slice($rows['Tom Grouped'][0], 1, 5));
        $this->assertArrayNotHasKey('Admin User', $rows, 'A quiz edit must not count as marking');
        // The total drills into Marked assessments for that trainer.
        $this->assertStringContainsString('marked_assessments', $res['rows'][0][4]['url']);
        $this->assertStringContainsString('f_trainer', $res['rows'][0][4]['url']);
    }

    /**
     * The detail report lists each task once, with scale results and attempts,
     * and the Trainer filter means "marked by".
     */
    public function test_marked_assessments_detail_and_trainer_filter(): void {
        $this->resetAfterTest();
        $this->seed();
        $ctx = \context_system::instance();
        $all = catalogue::report('marked_assessments')->run(new filterset($ctx, []), 100);
        $this->assertFalse($all['error']);
        $this->assertSame(6, $all['total']);

        $t1 = catalogue::report('marked_assessments')->run(
            filterset::from_params($ctx, ['f_trainer' => [$this->t1->id]]), 100);
        $this->assertSame(4, $t1['total']);
        $results = [];
        foreach ($t1['rows'] as $r) {
            $this->assertSame('Tina Editing', $r[0]['v']);
            $results[] = $r[5]['v'] . '|' . $r[4]['v'] . '|' . $r[6]['v'] . '|' . $r[7]['v'];
        }
        sort($results);
        $this->assertSame([
            'Assignment|Assessment Task 2|1|NYC',
            'Assignment|Assessment Task 2|2|C',
            'Gradebook item|Observation checklist|1|80%',
            'Quiz|Assessment Task 1 - Knowledge Quiz|1|93%',
        ], $results);

        // Marking activity with the same filter shows just that trainer.
        $sum = catalogue::report('marking_activity')->run(
            filterset::from_params($ctx, ['f_trainer' => [$this->t2->id]]), 100);
        $this->assertSame(1, $sum['total']);
        $this->assertSame('Tom Grouped', $sum['rows'][0][0]['v']);
    }

    /**
     * A non-editing teacher is scoped to the learners in their groups.
     */
    public function test_viewer_scope(): void {
        $this->resetAfterTest();
        $this->seed();
        $q = new filterset(\context_system::instance(), []);
        $q->enable_viewer_scope((int) $this->t2->id, true);
        $res = catalogue::report('marked_assessments')->run($q, 100);
        $this->assertFalse($res['error']);
        $this->assertSame(2, $res['total']);
        foreach ($res['rows'] as $r) {
            $this->assertSame('Learner B', $r[1]['v']);
        }
    }

    /**
     * Rows older than the window are excluded; a date range brings them back.
     */
    public function test_date_window(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed();
        $old = time() - 60 * DAYSECS;
        $DB->execute("UPDATE {assign_grades} SET timemodified = ?", [$old]);
        $ctx = \context_system::instance();
        $def = catalogue::report('marked_assessments')->run(new filterset($ctx, []), 100);
        $this->assertSame(3, $def['total'], 'Default window is the last 30 days');
        $range = catalogue::report('marked_assessments')->run(filterset::from_params($ctx,
            ['f_from' => userdate($old - DAYSECS, '%Y-%m-%d')]), 100);
        $this->assertSame(6, $range['total']);
    }

    /**
     * Grade changes measures each history row against the maximum in force at
     * that moment, so a quiz edit no longer shows figures such as 202%.
     */
    public function test_grade_changes_never_exceeds_100_after_quiz_edit(): void {
        $this->resetAfterTest();
        $this->seed();
        $res = catalogue::report('grade_changes')->run(new filterset(\context_system::instance(), []), 500);
        $this->assertFalse($res['error']);
        $quizrows = 0;
        foreach ($res['rows'] as $r) {
            if ($r[3]['v'] !== 'Assessment Task 1 - Knowledge Quiz') {
                continue;
            }
            $quizrows++;
            foreach ([4, 5] as $i) {
                if (preg_match('/^(\d+)%$/', $r[$i]['v'], $m)) {
                    $this->assertLessThanOrEqual(100, (int) $m[1], 'Impossible percentage: ' . $r[$i]['v']);
                }
            }
        }
        $this->assertGreaterThan(0, $quizrows);
    }

    /**
     * The Course total moving to 100% is shown on each marked task, counted per
     * trainer, and filterable.
     */
    public function test_course_total_reaching_100(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->setAdminUser();
        $course = $gen->create_course(['fullname' => 'BSBCRT511']);
        $t = $gen->create_user(['firstname' => 'Tina', 'lastname' => 'Editing']);
        $s = $gen->create_user(['firstname' => 'Walter', 'lastname' => 'Learner']);
        $gen->enrol_user($s->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $asg = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Assessment Task 2', 'grade' => 100,
            'attemptreopenmethod' => 'manual', 'maxattempts' => -1]);
        $cm = get_coursemodule_from_instance('assign', $asg->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $this->setUser($t);
        $assign->get_user_grade($s->id, true, 0);
        $assign->save_grade($s->id, (object) ['grade' => 80, 'attemptnumber' => 0, 'applytoall' => 0,
            'addattempt' => 0, 'sendstudentnotifications' => false]);
        // Move the first marking an hour into the past, as it would be in real life.
        $DB->execute("UPDATE {assign_grades} SET timemodified = timemodified - 3600");
        $DB->execute("UPDATE {grade_grades_history} SET timemodified = timemodified - 3600");
        $assign->get_user_grade($s->id, true, 1);
        $assign->save_grade($s->id, (object) ['grade' => 100, 'attemptnumber' => 1, 'applytoall' => 0,
            'addattempt' => 0, 'sendstudentnotifications' => false]);
        \cache::make('local_beacon', 'reports')->purge();
        $ctx = \context_system::instance();

        $sum = catalogue::report('marking_activity')->run(new filterset($ctx, []), 100);
        $this->assertFalse($sum['error']);
        $this->assertSame('1', $sum['rows'][0][6]['v'], 'One learner reached a 100% Course total');
        $this->assertStringContainsString('f_coursetotal', $sum['rows'][0][6]['url']);

        $det = catalogue::report('marked_assessments')->run(new filterset($ctx, []), 100);
        $moves = [];
        foreach ($det['rows'] as $r) {
            $moves[$r[6]['v']] = $r[8]['v'];
        }
        $this->assertSame('80% → 100%', $moves['2']);
        $this->assertStringEndsWith('→ 80%', $moves['1']);

        $reached = catalogue::report('marked_assessments')->run(
            filterset::from_params($ctx, ['f_coursetotal' => ['reached']]), 100);
        $this->assertSame(1, $reached['total']);
        $this->assertSame('2', $reached['rows'][0][6]['v']);
        $not = catalogue::report('marked_assessments')->run(
            filterset::from_params($ctx, ['f_coursetotal' => ['notreached']]), 100);
        $this->assertSame(1, $not['total']);
    }
}
