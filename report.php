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

    /**
     * @var string[]
     */
    protected $subqs = null;

    /**
     * @var int[]
     */
    protected $qids = null;

    public function display($quiz, $cm, $course) {
        global $OUTPUT, $DB;
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
                $possibleresponses = array();
                $possiblefirstnames = array();
                $possiblelastnames = array();
                $stepdatum = array();
                while ($data = $cir->next()) {
                    $stepdata = array_combine($cir->get_columns(), $data);
                    $stepdata = $this->explode_dot_separated_keys_to_make_subindexs($stepdata);
                    $stepdatum[] = $stepdata;
                    $possiblefirstnames[] = $stepdata['firstname'];
                    $possiblelastnames[] = $stepdata['lastname'];
                    foreach ($stepdata['responses'] as $slot => $response) {
                        list($variant, $rand) = $this->extract_variant_no_and_rand_name($stepdata, $slot);
                        if (!isset($possibleresponses[$slot])) {
                            $possibleresponses[$slot] = array();
                        }
                        if (!isset($possibleresponses[$slot][$rand])) {
                            $possibleresponses[$slot][$rand] = array();
                        }
                        if (!isset($possibleresponses[$slot][$rand][$variant])) {
                            $possibleresponses[$slot][$rand][$variant] = array();
                        }
                        $possibleresponses[$slot][$rand][$variant][] = $response;
                    }
                }
                $progress = new \core\progress\display_if_slow();
                $progress->start_progress('Generating attempt data', 40);
                for ($loopno = 1; $loopno <= 40; $loopno++) {
                    foreach ($stepdatum as $stepdata) {
                        $progress->progress($loopno);
                        foreach ($possibleresponses as $slot => $possibleresponse) {
                            list($variant, $randname) = $this->extract_variant_no_and_rand_name($stepdata, $slot);
                            shuffle($possibleresponse[$randname][$variant]);
                            $stepdata['responses'][$slot] = end($possibleresponse[$randname][$variant]);
                        }
                        shuffle($possiblefirstnames);
                        $firstname = end($possiblefirstnames);
                        shuffle($possiblelastnames);
                        $lastname = end($possiblelastnames);
                        $userid = $this->find_or_create_user($firstname, $lastname, true);
                        $attemptid = $this->start_attempt($stepdata, $userid);
                        $this->attempt_step($stepdata, $attemptid);
                    }
                }
                $progress->end_progress();
                echo $OUTPUT->continue_button($reporturl->out(false, array('mode' => 'overview')));
            }
        } else if (optional_param('download', 0, PARAM_BOOL)) {
            $this->send_download();

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
        $quizobj = quiz::create($this->quiz->id, $userid);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $prevattempts = quiz_get_user_attempts($this->quiz->id, $userid, 'all', true);

        $attemptnumber = count($prevattempts) + 1;
        $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, time(), false, $userid);
        // Select variant and / or random sub question.
        if (!isset($step['variants'])) {
            $step['variants'] = array();
        }

        $randqids = $this->find_randq_ids_from_step_data($step);

        quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, time(), $randqids, $step['variants']);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
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
        $qids = explode(',', quiz_questions_in_quiz($this->quiz->questions));
        question_finder::get_instance()->load_many_for_cache(array_combine($qids, $qids));

        $this->qids = array_combine(range(1, count($qids)), $qids);
        foreach ($this->qids as $slot => $qid) {
            $q = question_finder::get_instance()->load_question_data($qid);
            if ($q->qtype === 'random') {
                $subqids = question_bank::get_qtype('random')
                    ->get_available_questions_from_category($q->category, !empty($q->questiontext));
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
        if ($qid == $this->qids[$slot]) {
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
    protected function send_download() {
        global $DB;
        $sql = <<<EOF
 SELECT
    qasd.id AS id,
    quiza.id AS quizattemptid,
    u.lastname,
    u.firstname,
    qa.variant,
    qa.slot,
    qa.questionid,
    qas.sequencenumber,
    qasd.name,
    qasd.VALUE

FROM mdl_user u,
mdl_quiz_attempts quiza
JOIN mdl_question_usages qu ON qu.id = quiza.uniqueid
JOIN mdl_question_attempts qa ON qa.questionusageid = qu.id
JOIN mdl_question_attempt_steps qas ON qas.questionattemptid = qa.id
LEFT JOIN mdl_question_attempt_step_data qasd ON qasd.attemptstepid = qas.id

WHERE quiza.quiz = {$this->quiz->id} AND u.id = quiza.userid

ORDER BY quiza.userid, quiza.attempt, qa.slot, qas.sequencenumber, qasd.name

EOF;
        $attempts = $DB->get_records_sql($sql);
        $steps = array();
        $fields = array('quizattempt', 'firstname', 'lastname', 'finished');
        $slotsfields = array();
        foreach ($attempts as $attempt) {
            if ($attempt->name{0} != '_' && $attempt->name{1} != '_' && $attempt->name != '-finish') {
                if (!isset($steps[$attempt->quizattemptid])) {
                    $steps[$attempt->quizattemptid] = array();
                }
                $slot = $attempt->slot;
                if (!isset($slotsfields[$slot])) {
                    $slotsfields[$slot] = array();
                }
                if (!isset($steps[$attempt->quizattemptid][$attempt->sequencenumber])) {
                    $steps[$attempt->quizattemptid][$attempt->sequencenumber] = array();
                    $steps[$attempt->quizattemptid][$attempt->sequencenumber]['lastname'] = $attempt->lastname;
                    $steps[$attempt->quizattemptid][$attempt->sequencenumber]['firstname'] = $attempt->firstname;

                    if ($attempt->variant != 1) {
                        if (!in_array('variants.'.$slot, $slotsfields[$slot])) {
                            $slotsfields[$slot][] = 'variants.'.$slot;
                        }
                        $steps[$attempt->quizattemptid][$attempt->sequencenumber]['variants.'.$slot] = $attempt->variant;
                    }
                    $subqname = $this->get_subqname_for_slot_from_id($slot, $attempt->questionid);
                    if ($subqname !== null) {
                        if (!in_array('randqs.'.$slot, $slotsfields[$slot])) {
                            $slotsfields[$slot][] = 'randqs.'.$slot;
                        }
                        $steps[$attempt->quizattemptid][$attempt->sequencenumber]['randqs.'.$slot] = $subqname;
                    }
                }
                $csvcolumn = 'responses.'.$slot.'.'.$attempt->name;

                if (!in_array($csvcolumn, $slotsfields[$slot])) {
                    $slotsfields[$slot][] = $csvcolumn;
                }
                $steps[$attempt->quizattemptid][$attempt->sequencenumber][$csvcolumn] = $attempt->value;
            }
        }

        $export = new csv_export_writer();

        $export->set_filename(clean_filename($this->quiz->name.'_stepdata'));

        foreach ($slotsfields as $slotfields) {
            sort($slotfields);
            $fields = array_merge($fields, $slotfields);
        }
        $export->add_data($fields);

        $attemptnumber = 1;
        foreach ($steps as $attemptsteps) {
            $row = array();
            do {
                $attemptstep = array_shift($attemptsteps);
                foreach ($fields as $field) {
                    if (!isset($attemptstep[$field])) {
                        if ($field === 'finished') {
                            if (count($attemptsteps) > 0) {
                                $row['finished'] = 0;
                            } else {
                                $row['finished'] = 1;
                            }
                        } else if ($field === 'quizattempt') {
                            $row['quizattempt'] = $attemptnumber;
                        } else if (substr($field, 0, 9) === 'variants.') {
                            $row[$field] = 1;
                        } else if (substr($field, -9) == '-tryagain' || substr($field, -7) == '-submit') {
                            $row[$field] = 0;
                        } else if (!isset($row[$field])) {
                            $row[$field] = '';
                        }
                    } else {
                        $row[$field] = $attemptstep[$field];
                    }
                }
                $export->add_data($row);
            } while ($attemptsteps);
            $attemptnumber++;
        }
        $export->download_file();
    }

}
