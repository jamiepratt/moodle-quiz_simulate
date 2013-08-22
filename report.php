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
 * This file defines a report class used for the uploading of response data.
 *
 * @copyright  2013 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot .'/mod/quiz/report/simulate/simulate_form.php');
require_once($CFG->dirroot . '/mod/quiz/editlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Report subclass used for the uploading of response data.
 *
 * @copyright 2013 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_simulate_report extends quiz_default_report {

    /** @var string the mode this report is. */
    protected $mode = 'simulate';

    /** @var object the quiz context. */
    protected $context;

    /** @var object the quiz record. */
    protected $quiz;

    /** @var object the course record. */
    protected $course;

    public function display($quiz, $cm, $course) {
        global $USER;

        $this->context = context_module::instance($cm->id);
        $this->quiz = $quiz;
        $this->course = $course;

        $reporturl = new moodle_url('/mod/quiz/report.php', array('id' => $cm->id, 'mode' => $this->mode));

        $mform = new mod_quiz_simulate_report_form($reporturl);
        if ($formdata = $mform->get_data()) {
            $importid = csv_import_reader::get_new_iid('quiz_simulate');
            $cir = new csv_import_reader($importid, 'quiz_simulate');
            $content = $mform->get_file_content('stepsfile');
            $readcount = $cir->load_csv_content($content, 'UTF-8', $formdata->delimiter_name);
            unset($content);
            if ($readcount === false) {
                print_error('csvloaderror', 'error', $reporturl, $cir->get_error());
            } else if ($readcount == 0) {
                print_error('csvemptyfile', 'error', $reporturl, $cir->get_error());
            }

            $cir->init();

            $olduserid = $USER->id;
            while ($data = $cir->next()) {
                $this->create_attempts(array_combine($cir->get_columns(), $data));
            }
            $this->set_user($olduserid);
            redirect($reporturl->out(false, array('mode' => 'overview')));
        } else {
            $this->print_header_and_tabs($cm, $course, $quiz, $this->mode);
            $mform->display();
        }
    }

    /**
     * @var testing_data_generator
     */
    protected $generator;

    /**
     * Get data generator
     * @return testing_data_generator
     */
    protected function get_data_generator() {
        global $CFG;
        if (is_null($this->generator)) {
            require_once($CFG->libdir.'/testing/generator/lib.php');
            $this->generator = new testing_data_generator();
        }
        return $this->generator;
    }

    /**
     * @param $step array of data from csv file keyed with column names.
     */
    protected function create_attempts($step) {
        global $DB;

        $step = $this->explode_dot_separated_keys_to_make_subindexs($step);
        // Find existing user or make a new user to do the quiz.
        $username = array('username' => $step['firstname'].'.'.$step['lastname'],
                          'firstname' => $step['firstname'],
                          'lastname'  => $step['lastname']);

        if (!$user = $DB->get_record('user', $username)) {
            $user = $this->get_data_generator()->create_user($username);
        }
        if ($this->quiz->course != SITEID) { // No need to enrol user if quiz is on front page.
            // Enrol or update enrollment.
            $this->get_data_generator()->enrol_user($user->id, $this->quiz->course);
        }
        $this->set_user($user);


        // Start the attempt.
        $quizobj = quiz::create($this->quiz->id, $user->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow);
        // Select variant and / or random sub question.
        if (!isset($step['variants'])) {
            $step['variants'] = array();
        }

        if (isset($step['randqs'])) {
            $quizobj->preload_questions();
            $qids = explode(',', quiz_questions_in_quiz($this->quiz->questions));

            foreach ($step['randqs'] as $slotno => $randqname) {
                $randq = question_finder::get_instance()->load_question_data($qids[$slotno - 1]);
                if ($randq->qtype !== 'random') {
                    $a = new stdClass();
                    $a->slotno = $slotno;
                    throw new moodle_exception('thisisnotarandomquestion', 'quiz_simulate', '', $a);
                } else {
                    $qids = question_bank::get_qtype('random')->get_available_questions_from_category($randq->category,
                                                                                    !empty($randq->questiontext));
                    $found = false;
                    foreach ($qids as $qid) {
                        $q = question_finder::get_instance()->load_question_data($qid);
                        if ($q->name === $randqname) {
                            $step['randqs'][$slotno] = $q->id;
                            $found = true;
                            break;
                        }
                    }
                    if (false === $found) {
                        $a = new stdClass();
                        $a->name = $randqname;
                        throw new moodle_exception('noquestionwasfoundwithname', 'quiz_simulate', '', $a);
                    }
                }
            }
        } else {
            $step['randqs'] = array();
        }
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow, $step['randqs'], $step['variants']);
        quiz_attempt_save_started($quizobj, $quba, $attempt);


        // Process some responses from the student.
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, $step['responses']);

        // Finish the attempt.
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_finish($timenow, false);
    }


    /**
     * Set current $USER, reset access cache.
     * @static
     * @param null|int|stdClass $user user record, null or 0 means non-logged-in, positive integer means userid
     * @return void
     */
    protected function set_user($user = null) {
        global $CFG, $DB;
        if (is_object($user)) {
            $user = clone($user);
        } else if (!$user) {
            $user = new stdClass();
            $user->id = 0;
            $user->mnethostid = $CFG->mnet_localhost_id;
        } else {
            $user = $DB->get_record('user', array('id'=>$user));
        }
        unset($user->description);
        unset($user->access);
        unset($user->preference);

        session_set_user($user);
    }

    /**
     * Break down row of csv data into sub arrays, according to column names.
     *
     * @param array $row from csv file with field names with parts separate by '.'.
     * @return array the row with each part of the field name following a '.' being a separate sub array's index.
     */
    protected function explode_dot_separated_keys_to_make_subindexs(array $row) {
        $parts = array();
        foreach ($row as $columnkey => $value) {
            $newkeys = explode('.', trim($columnkey));
            $placetoputvalue =& $parts;
            foreach ($newkeys as $newkeydepth => $newkey) {
                if ($newkeydepth + 1 === count($newkeys)) {
                    $placetoputvalue[$newkey] = $value;
                } else {
                    // Going deeper down.
                    if (!isset($placetoputvalue[$newkey])) {
                        $placetoputvalue[$newkey] = array();
                    }
                    $placetoputvalue =& $placetoputvalue[$newkey];
                }
            }
        }
        return $parts;
    }


}
