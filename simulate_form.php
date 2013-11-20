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
 * Form used to upload csv file of step data.
 *
 * @package    quiz_simulate
 * @copyright  2013 The Open University
 * @author     James Pratt me@jamiep.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class mod_quiz_simulate_report_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'generalhdr', get_string('general'));

        $mform->addElement('filepicker', 'stepsfile', get_string('file'));
        $mform->addRule('stepsfile', null, 'required');

        $choices = csv_import_reader::get_delimiter_list();
        $mform->addElement('select', 'delimiter_name', get_string('csvdelimiter', 'tool_uploadcourse'), $choices);
        if (array_key_exists('cfg', $choices)) {
            $mform->setDefault('delimiter_name', 'cfg');
        } else if (get_string('listsep', 'langconfig') == ';') {
            $mform->setDefault('delimiter_name', 'semicolon');
        } else {
            $mform->setDefault('delimiter_name', 'comma');
        }

        $mform->addElement('advcheckbox', 'deleteattemptsfirst', '', get_string('deleteattemptsfirst', 'quiz_simulate'));
        $mform->addElement('advcheckbox', 'shuffletocreatelargedataset', '',
                           get_string('shuffletocreatelargedataset', 'quiz_simulate'));

        $this->add_action_buttons(false, get_string('upload'));
    }
}
