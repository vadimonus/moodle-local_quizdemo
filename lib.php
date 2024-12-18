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

/**
 * Adds quizdemo item into quiz module.
 *
 * @param navigation_node $nav navigation node object
 * @param context $context course context object
 */
function local_quizdemo_extend_settings_navigation(navigation_node $nav, context $context) {
    global $COURSE, $PAGE;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return;
    }
    if ($PAGE->cm->modname != 'quiz') {
        return;
    }
    if (!has_capability('local/quizdemo:createquizdemo', context_course::instance($COURSE->id))) {
        return;
    }
    $parentnode = $nav->get('modulesettings');
    $url = new moodle_url('/local/quizdemo/confirm.php', ['cmid' => $context->instanceid]);
    $parentnode->add(get_string('createdemoquiz', 'local_quizdemo'), $url, navigation_node::TYPE_SETTING,
            null, 'createdemoquiz');
}
