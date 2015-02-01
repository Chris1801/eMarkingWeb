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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
/**
 *
 * @package mod
 * @subpackage emarking
 * @copyright 2014 Jorge Villalón {@link http://www.uai.cl}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class emarking_printexam_form extends moodleform {

	// Extra HTML to be added at the end of the form, used for javascript functions.
	function definition() {
		global $DB, $CFG;

		$mform = $this->_form;
		$instance = $this->_customdata;
		
		$examid = $instance ['examid'];
		
		// Exam totalpages goes hidden as well
		$mform->addElement ( 'hidden', 'exam', $examid );
		$mform->setType ( 'exam', PARAM_INT );

		$mform->addElement ( 'header', 'selectprinter', get_string('selectprinter','mod_emarking' ) );

		$printerarray = array();
		foreach(explode(',', $CFG->emarking_printername) as $printer) {
			$printerarray[] = $printer;
		}

		// Extra sheets per student
		$mform->addElement ( 'select', 'printername', 
				get_string('printername','mod_emarking' ), $printerarray, null );
		$mform->addHelpButton ( 'printername', 'printername', 'mod_emarking' );

		// buttons
		$this->add_action_buttons ( true, get_string ( 'submit' ) );
	}
}
