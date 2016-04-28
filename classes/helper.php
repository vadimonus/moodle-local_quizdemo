<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tool for creating demo version with specific questions of quiz with random questions.
 *
 * @package    local_quizdemo
 * @copyright  2016 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("$CFG->libdir/datalib.php");
require_once("$CFG->dirroot/course/lib.php");
require_once("$CFG->dirroot/mod/quiz/locallib.php");

/**
 * Helper class.
 *
 * @package    local_quizdemo
 * @copyright  2016 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_quizdemo_helper {

    /**
     * Creates quiz demonstration version.
     *
     * @param int $cmid Source course module id
     * @param object $newdata Data from form
     */
    public static function create_demo($cmid, $newdata) {
        $newcm = self::duplicate($cmid, $newdata);
        self::replace_random_questions($newcm);
        return $newcm->id;
    }

    /**
     * Duplicates course module and changes it's name.
     *
     * @param int $cmid Source course module id
     * @param object $newdata New data for module
     * @return stdClass New course module object
     */
    private static function duplicate($cmid, $newdata) {
        global $CFG, $DB;

        $cm = get_coursemodule_from_id('quiz', $cmid);
        $course = get_course($cm->course);

        $newcm = duplicate_module($course, $cm);
        $module = new stdClass();
        $module->id = $newcm->instance;
        if (!empty($CFG->formatstringstriptags)) {
            $module->name = clean_param($newdata->name, PARAM_TEXT);
        } else {
            $module->name = clean_param($newdata->name, PARAM_CLEANHTML);
        }
        $DB->update_record($newcm->modname, $module);
        rebuild_course_cache($newcm->course);

        return $newcm;
    }

    /**
     * Replaces random questions with fixed.
     *
     * @param object $cm Course module object
     */
    private static function replace_random_questions($cm) {
        global $DB;

        $quizobj = quiz::create($cm->instance, null);
        $newquestionids = self::get_fixed_questions($quizobj);

        $savedquestions = $DB->get_records('quiz_slots', array('quizid' => $quizobj->get_quizid()));
        $savedquestionsbyslot = array();
        foreach ($savedquestions as $savedquestion) {
            $savedquestionsbyslot[$savedquestion->slot] = $savedquestion;
        }
        foreach ($savedquestionsbyslot as $slot => $savedquestion) {
            if (!empty($newquestionids[$slot]) && $savedquestion->questionid != $newquestionids[$slot]) {
                $savedquestion->questionid = $newquestionids[$slot];
                $DB->update_record('quiz_slots', $savedquestion);
            }
        }
    }

    /**
     * Get fuxed questions array for this quiz.
     *
     * @param quiz $quizobj Quiz object
     * @return array Array of questions id, with slot as key.
     */
    private static function get_fixed_questions($quizobj) {
        global $DB, $USER;

        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $qubaids = new \mod_quiz\question\qubaids_for_users_attempts($quizobj->get_quizid(), $USER->id);

        $quizobj->preload_questions();
        $quizobj->load_questions();

        // First load all the non-random questions.
        $randomfound = false;
        $slot = 0;
        $questions = array();
        $page = array();
        foreach ($quizobj->get_questions() as $questiondata) {
            $slot += 1;
            $page[$slot] = $questiondata->page;
            if ($questiondata->qtype == 'random') {
                $randomfound = true;
                continue;
            }
            if (!$quizobj->get_quiz()->shuffleanswers) {
                $questiondata->options->shuffleanswers = false;
            }
            $questions[$slot] = $questiondata;
        }

        // Then find a question to go in place of each random question.
        if ($randomfound) {
            $slot = 0;
            $usedquestionids = array();
            foreach ($questions as $question) {
                if (isset($usedquestions[$question->id])) {
                    $usedquestionids[$question->id] += 1;
                } else {
                    $usedquestionids[$question->id] = 1;
                }
            }
            $randomloader = new \core_question\bank\random_question_loader($qubaids, $usedquestionids);
            foreach ($quizobj->get_questions() as $questiondata) {
                $slot += 1;
                if ($questiondata->qtype != 'random') {
                    continue;
                }

                // Deal with fixed random choices for testing.
                if (isset($questionids[$quba->next_slot_number()])) {
                    if ($randomloader->is_question_available($questiondata->category, (bool) $questiondata->questiontext,
                                    $questionids[$quba->next_slot_number()])) {
                        $questions[$slot] = question_bank::load_question($questionids[$quba->next_slot_number()],
                                        $quizobj->get_quiz()->shuffleanswers);
                        continue;
                    } else {
                        throw new coding_exception('Forced question id not available.');
                    }
                }

                // Normal case, pick one at random.
                $questionid = $randomloader->get_next_question_id($questiondata->category, (bool) $questiondata->questiontext);
                if ($questionid === null) {
                    throw new moodle_exception('notenoughrandomquestions', 'quiz', $quizobj->view_url(), $questiondata);
                }

                $questions[$slot] = question_bank::load_question($questionid, $quizobj->get_quiz()->shuffleanswers);
            }
        }

        $questionsids = array();
        foreach ($questions as $slot => $question) {
            $questionsids[$slot] = $question->id;
        }

        return $questionsids;
    }

}