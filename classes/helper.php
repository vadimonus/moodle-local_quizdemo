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

namespace local_quizdemo;

use core_question\local\bank\random_question_loader;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\quiz_settings;
use mod_quiz\structure;
use moodle_exception;
use qubaid_list;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Helper class.
 *
 * @package    local_quizdemo
 * @copyright  2016 Vadim Dvorovenko <Vadimon@mail.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Creates quiz demonstration version.
     *
     * @param int $cmid Source course module id
     * @param object $newdata Data from form
     */
    public static function create_demo($cmid, $newdata) {
        $cm = get_coursemodule_from_id('quiz', $cmid);
        $replacements = self::get_replacements($cm);
        $newcm = self::duplicate($cmid, $newdata);
        self::replace_questions($newcm, $replacements);
        return $newcm->id;
    }

    /**
     * Generate replacements array.
     *
     * @param object $cm
     * @return array [$slotnumber => $fixedquestionid]
     * @throws moodle_exception
     */
    private static function get_replacements(object $cm): array {
        $quiz = quiz_settings::create($cm->instance, null);
        $structure = $quiz->get_structure();
        $randomloader = static::get_random_loader($structure);
        $slots = $structure->get_slots();

        $result = [];
        foreach ($slots as $slot) {
            $slotnumber = $slot->slot;
            $questiontype = $structure->get_question_type_for_slot($slotnumber);
            if ($questiontype == 'random') {
                $fixedquestionid = static::get_fixed_question_id($quiz, $slot, $randomloader);
                $result[$slotnumber] = $fixedquestionid;
            }
        }
        return $result;
    }

    /**
     * Duplicates course module and changes it's name.
     *
     * @param int $cmid Source course module id
     * @param object $newdata New data for module
     * @return stdClass|\cm_info New course module object
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
     * Replaces random questions with fixed using provided array.
     *
     * @param object $cm Course module object
     * @param array $replace [$slotnumber => $fixedquestionid]
     * @throws moodle_exception
     */
    private static function replace_questions(object $cm, array $replace): void {
        $quiz = quiz_settings::create($cm->instance, null);
        $structure = $quiz->get_structure();
        foreach ($replace as $slotnumber => $fixedquestionid) {
            $slot = $structure->get_slot_by_number($slotnumber);
            static::replace_question($quiz, $slot, $fixedquestionid);
        }
    }

    /**
     * Creates random loader to replaces random questions with fixed.
     *
     * @param structure $structure
     * @return random_question_loader
     */
    private static function get_random_loader(structure $structure): random_question_loader {
        $qubaids = new qubaid_list([]);
        $usedquestionids = static::get_used_question_ids($structure);
        return new random_question_loader($qubaids, $usedquestionids);
    }

    /**
     * Gathers ids for used fixed question in quiz.
     * These ids will be excluded, so test will not have duplicate questions
     * after replacing random questions with fixed.
     *
     * @param structure $structure
     * @return array
     */
    private static function get_used_question_ids(structure $structure): array {
        $usedquestionids = [];
        foreach ($structure->get_slots() as $slot) {
            $slotnumber = $slot->slot;
            $questiontype = $structure->get_question_type_for_slot($slotnumber);
            if ($questiontype != 'random') {
                $questionid = $structure->get_question_in_slot($slotnumber)->questionid;
                $usedquestionids[$questionid] = ($usedquestionids[$questionid] ?? 0) + 1;
            }
        }
        return $usedquestionids;
    }

    /**
     * Returns new question id or throws exception if there is not enough questions.
     *
     * @param quiz_settings $quizobj
     * @param object $slot
     * @param random_question_loader $randomloader
     * @return int
     * @throws moodle_exception
     */
    private static function get_fixed_question_id(quiz_settings $quizobj, object $slot, random_question_loader $randomloader): int {
        $fixedquestionid = $randomloader->get_next_question_id($slot->category, $slot->randomrecurse,
            qbank_helper::get_tag_ids_for_slot($slot));
        if ($fixedquestionid === null) {
            throw new moodle_exception('notenoughrandomquestions', 'quiz', $quizobj->view_url(), $slot);
        }
        return $fixedquestionid;
    }

    /**
     * Replaces random question in slot with fixed.
     *
     * @param quiz_settings $quiz
     * @param object $slot
     * @param int $fixedquestionid
     * @return void
     */
    private static function replace_question(quiz_settings $quiz, object $slot, int $fixedquestionid): void {
        static::delete_question_set_reference($slot);
        static::add_question_reference($quiz, $slot, $fixedquestionid);
    }

    /**
     * Removes random question reference for slot.
     *
     * @param object $slot
     * @return void
     */
    private static function delete_question_set_reference(object $slot): void {
        global $DB;
        $questionsetreference = $DB->get_record('question_set_references',
            ['component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id], '*', MUST_EXIST);
        $DB->delete_records('question_set_references',
            ['id' => $questionsetreference->id, 'component' => 'mod_quiz', 'questionarea' => 'slot']);
    }

    /**
     * Adds fixed question reference for slot.
     *
     * @param quiz_settings $quiz
     * @param object $slot
     * @param int $fixedquestionid
     * @return void
     */
    private static function add_question_reference(quiz_settings $quiz, object $slot, int $fixedquestionid): void {
        global $DB;
        $questionreferences = new \StdClass();
        $questionreferences->usingcontextid = $quiz->get_context()->id;
        $questionreferences->component = 'mod_quiz';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slot->id;
        $questionreferences->questionbankentryid = get_question_bank_entry($fixedquestionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);
    }
}
