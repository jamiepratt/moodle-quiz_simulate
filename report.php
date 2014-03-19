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
require_once($CFG->dirroot . '/mod/quiz/report/statistics/statisticslib.php');

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

    /** @var object quiz record for this quiz. */
    protected $quiz;

    /** @var quiz instance of quiz for this quiz. */
    protected $quizobj;

    /** @var object the course record. */
    protected $course;

    /**
     * @var string[]
     */
    protected $subqs = null;

    /**
     * Index is slot number. Value is full question object.
     * @var object[]
     */
    protected $questions = null;

    public function display($quiz, $cm, $course) {
        global $OUTPUT;
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

            if ($formdata->deleteattemptsfirst == 1) {
                quiz_delete_all_attempts($this->quiz);
            }

            $attemptids = array();

            if ($formdata->shuffletocreatelargedataset != 1) {
                while ($data = $cir->next()) {
                    $stepdata = array_combine($cir->get_columns(), $data);
                    $stepdata = $this->explode_dot_separated_keys_to_make_subindexs($stepdata);
                    if (isset($attemptids[$stepdata['quizattempt']])) {
                        $attemptid = $attemptids[$stepdata['quizattempt']];
                    } else {
                        $userid = $this->find_or_create_user($stepdata['firstname'], $stepdata['lastname']);
                        $attemptid = $this->start_attempt($stepdata, $userid);
                        $attemptids[$stepdata['quizattempt']] = $attemptid;
                    }
                    $this->attempt_step($stepdata, $attemptid);
                }
                redirect($reporturl->out(false, array('mode' => 'overview')));
            } else {
                $this->print_header_and_tabs($cm, $course, $quiz, $this->mode);
                $responsesequences = array();
                $possiblefirstnames = array();
                $possiblelastnames = array();
                $stepdatum = array();
                $attemptsequencenumbers = array();
                while ($data = $cir->next()) {
                    $stepdata = array_combine($cir->get_columns(), $data);
                    $stepdata = $this->explode_dot_separated_keys_to_make_subindexs($stepdata);
                    $stepdatum[] = $stepdata;
                    $possiblefirstnames[] = $stepdata['firstname'];
                    $possiblelastnames[] = $stepdata['lastname'];
                    if (!isset($attemptsequencenumbers[$stepdata['quizattempt']])) {
                        $attemptsequencenumbers[$stepdata['quizattempt']] = 1;
                    } else {
                        $attemptsequencenumbers[$stepdata['quizattempt']]++;
                    }
                    $sequencenumber = $attemptsequencenumbers[$stepdata['quizattempt']];

                    foreach ($stepdata['responses'] as $slot => $responsesequence) {
                        list($variant, $rand) = $this->extract_variant_no_and_rand_name($stepdata, $slot);
                        if (!isset($responsesequences[$slot])) {
                            $responsesequences[$slot] = array();
                        }
                        if (!isset($responsesequences[$slot][$rand])) {
                            $responsesequences[$slot][$rand] = array();
                        }
                        if (!isset($responsesequences[$slot][$rand][$variant])) {
                            $responsesequences[$slot][$rand][$variant] = array();
                        }
                        if (!isset($responsesequences[$slot][$rand][$variant][$stepdata['quizattempt']])) {
                            $responsesequences[$slot][$rand][$variant][$stepdata['quizattempt']] = array();
                        }
                        $responsesequences[$slot][$rand][$variant][$stepdata['quizattempt']][$sequencenumber] = $responsesequence;
                    }
                }
                $progress = new \core\progress\display_if_slow();
                $progress->start_progress('Generating attempt data', 40);
                for ($loopno = 1; $loopno <= 40; $loopno++) {
                    foreach ($stepdatum as $stepdata) {
                        $seqforthisattempt = array();
                        $progress->progress($loopno);
                        shuffle($possiblefirstnames);
                        $firstname = end($possiblefirstnames);
                        shuffle($possiblelastnames);
                        $lastname = end($possiblelastnames);
                        $userid = $this->find_or_create_user($firstname, $lastname, true);
                        $attemptid = $this->start_attempt($stepdata, $userid);
                        foreach ($responsesequences as $slot => $responsesequencesforslot) {
                            list($variant, $randname) = $this->extract_variant_no_and_rand_name($stepdata, $slot);
                            shuffle($responsesequencesforslot[$randname][$variant]);
                            $seqforthisattempt[$slot] = end($responsesequencesforslot[$randname][$variant]);
                        }

                        do {
                            $finished = true;
                            foreach (array_keys($seqforthisattempt) as $slot) {
                                if ($stepforslot = array_shift($seqforthisattempt[$slot])) {
                                    $stepdata[$slot] = $stepforslot;
                                    if (count($seqforthisattempt[$slot]) > 0) {
                                        $finished = false;
                                    }
                                } else {
                                    foreach ($stepdata[$slot] as $name => $value) {
                                        if ($name{0} = '-') {
                                            $stepdata[$slot][$name] = 0;
                                        }
                                    }
                                }
                            }
                            $stepdata['finished'] = $finished;
                            $this->attempt_step($stepdata, $attemptid);
                        } while (!$finished);

                    }
                }
                $progress->end_progress();
                echo $OUTPUT->continue_button($reporturl->out(false, array('mode' => 'overview')));
            }
        } else if (optional_param('download', 0, PARAM_BOOL)) {
            $this->download();

        } else {
            $this->print_header_and_tabs($cm, $course, $quiz, $this->mode);
            echo $OUTPUT->heading(get_string('uploaddata', 'quiz_simulate'), 3);
            $mform->display();
            echo $OUTPUT->heading(get_string('downloaddata', 'quiz_simulate'), 3);
            echo $OUTPUT->single_button(new moodle_url($reporturl, array('download' => 1)),
                                        get_string('download', 'quiz_simulate'),
                                        'post',
                                        array('class' => 'mdl-align'));
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
     * @param      $firstname
     * @param      $lastname
     * @param bool $alwayscreatenew
     * @return int user id
     */
    protected function find_or_create_user($firstname, $lastname, $alwayscreatenew = false) {
        global $DB;
        // Find existing user or make a new user to do the quiz.
        $username = array('username' => $firstname.'.'.$lastname,
                          'firstname' => $firstname,
                          'lastname'  => $lastname);

        if (!$user = $DB->get_record('user', $username)) {
            $user = $this->get_data_generator()->create_user($username);
        } else if ($alwayscreatenew) {
            do {
                $toappend = ''.random_string(4);
                $newusername = array('username' => $firstname.'.'.$lastname.' '.$toappend,
                                     'firstname' => $firstname,
                                     'lastname'  => $lastname.' '.$toappend);

            } while ($user = $DB->get_record('user', $newusername));
            $user = $this->get_data_generator()->create_user($newusername);
        }
        if ($this->quiz->course != SITEID) { // No need to enrol user if quiz is on front page.
            // Enrol or update enrollment.
            $this->get_data_generator()->enrol_user($user->id, $this->quiz->course);
        }
        return $user->id;
    }

    /**
     * @param $step array of data from csv file keyed with column names.
     * @param $attemptid integer attempt id if this is not a new attempt or 0.
     * @throws moodle_exception
     */
    protected function attempt_step($step, $attemptid) {
        // Process some responses from the student.
        $attemptobj = quiz_attempt::create($attemptid);
        $attemptobj->process_submitted_actions(time(), false, $step['responses']);

        // If there is no column in the csv file 'finish', then default to finish each attempt.
        // Or else only finish when the finish column is not empty.
        if (!isset($step['finished']) || !empty($step['finished'])) {
            // Finish the attempt.
            $attemptobj = quiz_attempt::create($attemptid);
            $attemptobj->process_finish(time(), false);
        }
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

    /**
     * @param $step array of data from csv file keyed with column names.
     * @param $userid integer id of the user doing the attempt.
     * @return integer id of the attempt created.
     * @throws moodle_exception
     */
    protected function start_attempt($step, $userid) {
        // Start the attempt.
        $this->quizobj = quiz::create($this->quiz->id, $userid);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $this->quizobj->get_context());
        $quba->set_preferred_behaviour($this->quiz->preferredbehaviour);

        $prevattempts = quiz_get_user_attempts($this->quiz->id, $userid, 'all', true);

        $attemptnumber = count($prevattempts) + 1;
        $attempt = quiz_create_attempt($this->quizobj, $attemptnumber, false, time(), false, $userid);
        // Select variant and / or random sub question.
        if (!isset($step['variants'])) {
            $step['variants'] = array();
        }

        // Pre-load the questions so that we can find the ids of random questions.
        $this->quizobj->preload_questions();
        $this->quizobj->load_questions();

        $randqids = $this->find_randq_ids_from_step_data($step);

        quiz_start_new_attempt($this->quizobj, $quba, $attempt, $attemptnumber, time(), $randqids, $step['variants']);
        quiz_attempt_save_started($this->quizobj, $quba, $attempt);
        return $attempt->id;
    }

    protected function find_randq_ids_from_step_data($step) {
        if (isset($step['randqs'])) {
            $randqids = array();
            $this->get_subq_names();
            foreach ($step['randqs'] as $slotno => $randqname) {
                $subqnames = $this->get_subq_names_for_slot($slotno);
                if (!$randqid = array_search($randqname, $subqnames)) {
                    $a = new stdClass();
                    $a->name = $randqname;
                    throw new moodle_exception('noquestionwasfoundwithname', 'quiz_simulate', '', $a);
                }
                $randqids[$slotno] = $randqid;
            }
            return $randqids;

        } else {
            return array();
        }
    }

    protected function get_subq_names() {
        if ($this->subqs !== null) {
            return;
        }
        $this->subqs = array();
        $questions = $this->quizobj->get_questions();

        foreach ($questions as $q) {
            $this->questions[$q->slot] = $q;
        }
        foreach ($this->questions as $slot => $q) {
            if ($q->qtype === 'random') {
                $randqtypeobj = question_bank::get_qtype('random');
                $subqids = $randqtypeobj->get_available_questions_from_category($q->category, !empty($q->questiontext));
                $this->subqs[$slot] = array();
                foreach ($subqids as $subqid) {
                    $subq = question_finder::get_instance()->load_question_data($subqid);
                    $this->subqs[$slot][$subq->id] = $subq->name;
                }
            }
        }
    }

    /**
     * @param int $slot the slot no
     * @throws moodle_exception
     * @return string[] the rand question names indexed by id.
     */
    protected function get_subq_names_for_slot($slot) {
        $this->get_subq_names();
        if (!isset($this->subqs[$slot])) {
            $a = new stdClass();
            $a->slotno = $slot;
            throw new moodle_exception('thisisnotarandomquestion', 'quiz_simulate', '', $a);
        }
        return $this->subqs[$slot];
    }

    /**
     * @param $slot int
     * @param $qid int
     * @return null|string null if not a random question.
     */
    protected function get_subqname_for_slot_from_id($slot, $qid) {
        $this->get_subq_names();
        if ($this->questions[$slot]->qtype !== 'random') {
            return null;
        } else {
            $subqnames = $this->get_subq_names_for_slot($slot);
            return $subqnames[$qid];
        }
    }

    /**
     * @param $stepdata
     * @param $slot
     * @return array
     */
    protected function extract_variant_no_and_rand_name($stepdata, $slot) {
        if (isset($stepdata['variants']) && isset($stepdata['variants'][$slot])) {
            $variant = $stepdata['variants'][$slot];
        } else {
            $variant = 1;
        }
        if (isset($stepdata['randqs']) && isset($stepdata['randqs'][$slot])) {
            $rand = $stepdata['randqs'][$slot];
        } else {
            $rand = 0;
        }
        return array($variant, $rand);
    }

    /**
     * Prepare csv file describing student attempts and send it as download.
     */
    protected function download() {
        list($fieldnamesforslots, $userids, $attemptdata, $questionnames, $variants, $finish) = $this->get_data_for_download();
        list($headers, $rows) = $this->get_csv_file_data($fieldnamesforslots,
                                                         $userids,
                                                         $attemptdata,
                                                         $questionnames,
                                                         $variants,
                                                         $finish);
        $this->send_csv_file($headers, $rows);
    }

    /**
     * @return array params for get_csv_file_data see that method's description.
     */
    protected function get_data_for_download() {
        $qubaids = quiz_statistics_qubaids_condition($this->quiz->id, array());
        $dm = new question_engine_data_mapper();
        $qubas = $dm->load_questions_usages_by_activity($qubaids);
        $quizattempt = 1;
        $fieldnamesforslots = array();
        $attemptdata = array();
        $finish = array();
        $questionnames = array();
        $variants = array();
        $userids = array();
        foreach ($qubas as $quba) {
            $attemptdata[$quizattempt] = array();
            $quizattemptobj = quiz_attempt::create_from_usage_id($quba->get_id());
            $userids[$quizattempt] = $quizattemptobj->get_userid();
            $slots = $quba->get_slots();
            foreach ($slots as $slot) {
                if (!isset($questionnames[$slot])) {
                    $questionnames[$slot] = array();
                }
                if (!isset($variants[$slot])) {
                    $variants[$slot] = array();
                }
                if (!isset($fieldnamesforslots[$slot])) {
                    $fieldnamesforslots[$slot] = array();
                }
                $question = $quba->get_question($slot);
                $questionnames[$slot][$quizattempt] = $question->name;
                $variants[$slot][$quizattempt] = $quba->get_variant($slot);
                $steps = $quba->get_question_attempt($slot)->get_full_step_iterator();
                foreach ($steps as $stepno => $step) {
                    $dataforthisslotandstep = $this->get_csv_step_data($question, $step);
                    if (!count($dataforthisslotandstep)) {
                        continue;
                    }
                    if (!isset($attemptdata[$quizattempt][$stepno])) {
                        $attemptdata[$quizattempt][$stepno] = array();
                    }
                    $attemptdata[$quizattempt][$stepno][$slot] = $dataforthisslotandstep;
                    $thisstepslotfieldnames = array_keys($dataforthisslotandstep);
                    $fieldnamesforslots[$slot] = array_unique(array_merge($fieldnamesforslots[$slot], $thisstepslotfieldnames));
                }
            }
            $firstslot = reset($slots);
            // Use last step of first slot to see if this attempt was finished.
            $finish[$quizattempt] = $quba->get_question_attempt($firstslot)->get_last_step()->get_state()->is_finished();
            $quizattempt++;
        }
        return array($fieldnamesforslots, $userids, $attemptdata, $questionnames, $variants, $finish);
    }

    /**
     * Gets the data for one step for one question.
     *
     * @param question_definition $question The question.
     * @param question_attempt_step $step   The step to get the data from.
     * @return string[] the csv data for this step.
     */
    protected function get_csv_step_data($question, $step) {
        $csvdata = array();
        $allqtdata = $question->get_student_response_values_for_simulation($step->get_qt_data());
        foreach ($allqtdata as $qtname => $qtvalue) {
            if ($qtname[0] != '_') {
                $csvdata[$qtname] = $qtvalue;
            }
        }
        $behaviourdata = $step->get_behaviour_data();
        foreach ($behaviourdata as $behaviourvarname => $behaviourvarvalue) {
            if ($behaviourvarname[0] != '_' && $behaviourvarname != 'finish') {
                $csvdata['-'.$behaviourvarname] = $behaviourvarvalue;
            }
        }
        return $csvdata;
    }

    /**
     * @param array[] $fieldnamesforslots The field names per slot of data to download.
     * @param int[]   $userids            The user id for the user for each quiz attempt.
     * @param array[] $attemptdata        with step data first index is quiz attempt no and second is step no third index is
     *                                    question type data or behaviour var name.
     * @param array[] $questionnames      The question name for question indexed by slot no and then quiz attempt.
     * @param array[] $variants           The variant no for question indexed by slot no and then quiz attempt.
     * @param bool[]  $finish             Is question attempt finished - indexed by quiz attempt no.
     * @return array[] with two values array $headers for file and array $rows for file
     */
    protected function get_csv_file_data($fieldnamesforslots, $userids, $attemptdata, $questionnames, $variants, $finish) {
        global $DB;
        $headers = array('quizattempt', 'firstname', 'lastname');
        $rows = array();
        $subqcolumns = array();
        $variantnocolumns = array();
        foreach (array_keys($fieldnamesforslots) as $slot) {
            sort($fieldnamesforslots[$slot]);
            $subqcolumns[$slot] = count(array_unique($questionnames[$slot])) > 1;
            if ($subqcolumns[$slot]) {
                $headers[] = 'randqs.'.$slot;
            }
            $variantnocolumns[$slot] = false;
            foreach ($variants[$slot] as $variant) {
                if ($variant != 1) {
                    $variantnocolumns[$slot] = true;
                    break;
                }
            }
            if ($variantnocolumns[$slot]) {
                $headers[] = 'variants.'.$slot;
            }
            foreach ($fieldnamesforslots[$slot] as $fieldname) {
                $headers[] = 'responses.'.$slot.'.'.$fieldname;
            }
        }
        $users = $DB->get_records_list('user', 'id', array_unique($userids), '', 'id, firstname, lastname');
        // Any zero elements in finish array?
        $finishcolumn = false;
        foreach ($attemptdata as $quizattempt => $stepsslotscsvdata) {
            if (count($stepsslotscsvdata) > 1) {
                // More than one step in this quiz attempt.
                $finishcolumn = true;
                break;
            }
        }
        if ($finishcolumn) {
            $headers[] = 'finished';
        }
        foreach ($attemptdata as $quizattempt => $stepsslotscsvdata) {
            $firstrow = true;
            $stepnos = array_keys($stepsslotscsvdata);
            $laststepno = array_pop($stepnos);
            foreach ($stepsslotscsvdata as $stepno => $slotscsvdata) {
                $row = array();
                $row[] = $quizattempt;
                if ($firstrow) {
                    $row[] = $users[$userids[$quizattempt]]->firstname;
                    $row[] = $users[$userids[$quizattempt]]->lastname;
                } else {
                    $row[] = '';
                    $row[] = '';
                }
                foreach ($fieldnamesforslots as $slot => $fieldnames) {
                    if ($subqcolumns[$slot]) {
                        if ($firstrow) {
                            $row[] = $questionnames[$slot][$quizattempt];
                        } else {
                            $row[] = '';
                        }
                    }
                    if ($variantnocolumns[$slot]) {
                        if ($firstrow) {
                            $row[] = $variants[$slot][$quizattempt];
                        } else {
                            $row[] = '';
                        }
                    }
                    foreach ($fieldnames as $fieldname) {
                        $value = '';
                        $stepnocountback = $stepno;
                        if ($fieldname{0} == '-') {
                            // Behaviour data.
                            if (isset($slotscsvdata[$slot][$fieldname])) {
                                $value = $slotscsvdata[$slot][$fieldname];
                            } else {
                                $value = '';
                            }
                        } else {
                            // Question type data, repeat last value if there is no value in this step.
                            while ($stepnocountback > 0) {
                                if (isset($stepsslotscsvdata[$stepnocountback][$slot][$fieldname]) &&
                                                $stepsslotscsvdata[$stepnocountback][$slot][$fieldname] !== '') {
                                    $value = $stepsslotscsvdata[$stepnocountback][$slot][$fieldname];
                                    break;
                                }
                                $stepnocountback--;
                            }
                        }
                        $row[] = $value;
                    }
                }
                if ($finishcolumn) {
                    if ($stepno == $laststepno) {
                        $row[] = $finish[$quizattempt] ? '1' : '0';
                    } else {
                        $row[] = '0';
                    }
                }
                $rows[] = $row;
                $firstrow = false;
            }
        }
        return array($headers, $rows);
    }

    protected function send_csv_file($headers, $rows) {
        $export = new csv_export_writer();
        $export->set_filename(clean_filename($this->quiz->name.'_stepdata'));
        $export->add_data($headers);
        foreach ($rows as $row) {
            $export->add_data($row);
        }
        $export->download_file();
    }
}
