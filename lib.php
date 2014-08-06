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
 * Self enrolment plugin.
 *
 * @package    enrol_ilbeadtutorado
 * @copyright  2010 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Self enrolment plugin implementation.
 * @author Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_ilbeadtutorado_plugin extends enrol_plugin {

    protected $lasternoller = null;
    protected $lasternollerinstanceid = 0;

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        $key = false;
        $nokey = false;
        foreach ($instances as $instance) {
            if (!$instance->customint6) {
                // New enrols not allowed.
                continue;
            }
            if ($instance->password or $instance->customint1) {
                $key = true;
            } else {
                $nokey = true;
            }
        }
        $icons = array();
        if ($nokey) {
            $icons[] = new pix_icon('withoutkey', get_string('pluginname', 'enrol_ilbeadtutorado'), 'enrol_ilbeadtutorado');
        }
        if ($key) {
            $icons[] = new pix_icon('withkey', get_string('pluginname', 'enrol_ilbeadtutorado'), 'enrol_ilbeadtutorado');
        }
        return $icons;
    }

    /**
     * Returns localised name of enrol instance
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance->name)) {
            if (!empty($instance->roleid) and $role = $DB->get_record('role', array('id'=>$instance->roleid))) {
                $role = ' (' . role_get_name($role, context_course::instance($instance->courseid, IGNORE_MISSING)) . ')';
            } else {
                $role = '';
            }
            $enrol = $this->get_name();
            return get_string('pluginname', 'enrol_'.$enrol) . $role;
        } else {
            return format_string($instance->name);
        }
    }

    public function roles_protected() {
        // Users may tweak the roles later.
        return false;
    }

    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually manually.
        return true;
    }

    public function allow_manage(stdClass $instance) {
        // Users with manage cap may tweak period and status.
        return true;
    }

    public function show_enrolme_link(stdClass $instance) {
        global $CFG, $USER;

        if ($instance->status != ENROL_INSTANCE_ENABLED) {
            return false;
        }

        if (!$instance->customint6) {
            // New enrols not allowed.
            return false;
        }

        if ($this->max_ongoing_reached($instance)) {
            // Max ongoing EAD courses reached. New enrol not allowed
            return false;
        }

        if ($instance->customint5) {
            require_once("$CFG->dirroot/cohort/lib.php");
            return cohort_is_member($instance->customint5, $USER->id);
        }
        return true;
    }

    /**
     * Sets up navigation entries.
     *
     * @param stdClass $instancesnode
     * @param stdClass $instance
     * @return void
     */
    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'ilbeadtutorado') {
             throw new coding_exception('Invalid enrol instance type!');
        }

        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/ilbeadtutorado:config', $context)) {
            $managelink = new moodle_url('/enrol/ilbeadtutorado/edit.php', array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $instancesnode->add($this->get_instance_name($instance), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Returns edit icons for the page with list of instances
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        if ($instance->enrol !== 'ilbeadtutorado') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);

        $icons = array();

        if (has_capability('enrol/ilbeadtutorado:config', $context)) {
            $editlink = new moodle_url("/enrol/ilbeadtutorado/edit.php", array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                array('class' => 'iconsmall')));
        }

        return $icons;
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) or !has_capability('enrol/ilbeadtutorado:config', $context)) {
            return NULL;
        }
        // Multiple instances supported - different roles with different password.
        return new moodle_url('/enrol/ilbeadtutorado/edit.php', array('courseid'=>$courseid));
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    public function enrol_page_hook(stdClass $instance) {
        global $CFG, $OUTPUT, $SESSION, $USER, $DB;

        if (isguestuser()) {
            // Can not enrol guest!!
            return null;
        }

        if ($DB->record_exists('user_enrolments', array('userid'=>$USER->id, 'enrolid'=>$instance->id))) {
            $error = get_string('alreadyenroled', 'enrol_ilbeadtutorado');
            return $OUTPUT->box($error).$OUTPUT->continue_button("$CFG->wwwroot/index.php");
        }

        if ($instance->enrolstartdate != 0 and $instance->enrolstartdate > time()) {
            //TODO: inform that we can not enrol yet
            return null;
        }

        if ($instance->enrolenddate != 0 and $instance->enrolenddate < time()) {
            //TODO: inform that enrolment is not possible any more
            return null;
        }

        if (!$instance->customint6) {
            // New enrols not allowed.
            return null;
        }

        $ongoing = $this->get_ongoing($instance);

        if ($instance->customint7 !== null and $instance->customint7 > 0) {
            if (count($ongoing) >= $instance->customint7) {
                // Max ongoing EAD courses reached. New enrol not allowed
                $error  = $OUTPUT->error_text(get_string('maxongoing', 'enrol_ilbeadtutorado'));
                $error .= '<br/><br/><p>'.get_string('maxongoingmessage', 'enrol_ilbeadtutorado', count($ongoing)).'</p>';
                $error .= '<p><strong>'.get_string('ongoingcourses', 'enrol_ilbeadtutorado').'</strong></p>';
                $table = new html_table();
                $table->head = array(get_string('coursename', 'enrol_ilbeadtutorado'), get_string('startdate', 'enrol_ilbeadtutorado'));
                $tabledata = array();
                foreach ($ongoing as $course) {
                    $link = '<a href="'.course_get_url($course).'">'.$course->fullname.'</a>';
                    $tabledata[] = array($link, userdate($course->startdate));
                }
                $table->data = $tabledata;
                $error .= html_writer::table($table);
                $error = $OUTPUT->box($error).$OUTPUT->continue_button("$CFG->wwwroot/index.php");
                return $error;
            }
        }

        $course = $DB->get_record('course', array('id'=>$instance->courseid), '*', MUST_EXIST);
        $course_prefix = explode('.', $course->idnumber);
        $course_prefix = $course_prefix[0];
        $same_courses = $this->get_same_courses($instance, $course_prefix);

        // Search for abandon/reproval facts
        $count = 0;

        foreach ($same_courses as $course) {
            $count++;
            $link = '<a href="'.course_get_url($course).'">'.$course->fullname.'</a>';
            if ($course->user_enroled) {
                if (($count <= $instance->customint8) and ($course->finalgrade === NULL)) {
                    // Punish for abandon
                    $error = $OUTPUT->error_text(get_string('abandonalert', 'enrol_ilbeadtutorado', $link));
                    $error = $OUTPUT->box($error).$OUTPUT->continue_button("$CFG->wwwroot/index.php");
                    return $error;
                }
                if (($count <= $instance->customdec1) and ($course->finalgrade < $course->gradepass)) {
                    // Punish for reproval
                    $error = $OUTPUT->error_text(get_string('reprovalalert', 'enrol_ilbeadtutorado', $link));
                    $error = $OUTPUT->box($error).$OUTPUT->continue_button("$CFG->wwwroot/index.php");
                    return $error;
                }
            }
        } 

        if ($instance->customint5) {
            require_once("$CFG->dirroot/cohort/lib.php");
            if (!cohort_is_member($instance->customint5, $USER->id)) {
                $cohort = $DB->get_record('cohort', array('id'=>$instance->customint5));
                if (!$cohort) {
                    return null;
                }
                $a = format_string($cohort->name, true, array('context'=>context::instance_by_id($cohort->contextid)));
                return $OUTPUT->box(markdown_to_html(get_string('cohortnonmemberinfo', 'enrol_ilbeadtutorado', $a)));
            }
        }

        require_once("$CFG->dirroot/enrol/ilbeadtutorado/locallib.php");
        require_once("$CFG->dirroot/group/lib.php");

        $form = new enrol_ilbeadtutorado_enrol_form(NULL, $instance);
        $instanceid = optional_param('instance', 0, PARAM_INT);

        if ($instance->id == $instanceid) {
            if ($data = $form->get_data()) {
                $enrol = enrol_get_plugin('ilbeadtutorado');
                $timestart = time();
                if ($instance->enrolperiod) {
                    $timeend = $timestart + $instance->enrolperiod;
                } else {
                    $timeend = 0;
                }

                $this->enrol_user($instance, $USER->id, $instance->roleid, $timestart, $timeend);
                add_to_log($instance->courseid, 'course', 'enrol', '../enrol/users.php?id='.$instance->courseid, $instance->courseid); //TODO: There should be userid somewhere!

                if ($instance->password and $instance->customint1 and $data->enrolpassword !== $instance->password) {
                    // it must be a group enrolment, let's assign group too
                    $groups = $DB->get_records('groups', array('courseid'=>$instance->courseid), 'id', 'id, enrolmentkey');
                    foreach ($groups as $group) {
                        if (empty($group->enrolmentkey)) {
                            continue;
                        }
                        if ($group->enrolmentkey === $data->enrolpassword) {
                            groups_add_member($group->id, $USER->id);
                            break;
                        }
                    }
                }
                // Send welcome message.
                if ($instance->customint4) {
                    $this->email_welcome_message($instance, $USER);
                }
            }
        }

        ob_start();
        $form->display();
        $output = ob_get_clean();

        return $OUTPUT->box($output);
    }

    /**
     * Add new instance of enrol plugin with default settings.
     * @param stdClass $course
     * @return int id of new instance
     */
    public function add_default_instance($course) {
        $fields = $this->get_instance_defaults();

        if ($this->get_config('requirepassword')) {
            $fields['password'] = generate_password(20);
        }

        return $this->add_instance($course, $fields);
    }

    /**
     * Returns defaults for new instances.
     * @return array
     */
    public function get_instance_defaults() {
        $expirynotify = $this->get_config('expirynotify');
        if ($expirynotify == 2) {
            $expirynotify = 1;
            $notifyall = 1;
        } else {
            $notifyall = 0;
        }

        $fields = array();
        $fields['status']          = $this->get_config('status');
        $fields['roleid']          = $this->get_config('roleid');
        $fields['enrolperiod']     = $this->get_config('enrolperiod');
        $fields['expirynotify']    = $expirynotify;
        $fields['notifyall']       = $notifyall;
        $fields['expirythreshold'] = $this->get_config('expirythreshold');
        $fields['customint1']      = $this->get_config('groupkey');
        $fields['customint2']      = $this->get_config('longtimenosee');
        $fields['customint3']      = $this->get_config('maxenrolled');
        $fields['customint4']      = $this->get_config('sendcoursewelcomemessage');
        $fields['customint5']      = 0;
        $fields['customint6']      = $this->get_config('newenrols');
        $fields['customint7']      = $this->get_config('maxongoing');
        $fields['customint8']      = $this->get_config('abandonpunishment');

        return $fields;
    }

    /**
     * Send welcome email to specified user.
     *
     * @param stdClass $instance
     * @param stdClass $user user record
     * @return void
     */
    protected function email_welcome_message($instance, $user) {
        global $CFG, $DB;

        $course = $DB->get_record('course', array('id'=>$instance->courseid), '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        $a = new stdClass();
        $a->coursename = format_string($course->fullname, true, array('context'=>$context));
        $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id&course=$course->id";

        if (trim($instance->customtext1) !== '') {
            $message = $instance->customtext1;
            $message = str_replace('{$a->coursename}', $a->coursename, $message);
            $message = str_replace('{$a->profileurl}', $a->profileurl, $message);
            if (strpos($message, '<') === false) {
                // Plain text only.
                $messagetext = $message;
                $messagehtml = text_to_html($messagetext, null, false, true);
            } else {
                // This is most probably the tag/newline soup known as FORMAT_MOODLE.
                $messagehtml = format_text($message, FORMAT_MOODLE, array('context'=>$context, 'para'=>false, 'newlines'=>true, 'filter'=>true));
                $messagetext = html_to_text($messagehtml);
            }
        } else {
            $messagetext = get_string('welcometocoursetext', 'enrol_ilbeadtutorado', $a);
            $messagehtml = text_to_html($messagetext, null, false, true);
        }

        $subject = get_string('welcometocourse', 'enrol_ilbeadtutorado', format_string($course->fullname, true, array('context'=>$context)));

        $rusers = array();
        if (!empty($CFG->coursecontact)) {
            $croles = explode(',', $CFG->coursecontact);
            list($sort, $sortparams) = users_order_by_sql('u');
            $rusers = get_role_users($croles, $context, true, '', 'r.sortorder ASC, ' . $sort, null, '', '', '', '', $sortparams);
        }
        if ($rusers) {
            $contact = reset($rusers);
        } else {
            $contact = generate_email_supportuser();
        }

        // Directly emailing welcome message rather than using messaging.
        email_to_user($user, $contact, $subject, $messagetext, $messagehtml);
    }

    /**
     * Enrol ilbeadtutorado cron support.
     * @return void
     */
    public function cron() {
        $trace = new text_progress_trace();
        $this->sync($trace, null);
        $this->send_expiry_notifications($trace);
    }

    /**
     * Sync all meta course links.
     *
     * @param progress_trace $trace
     * @param int $courseid one course, empty mean all
     * @return int 0 means ok, 1 means error, 2 means plugin disabled
     */
    public function sync(progress_trace $trace, $courseid = null) {
        global $DB;

        if (!enrol_is_enabled('ilbeadtutorado')) {
            $trace->finished();
            return 2;
        }

        // Unfortunately this may take a long time, execution can be interrupted safely here.
        @set_time_limit(0);
        raise_memory_limit(MEMORY_HUGE);

        $trace->output('Verifying ilb ead self-enrolments...');

        $params = array('now'=>time(), 'useractive'=>ENROL_USER_ACTIVE, 'courselevel'=>CONTEXT_COURSE);
        $coursesql = "";
        if ($courseid) {
            $coursesql = "AND e.courseid = :courseid";
            $params['courseid'] = $courseid;
        }

        // Note: the logic of ilb ead self enrolment guarantees that user logged in at least once (=== u.lastaccess set)
        //       and that user accessed course at least once too (=== user_lastaccess record exists).

        // First deal with users that did not log in for a really long time - they do not have user_lastaccess records.
        $sql = "SELECT e.*, ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'ilbeadtutorado' AND e.customint2 > 0)
                  JOIN {user} u ON u.id = ue.userid
                 WHERE :now - u.lastaccess > e.customint2
                       $coursesql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $instance) {
            $userid = $instance->userid;
            unset($instance->userid);
            $this->unenrol_user($instance, $userid);
            $days = $instance->customint2 / 60*60*24;
            $trace->output("unenrolling user $userid from course $instance->courseid as they have did not log in for at least $days days", 1);
        }
        $rs->close();

        // Now unenrol from course user did not visit for a long time.
        $sql = "SELECT e.*, ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'ilbeadtutorado' AND e.customint2 > 0)
                  JOIN {user_lastaccess} ul ON (ul.userid = ue.userid AND ul.courseid = e.courseid)
                 WHERE :now - ul.timeaccess > e.customint2
                       $coursesql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $instance) {
            $userid = $instance->userid;
            unset($instance->userid);
            $this->unenrol_user($instance, $userid);
                $days = $instance->customint2 / 60*60*24;
            $trace->output("unenrolling user $userid from course $instance->courseid as they have did not access course for at least $days days", 1);
        }
        $rs->close();

        $trace->output('...user ilb ead self-enrolment updates finished.');
        $trace->finished();

        $this->process_expirations($trace, $courseid);

        return 0;
    }

    /**
     * Returns the user who is responsible for ilb ead self enrolments in given instance.
     *
     * Usually it is the first editing teacher - the person with "highest authority"
     * as defined by sort_by_roleassignment_authority() having 'enrol/ilbeadtutorado:manage'
     * capability.
     *
     * @param int $instanceid enrolment instance id
     * @return stdClass user record
     */
    protected function get_enroller($instanceid) {
        global $DB;

        if ($this->lasternollerinstanceid == $instanceid and $this->lasternoller) {
            return $this->lasternoller;
        }

        $instance = $DB->get_record('enrol', array('id'=>$instanceid, 'enrol'=>$this->get_name()), '*', MUST_EXIST);
        $instance->customtext2 = unserialize($instance->customtext2);
        $context = context_course::instance($instance->courseid);

        if ($users = get_enrolled_users($context, 'enrol/ilbeadtutorado:manage')) {
            $users = sort_by_roleassignment_authority($users, $context);
            $this->lasternoller = reset($users);
            unset($users);
        } else {
            $this->lasternoller = parent::get_enroller($instanceid);
        }

        $this->lasternollerinstanceid = $instanceid;

        return $this->lasternoller;
    }

    /**
     * Gets an array of the user enrolment actions.
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol($instance) && has_capability("enrol/ilbeadtutorado:unenrol", $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class'=>'unenrollink', 'rel'=>$ue->id));
        }
        if ($this->allow_manage($instance) && has_capability("enrol/ilbeadtutorado:manage", $context)) {
            $url = new moodle_url('/enrol/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''), get_string('edit'), $url, array('class'=>'editenrollink', 'rel'=>$ue->id));
        }
        return $actions;
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = array(
                'courseid'   => $data->courseid,
                'enrol'      => $this->get_name(),
                'roleid'     => $data->roleid,
            );
        }
        if ($merge and $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            if (!empty($data->customint5)) {
                if ($step->get_task()->is_samesite()) {
                    // Keep cohort restriction unchanged - we are on the same site.
                } else {
                    // Use some id that can not exist in order to prevent self enrolment,
                    // because we do not know what cohort it is in this site.
                    $data->customint5 = -1;
                }
            }
            $instanceid = $this->add_instance($course, (array)$data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Restore role assignment.
     *
     * @param stdClass $instance
     * @param int $roleid
     * @param int $userid
     * @param int $contextid
     */
    public function restore_role_assignment($instance, $roleid, $userid, $contextid) {
        // This is necessary only because we may migrate other types to this instance,
        // we do not use component in manual or self enrol.
        role_assign($roleid, $userid, $contextid, '', 0);
    }

    /**
     * Get user ongoing EAD courses
     *
     * @param stdClass $instance
     * @return array ongoing EAD courses
     */

    public function get_ongoing($instance) {
        global $DB;
        global $USER;
        $course = $DB->get_record('course', array('id'=>$instance->courseid), '*', MUST_EXIST);
        $sql = "select c.*, ue.timestart, ue.timeend, e.customint8 as abandonpunishment
                from {user_enrolments} ue
                  join {enrol} e on e.id = ue.enrolid
                  join {course} c on c.id = e.courseid
                  left outer join {course_completions} cc on cc.userid = ue.userid and cc.course = e.courseid
                where e.enrol = 'ilbeadtutorado'
                  and cc.timecompleted is null
                  and ue.userid = ?
                  and c.category = ?";
        return $DB->get_records_sql($sql, array($USER->id, $course->category));
    }

    /**
     * Max ongoing reached
     *
     * @param stdClass $instance
     * @return bool ongoing_reached
     */

    public function max_ongoing_reached($instance) {
        if ($instance->customint7 == null or $instance->customint7 == 0) {
            return false; // We have not a max ongoing, then its never reached
        }

        $ongoing = $this->get_ongoing($instance);
 
        if (count($ongoing) >= $instance->customint7) {
            return true; // Max ongoing reached
        }

        return false; // Default max not reached
    }
    
    /**
     * Get all same courses tried by the user ordered by startdate descending
     *
     * @param stdClass $instance
     * @param str $course_prefix
     */

     public function get_same_courses($instance, $course_prefix) {
         global $DB;
         global $USER;

         if ($instance->customint8 >= $instance->customdec1) {
             $limitnum = $instance->customint8;
         } else {
             $limitnum = int($instance->customdec1);
         }

         if ($limitnum <= 0) {
             return array(); // No validations
         }

         $idnumberlike = $DB->sql_like('c.idnumber', "'$course_prefix%'");

         $sql = "select c.*, cc.gradepass, gg.finalgrade, ue.userid as user_enroled
                 from {course} c
                   inner join {enrol} e on e.courseid = c.id and e.enrol = 'ilbeadtutorado'
                   inner join {grade_items} gi on gi.courseid = c.id and gi.itemtype = 'course'
                   left outer join {user_enrolments} ue on ue.enrolid = e.id and ue.userid = ?
                   left outer join {course_completion_criteria} cc on cc.course = c.id and cc.module is null and cc.gradepass > 0
                   left outer join {grade_grades} gg on gg.itemid = gi.id and gg.userid = ue.userid
                 where $idnumberlike
                   and e.id <> ?
                 order by startdate desc";

         return $DB->get_records_sql($sql, array($USER->id, $instance->id), 0, $limitnum);
     }
}
