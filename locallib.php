<?php

///////////////////////////////////////////////////////////////////////////
//                                                                       //
// NOTICE OF COPYRIGHT                                                   //
//                                                                       //
//                   Moss Anti-Plagiarism for Moodle                     //
//         https://github.com/hit-moodle/moodle-plagiarism_moss          //
//                                                                       //
// Copyright (C) 2009 onwards  Sun Zhigang  http://sunner.cn             //
//                                                                       //
// This program is free software; you can redistribute it and/or modify  //
// it under the terms of the GNU General Public License as published by  //
// the Free Software Foundation; either version 3 of the License, or     //
// (at your option) any later version.                                   //
//                                                                       //
// This program is distributed in the hope that it will be useful,       //
// but WITHOUT ANY WARRANTY; without even the implied warranty of        //
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the         //
// GNU General Public License for more details:                          //
//                                                                       //
//          http://www.gnu.org/copyleft/gpl.html                         //
//                                                                       //
///////////////////////////////////////////////////////////////////////////

/**
 * Anti-Plagiarism by Moss
 *
 * @package   plagiarism_moss
 * @copyright 2011 Sun Zhigang (http://sunner.cn)
 * @author    Sun Zhigang
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

require_once($CFG->dirroot.'/plagiarism/moss/moss.php');

/**
 * Whether moss is enabled
 *
 * @param int cmid
 * @return bool
 */
function moss_enabled($cmid = 0) {
    global $DB;

    if (!get_config('plagiarism', 'moss_use')) {
        return false;
    } else if ($cmid == 0) {
        return true;
    } else {
        return $DB->get_field('moss', 'enabled', array('cmid' => $cmid));
    }
}

/**
 * Save files in $eventdata to moss file area
 *
 * @param object $eventdata
 */
function moss_save_files_from_event($eventdata) {
    global $DB;
    $result = true;

    if (!moss_enabled($eventdata->cmid)) {
        return $result;
    }

    if (!empty($eventdata->file) && empty($eventdata->files)) { //single assignment type passes a single file
        $eventdata->files[] = $eventdata->file;
    }

    if (isset($eventdata->files)) {
        moss_save_storedfiles($eventdata->files, $eventdata->cmid, $eventdata->userid);
    }

    return $result;
}

/**
 * Save storedfiles into moss
 *
 * The function will clean all previous stored files
 * @param array $storedfiles
 * @param int $cmid
 * @param int $userid
 */
function moss_save_storedfiles($storedfiles, $cmid, $userid) {
    $context = get_system_context();
    $fs = get_file_storage();

    // remove all old files
    $old_files = $fs->get_directory_files($context->id, 'plagiarism_moss', 'files', $cmid, "/$userid/", true, true);
    foreach($old_files as $oldfile) {
        $oldfile->delete();
    }

    // store files
    foreach($storedfiles as $file) {
        if ($file->get_filename() === '.') {
            continue;
        }
        //hacky way to check file still exists
        $fileid = $fs->get_file_by_id($file->get_id());
        if (empty($fileid)) {
            mtrace("nofilefound!");
            continue;
        }

        $fileinfo = array(
            'contextid' => $context->id,
            'component' => 'plagiarism_moss',
            'filearea'  => 'files',
            'itemid'    => $cmid,
            'filepath'  => "/$userid/",
            // save /abc/def/ghi.c as abc_def_ghi.c
            'filename'  => str_replace('/', '_', ltrim($file->get_filepath(), '/')).$file->get_filename());
        $fs->create_file_from_storedfile($fileinfo, $file);
    }
}

/**
 * Trigger assessable_file_uploaded and assessable_files_done events of specified assignment.
 *
 * @param int $cmid
 * @param bool $trigger_done whether trigger assessable_files_done event. Advanced upload assignment only.
 * @return number of submissions found, or false on failed
 */
function moss_trigger_assignment_events($cmid, $trigger_done = true) {
    global $DB, $CFG;
    require_once($CFG->dirroot.'/mod/assignment/lib.php');

    $cm = get_coursemodule_from_id('assignment', $cmid);
    if (empty($cm)) {
        return false;
    }

    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    $fs = get_file_storage();
    $assignment = $DB->get_record('assignment', array('id' => $cm->instance), '*', MUST_EXIST);
    $submissions = assignment_get_all_submissions($assignment);

    foreach ($submissions as $submission) {
        $files = $fs->get_area_files($context->id, 'mod_assignment', 'submission', $submission->id, "timemodified", false);
        $eventdata = new stdClass();
        $eventdata->modulename   = 'assignment';
        $eventdata->cmid         = $cmid;
        $eventdata->itemid       = $submission->id;
        $eventdata->courseid     = $cm->course;
        $eventdata->userid       = $submission->userid;
        if ($files) {
            if ($assignment->assignmenttype == 'upload') {
                $eventdata->files = $files;
            } else { // uploadsingle
                $eventdata->file = current($files);
            }
        }
        events_trigger('assessable_file_uploaded', $eventdata);
        if ($trigger_done and $assignment->assignmenttype == 'upload') {
            unset($eventdata->files);
            events_trigger('assessable_files_done', $eventdata);
        }
    }

    return count($submissions);
}

/**
 * Sent notification
 *
 * @param object $result
 */
function moss_message_send($result) {
    global $DB, $CFG;

    $teacher = $DB->get_record('user', array('id' => $result->confirmer));
    $user = $DB->get_record('user', array('id' => $result->userid));

    $subject = get_string('messagesubject', 'plagiarism_moss');

    $moss = $DB->get_record('moss', array('id' => $result->moss));
    $moss->link = $CFG->wwwroot."/plagiarism/moss/view.php?id=$moss->cmid&user=$result->userid";
    $html = '';
    if ($result->confirmed) {
        $text = get_string('messageconfirmedtext', 'plagiarism_moss', $moss);
        if ($user->mailformat == 1) {  // HTML
            $html = get_string('messageconfirmedhtml', 'plagiarism_moss', $moss);
        }
    } else {
        $text = get_string('messageunconfirmedtext', 'plagiarism_moss', $moss);
        if ($user->mailformat == 1) {  // HTML
            $html = get_string('messageunconfirmedhtml', 'plagiarism_moss', $moss);
        }
    }

    $eventdata = new stdClass();
    $eventdata->modulename       = 'moss';
    $eventdata->userfrom         = $teacher;
    $eventdata->userto           = $user;
    $eventdata->subject          = $subject;
    $eventdata->fullmessage      = $text;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml  = $html;
    $eventdata->smallmessage     = $subject;

    $eventdata->name            = 'moss_updates';
    $eventdata->component       = 'plagiarism_moss';
    $eventdata->notification    = 1;
    $eventdata->contexturl      = $moss->link;
    $eventdata->contexturlname  = $moss->modulename;

    message_send($eventdata);
}
