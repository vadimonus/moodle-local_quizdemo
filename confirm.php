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

require_once("../../config.php");

require_login();

$cmid = required_param('cmid', PARAM_INT); // Course Module ID, or ...

if (!$cm = get_coursemodule_from_id('quiz', $cmid)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error('coursemisconf');
}

require_login($course, false, $cm);
$coursecontext = context_course::instance($cm->course);
require_capability('local/quizdemo:createquizdemo', $coursecontext);

$url = new moodle_url('/local/quizdemo/confirm.php', array('cmid' => $cmid));
$PAGE->set_url($url);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('confirmheader', 'local_quizdemo'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->set_pagelayout('incourse');

$mform = new local_quizdemo_confirm_form($url, array('cm' => $cm));
$data = new stdClass();
$name = $cm->name;
$data->name = get_string('newnamedefault', 'local_quizdemo', $name);
$mform->set_data($data);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/quiz/view.php', array('id' => $cmid)));
} else if ($data = $mform->get_data()) {
    $newcmid = local_quizdemo_helper::create_demo($cmid, $data);
    redirect(new moodle_url('/course/view.php', array('id' => $course->id)));
}
echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
