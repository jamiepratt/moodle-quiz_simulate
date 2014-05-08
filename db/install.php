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
 * Post-install script for the simulate quiz report.
 * @package   quiz_simulate
 * @copyright  2013 The Open University
 * @author     James Pratt me@jamiep.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Post-install script
 */
function xmldb_quiz_simulate_install() {
    global $DB;

    $record = new stdClass();
    $record->name         = 'simulate';
    $record->displayorder = 1;
    $record->cron         = 0;
    $record->capability   = 'mod/quiz:grade';

    $DB->insert_record('quiz_reports', $record);
}
