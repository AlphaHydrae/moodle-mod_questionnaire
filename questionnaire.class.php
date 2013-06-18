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

require_once('questiontypes/questiontypes.class.php');

class questionnaire {

    // Class Properties.
    /*
     * The survey record.
     * @var object $survey
     */
     //  Todo var $survey; TODO.

    // Class Methods.

    /*
     * The class constructor
     *
     */
    public function __construct($id = 0, $questionnaire = null, &$course, &$cm, $addquestions = true) {
        global $DB;

        if ($id) {
            $questionnaire = $DB->get_record('questionnaire', array('id' => $id));
        }

        if (is_object($questionnaire)) {
            $properties = get_object_vars($questionnaire);
            foreach ($properties as $property => $value) {
                $this->$property = $value;
            }
        }

        if (!empty($this->sid)) {
            $this->add_survey($this->sid);
        }

        $this->course = $course;
        $this->cm = $cm;
        // When we are creating a brand new questionnaire, we will not yet have a context.
        if (!empty($cm) && !empty($this->id)) {
            $this->context = get_context_instance(CONTEXT_MODULE, $cm->id);
        } else {
            $this->context = null;
        }

        if ($addquestions && !empty($this->sid)) {
            $this->add_questions($this->sid);
        }

        $this->usehtmleditor = can_use_html_editor();

        // Load the capabilities for this user and questionnaire, if not creating a new one.
        if (!empty($this->cm->id)) {
            $this->capabilities = questionnaire_load_capabilities($this->cm->id);
        }
    }

    /**
     * Adding a survey record to the object.
     *
     */
    public function add_survey($sid = 0, $survey = null) {
        global $DB;

        if ($sid) {
            $this->survey = $DB->get_record('questionnaire_survey', array('id' => $sid));
        } else if (is_object($survey)) {
            $this->survey = clone($survey);
        }
    }

    /**
     * Adding questions to the object.
     */
    public function add_questions($sid = false, $section = false) {
        global $DB;

        if ($sid === false) {
            $sid = $this->sid;
        }

        if (!isset($this->questions)) {
            $this->questions = array();
            $this->questionsbysec = array();
        }

        $select = 'survey_id = '.$sid.' AND deleted != \'y\'';
        if ($records = $DB->get_records_select('questionnaire_question', $select, null, 'position')) {
            $sec = 1;
            $isbreak = false;
            foreach ($records as $record) {
                $this->questions[$record->id] = new questionnaire_question(0, $record, $this->context);
                if ($record->type_id != 99) {
                    $this->questionsbysec[$sec][$record->id] = &$this->questions[$record->id];
                    $isbreak = false;
                } else {
                    // Sanity check: no section break allowed as first position, no 2 consecutive section breaks.
                    if ($record->position != 1 && $isbreak == false) {
                        $sec++;
                        $isbreak = true;
                    }
                }
            }
        }
    }

    public function view() {
        global $CFG, $USER, $PAGE, $OUTPUT;

        $PAGE->set_title(format_string($this->name));
        $PAGE->set_heading(format_string($this->course->fullname));

        // Initialise the JavaScript.
        $PAGE->requires->js_init_call('M.mod_questionnaire.init_attempt_form', null, false, questionnaire_get_js_module());

        echo $OUTPUT->header();

        $questionnaire = $this;

        if (!$this->cm->visible && !$this->capabilities->viewhiddenactivities) {
                notice(get_string("activityiscurrentlyhidden"));
        }

        if (!$this->capabilities->view) {
            echo('<br/>');
            questionnaire_notify(get_string("guestsno", "questionnaire", $this->name));
            echo('<div><a href="'.$CFG->wwwroot.'/course/view.php?id='.$this->course->id.'">'.
                get_string("continue").'</a></div>');
            exit;
        }

        // Print the main part of the page.

        if (!$this->is_active()) {
            echo '<div class="message">'
            .get_string('notavail', 'questionnaire')
            .'</div>';
        } else if (!$this->is_open()) {
                echo '<div class="message">'
                .get_string('notopen', 'questionnaire', userdate($this->opendate))
                .'</div>';
        } else if ($this->is_closed()) {
            echo '<div class="message">'
            .get_string('closed', 'questionnaire', userdate($this->closedate))
            .'</div>';
        } else if (!$this->user_is_eligible($USER->id)) {
            echo '<div class="message">'
            .get_string('noteligible', 'questionnaire')
            .'</div>';
        } else if ($this->user_can_take($USER->id)) {
            $sid=$this->sid;
            $quser = $USER->id;

            if ($this->survey->realm == 'template') {
                print_string('templatenotviewable', 'questionnaire');
                echo $OUTPUT->footer($this->course);
                exit();
            }

            $msg = $this->print_survey($USER->id, $quser);

            // If Survey was submitted with all required fields completed ($msg is empty),
            // then record the submittal.
            $viewform = data_submitted($CFG->wwwroot."/mod/questionnaire/complete.php");
            if (!empty($viewform->rid)) {
                $viewform->rid = (int)$viewform->rid;
            }
            if (!empty($viewform->sec)) {
                $viewform->sec = (int)$viewform->sec;
            }

            if (data_submitted() && confirm_sesskey() && isset($viewform->submit) && isset($viewform->submittype) &&
                ($viewform->submittype == "Submit Survey") && empty($msg)) {
                $this->response_delete($viewform->rid, $viewform->sec);
                $this->rid = $this->response_insert($this->survey->id, $viewform->sec, $viewform->rid, $quser);
                $this->response_commit($this->rid);

                // If it was a previous save, rid is in the form...
                if (!empty($viewform->rid) && is_numeric($viewform->rid)) {
                    $rid = $viewform->rid;

                    // Otherwise its in this object.
                } else {
                    $rid = $this->rid;
                }

                questionnaire_record_submission($this, $USER->id, $rid);

                if ($this->grade != 0) {
                    $questionnaire = new Object();
                    $questionnaire->id = $this->id;
                    $questionnaire->name = $this->name;
                    $questionnaire->grade = $this->grade;
                    $questionnaire->cmidnumber = $this->cm->idnumber;
                    $questionnaire->courseid = $this->course->id;
                    questionnaire_update_grades($questionnaire, $quser);
                }

                add_to_log($this->course->id, "questionnaire", "submit", "view.php?id={$this->cm->id}", "{$this->name}",
                    $this->cm->id, $USER->id);

                $this->response_send_email($this->rid);
                $this->response_goto_thankyou();
            }

        } else {
            switch ($this->qtype) {
                case QUESTIONNAIREDAILY:
                    $msgstring = ' '.get_string('today', 'questionnaire');
                    break;
                case QUESTIONNAIREWEEKLY:
                    $msgstring = ' '.get_string('thisweek', 'questionnaire');
                    break;
                case QUESTIONNAIREMONTHLY:
                    $msgstring = ' '.get_string('thismonth', 'questionnaire');
                    break;
                default:
                    $msgstring = '';
                    break;
            }
            echo ('<div class="message">'.get_string("alreadyfilled", "questionnaire", $msgstring).'</div>');
        }

        // Finish the page.
        echo $OUTPUT->footer($this->course);
    }

    /*
    * Function to view an entire responses data.
    *
    */
    public function view_response($rid, $blankquestionnaire=false) {
        global $OUTPUT;

        $this->print_survey_start('', 1, 1, 0, $rid, false);

        $data = new Object();
        $i = 1;
        $this->response_import_all($rid, $data);
        foreach ($this->questions as $question) {
            if ($question->type_id < QUESPAGEBREAK) {
                $question->response_display($data, $i++);
            }
        }
    }

    /*
    * Function to view an entire responses data.
    *
    */
    public function view_all_responses($resps) {
        global $qtypenames, $OUTPUT;
        $this->print_survey_start('', 1, 1, 0);

        foreach ($resps as $resp) {
            $data[$resp->id] = new Object();
            $this->response_import_all($resp->id, $data[$resp->id]);
        }

        $i = 1;

        foreach ($this->questions as $question) {

            if ($question->type_id < QUESPAGEBREAK) {
                $method = $qtypenames[$question->type_id].'_response_display';
                if (method_exists($question, $method)) {
                    echo $OUTPUT->box_start('individualresp');
                    $question->questionstart_survey_display($i);
                    foreach ($data as $respid => $respdata) {
                        echo '<div class="respdate">'.userdate($resps[$respid]->submitted).'</div>';
                        $question->$method($respdata);
                    }
                    $question->questionend_survey_display($i);
                    echo $OUTPUT->box_end();
                } else {
                    print_error('displaymethod', 'questionnaire');
                }
                $i++;
            }
        }

        $this->print_survey_end(1, 1);
    }

    // Access Methods.
    public function is_active() {
        return (!empty($this->survey));
    }

    public function is_open() {
        return ($this->opendate > 0) ? ($this->opendate < time()) : true;
    }

    public function is_closed() {
        return ($this->closedate > 0) ? ($this->closedate < time()) : false;
    }

    public function user_can_take($userid) {

        if (!$this->is_active() || !$this->user_is_eligible($userid)) {
            return false;
        } else if ($this->qtype == QUESTIONNAIREUNLIMITED) {
            return true;
        } else if ($userid > 0) {
            return $this->user_time_for_new_attempt($userid);
        } else {
            return false;
        }
    }

    public function user_is_eligible($userid) {
        return ($this->capabilities->view && $this->capabilities->submit);
    }

    public function user_time_for_new_attempt($userid) {
        global $DB;

        $select = 'qid = '.$this->id.' AND userid = '.$userid;
        if (!($attempts = $DB->get_records_select('questionnaire_attempts', $select, null, 'timemodified DESC'))) {
            return true;
        }

        $attempt = reset($attempts);
        $timenow = time();

        switch ($this->qtype) {

            case QUESTIONNAIREUNLIMITED:
                $cantake = true;
                break;

            case QUESTIONNAIREONCE:
                $cantake = false;
                break;

            case QUESTIONNAIREDAILY:
                $attemptyear = date('Y', $attempt->timemodified);
                $currentyear = date('Y', $timenow);
                $attemptdayofyear = date('z', $attempt->timemodified);
                $currentdayofyear = date('z', $timenow);
                $cantake = (($attemptyear < $currentyear) ||
                            (($attemptyear == $currentyear) && ($attemptdayofyear < $currentdayofyear)));
                break;

            case QUESTIONNAIREWEEKLY:
                $attemptyear = date('Y', $attempt->timemodified);
                $currentyear = date('Y', $timenow);
                $attemptweekofyear = date('W', $attempt->timemodified);
                $currentweekofyear = date('W', $timenow);
                $cantake = (($attemptyear < $currentyear) ||
                            (($attemptyear == $currentyear) && ($attemptweekofyear < $currentweekofyear)));
                break;

            case QUESTIONNAIREMONTHLY:
                $attemptyear = date('Y', $attempt->timemodified);
                $currentyear = date('Y', $timenow);
                $attemptmonthofyear = date('n', $attempt->timemodified);
                $currentmonthofyear = date('n', $timenow);
                $cantake = (($attemptyear < $currentyear) ||
                            (($attemptyear == $currentyear) && ($attemptmonthofyear < $currentmonthofyear)));
                break;

            default:
                $cantake = false;
                break;
        }

        return $cantake;
    }

    public function is_survey_owner() {
        return (!empty($this->survey->owner) && ($this->course->id == $this->survey->owner));
    }

    public function can_view_response($rid) {
        global $USER, $DB;

        if (!empty($rid)) {
            $response = $DB->get_record('questionnaire_response', array('id' => $rid));

            // If the response was not found, can't view it.
            if (empty($response)) {
                return false;
            }

            // If the response belongs to a different survey than this one, can't view it.
            if ($response->survey_id != $this->survey->id) {
                return false;
            }

            // If you can view all responses always, then you can view it.
            if ($this->capabilities->readallresponseanytime) {
                return true;
            }

            // If you are allowed to view this response for another user.
            if ($this->capabilities->readallresponses &&
                ($this->resp_view == QUESTIONNAIRE_STUDENTVIEWRESPONSES_ALWAYS ||
                 ($this->resp_view == QUESTIONNAIRE_STUDENTVIEWRESPONSES_WHENCLOSED && $this->is_closed()) ||
                 ($this->resp_view == QUESTIONNAIRE_STUDENTVIEWRESPONSES_WHENANSWERED  && !$this->user_can_take($USER->id)))) {
                return true;
            }

             // If you can read your own response.
            if (($response->username == $USER->id) && $this->capabilities->readownresponses &&
                            ($this->count_submissions($USER->id) > 0)) {
                return true;
            }

        } else {
            // If you can view all responses always, then you can view it.
            if ($this->capabilities->readallresponseanytime) {
                return true;
            }

            // If you are allowed to view this response for another user.
            if ($this->capabilities->readallresponses &&
                ($this->resp_view == QUESTIONNAIRE_STUDENTVIEWRESPONSES_ALWAYS ||
                 ($this->resp_view == QUESTIONNAIRE_STUDENTVIEWRESPONSES_WHENCLOSED && $this->is_closed()) ||
                 ($this->resp_view == QUESTIONNAIRE_STUDENTVIEWRESPONSES_WHENANSWERED  && !$this->user_can_take($USER->id)))) {
                return true;
            }

             // If you can read your own response.
            if ($this->capabilities->readownresponses && ($this->count_submissions($USER->id) > 0)) {
                return true;
            }
        }
    }

    public function count_submissions($userid=false) {
        global $DB;

        if (!$userid) {
            // Provide for groups setting.
            return $DB->count_records('questionnaire_response', array('survey_id' => $this->sid, 'complete' => 'y'));
        } else {
            return $DB->count_records('questionnaire_response', array('survey_id' => $this->sid, 'username' => $userid,
                                      'complete' => 'y'));
        }
    }

    private function has_required($section = 0) {
        if (empty($this->questions)) {
            return false;
        } else if ($section <= 0) {
            foreach ($this->questions as $question) {
                if ($question->required == 'y') {
                    return true;
                }
            }
        } else {
            foreach ($this->questionsbysec[$section] as $question) {
                if ($question->required == 'y') {
                    return true;
                }
            }
        }
        return false;
    }

    // Display Methods.

    public function print_survey($userid=false, $quser) {
        global $CFG;

        $formdata = new stdClass();
        if (data_submitted() && confirm_sesskey()) {
            $formdata = data_submitted();
        }
        $formdata->rid = $this->get_response($quser);
        // If student saved a "resume" questionnaire OR left a questionnaire unfinished
        // and there are more pages than one find the page of the last answered question.
        if (!empty($formdata->rid) && (empty($formdata->sec) || intval($formdata->sec) < 1)) {
            $formdata->sec = $this->response_select_max_sec($formdata->rid);
        }
        if (empty($formdata->sec)) {
            $formdata->sec = 1;
        } else {
            $formdata->sec = (intval($formdata->sec) > 0) ? intval($formdata->sec) : 1;
        }

        $num_sections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;    // Indexed by section.
        $msg = '';
        $action = $CFG->wwwroot.'/mod/questionnaire/complete.php?id='.$this->cm->id;

        // TODO - Need to rework this. Too much crossover with ->view method.
        if (!empty($formdata->submit)) {
            $msg = $this->response_check_format($formdata->sec, $formdata);
            if (empty($msg)) {
                return;
            }
        }

        if (!empty($formdata->resume) && ($this->resume)) {
            $this->response_delete($formdata->rid, $formdata->sec);
            $formdata->rid = $this->response_insert($this->survey->id, $formdata->sec, $formdata->rid, $quser, $resume=true);
            $this->response_goto_saved($action);
            return;
        }
        // JR save each section 's $formdata somewhere in case user returns to that page when navigating the questionnaire...
        if (!empty($formdata->next)) {
            $this->response_delete($formdata->rid, $formdata->sec);
            $formdata->rid = $this->response_insert($this->survey->id, $formdata->sec, $formdata->rid, $quser);
            $msg = $this->response_check_format($formdata->sec, $formdata);
            if ( $msg ) {
                $formdata->next = '';
            } else {
                $formdata->sec++;
            }
        }
        if (!empty($formdata->prev) && ($this->navigate)) {
            $this->response_delete($formdata->rid, $formdata->sec);
            $formdata->rid = $this->response_insert($this->survey->id, $formdata->sec, $formdata->rid, $quser);
            $msg = $this->response_check_format($formdata->sec, $formdata);
            if ( $msg ) {
                $formdata->prev = '';
            } else {
                $formdata->sec--;
            }
        }

        if (!empty($formdata->rid)) {
            $this->response_import_sec($formdata->rid, $formdata->sec, $formdata);
        }

        // TODO move this script to questionnaire/module.js.
        echo '
    <script type="text/javascript">
    <!-- // Begin
    // when respondent enters text in !other field, corresponding radio button OR check box is automatically checked
    function other_check(name) {
      other = name.split("_");
      var f = document.getElementById("phpesp_response");
      for (var i=0; i<=f.elements.length; i++) {
        if (f.elements[i].value == "other_"+other[1]) {
          f.elements[i].checked=true;
          break;
        }
      }
    }

    // function added by JR to automatically empty an !other text input field if another Radio button is clicked
    function other_check_empty(name, value) {
      var f = document.getElementById("phpesp_response");
      for (var i=0; i<f.elements.length; i++) {
        if ((f.elements[i].name == name) && f.elements[i].value.substr(0,6) == "other_") {
            f.elements[i].checked=true;
            var otherid = f.elements[i].name + "_" + f.elements[i].value.substring(6);
            var other = document.getElementsByName (otherid);
            if (value.substr(0,6) != "other_") {
               other[0].value = "";
            } else {
                other[0].focus();
            }
            var actualbuttons = document.getElementsByName (name);
              for (var i=0; i<=actualbuttons.length; i++) {
                if (actualbuttons[i].value == value) {
                    actualbuttons[i].checked=true;
                    break;
                }
            }
        break;
        }
      }
    }

    // function added by JR in a Rate question type of sub-type Order to automatically uncheck a Radio button
    // when another radio button in the same column is clicked
    function other_rate_uncheck(name, value) {
        col_name = name.substr(0, name.indexOf("_"));
        var inputbuttons = document.getElementsByTagName("input");
        for (var i=0; i<=inputbuttons.length - 1; i++) {
            button = inputbuttons[i];
            if (button.type == "radio" && button.name != name && button.value == value
                        && button.name.substr(0, name.indexOf("_")) == col_name) {
                button.checked = false;
            }
        }
    }

    // function added by JR to empty an !other text input when corresponding Check Box is clicked (supposedly to empty it)
    function checkbox_empty(name) {
        var actualbuttons = document.getElementsByName (name);
        for (var i=0; i<=actualbuttons.length; i++) {
            if (actualbuttons[i].value.substr(0,6) == "other_") {
                name = name.substring(0,name.length-2) + actualbuttons[i].value.substring(5);
                var othertext = document.getElementsByName (name);
                if (othertext[0].value == "" && actualbuttons[i].checked == true) {
                    othertext[0].focus();
                } else {
                    othertext[0].value = "";
                }
                break;
            }
        }
    }
    // End -->
    </script>
            ';
        $formdatareferer = !empty($formdata->referer) ? htmlspecialchars($formdata->referer) : '';
        $formdatarid = isset($formdata->rid) ? $formdata->rid : '0';
        echo '<div class="generalbox">';
        echo '
                <form id="phpesp_response" method="post" action="'.$action.'">
                <div>
                <input type="hidden" name="referer" value="'.$formdatareferer.'" />
                <input type="hidden" name="a" value="'.$this->id.'" />
                <input type="hidden" name="sid" value="'.$this->survey->id.'" />
                <input type="hidden" name="rid" value="'.$formdatarid.'" />
                <input type="hidden" name="sec" value="'.$formdata->sec.'" />
                <input type="hidden" name="sesskey" value="'.sesskey().'" />
                </div>
            ';
        if (isset($this->questions) && $num_sections) { // Sanity check.
            $this->survey_render($formdata->sec, $msg, $formdata);
            echo '<div class="notice" style="padding: 0.5em 0 0.5em 0.2em;"><div class="buttons">';
            if (($this->navigate) && ($formdata->sec > 1)) {
                echo '<input type="submit" name="prev" value="'.get_string('previouspage', 'questionnaire').'" />';
            }
            if ($this->resume) {
                echo '<input type="submit" name="resume" value="'.get_string('save', 'questionnaire').'" />';
            }

            //  Add a 'hidden' variable for the mod's 'view.php', and use a language variable for the submit button.

            if ($formdata->sec == $num_sections) {
                echo '
                    <div><input type="hidden" name="submittype" value="Submit Survey" />
                    <input type="submit" name="submit" value="'.get_string('submitsurvey', 'questionnaire').'" /></div>';
            } else {
                echo '<div><input type="submit" name="next" value="'.get_string('nextpage', 'questionnaire').'" /></div>';
            }
            echo '</div></div>'; // Divs notice & buttons.
            echo '</form>';
            echo '</div>'; // Div class="generalbox".

            return $msg;
        } else {
            echo '<p>'.get_string('noneinuse', 'questionnaire').'</p>';
            echo '</form>';
            echo '</div>';
        }
    }

    private function survey_render($section = 1, $message = '', &$formdata) {

        $this->usehtmleditor = null;

        if (empty($section)) {
            $section = 1;
        }

        $num_sections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;    // Indexed by section.
        if ($section > $num_sections) {
            return(false);  // Invalid section.
        }

        // Check to see if there are required questions.
        $has_required = $this->has_required($section);

        // Find out what question number we are on $i New fix for question numbering.
        $i = 0;
        if ($section > 1) {
            for ($j = 2; $j<=$section; $j++) {
                foreach ($this->questionsbysec[$j-1] as $question) {
                    if ($question->type_id < 99) {
                        $i++;
                    }
                }
            }
        }

        $this->print_survey_start($message, $section, $num_sections, $has_required, '', 1);
        foreach ($this->questionsbysec[$section] as $question) {
            if ($question->type === 'Essay Box') {
                $this->usehtmleditor = can_use_html_editor();
            }
            if ($question->type_id != QUESSECTIONTEXT) {
                $i++;
            }
            $question->survey_display($formdata, $i, $this->usehtmleditor);
            // Bug MDL-7292 - Don't count section text as a question number.
            // Process each question.
        }
        // End of questions.
        echo ('<div class="surveyPage">');
        $this->print_survey_end($section, $num_sections);
        echo '</div>';
        return;
    }

    private function print_survey_start($message, $section, $num_sections, $has_required, $rid='', $blankquestionnaire=false) {
        global $CFG, $DB, $OUTPUT;
        require_once($CFG->libdir.'/filelib.php');
        $userid = '';
        $resp = '';
        $groupname = '';
        $timesubmitted = '';
        // Available group modes (0 = no groups; 1 = separate groups; 2 = visible groups).

        if ($rid) {
            $courseid = $this->course->id;
            if ($resp = $DB->get_record('questionnaire_response', array('id' => $rid)) ) {
                if ($this->respondenttype == 'fullname') {
                    $userid = $resp->username;
                    // Display name of group(s) that student belongs to... if questionnaire is set to Groups separate or visible.
                    if ($this->cm->groupmode > 0) {
                        if ($groups = groups_get_all_groups($courseid, $resp->username)) {
                            if (count($groups) == 1) {
                                $group = current($groups);
                                $groupname = ' ('.get_string('group').': '.$group->name.')';
                            } else {
                                $groupname = ' ('.get_string('groups').': ';
                                foreach ($groups as $group) {
                                    $groupname.= $group->name.', ';
                                }
                                $groupname = substr($groupname, 0, strlen($groupname) -2).')';
                            }
                        } else {
                            $groupname = ' ('.get_string('groupnonmembers').')';
                        }
                    }
                }
            }
        }
        $ruser = '';
        if ($resp && !$blankquestionnaire) {
            if ($userid) {
                if ($user = $DB->get_record('user', array('id' => $userid))) {
                    $ruser = fullname($user);
                }
            }
            if ($this->respondenttype == 'anonymous') {
                $ruser = '- '.get_string('anonymous', 'questionnaire').' -';
            } else {
                // JR DEV comment following line out if you do NOT want time submitted displayed in Anonymous surveys.
                if ($resp->submitted) {
                    $timesubmitted = '&nbsp;'.get_string('submitted', 'questionnaire').'&nbsp;'.userdate($resp->submitted);
                }
            }
        }
        if ($ruser) {
            echo (get_string('respondent', 'questionnaire').': <strong>'.$ruser.'</strong>');
            if ($this->survey->realm == 'public') {
                // For a public questionnaire, look for the course that used it.
                $coursename = '';
                $sql = 'SELECT q.id, q.course, c.fullname '.
                       'FROM {questionnaire} q, {questionnaire_attempts} qa, {course} c '.
                       'WHERE qa.rid = ? AND q.id = qa.qid AND c.id = q.course';
                if ($record = $DB->get_record_sql($sql, array($rid))) {
                    $coursename = $record->fullname;
                }
                echo (' '.get_string('course'). ': '.$coursename);
            }
            echo ($groupname);
            echo ($timesubmitted);
        }
        echo '<h3 class="surveyTitle">'.s($this->survey->title).'</h3>';

        // We don't want to display the print icon in the print popup window itself!
        if ($this->capabilities->printblank && $blankquestionnaire && $section == 1) {
            // Open print friendly as popup window.
            $image_url = $CFG->wwwroot.'/mod/questionnaire/images/';
            $linkname = '<img src="'.$image_url.'print.gif" alt="Printer-friendly version" />';
            $title = get_string('printblanktooltip', 'questionnaire');
            $url = '/mod/questionnaire/print.php?qid='.$this->id.'&amp;rid=0&amp;'.'courseid='.$this->course->id.'&amp;sec=1';
            $options = array('menubar' => true, 'location' => false, 'scrollbars' => true, 'resizable' => true,
                    'height' => 600, 'width' => 800, 'title'=>$title);
            $name = 'popup';
            $link = new moodle_url($url);
            $action = new popup_action('click', $link, $name, $options);
            $class = "floatprinticon";
            echo $OUTPUT->action_link($link, $linkname, $action, array('class'=>$class, 'title'=>$title));
        }
        if ($section == 1) {
            if ($this->survey->subtitle) {
                echo '<h4 class="surveySubtitle">'.(format_text($this->survey->subtitle, FORMAT_HTML)).'</h4>';
            }
            if ($this->survey->info) {
                $infotext = file_rewrite_pluginfile_urls($this->survey->info, 'pluginfile.php',
                                $this->context->id, 'mod_questionnaire', 'info', $this->survey->id);
                echo '<div class="addInfo">'.format_text($infotext, FORMAT_HTML).'</div>';
            }
        }
        if ($num_sections>1) {
            $a = new stdClass();
            $a->page = $section;
            $a->totpages = $num_sections;
            echo '<div class="surveyPage">&nbsp;'.get_string('pageof', 'questionnaire', $a).'</div>';
        }
        if ($message) {
            echo '<div class="message">'.$message.'</div>';
        }

    }

    private function print_survey_end($section, $num_sections) {
        if ($num_sections>1) {
            $a = new stdClass();
            $a->page = $section;
            $a->totpages = $num_sections;
            echo get_string('pageof', 'questionnaire', $a).'&nbsp;&nbsp;';
        }
    }

    // Blankquestionnaire : if we are printing a blank questionnaire.
    public function survey_print_render($message = '', $referer='', $courseid, $blankquestionnaire=false) {
        global $USER, $DB, $OUTPUT;
        $rid = optional_param('rid', 0, PARAM_INT);

        if (! $course = $DB->get_record("course", array("id" => $courseid))) {
            print_error('incorrectcourseid', 'questionnaire');
        }
        $this->course = $course;

        if ($this->resume && empty($rid)) {
            $rid = $this->get_response($USER->id, $rid);
        }

        if (!empty($rid)) {
            // If we're viewing a response, use this method.
            $this->view_response($rid, $blankquestionnaire);
            return;
        }

        if (empty($section)) {
            $section = 1;
        }

        if (isset($this->questionsbysec)) {
            $num_sections = count($this->questionsbysec);
        } else {
            $num_sections = 0;
        }

        if ($section > $num_sections) {
            return(false);  // Invalid section.
        }

        $has_required = $this->has_required();

        // Find out what question number we are on $i.
        $i = 1;
        for ($j = 2; $j<=$section; $j++) {
            $i += count($this->questionsbysec[$j-1]);
        }

        echo $OUTPUT->box_start();
        $this->print_survey_start($message, 1, 1, $has_required);
        // Print all sections.
        $formdata = new stdClass();
        if (data_submitted() && confirm_sesskey()) {
            $formdata = data_submitted();
        }
        foreach ($this->questionsbysec as $section) {
            foreach ($section as $question) {
                if ($question->type_id == QUESSECTIONTEXT) {
                    $i--;
                }
                $question->survey_display($formdata, $i++, $usehtmleditor=null, $blankquestionnaire);
            }
            if (!$blankquestionnaire) {
                echo (get_string('sectionbreak', 'questionnaire').'<br /><br />'); // Print on preview questionaire page only.
            }
        }
        // End of questions.
        echo $OUTPUT->box_end();
        return;
    }

    public function survey_update($sdata) {
        global $DB;

        $errstr = ''; // TODO: notused!

        // New survey.
        if (empty($this->survey->id)) {
            // Create a new survey in the database.
            $fields = array('name', 'realm', 'title', 'subtitle', 'email', 'theme', 'thanks_page', 'thank_head',
                            'thank_body', 'info');
            // Theme field deprecated.
            $record = new Object();
            $record->id = 0;
            $record->owner = $sdata->owner;
            foreach ($fields as $f) {
                if (isset($sdata->$f)) {
                    $record->$f = $sdata->$f;
                }
            }

            $this->survey = new stdClass();
            $this->survey->id = $DB->insert_record('questionnaire_survey', $record);
            $this->add_survey($this->survey->id);

            if (!$this->survey->id) {
                $errstr = get_string('errnewname', 'questionnaire') .' [ :  ]'; // TODO: notused!
                return(false);
            }
        } else {
            if (empty($sdata->name) || empty($sdata->title)
                    || empty($sdata->realm)) {
                return(false);
            }

            $fields = array('name', 'realm', 'title', 'subtitle', 'email', 'theme', 'thanks_page', 'thank_head',
                            'thank_body', 'info');  // Theme field deprecated.

            $name = $DB->get_field('questionnaire_survey', 'name', array('id' => $this->survey->id));

            // Trying to change survey name.
            if (trim($name) != trim(stripslashes($sdata->name))) {  // $sdata will already have slashes added to it.
                $count = $DB->count_records('questionnaire_survey', array('name' => $sdata->name));
                if ($count != 0) {
                    $errstr = get_string('errnewname', 'questionnaire');  // TODO: notused!
                    return(false);
                }
            }

            // UPDATE the row in the DB with current values.
            $survey_record = new Object();
            $survey_record->id = $this->survey->id;
            foreach ($fields as $f) {
                $survey_record->$f = trim($sdata->{$f});
            }

            $result = $DB->update_record('questionnaire_survey', $survey_record);
            if (!$result) {
                $errstr = get_string('warning', 'questionnaire').' [ :  ]';  // TODO: notused!
                return(false);
            }
        }

        return($this->survey->id);
    }

    /* Creates an editable copy of a survey. */
    public function survey_copy($owner) {
        global $DB;

        // Clear the sid, clear the creation date, change the name, and clear the status.
        // Since we're copying a data record, addslashes.
        // 2.0 - don't need to do this now, since its handled by the $DB-> functions.
        $survey = clone($this->survey);

        unset($survey->id);
        $survey->owner = $owner;
        // Make sure that the survey name is not larger than the field size (CONTRIB-2999). Leave room for extra chars.
        $survey->name = textlib::substr($survey->name, 0, (64-10));

        $survey->name .= '_copy';
        $survey->status = 0;

        // Check for 'name' conflict, and resolve.
        $i=0;
        $name = $survey->name;
        while ($DB->count_records('questionnaire_survey', array('name' => $name)) > 0) {
            $name = $survey->name.(++$i);
        }
        if ($i) {
            $survey->name .= $i;
        }

        // Create new survey.
        if (!($new_sid = $DB->insert_record('questionnaire_survey', $survey))) {
            return(false);
        }

        // Make copies of all the questions.
        $pos=1;
        foreach ($this->questions as $question) {
            // Fix some fields first.
            unset($question->id);
            $question->survey_id = $new_sid;
            $question->position = $pos++;
            $question->name = addslashes($question->name);
            $question->content = addslashes($question->content);

            // Copy question to new survey.
            if (!($new_qid = $DB->insert_record('questionnaire_question', $question))) {
                return(false);
            }

            foreach ($question->choices as $choice) {
                unset($choice->id);
                $choice->question_id = $new_qid;
                $choice->content = addslashes($choice->content);
                $choice->value = addslashes($choice->value);
                if (!$DB->insert_record('questionnaire_quest_choice', $choice)) {
                    return(false);
                }
            }
        }

        return($new_sid);
    }

    public function type_has_choices() {
        global $DB;

        $has_choices = array();

        if ($records = $DB->get_records('questionnaire_question_type', array(), 'typeid', 'typeid, has_choices')) {
            foreach ($records as $record) {
                if ($record->has_choices == 'y') {
                    $has_choices[$record->typeid]=1;
                } else {
                    $has_choices[$record->typeid]=0;
                }
            }
        } else {
            $has_choices = array();
        }

        return($has_choices);
    }

    private function array_to_insql($array) {
        if (count($array)) {
            return("IN (".preg_replace("/([^,]+)/", "'\\1'", join(",", $array)).")");
        }
        return 'IS NULL';
    }

    // RESPONSE LIBRARY.

    private function response_check_format($section, &$formdata, $qnum='') {
        $missing = 0;
        $strmissing = '';     // Missing questions.
        $wrongformat = 0;
        $strwrongformat = ''; // Wrongly formatted questions (Numeric, 5:Check Boxes, Date).
        $i = 1;
        for ($j = 2; $j<=$section; $j++) {
            // ADDED A SIMPLE LOOP FOR MAKING SURE PAGE BREAKS (type 99) AND LABELS (type 100) ARE NOT ALLOWED.
            foreach ($this->questionsbysec[$j-1] as $sectionrecord) {
                $tid = $sectionrecord->type_id;
                if ($tid < 99) {
                    $i++;
                }
            }
        }
        $qnum = $i - 1;

        foreach ($this->questionsbysec[$section] as $record) {

            $qid = $record->id;
            $tid = $record->type_id;
            $lid = $record->length;
            $pid = $record->precise;
            if ($tid != 100) {
                $qnum++;
            }
            if ( ($record->required == 'y') && ($record->deleted == 'n') && ((isset($formdata->{'q'.$qid})
                    && $formdata->{'q'.$qid} == '')
                    || (!isset($formdata->{'q'.$qid}))) && $tid != 8 && $tid != 100 ) {
                $missing++;
                $strmissing .= get_string('num', 'questionnaire').$qnum.'. ';
            }

            switch ($tid) {

                case 4: // Radio Buttons with !other field.
                    if (!isset($formdata->{'q'.$qid})) {
                        break;
                    }
                    $resp = $formdata->{'q'.$qid};
                    $pos = strpos($resp, 'other_');

                    // Other "other" choice is checked but text box is empty.
                    if (is_int($pos) == true) {
                        $othercontent = "q".$qid.substr($resp, 5);
                        if ( !$formdata->$othercontent ) {
                            $wrongformat++;
                            $strwrongformat .= get_string('num', 'questionnaire').$qnum.'. ';
                            break;
                        }
                    }

                    if (is_int($pos) == true && $record->required == 'y') {
                        $resp = 'q'.$qid.''.substr($resp, 5);
                        if (!$formdata->$resp) {
                            $missing++;
                            $strmissing .= get_string('num', 'questionnaire').$qnum.'. ';
                        }
                    }
                    break;

                case 5: // Check Boxes.
                    if (!isset($formdata->{'q'.$qid})) {
                        break;
                    }
                    $resps = $formdata->{'q'.$qid};
                    $nbrespchoices = 0;
                    foreach ($resps as $resp) {
                        $pos = strpos($resp, 'other_');

                        // Other "other" choice is checked but text box is empty.
                        if (is_int ($pos) == true) {
                            $othercontent = "q".$qid.substr($resp, 5);
                            if ( !$formdata->$othercontent ) {
                                $wrongformat++;
                                $strwrongformat .= get_string('num', 'questionnaire').$qnum.'. ';
                                break;
                            }
                        }

                        if (is_numeric($resp) || is_int($pos) == true) {
                            $nbrespchoices++;
                        }
                    }
                    $nbquestchoices = count($record->choices);
                    $min = $lid;
                    $max = $pid;
                    if ($max == 0) {
                        $max = $nbquestchoices;
                    }
                    if ($min > $max) {
                        $min = $max;     // Sanity check.
                    }
                    $min = min($nbquestchoices, $min);
                    // Number of ticked boxes is not within min and max set limits.
                    if ( $nbrespchoices && ($nbrespchoices < $min || $nbrespchoices > $max) ) {
                        $wrongformat++;
                        $strwrongformat .= get_string('num', 'questionnaire').$qnum.'. ';
                        break;
                    }
                    break;

                case 6: // Drop.
                    $resp = $formdata->{'q'.$qid};
                    if (!$resp && $record->required == 'y') {
                        $missing++;
                        $strmissing .= get_string('num', 'questionnaire').$qnum.'. ';
                    }
                    break;

                case 8: // Rate.
                    $num = 0;
                    $nbchoices = count($record->choices);
                    $na = get_string('notapplicable', 'questionnaire');
                    foreach ($record->choices as $cid => $choice) {
                        // In case we have named degrees on the Likert scale, count them to substract from nbchoices.
                        $nameddegrees = 0;
                        $content = $choice->content;
                        if (preg_match("/^[0-9]{1,3}=/", $content, $ndd)) {
                            $nameddegrees++;
                        } else {
                            $str = 'q'."{$record->id}_$cid";
                            if (isset($formdata->$str) && $formdata->$str == $na) {
                                $formdata->$str = -1;
                            }
                            for ($j = 0; $j < $record->length; $j++) {
                                $num += (isset($formdata->$str) && ($j == $formdata->$str));
                            }
                            $num += (($record->precise) && isset($formdata->$str) && ($formdata->$str == -1));
                        }
                        $nbchoices -= $nameddegrees;
                    }
                    if ( $num == 0 && $record->required == 'y') {
                        $missing++;
                        $strmissing .= get_string('num', 'questionnaire').$qnum.'. ';
                        break;
                    }
                    // If nodupes and nb choice restricted, nbchoices may be > actual choices, so limit it to $record->length.
                    $isrestricted = ($record->length < count($record->choices)) && $record->precise == 2;
                    if ($isrestricted) {
                        $nbchoices = min ($nbchoices, $record->length);
                    }
                    if ( $num != $nbchoices && $num!=0 ) {
                        $wrongformat++;
                        $strwrongformat .= get_string('num', 'questionnaire').$qnum.'. ';
                    }
                    break;

                case 9: // Date.
                    $checkdateresult = '';
                    if ($formdata->{'q'.$qid} != '') {
                        $checkdateresult = questionnaire_check_date($formdata->{'q'.$qid});
                    }
                    if (substr($checkdateresult, 0, 5) == 'wrong') {
                        $wrongformat++;
                        $strwrongformat .= get_string('num', 'questionnaire').$qnum.'. ';
                    }
                    break;

                case 10: // Numeric.
                    if ( ($formdata->{'q'.$qid} != '') && (!is_numeric($formdata->{'q'.$qid})) ) {
                        $wrongformat++;
                        $strwrongformat .= get_string('num', 'questionnaire').$qnum.'. ';
                    }
                    break;

                default:
                break;
            }
        }
        $message ='';
        if ($missing) {
            if ($missing == 1) {
                $message = get_string('missingquestion', 'questionnaire').$strmissing;
            } else {
                $message = get_string('missingquestions', 'questionnaire').$strmissing;
            }
            if ($wrongformat) {
                $message .= '<br />';
            }
        }
        if ($wrongformat) {
            if ($wrongformat == 1) {
                $message .= get_string('wrongformat', 'questionnaire').$strwrongformat;
            } else {
                $message .= get_string('wrongformats', 'questionnaire').$strwrongformat;
            }
        }
        return ($message);
    }


    private function response_delete($rid, $sec = null) {
        global $DB;

        if (empty($rid)) {
            return;
        }

        if ($sec != null) {
            if ($sec < 1) {
                return;
            }

            /* get question_id's in this section */
            $qids = '';
            foreach ($this->questionsbysec[$sec] as $question) {
                if (empty($qids)) {
                    $qids .= ' AND question_id IN ('.$question->id;
                } else {
                    $qids .= ','.$question->id;
                }
            }
            if (!empty($qids)) {
                $qids .= ')';
            } else {
                return;
            }
        } else {
            /* delete all */
            $qids = '';
        }

        /* delete values */
        $select = 'response_id = \''.$rid.'\' '.$qids;
        foreach (array('response_bool', 'resp_single', 'resp_multiple', 'response_rank', 'response_text',
                       'response_other', 'response_date') as $tbl) {
            $DB->delete_records_select('questionnaire_'.$tbl, $select);
        }
    }

    private function response_import_sec($rid, $sec, &$varr) {
        if ($sec < 1 || !isset($this->questionsbysec[$sec])) {
            return;
        }
        $vals = $this->response_select($rid, 'content');
        reset($vals);
        foreach ($vals as $id => $arr) {
            if (isset($arr[0]) && is_array($arr[0])) {
                // Multiple.
                $varr->{'q'.$id} = array_map('array_pop', $arr);
            } else {
                $varr->{'q'.$id} = array_pop($arr);
            }
        }
    }



    private function response_import_all($rid, &$varr) {
        $vals = $this->response_select($rid, 'content');
        reset($vals);
        foreach ($vals as $id => $arr) {
            if (strstr($id, '_') && isset($arr[4])) { // Single OR multiple with !other choice selected.
                $varr->{'q'.$id} = $arr[4];
            } else {
                if (isset($arr[0]) && is_array($arr[0])) { // Multiple.
                    $varr->{'q'.$id} = array_map('array_pop', $arr);
                } else { // Boolean, rate and other.
                    $varr->{'q'.$id} = array_pop($arr);
                }
            }
        }
    }

    private function response_commit($rid) {
        global $DB;

        $record = new object;
        $record->id = $rid;
        $record->complete = 'y';
        $record->submitted = time();

        if ($this->grade < 0) {
            $record->grade = 1;  // Don't know what to do if its a scale...
        } else {
            $record->grade = $this->grade;
        }
        return $DB->update_record('questionnaire_response', $record);
    }

    private function get_response($username, $rid = 0) {
        global $DB;

        $rid = intval($rid);
        if ($rid != 0) {
            // Check for valid rid.
            $fields = 'id, username';
            $select = 'id = '.$rid.' AND survey_id = '.$this->sid.' AND username = \''.$username.'\' AND complete = \'n\'';
            return ($DB->get_record_select('questionnaire_response', $select, null, $fields) !== false) ? $rid : '';

        } else {
            // Find latest in progress rid.
            $select = 'survey_id = '.$this->sid.' AND complete = \'n\' AND username = \''.$username.'\'';
            if ($records = $DB->get_records_select('questionnaire_response', $select, null, 'submitted DESC',
                                              'id,survey_id', 0, 1)) {
                $rec = reset($records);
                return $rec->id;
            } else {
                return '';
            }
        }
    }

    // Returns the number of the section in which questions have been answered in a response.
    private function response_select_max_sec($rid) {
        global $DB;

        $pos = $this->response_select_max_pos($rid);
        $select = 'survey_id = \''.$this->sid.'\' AND type_id = 99 AND position < '.$pos.' AND deleted = \'n\'';
        $max = $DB->count_records_select('questionnaire_question', $select) + 1;

        return $max;
    }

    // Returns the position of the last answered question in a response.
    private function response_select_max_pos($rid) {
        global $DB;

        $max = 0;

        foreach (array('response_bool', 'resp_single', 'resp_multiple', 'response_rank', 'response_text',
                       'response_other', 'response_date') as $tbl) {
            $sql = 'SELECT MAX(q.position) as num FROM {questionnaire_'.$tbl.'} a, {questionnaire_question} q '.
                   'WHERE a.response_id = ? AND '.
                   'q.id = a.question_id AND '.
                   'q.survey_id = ? AND '.
                   'q.deleted = \'n\'';
            if ($record = $DB->get_record_sql($sql, array($rid, $this->sid))) {
                $newmax = (int)$record->num;
                if ($newmax > $max) {
                    $max = $newmax;
                }
            }
        }
        return $max;
    }

    /* {{{ proto array response_select_name(int survey_id, int response_id, array question_ids)
       A wrapper around response_select(), that returns an array of
       key/value pairs using the field name as the key.
       $csvexport = true: a parameter to return a different response formatting for CSV export from normal report formatting
     */
    private function response_select_name($rid, $choicecodes, $choicetext) {
        $res = $this->response_select($rid, 'position, type_id, name', true, $choicecodes, $choicetext);
        $nam = array();
        reset($res);
        $subqnum = 0;
        $oldpos = '';
        while (list($qid, $arr) = each($res)) {
            // Question position (there may be "holes" in positions list).
            $qpos = $arr[0];
            // Question type (1:bool,2:text,3:essay,4:radio,5:check,6:dropdn,7:rating(not used),8:rate,9:date,10:numeric).
            $qtype = $arr[1];
            // Variable name; (may be empty); for rate questions: 'variable group' name.
            $qname = $arr[2];
            // Modality; for rate questions: variable.
            $qchoice = $arr[3];

            // Strip potential html tags from modality name.
            if (!empty($qchoice)) {
                $qchoice = strip_tags($arr[3]);
                $qchoice = preg_replace("/[\r\n\t]/", ' ', $qchoice);
            }
            // For rate questions: modality; for multichoice: selected = 1; not selected = 0.
            $q4 = '';
            if (isset($arr[4])) {
                $q4 = $arr[4];
            }
            if (strstr($qid, '_')) {
                if ($qtype == 4) {     // Single.
                    $nam[$qpos][$qname.'_'.get_string('other', 'questionnaire')] = $q4;
                    continue;
                }
                // Multiple OR rank.
                if ($oldpos != $qpos) {
                    $subqnum = 1;
                    $oldpos = $qpos;
                } else {
                        $subqnum++;
                }
                if ($qtype == 8) {     // Rate.
                    $qname .= "->$qchoice";
                    if ($q4 == -1) {
                        // Here $q4 = get_string('notapplicable', 'questionnaire'); DEV JR choose one solution please.
                        $q4 = '';
                    } else {
                        if (is_numeric($q4)) {
                            $q4++;
                        }
                    }
                } else {     // Multiple.
                    $qname .= "->$qchoice";
                }
                $nam[$qpos][$qname] = $q4;
                continue;
            }
            $val = $qchoice;
            $nam[$qpos][$qname] = $val;
        }
        return $nam;
    }

    private function response_send_email($rid, $userid=false) {
        global $CFG, $USER, $DB;

        require_once($CFG->libdir.'/phpmailer/class.phpmailer.php');

        $name = s($this->name);
        if ($record = $DB->get_record('questionnaire_survey', array('id' => $this->survey->id))) {
            $email = $record->email;
        } else {
            $email = '';
        }

        if (empty($email)) {
            return(false);
        }
        $answers = $this->generate_csv($rid, $userid='', null, 1);

        // Line endings for html and plaintext emails.
        $end_html = "\r\n<br>";
        $end_plaintext = "\r\n";

        $subject = get_string('surveyresponse', 'questionnaire') .": $name [$rid]";
        $url = $CFG->wwwroot.'/mod/questionnaire/report.php?action=vresp&amp;sid='.$this->survey->id.
                '&amp;rid='.$rid.'&amp;instance='.$this->id;

        // Html and plaintext body.
        $body_html        = '<a href="'.$url.'">'.$url.'</a>'.$end_html;
        $body_plaintext   = $url.$end_plaintext;
        $body_html       .= get_string('surveyresponse', 'questionnaire') .' "'.$name.'"'.$end_html;
        $body_plaintext  .= get_string('surveyresponse', 'questionnaire') .' "'.$name.'"'.$end_plaintext;

        reset($answers);

        for ($i = 0; $i < count($answers[0]); $i++) {
            $sep = ' : ';

            switch($i) {
                case 1:
                    $sep = ' ';
                    break;
                case 4:
                    $body_html        .= get_string('user').' ';
                    $body_plaintext   .= get_string('user').' ';
                    break;
                case 6:
                    if ($this->respondenttype != 'anonymous') {
                        $body_html         .= get_string('email').$sep.$USER->email. $end_html;
                        $body_plaintext    .= get_string('email').$sep.$USER->email. $end_plaintext;
                    }
            }
            $body_html         .= $answers[0][$i].$sep.$answers[1][$i]. $end_html;
            $body_plaintext    .= $answers[0][$i].$sep.$answers[1][$i]. $end_plaintext;
        }

        // Use plaintext version for altbody.
        $altbody =  "\n$body_plaintext\n";

        $return = true;
        $mailaddresses = preg_split('/,|;/', $email);
        foreach ($mailaddresses as $email) {
            $userto = new Object();
            $userto->email = $email;
            $userto->mailformat = 1;
            $userfrom = $CFG->noreplyaddress;
            if (email_to_user($userto, $userfrom, $subject, $altbody, $body_html)) {
                $return = $return && true;
            } else {
                $return = false;
            }
        }
        return $return;
    }

    private function response_insert($sid, $section, $rid, $userid, $resume=false) {
        global $DB, $USER;

        $record = new object;
        $record->submitted = time();

        if (empty($rid)) {
            // Create a uniqe id for this response.
            $record->survey_id = $sid;
            $record->username = $userid;
            $rid = $DB->insert_record('questionnaire_response', $record);
        } else {
            $record->id = $rid;
            $DB->update_record('questionnaire_response', $record);
        }
        if ($resume) {
            add_to_log($this->course->id, "questionnaire", "save", "view.php?id={$this->cm->id}",
                "{$this->name}", $this->cm->id, $USER->id);
        }

        if (!empty($this->questionsbysec[$section])) {
            foreach ($this->questionsbysec[$section] as $question) {
                $question->insert_response($rid);
            }
        }
        return($rid);
    }

    private function response_select($rid, $col = null, $csvexport = false, $choicecodes=0, $choicetext=1) {
        global $DB;

        $sid = $this->survey->id;
        $values = array();
        $stringother = get_string('other', 'questionnaire');
        if ($col == null) {
            $col = '';
        }
        if (!is_array($col) && !empty($col)) {
            $col = explode(',', preg_replace("/\s/", '', $col));
        }
        if (is_array($col) && count($col) > 0) {
            $col = ',' . implode(',', array_map(create_function('$a', 'return "q.$a";'), $col));
        }

        // Response_bool (yes/no).
        $sql = 'SELECT q.id '.$col.', a.choice_id '.
               'FROM {questionnaire_response_bool} a, {questionnaire_question} q '.
               'WHERE a.response_id= ? AND a.question_id=q.id ';
        if ($records = $DB->get_records_sql($sql, array($rid))) {
            foreach ($records as $qid => $row) {
                $choice = $row->choice_id;
                if (isset ($row->name) && $row->name == '') {
                    $noname = true;
                }
                unset ($row->id);
                unset ($row->choice_id);
                $row = (array)$row;
                $newrow = array();
                foreach ($row as $key => $val) {
                    if (!is_numeric($key)) {
                        $newrow[] = $val;
                    }
                }
                $values[$qid] = $newrow;
                array_push($values["$qid"], ($choice == 'y') ? '1' : '0');
                if (!$csvexport) {
                    array_push($values["$qid"], $choice); // DEV still needed for responses display.
                }
            }
        }

        // Response_single (radio button or dropdown).
        $sql = 'SELECT q.id '.$col.', q.type_id as q_type, c.content as ccontent,c.id as cid '.
               'FROM {questionnaire_resp_single} a, {questionnaire_question} q, {questionnaire_quest_choice} c '.
               'WHERE a.response_id = ? AND a.question_id=q.id AND a.choice_id=c.id ';
        if ($records = $DB->get_records_sql($sql, array($rid))) {
            foreach ($records as $qid => $row) {
                $cid = $row->cid;
                $qtype = $row->q_type;
                if ($csvexport) {
                    static $i = 1;
                    $qrecords = $DB->get_records('questionnaire_quest_choice', array('question_id' => $qid));
                    foreach ($qrecords as $value) {
                        if ($value->id == $cid) {
                            $contents = questionnaire_choice_values($value->content);
                            if ($contents->modname) {
                                $row->ccontent = $contents->modname;
                            } else {
                                $content = $contents->text;
                                if (preg_match('/^!other/', $content)) {
                                    $row->ccontent = get_string('other', 'questionnaire');
                                } else if (($choicecodes == 1) && ($choicetext == 1)) {
                                    $row->ccontent = "$i : $content";
                                } else if ($choicecodes == 1) {
                                    $row->ccontent = "$i";
                                } else {
                                    $row->ccontent = $content;
                                }
                            }
                            $i = 1;
                            break;
                        }
                        $i++;
                    }
                }
                unset($row->id);
                unset($row->cid);
                unset($row->q_type);
                $arow = get_object_vars($row);
                $newrow = array();
                foreach ($arow as $key => $val) {
                    if (!is_numeric($key)) {
                        $newrow[] = $val;
                    }
                }
                if (preg_match('/^!other/', $row->ccontent)) {
                    $newrow[] = 'other_' . $cid;
                } else {
                    $newrow[] = (int)$cid;
                }
                $values[$qid] = $newrow;
            }
        }

        // Response_multiple.
        $sql = 'SELECT a.id as aid, q.id as qid '.$col.',c.content as ccontent,c.id as cid '.
               'FROM {questionnaire_resp_multiple} a, {questionnaire_question} q, {questionnaire_quest_choice} c '.
               'WHERE a.response_id = ? AND a.question_id=q.id AND a.choice_id=c.id '.
               'ORDER BY a.id,a.question_id,c.id';
        $records = $DB->get_records_sql($sql, array($rid));
        if ($csvexport) {
            $tmp = null;
            if (!empty($records)) {
                $qids2 = array();
                $oldqid = '';
                foreach ($records as $qid => $row) {
                    if ($row->qid != $oldqid) {
                        $qids2[] = $row->qid;
                        $oldqid = $row->qid;
                    }
                }
                if (is_array($qids2)) {
                    $qids2 = 'question_id ' . $this->array_to_insql($qids2);
                } else {
                    $qids2 = 'question_id= ' . $qids2;
                }
                $sql = 'SELECT * FROM {questionnaire_quest_choice} WHERE '.$qids2.
                    'ORDER BY id';
                if ($records2 = $DB->get_records_sql($sql)) {
                    foreach ($records2 as $qid => $row2) {
                        $selected = '0';
                        $qid2 = $row2->question_id;
                        $cid2 = $row2->id;
                        $c2 = $row2->content;
                        $otherend = false;
                        if ($c2 == '!other') {
                            $c2 = '!other='.get_string('other', 'questionnaire');
                        }
                        if (preg_match('/^!other/', $c2)) {
                            $otherend = true;
                        } else {
                            $contents = questionnaire_choice_values($c2);
                            if ($contents->modname) {
                                $c2 = $contents->modname;
                            } else if ($contents->title) {
                                $c2 = $contents->title;
                            }
                        }
                        $sql = 'SELECT a.name as name, a.type_id as q_type, a.position as pos ' .
                                'FROM {questionnaire_question} a WHERE id = ?';
                        if ($currentquestion = $DB->get_records_sql($sql, array($qid2))) {
                            foreach ($currentquestion as $question) {
                                $name1 = $question->name;
                                $type1 = $question->q_type;
                            }
                        }
                        $newrow = array();
                        foreach ($records as $qid => $row1) {
                            $qid1 = $row1->qid;
                            $cid1 = $row1->cid;
                            // If available choice has been selected by student.
                            if ($qid1 == $qid2 && $cid1 == $cid2) {
                                $selected = '1';
                            }
                        }
                        if ($otherend) {
                            $newrow2 = array();
                            $newrow2[] = $question->pos;
                            $newrow2[] = $type1;
                            $newrow2[] = $name1;
                            $newrow2[] = '['.get_string('other', 'questionnaire').']';
                            $newrow2[] = $selected;
                            $tmp2 = $qid2.'_other';
                            $values["$tmp2"]=$newrow2;
                        }
                        $newrow[] = $question->pos;
                        $newrow[] = $type1;
                        $newrow[] = $name1;
                        $newrow[] = $c2;
                        $newrow[] = $selected;
                        $tmp = $qid2.'_'.$cid2;
                        $values["$tmp"]=$newrow;
                    }
                }
            }
            unset($tmp);
            unset($row);

        } else {
                $arr = array();
                $tmp = null;
            if (!empty($records)) {
                foreach ($records as $aid => $row) {
                    $qid = $row->qid;
                    $cid = $row->cid;
                    unset($row->aid);
                    unset($row->qid);
                    unset($row->cid);
                    $arow = get_object_vars($row);
                    $newrow = array();
                    foreach ($arow as $key => $val) {
                        if (!is_numeric($key)) {
                            $newrow[] = $val;
                        }
                    }
                    if (preg_match('/^!other/', $row->ccontent)) {
                        $newrow[] = 'other_' . $cid;
                    } else {
                        $newrow[] = (int)$cid;
                    }
                    if ($tmp == $qid) {
                        $arr[] = $newrow;
                        continue;
                    }
                    if ($tmp != null) {
                        $values["$tmp"]=$arr;
                    }
                    $tmp = $qid;
                    $arr = array($newrow);
                }
            }
            if ($tmp != null) {
                $values["$tmp"]=$arr;
            }
            unset($arr);
            unset($tmp);
            unset($row);
        }

            // Response_other.
            // This will work even for multiple !other fields within one question
            // AND for identical !other responses in different questions JR.
        $sql = 'SELECT c.id as cid, c.content as content, a.response as aresponse, q.id as qid, q.position as position,
                                    q.type_id as type_id, q.name as name '.
               'FROM {questionnaire_response_other} a, {questionnaire_question} q, {questionnaire_quest_choice} c '.
               'WHERE a.response_id= ? AND a.question_id=q.id AND a.choice_id=c.id '.
               'ORDER BY a.question_id,c.id ';
        if ($records = $DB->get_records_sql($sql, array($rid))) {
            foreach ($records as $record) {
                $newrow = array();
                $position = $record->position;
                $type_id = $record->type_id;
                $name = $record->name;
                $cid = $record->cid;
                $qid = $record->qid;
                $content = $record->content;

                // The !other modality with no label.
                if ($content == '!other') {
                    $content = '!other='.$stringother;
                }
                $content = substr($content, 7);
                $aresponse = $record->aresponse;
                // The first two empty values are needed for compatibility with "normal" (non !other) responses.
                // They are only needed for the CSV export, in fact.
                $newrow[] = $position;
                $newrow[] = $type_id;
                $newrow[] = $name;
                $content = $stringother;
                $newrow[] = $content;
                $newrow[] = $aresponse;
                $values["${qid}_${cid}"] = $newrow;
            }
        }

        // Response_rank.
        $sql = 'SELECT a.id as aid, q.id AS qid, q.precise AS precise, c.id AS cid '.$col.', c.content as ccontent,
                                a.rank as arank '.
               'FROM {questionnaire_response_rank} a, {questionnaire_question} q, {questionnaire_quest_choice} c '.
               'WHERE a.response_id= ? AND a.question_id=q.id AND a.choice_id=c.id '.
               'ORDER BY aid, a.question_id, c.id';
        if ($records = $DB->get_records_sql($sql, array($rid))) {
            foreach ($records as $row) {
                // Next two are 'qid' and 'cid', each with numeric and hash keys.
                $osgood = false;
                if ($row->precise == 3) {
                    $osgood = true;
                }
                $qid = $row->qid.'_'.$row->cid;
                unset($row->aid); // Get rid of the answer id.
                unset($row->qid);
                unset($row->cid);
                unset($row->precise);
                $row = (array)$row;
                $newrow = array();
                foreach ($row as $key => $val) {
                    if ($key != 'content') { // No need to keep question text - ony keep choice text and rank.
                        if ($key == 'ccontent') {
                            if ($osgood) {
                                list($contentleft, $contentright) = preg_split('/[|]/', $val);
                                $contents = questionnaire_choice_values($contentleft);
                                if ($contents->title) {
                                    $contentleft = $contents->title;
                                }
                                $contents = questionnaire_choice_values($contentright);
                                if ($contents->title) {
                                    $contentright = $contents->title;
                                }
                                $val = strip_tags($contentleft.'|'.$contentright);
                                $val = preg_replace("/[\r\n\t]/", ' ', $val);
                            } else {
                                $contents = questionnaire_choice_values($val);
                                if ($contents->modname) {
                                    $val = $contents->modname;
                                } else if ($contents->title) {
                                    $val = $contents->title;
                                } else if ($contents->text) {
                                    $val = strip_tags($contents->text);
                                    $val = preg_replace("/[\r\n\t]/", ' ', $val);
                                }
                            }
                        }
                        $newrow[] = $val;
                    }
                }
                $values[$qid] = $newrow;
            }
        }

        // Response_text.
        $sql = 'SELECT q.id '.$col.', a.response as aresponse '.
               'FROM {questionnaire_response_text} a, {questionnaire_question} q '.
               'WHERE a.response_id=\''.$rid.'\' AND a.question_id=q.id ';
        if ($records = $DB->get_records_sql($sql)) {
            foreach ($records as $qid => $row) {
                unset($row->id);
                $row = (array)$row;
                $newrow = array();
                foreach ($row as $key => $val) {
                    if (!is_numeric($key)) {
                        $newrow[] = $val;
                    }
                }
                $values["$qid"]=$newrow;
                $val = array_pop($values["$qid"]);
                array_push($values["$qid"], $val, $val);
            }
        }

        // Response_date.
        $sql = 'SELECT q.id '.$col.', a.response as aresponse '.
               'FROM {questionnaire_response_date} a, {questionnaire_question} q '.
               'WHERE a.response_id=\''.$rid.'\' AND a.question_id=q.id ';
        if ($records = $DB->get_records_sql($sql)) {
            $dateformat = get_string('strfdate', 'questionnaire');
            foreach ($records as $qid => $row) {
                unset ($row->id);
                $row = (array)$row;
                $newrow = array();
                foreach ($row as $key => $val) {
                    if (!is_numeric($key)) {
                        $newrow[] = $val;
                        // Convert date from yyyy-mm-dd database format to actual questionnaire dateformat.
                        // does not work with dates prior to 1900 under Windows.
                        if (preg_match('/\d\d\d\d-\d\d-\d\d/', $val)) {
                            $dateparts = preg_split('/-/', $val);
                            $val = make_timestamp($dateparts[0], $dateparts[1], $dateparts[2]); // Unix timestamp.
                            $val = userdate ( $val, $dateformat);
                            $newrow[] = $val;
                        }
                    }
                }
                $values["$qid"]=$newrow;
                $val = array_pop($values["$qid"]);
                array_push($values["$qid"], '', '', $val);
            }
        }
        return($values);
    }

    private function response_goto_thankyou() {
        global $CFG, $USER, $DB;

        $select = 'id = '.$this->survey->id;
        $fields = 'thanks_page, thank_head, thank_body';
        if ($result = $DB->get_record_select('questionnaire_survey', $select, null, $fields)) {
            $thank_url = $result->thanks_page;
            $thank_head = $result->thank_head;
            $thank_body = $result->thank_body;
        } else {
            $thank_url = '';
            $thank_head = '';
            $thank_body = '';
        }
        if (!empty($thank_url)) {
            if (!headers_sent()) {
                header("Location: $thank_url");
                exit;
            }
            echo '
                <script language="JavaScript" type="text/javascript">
                <!--
                window.location="'.$thank_url.'"
                //-->
                </script>
                <noscript>
                <h2 class="thankhead">Thank You for completing this survey.</h2>
                <blockquote class="thankbody">Please click
                <a href="'.$thank_url.'">here</a> to continue.</blockquote>
                </noscript>
            ';
            exit;
        }
        if (empty($thank_head)) {
            $thank_head = get_string('thank_head', 'questionnaire');
        }
        $message =  '<h3>'.$thank_head.'</h3>'.file_rewrite_pluginfile_urls(format_text($thank_body, FORMAT_HTML), 'pluginfile.php',
                $this->context->id, 'mod_questionnaire', 'thankbody', $this->id);
        echo ($message);
        if ($this->capabilities->readownresponses) {
            echo('<a href="'.$CFG->wwwroot.'/mod/questionnaire/myreport.php?id='.
            $this->cm->id.'&amp;instance='.$this->cm->instance.'&amp;user='.$USER->id.'">'.
            get_string("continue").'</a>');
        } else {
            echo('<a href="'.$CFG->wwwroot.'/course/view.php?id='.$this->course->id.'">'.
            get_string("continue").'</a>');
        }
        return;
    }

    private function response_goto_saved($url) {
        global $CFG;
        $resumesurvey =  get_string('resumesurvey', 'questionnaire');
        $savedprogress = get_string('savedprogress', 'questionnaire', '<strong>'.$resumesurvey.'</strong>');

        echo '
                <div class="thankbody">'.$savedprogress.'</div>
                <div class="homelink"><a href="'.$CFG->wwwroot.'/course/view.php?id='.$this->course->id.'">&nbsp;&nbsp;'
                    .get_string("backto", "moodle", $this->course->fullname).'&nbsp;&nbsp;</a></div>
             ';
        return;
    }

    // Survey Results Methods.

    public function survey_results_navbar($curr_rid, $userid=false) {
        global $CFG, $DB;

        $stranonymous = get_string('anonymous', 'questionnaire');

        $select = 'survey_id='.$this->survey->id.' AND complete = \'y\'';
        if ($userid !== false) {
            $select .= ' AND username = \''.$userid.'\'';
        }
        if (!($responses = $DB->get_records_select('questionnaire_response', $select, null,
                'id', 'id, survey_id, submitted, username'))) {
            return;
        }
        $total = count($responses);
        if ($total == 1) {
            return;
        }
        $rids = array();
        $ridssub = array();
        $ridsusername = array();
        $i = 0;
        $curr_pos = -1;
        foreach ($responses as $response) {
            array_push($rids, $response->id);
            array_push($ridssub, $response->submitted);
            array_push($ridsusername, $response->username);
            if ($response->id == $curr_rid) {
                $curr_pos = $i;
            }
            $i++;
        }

        $prev_rid = ($curr_pos > 0) ? $rids[$curr_pos - 1] : null;
        $next_rid = ($curr_pos < $total - 1) ? $rids[$curr_pos + 1] : null;
        $rows_per_page = 1;
        $pages = ceil($total / $rows_per_page);

        $url = $CFG->wwwroot.'/mod/questionnaire/report.php?action=vresp&amp;sid='.$this->survey->id;

        $mlink = create_function('$i,$r', 'return "<a href=\"'.$url.'&amp;rid=$r\">$i</a>";');

        $linkarr = array();

        $display_pos = 1;
        if ($prev_rid != null) {
            array_push($linkarr, "<a href=\"$url&amp;rid=$prev_rid\">".get_string('previous').'</a>');
        }
        $ruser = '';
        for ($i = 0; $i < $curr_pos; $i++) {
            if ($this->respondenttype != 'anonymous') {
                if ($user = $DB->get_record('user', array('id' => $ridsusername[$i]))) {
                    $ruser = fullname($user);
                }
            } else {
                $ruser = $stranonymous;
            }
            $title = userdate($ridssub[$i]).' | ' .$ruser;
            array_push($linkarr, '<a href="'.$url.'&amp;rid='.$rids[$i].'" title="'.$title.'">'.$display_pos.'</a>');
            $display_pos++;
        }
        array_push($linkarr, '<b>'.$display_pos.'</b>');
        for (++$i; $i < $total; $i++) {
            if ($this->respondenttype != 'anonymous') {
                if ($user = $DB->get_record('user', array('id' => $ridsusername[$i]))) {
                    $ruser = fullname($user);
                }
            } else {
                $ruser = $stranonymous;
            }
            $title = userdate($ridssub[$i]).' | ' .$ruser;
            $display_pos++;
            array_push($linkarr, '<a href="'.$url.'&amp;rid='.$rids[$i].'" title="'.$title.'">'.$display_pos.'</a>');

        }
        if ($next_rid != null) {
            array_push($linkarr, "<a href=\"$url&amp;rid=$next_rid\">".get_string('next').'</a>');
        }
        echo implode(' | ', $linkarr);
    }

    public function survey_results_navbar_alpha($curr_rid, $groupid, $cm, $byresponse) {
        global $CFG, $DB, $OUTPUT;
        $selectgroupid ='';
        $gmuserid = ', GM.userid ';
        $groupmembers = ', '.$CFG->prefix.'groups_members GM ';
        switch ($groupid) {
            case 0:     // No groups.
            case -1:     // All participants.
            case -3:     // Not members of any group.
                $gmuserid = '';
                $groupmembers = '';
                break;
            case -2:     // All members of any group.
                $selectgroupid = ' AND GM.groupid>0 AND R.username = GM.userid ';
                break;
            default:     // Members of a specific group.
                $selectgroupid = ' AND GM.groupid='.$groupid.' AND R.username = GM.userid ';
        }
        $castsql = $DB->sql_cast_char2int('R.username');
        $sql = 'SELECT R.id AS responseid, R.submitted AS submitted, R.username, U.username AS username,
                        U.id AS user, U.lastname, U.firstname '.$gmuserid.
        'FROM '.$CFG->prefix.'questionnaire_response R,
        '.$CFG->prefix.'user U
        '.$groupmembers.
        'WHERE R.survey_id='.$this->survey->id.
        ' AND complete = \'y\''.
        ' AND U.id = '.$castsql.
        $selectgroupid.
        'ORDER BY U.lastname, U.firstname, R.submitted DESC';
        if (!$responses = $DB->get_records_sql($sql)) {
            return;
        }
        if ($groupid == -3) {     // Not members of any group.
            foreach ($responses as $resp => $key) {
                $userid = $key->user;
                if (groups_has_membership($cm, $userid)) {
                    unset($responses[$resp]);
                }
            }
        }
        $total = count($responses);
        if ($total === 0) {
            return;
        }
        $rids = array();
        $ridssub = array();
        $ridsusername = array();
        $ridsfirstname = array();
        $ridslastname = array();
        $i = 0;
        $curr_pos = -1;
        foreach ($responses as $response) {
            array_push($rids, $response->responseid);
            array_push($ridssub, $response->submitted);
            array_push($ridsusername, $response->username);
            array_push($ridsfirstname, $response->firstname);
            array_push($ridslastname, $response->lastname);
            if ($response->responseid == $curr_rid) {
                $curr_pos = $i;
            }
            $i++;
        }

        $url = $CFG->wwwroot.'/mod/questionnaire/report.php?action=vresp&amp;sid='.$this->survey->id.'&currentgroupid='.$groupid;
        $linkarr = array();
        if (!$byresponse) {     // Display navbar.
            // Build navbar.
            $prev_rid = ($curr_pos > 0) ? $rids[$curr_pos - 1] : null;
            $next_rid = ($curr_pos < $total - 1) ? $rids[$curr_pos + 1] : null;
            $first_rid = $rids[0];
            $last_rid = $rids[$total - 1];
            $display_pos = 1;
            if ($prev_rid != null) {
                $pos = $curr_pos - 1;
                $userfullname = $ridsfirstname[$pos].' '.$ridslastname[$pos];
                $responsedate = userdate($ridssub[$pos]);
                $title = $userfullname;
                // Only add date if more than one response by a student.
                if ($ridsusername[$pos] == $ridsusername[$curr_pos]) {
                    $title .= ' | '.$responsedate;
                }
                $firstuserfullname = $ridsfirstname[0].' '.$ridslastname[0];
                array_push($linkarr, '<b><<</b> <a href="'.$url.'&amp;rid='.$first_rid.'&amp;individualresponse=1" title="'.
                                $firstuserfullname.'">'.
                                get_string('firstrespondent', 'questionnaire').'</a>');
                array_push($linkarr, '<b><&nbsp;</b><a href="'.$url.'&amp;rid='.$prev_rid.'&amp;individualresponse=1"
                                title="'.$title.'">'.get_string('previous').'</a>');
            }
            array_push($linkarr, '<b>'.($curr_pos + 1).' / '.$total.'</b>');
            if ($next_rid != null) {
                $pos = $curr_pos + 1;
                $userfullname = $ridsfirstname[$pos].' '.$ridslastname[$pos];
                $responsedate = userdate($ridssub[$pos]);
                $title = $userfullname;
                // Only add date if more than one response by a student.
                if ($ridsusername[$pos] == $ridsusername[$curr_pos]) {
                    $title .= ' | '.$responsedate;
                }
                $lastuserfullname = $ridsfirstname[$total - 1].' '.$ridslastname[$total - 1];
                array_push($linkarr, '<a href="'.$url.'&amp;rid='.$next_rid.'&amp;individualresponse=1"
                                title="'.$title.'">'.get_string('next').'</a>&nbsp;<b>></b>');
                array_push($linkarr, '<a href="'.$url.'&amp;rid='.$last_rid.'&amp;individualresponse=1"
                                title="'.$lastuserfullname .'">'.
                                get_string('lastrespondent', 'questionnaire').'</a>&nbsp;<b>>></b>');
            }
            $url = $CFG->wwwroot.'/mod/questionnaire/report.php?action=vresp&amp;sid='.$this->survey->id.'&byresponse=1';
            // Display navbar.
            echo $OUTPUT->box_start('respondentsnavbar');
            echo implode(' | ', $linkarr);
            echo '<br /><b><<< <a href="'.$url.'">'.get_string('viewbyresponse', 'questionnaire').'</a></b>';
            echo $OUTPUT->box_end();
        } else { // Display respondents list.
            $userfullname = '';
            for ($i = 0; $i < $total; $i++) {
                $userfullname = $ridsfirstname[$i].' '.$ridslastname[$i];
                $responsedate = userdate($ridssub[$i]);
                array_push($linkarr, '<a title = "'.$responsedate.'" href="'.$url.'&amp;rid='.
                    $rids[$i].'&amp;individualresponse=1" >'.$userfullname.'</a>'.'&nbsp;');
            }
            // Table formatting from http://wikkawiki.org/PageAndCategoryDivisionInACategory.
            $total = count($linkarr);
            $entries = count($linkarr);
            // Default max 3 columns, max 25 lines per column.
            // TODO make this setting customizable.
            $maxlines = 20;
            $maxcols = 3;
            if ($entries >= $maxlines) {
                $colnumber = min (intval($entries / $maxlines), $maxcols);
            } else {
                $colnumber = 1;
            }
            $lines = 0;
            $a = 0;
            $str = '';
            // How many lines with an entry in every column do we have?
            while ($entries / $colnumber > 1) {
                $lines++;
                $entries = $entries - $colnumber;
            }
            // Prepare output.
            for ($i=0; $i<$colnumber; $i++) {
                $str .= '<div id="respondentscolumn">'."\n";
                for ($j=0; $j<$lines; $j++) {
                    $str .= $linkarr[$a].'<br />'."\n";
                    $a++;
                }
                // The rest of the entries (less than the number of cols).
                if ($entries) {
                    $str .= $linkarr[$a].'<br />'."\n";
                    $entries--;
                    $a++;
                }
                $str .= "</div>\n";
            }
            $str .= '<div style="clear: both;">'."</div>\n";
            echo $OUTPUT->box_start();
            echo ($str);
            echo $OUTPUT->box_end();
        }
    }

    public function survey_results_navbar_student($curr_rid, $userid, $instance, $resps, $reporttype='myreport', $sid='') {
        global $DB;

        $stranonymous = get_string('anonymous', 'questionnaire');

        $total = count($resps);
        $rids = array();
        $ridssub = array();
        $ridsusers = array();
        $i = 0;
        $curr_pos = -1;
        $title = '';
        foreach ($resps as $response) {
            array_push($rids, $response->id);
            array_push($ridssub, $response->submitted);
            $ruser = '';
            if ($reporttype == 'report') {
                if ($this->respondenttype != 'anonymous') {
                    if ($user = $DB->get_record('user', array('id' => $response->username))) {
                        $ruser = ' | ' .fullname($user);
                    }
                } else {
                    $ruser = ' | ' . $stranonymous;
                }
            }
            array_push($ridsusers, $ruser);
            if ($response->id == $curr_rid) {
                $curr_pos = $i;
            }
            $i++;
        }
        $prev_rid = ($curr_pos > 0) ? $rids[$curr_pos - 1] : null;
        $next_rid = ($curr_pos < $total - 1) ? $rids[$curr_pos + 1] : null;
        $rows_per_page = 1;
        $pages = ceil($total / $rows_per_page);

        if ($reporttype == 'myreport') {
            $url = 'myreport.php?instance='.$instance.'&amp;user='.$userid.'&amp;action=vresp';
        } else {
            $url = 'report.php?instance='.$instance.'&amp;user='.$userid.'&amp;action=vresp&amp;byresponse=1&amp;sid='.$sid;
        }
        $linkarr = array();
        $display_pos = 1;
        if ($prev_rid != null) {
            $title = userdate($ridssub[$curr_pos - 1].$ridsusers[$curr_pos - 1]);
            array_push($linkarr, '<a href="'.$url.'&amp;rid='.$prev_rid.'" title="'.$title.'">'.get_string('previous').'</a>');
        }
        for ($i = 0; $i < $curr_pos; $i++) {
            $title = userdate($ridssub[$i]).$ridsusers[$i];
            array_push($linkarr, '<a href="'.$url.'&amp;rid='.$rids[$i].'" title="'.$title.'">'.$display_pos.'</a>');
            $display_pos++;
        }
        array_push($linkarr, '<b>'.$display_pos.'</b>');
        for (++$i; $i < $total; $i++) {
            $display_pos++;
            $title = userdate($ridssub[$i]).$ridsusers[$i];
            array_push($linkarr, '<a href="'.$url.'&amp;rid='.$rids[$i].'" title="'.$title.'">'.$display_pos.'</a>');
        }
        if ($next_rid != null) {
            $title = userdate($ridssub[$curr_pos + 1]).$ridsusers[$curr_pos + 1];
            array_push($linkarr, '<a href="'.$url.'&amp;rid='.$next_rid.'" title="'.$title.'">'.get_string('next').'</a>');
        }
        echo implode(' | ', $linkarr);
    }

    /* {{{ proto string survey_results(int survey_id, int precision, bool show_totals, int question_id,
     * array choice_ids, int response_id)
        Builds HTML for the results for the survey. If a
        question id and choice id(s) are given, then the results
        are only calculated for respodants who chose from the
        choice ids for the given question id.
        Returns empty string on sucess, else returns an error
        string. */

    public function survey_results($precision = 1, $showtotals = 1, $qid = '', $cids = '', $rid = '',
                $uid=false, $groupid='', $sort='') {
        global $SESSION, $DB;

        $SESSION->questionnaire->noresponses = false;
        if (empty($precision)) {
            $precision  = 1;
        }
        if ($showtotals === '') {
            $showtotals = 1;
        }

        if (is_int($cids)) {
            $cids = array($cids);
        }
        if (is_string($cids)) {
            $cids = preg_split("/ /", $cids); // Turn space seperated list into array.
        }

        // Build associative array holding whether each question
        // type has answer choices or not and the table the answers are in
        // TO DO - FIX BELOW TO USE STANDARD FUNCTIONS.
        $has_choices = array();
        $response_table = array();
        if (!($types = $DB->get_records('questionnaire_question_type', array(), 'typeid', 'typeid, has_choices, response_table'))) {
            $errmsg = sprintf('%s [ %s: question_type ]',
                    get_string('errortable', 'questionnaire'), 'Table');
            return($errmsg);
        }
        foreach ($types as $type) {
            $has_choices[$type->typeid]=$type->has_choices; // TODO is that variable actually used?
            $response_table[$type->typeid]=$type->response_table;
        }

        // Load survey title (and other globals).
        if (empty($this->survey)) {
            $errmsg = get_string('erroropening', 'questionnaire') ." [ ID:${sid} R:";
            return($errmsg);
        }

        if (empty($this->questions)) {
            $errmsg = get_string('erroropening', 'questionnaire') .' '. 'No questions found.' ." [ ID:${sid} ]";
            return($errmsg);
        }

        // Find total number of survey responses and relevant response ID's.
        if (!empty($rid)) {
            $rids = $rid;
            if (is_array($rids)) {
                $navbar = false;
            } else {
                $navbar = true;
            }
            $total = 1;
        } else {
            $navbar = false;
            $sql = "";
            $castsql = $DB->sql_cast_char2int('R.username');
            if ($uid !== false) { // One participant only.
                $sql = "SELECT r.id, r.survey_id
                          FROM {questionnaire_response} r
                         WHERE r.survey_id='{$this->survey->id}' AND
                               r.username = $uid AND
                               r.complete='y'
                         ORDER BY r.id";

                // Changed the system for retrieval of respondents list for moodle 2.5 to avoid Duplicate values warning.
                // All participants or all members of a group or non group members.
            } else if ($groupid < 0) {
                $sql = "SELECT R.id, R.survey_id, R.username as userid
                          FROM {questionnaire_response} R
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y'
                         ORDER BY R.id";
            } else { // Members of a specific group.
                $sql = "SELECT R.id, R.survey_id
                          FROM {questionnaire_response} R,
                                {groups_members} GM
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y' AND
                               GM.groupid=".$groupid." AND
                               ".$castsql."=GM.userid
                         ORDER BY R.id";
            }
            if (!($rows = $DB->get_records_sql($sql))) {
                echo (get_string('noresponses', 'questionnaire'));
                $SESSION->questionnaire->noresponses = true;
                return;
            }

            switch ($groupid) {
                case -2:    // Remove non group members from list of all participants.
                    foreach ($rows as $row => $key) {
                        if (!groups_has_membership($this->cm, $key->userid)) {
                            unset($rows[$row]);
                        }
                    }
                    break;
                case -3:    // Remove group members from list of all participants.
                    foreach ($rows as $row => $key) {
                        if (groups_has_membership($this->cm, $key->userid)) {
                            unset($rows[$row]);
                        }
                    }
                break;
            }

            $total = count($rows);
            echo (' '.get_string('responses', 'questionnaire').": <strong>$total</strong>");
            if (empty($rows)) {
                $errmsg = get_string('erroropening', 'questionnaire') .' '. get_string('noresponsedata', 'questionnaire');
                    return($errmsg);
            }

            $rids = array();
            foreach ($rows as $row) {
                array_push($rids, $row->id);
            }
        }

        if ($navbar) {
            // Show response navigation bar.
            $this->survey_results_navbar($rid);
        }

        echo '<h3 class="surveyTitle">'.s($this->survey->title).'</h3>';
        if ($this->survey->subtitle) {
            echo('<h4>'.$this->survey->subtitle.'</h4>');
        }
        if ($this->survey->info) {
            $infotext = file_rewrite_pluginfile_urls($this->survey->info, 'pluginfile.php',
                $this->context->id, 'mod_questionnaire', 'info', $this->survey->id);
            echo '<div class="addInfo">'.format_text($infotext, FORMAT_HTML).'</div>';
        }

        $qnum = 0;
        foreach ($this->questions as $question) {
            if ($question->type_id != QUESSECTIONTEXT) {
                $qnum++;
            }
            echo html_writer::start_tag('div', array('class' => 'qn-container'));
            echo html_writer::start_tag('div', array('class' => 'qn-info'));
            echo html_writer::tag('h2', $qnum, array('class' => 'qn-number'));
            echo html_writer::end_tag('h2'); // End qn-number.
            echo html_writer::end_tag('div'); // End qn-info.
            echo html_writer::start_tag('div', array('class' => 'qn-content'));
            echo html_writer::start_tag('div', array('class' => 'qn-question'));
            echo format_text(file_rewrite_pluginfile_urls($question->content, 'pluginfile.php',
                    $question->context->id, 'mod_questionnaire', 'question', $question->id), FORMAT_HTML);
            echo html_writer::end_tag('div'); // End qn-question.
            $question->display_results($rids, $sort);
            echo html_writer::end_tag('div'); // End qn-content.
            echo html_writer::end_tag('div'); // End qn-container.
        }

        return;
    }

    /* {{{ proto array survey_generate_csv(int survey_id)
    Exports the results of a survey to an array.
    */
    public function generate_csv($rid='', $userid='', $choicecodes=1, $choicetext=0) {
        global $SESSION, $DB;

        if (isset($SESSION->questionnaire->currentgroupid)) {
            $groupid = $SESSION->questionnaire->currentgroupid;
        } else {
            $groupid = -1;
        }
        $output = array();
        $nbinfocols = 9; // Change this if you want more info columns.
        $stringother = get_string('other', 'questionnaire');
        $columns = array(
                get_string('response', 'questionnaire'),
                get_string('submitted', 'questionnaire'),
                get_string('institution'),
                get_string('department'),
                get_string('course'),
                get_string('group'),
                get_string('id', 'questionnaire'),
                get_string('fullname'),
                get_string('username')
            );

        $types = array(
                0,
                0,
                1,
                1,
                1,
                1,
                0,
                1,
                1,
            );

        $arr = array();

        $id_to_csv_map = array(
            '0',    // 0: unused
            '0',    // 1: bool -> boolean
            '1',    // 2: text -> string
            '1',    // 3: essay -> string
            '0',    // 4: radio -> string
            '0',    // 5: check -> string
            '0',    // 6: dropdn -> string
            '0',    // 7: rating -> number
            '0',    // 8: rate -> number
            '1',    // 9: date -> string
            '0'     // 10: numeric -> number.
        );

        if (!$survey = $DB->get_record('questionnaire_survey', array('id' => $this->survey->id))) {
            print_error ('surveynotexists', 'questionnaire');
        }

        $select = 'survey_id = '.$this->survey->id.' AND deleted = \'n\' AND type_id < 50';
        $fields = 'id, name, type_id, position';
        if (!($records = $DB->get_records_select('questionnaire_question', $select, null, 'position', $fields))) {
            $records = array();
        }

        $num = 1;
        foreach ($records as $record) {
            // Establish the table's field names.
            $qid = $record->id;
            $qpos = $record->position;
            $col = $record->name;
            $type = $record->type_id;
            if ($type == 4 || $type == 5 || $type == 8) {
                /* single or multiple or rate */
                $sql = "SELECT c.id as cid, q.id as qid, q.precise AS precise, q.name, c.content
                FROM {questionnaire_question} q ".
                "LEFT JOIN {questionnaire_quest_choice} c ON question_id = q.id ".
                'WHERE q.id = '.$qid.' ORDER BY cid ASC';
                if (!($records2 = $DB->get_records_sql($sql))) {
                    $records2 = array();
                }
                $subqnum = 0;
                switch ($type) {

                    case 4: // Single.
                        $columns[][$qpos] = $col;
                        array_push($types, $id_to_csv_map[$type]);
                        $thisnum = 1;
                        foreach ($records2 as $record2) {
                            $content = $record2->content;
                            if (preg_match('/^!other/', $content)) {
                                $col = $record2->name.'_'.$stringother;
                                $columns[][$qpos] = $col;
                                array_push($types, '0');
                            }
                        }
                        break;

                    case 5: // Multiple.
                        $thisnum = 1;
                        foreach ($records2 as $record2) {
                            $content = $record2->content;
                            $modality = '';
                            if (preg_match('/^!other/', $content)) {
                                $content = $stringother;
                                $col = $record2->name.'->['.$content.']';
                                $columns[][$qpos] = $col;
                                array_push($types, '0');
                            }
                            $contents = questionnaire_choice_values($content);
                            if ($contents->modname) {
                                $modality = $contents->modname;
                            } else if ($contents->title) {
                                $modality = $contents->title;
                            } else {
                                $modality = strip_tags($contents->text);
                            }
                            $col = $record2->name.'->'.$modality;
                            $columns[][$qpos] = $col;
                            array_push($types, '0');
                        }
                        break;

                    case 8: // Rate.
                        foreach ($records2 as $record2) {
                            $nameddegrees = 0;
                            $modality = '';
                            $content = $record2->content;
                            $osgood = false;
                            if ($record2->precise == 3) {
                                $osgood = true;
                            }
                            if (preg_match("/^[0-9]{1,3}=/", $content, $ndd)) {
                                $nameddegrees++;
                            } else {
                                if ($osgood) {
                                    list($contentleft, $contentright) = preg_split('/[|]/', $content);
                                    $contents = questionnaire_choice_values($contentleft);
                                    if ($contents->title) {
                                        $contentleft = $contents->title;
                                    }
                                    $contents = questionnaire_choice_values($contentright);
                                    if ($contents->title) {
                                        $contentright = $contents->title;
                                    }
                                    $modality = strip_tags($contentleft.'|'.$contentright);
                                    $modality = preg_replace("/[\r\n\t]/", ' ', $modality);
                                } else {
                                    $contents = questionnaire_choice_values($content);
                                    if ($contents->modname) {
                                        $modality = $contents->modname;
                                    } else if ($contents->title) {
                                        $modality = $contents->title;
                                    } else {
                                        $modality = strip_tags($contents->text);
                                        $modality = preg_replace("/[\r\n\t]/", ' ', $modality);
                                    }
                                }
                                $col = $record2->name.'->'.$modality;
                                $columns[][$qpos] = $col;
                                array_push($types, $id_to_csv_map[$type]);
                            }
                        }
                        break;
                }
            } else {
                $columns[][$qpos] = $col;
                array_push($types, $id_to_csv_map[$type]);
            }
            $num++;
        }
        array_push($output, $columns);
        $numcols = count($output[0]);

        if ($rid) {             // Send e-mail for a unique response ($rid).
            $select = 'survey_id = '.$this->survey->id.' AND complete=\'y\' AND id = '.$rid;
            $fields = 'id,submitted,username';
            if (!($records = $DB->get_records_select('questionnaire_response', $select, null, 'submitted', $fields))) {
                $records = array();
            }
        } else if ($userid) {    // Download CSV for one user's own responses'.
                $sql = "SELECT R.id, R.survey_id, R.submitted, R.username
                          FROM {questionnaire_response} R
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y' AND
                               R.username='$userid'
                         ORDER BY R.id";
            if (!($records = $DB->get_records_sql($sql))) {
                $records = array();
            }

        } else { // Download CSV for all participants (or groups if enabled).
            $castsql = $DB->sql_cast_char2int('R.username');
            if ($groupid == -1) { // All participants.
                $sql = "SELECT R.id, R.survey_id, R.submitted, R.username
                          FROM {questionnaire_response} R
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y'
                         ORDER BY R.id";
            } else if ($groupid == -2) { // All members of any group.
                $sql = "SELECT R.id, R.survey_id, R.submitted, R.username
                          FROM {questionnaire_response} R,
                                {groups_members} GM
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y' AND
                               GM.groupid>0 AND
                               ".$castsql."=GM.userid
                         ORDER BY R.id";
            } else if ($groupid == -3) { // Not members of any group.
                $sql = "SELECT R.id, R.survey_id, R.submitted,  U.id AS username
                          FROM {questionnaire_response} R,
                                {user} U
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y' AND
                               ".$castsql."=U.id
                         ORDER BY username";
            } else {                 // Members of a specific group.
                $sql = "SELECT R.id, R.survey_id, R.submitted, R.username
                          FROM {questionnaire_response} R,
                                {groups_members} GM
                         WHERE R.survey_id='{$this->survey->id}' AND
                               R.complete='y' AND
                               GM.groupid=".$groupid." AND
                               ".$castsql."=GM.userid
                         ORDER BY R.id";
            }
            if (!($records = $DB->get_records_sql($sql))) {
                $records = array();
            }
            if ($groupid == -3) {     // Members of no group.
                foreach ($records as $row => $key) {
                    $userid = $key->username;
                    if (groups_has_membership($this->cm, $userid)) {
                        unset($records[$row]);
                    }
                }
            }
        }
        $isanonymous = $this->respondenttype == 'anonymous';
        $format_options = new Object();
        $format_options->filter = false;  // To prevent any filtering in CSV output.
        foreach ($records as $record) {
            // Get the response.
            $response = $this->response_select_name($record->id, $choicecodes, $choicetext);
            $qid = $record->id;
            // For better compabitility & readability with Excel.
            $submitted = date(get_string('strfdateformatcsv', 'questionnaire'), $record->submitted);
            $institution = '';
            $department = '';
            $username  = $record->username;
            if ($user = $DB->get_record('user', array('id' => $username))) {
                $institution = $user->institution;
                $department = $user->department;
            }

            // Moodle:
            //  Get the course name that this questionnaire belongs to.
            if ($survey->realm != 'public') {
                $courseid = $this->course->id;
                $coursename = $this->course->fullname;
            } else {
                // For a public questionnaire, look for the course that used it.
                $sql = 'SELECT q.id, q.course, c.fullname '.
                       'FROM {questionnaire} q, {questionnaire_attempts} qa, {course} c '.
                       'WHERE qa.rid = ? AND q.id = qa.qid AND c.id = q.course';
                if ($record = $DB->get_record_sql($sql, array($qid))) {
                    $courseid = $record->course;
                    $coursename = $record->fullname;
                } else {
                    $courseid = $this->course->id;
                    $coursename = $this->course->fullname;
                }
            }
            // Moodle:
            //  If the username is numeric, try it as a Moodle user id.
            if (is_numeric($username)) {
                if ($user = $DB->get_record('user', array('id' => $username))) {
                    $uid = $username;
                    $fullname = fullname($user);
                    $username = $user->username;
                }
            }

            // Moodle:
            //  Determine if the user is a member of a group in this course or not.
            $groupname = '';
            if ($this->cm->groupmode > 0) {
                if ($groupid > 0) {
                    $groupname = groups_get_group_name($groupid);
                } else {
                    if ($uid) {
                        if ($groups = groups_get_all_groups($courseid, $uid)) {
                            foreach ($groups as $group) {
                                $groupname.= $group->name.', ';
                            }
                            $groupname = substr($groupname, 0, strlen($groupname) -2);
                        } else {
                            $groupname = ' ('.get_string('groupnonmembers').')';
                        }
                    }
                }
            }
            if ($isanonymous) {
                $fullname =  get_string('anonymous', 'questionnaire');
                $username = '';
                $uid = '';
            }
            $arr = array();
            array_push($arr, $qid);
            array_push($arr, $submitted);
            array_push($arr, $institution);
            array_push($arr, $department);
            array_push($arr, $coursename);
            array_push($arr, $groupname);
            array_push($arr, $uid);
            array_push($arr, $fullname);
            array_push($arr, $username);

            // Merge it.
            for ($i = $nbinfocols; $i < $numcols; $i++) {
                $qpos = key($columns[$i]);
                $qname = current($columns[$i]);
                if (isset($response[$qpos][$qname]) && $response[$qpos][$qname] != '') {
                    $thisresponse = $response[$qpos][$qname];
                } else {
                    $thisresponse = '';
                }

                switch ($types[$i]) {
                    case 1:  // String
                             // Excel seems to allow "\n" inside a quoted string, but
                             // "\r\n" is used as a record separator and so "\r" may
                             // not occur within a cell. So if one would like to preserve
                             // new-lines in a response, remove the "\n" from the
                             // regex below.

                            // Email format text is plain text for being displayed in Excel, etc.
                            // But it must be stripped of carriage returns.
                        if ($thisresponse) {
                            $thisresponse = format_text($thisresponse, FORMAT_HTML, $format_options);
                            $thisresponse = preg_replace("/[\r\n\t]/", ' ', $thisresponse);
                            $thisresponse = preg_replace('/"/', '""', $thisresponse);
                        }
                         // Fall through.
                    case 0:  // Number.

                    break;
                }
                array_push($arr, $thisresponse);
            }
            array_push($output, $arr);
        }

        // Change table headers to incorporate actual question numbers.
        $numcol = 0;
        $numquestion = 0;
        $out = '';
        $nbrespcols = count($output[0]);
        $oldkey = 0;

        for ($i = $nbinfocols; $i < $nbrespcols; $i++) {
            $sep = '';
            $thisoutput = current($output[0][$i]);
            $thiskey =  key($output[0][$i]);
            // Case of unnamed rate single possible answer (full stop char is used for support).
            if (strstr($thisoutput, '->.')) {
                $thisoutput = str_replace('->.', '', $thisoutput);
            }
            // If variable is not named no separator needed between Question number and potential sub-variables.
            if ($thisoutput == '' || strstr($thisoutput, '->.') || substr($thisoutput, 0, 2) == '->'
                    || substr($thisoutput, 0, 1) == '_') {
                $sep = '';
            } else {
                $sep = '_';
            }
            if ($thiskey > $oldkey) {
                $oldkey = $thiskey;
                $numquestion++;
            }
            // Abbreviated modality name in multiple or rate questions (COLORS->blue=the color of the sky...).
            $pos = strpos($thisoutput, '=');
            if ($pos) {
                $thisoutput = substr($thisoutput, 0, $pos);
            }
            $other = $sep.$stringother;
            $out = 'Q'.sprintf("%02d", $numquestion).$sep.$thisoutput;
            $output[0][$i] = $out;
        }
        return $output;
    }

    /* {{{ proto bool survey_export_csv(int survey_id, string filename)
        Exports the results of a survey to a CSV file.
        Returns true on success.
        */

    private function export_csv($filename) {
        $umask = umask(0077);
        $fh = fopen($filename, 'w');
        umask($umask);
        if (!$fh) {
            return 0;
        }

        $data = survey_generate_csv($rid='', $userid='', $groupid='');

        foreach ($data as $row) {
            fputs($fh, join(', ', $row) . "\n");
        }

        fflush($fh);
        fclose($fh);

        return 1;
    }

    /**
     * Function to move a question to a new position.
     *
     * @param int $moveqid The id of the question to be moved.
     * @param int $movetopos The position to move before, or zero if the end.
     *
     */
    public function move_question($moveqid, $movetopos) {
        global $DB;

        // If its moving to the last position (moveto = 0), or its moving to a higher position
        // No point in moving it to where it already is...
        if (($movetopos == 0) || (($movetopos-1) > $this->questions[$moveqid]->position)) {
            $found = false;
            foreach ($this->questions as $qid => $question) {
                if ($moveqid == $qid) {
                    $found = true;
                    continue;
                }
                if ($found) {
                    $DB->set_field('questionnaire_question', 'position', $question->position-1, array('id' => $qid));
                }
                if ($question->position == ($movetopos-1)) {
                    break;
                }
            }
            if ($movetopos == 0) {
                $movetopos = count($this->questions);
            } else {
                $movetopos--;
            }
            $DB->set_field('questionnaire_question', 'position', $movetopos, array('id' => $moveqid));

        } else if ($movetopos < $this->questions[$moveqid]->position) {
            $found = false;
            foreach ($this->questions as $qid => $question) {
                if ($movetopos == $question->position) {
                    $found = true;
                }
                if (!$found) {
                    continue;
                } else {
                    $DB->set_field('questionnaire_question', 'position', $question->position+1, array('id' => $qid));
                }
                if ($question->position == ($this->questions[$moveqid]->position-1)) {
                    break;
                }
            }
            $DB->set_field('questionnaire_question', 'position', $movetopos, array('id' => $moveqid));
        }
    }
}
