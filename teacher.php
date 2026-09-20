<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
use local_tomb\local\{config, manager, ui, listing, i18n};

ui::start(i18n::get('text_teaching_archives_d93d0c'), '/local/tomb/teacher.php');
$courses = [];
foreach (manager::courses((int)$USER->id) as $course) {
    if (has_capability('local/tomb:exportteacher', context_course::instance($course->id))) {
        $courses[$course->id] = $course;
    }
}
$admin = has_capability('local/tomb:manage', context_system::instance());
if ($admin) {
    foreach ($DB->get_records_select('course', 'id <> ?', [SITEID], 'fullname') as $course) {
        $courses[$course->id] = $course;
    }
}
if (!$courses) {
    http_response_code(403);
    throw new moodle_exception('unavailable', 'local_tomb');
}
$courseid = optional_param('courseid', (int)array_key_first($courses), PARAM_INT);
if (!isset($courses[$courseid])) {
    throw new moodle_exception('invalidcourseid');
}
$context = context_course::instance($courseid);
$users = get_enrolled_users($context, 'moodle/grade:view', 0, 'u.*', 'u.lastname,u.firstname', 0, 0, true);
foreach ($users as $userid => $user) {
    if (has_capability('local/tomb:exportteacher', $context, $userid) || !config::allowed((int)$userid)) {
        unset($users[$userid]);
    }
}
if ($courses[$courseid]->groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    $groups = array_keys(groups_get_all_groups($courseid, $USER->id));
    foreach ($users as $userid => $user) {
        if (!array_intersect($groups, array_keys(groups_get_all_groups($courseid, $userid)))) {
            unset($users[$userid]);
        }
    }
}
$notice = '';
$batchresults = [];
$cangenerate = in_array(config::mode(), ['rehearsal', 'active'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'teacher') {
        $id = manager::request([$courseid], (int)$USER->id, 'teacher', required_param('policy', PARAM_ALPHA),
            optional_param('reason', '', PARAM_TEXT), 0, true, optional_param('outputlang', i18n::language(), PARAM_ALPHA));
        redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
    } else if ($action === 'delegate') {
        require_capability('local/tomb:delegate', $context);
        $ids = array_values(array_unique(optional_param_array('users', [], PARAM_INT)));
        if (!$ids || count($ids) > 20 || array_diff($ids, array_keys($users))) {
            throw new invalid_parameter_exception('Select 1–20 eligible students');
        }
        $accepted = [];
        $failed = 0;
        foreach ($ids as $userid) {
            try {
                $requestid = manager::request([$courseid], $userid);
                $accepted[] = $requestid;
                $batchresults[] = [fullname($users[$userid]), '#' . $requestid, i18n::get('text_accepted_79bc85')];
            } catch (moodle_exception $e) {
                $failed++;
                $batchresults[] = [fullname($users[$userid]), '—', $e->getMessage()];
            }
        }
        $notice = count($accepted) . i18n::get('text_requests_accepted_b78755') . ($failed ? $failed . i18n::get('text_requests_could_not_be_accepted_d6847a') : '');
    }
    } catch (Throwable $e) {$notice = ui::request_error($e);}
}
echo $OUTPUT->header();
echo '<div class="tomb-wrap"><div class="tomb-hero"><div class="tomb-eyebrow">TOMB / COURSE ARCHIVES</div>' .
    i18n::get('text_carry_the_work_of_your_10d095');
if ($notice) {
    echo $OUTPUT->notification(s($notice), 'info');
}
if ($batchresults) {
    echo i18n::get('text_results_of_this_batch_owner_de94af');
    foreach ($batchresults as [$name, $number, $result]) {
        echo '<tr><td>' . s($name) . '</td><td>' . s($number) . '</td><td>' . s($result) . '</td></tr>';
    }
    echo '</table></section>';
}
if (!$cangenerate) {
    echo i18n::get('text_new_collection_requests_are_closed_9cd0ff');
}
echo i18n::get('text_course_14fbb3');
foreach ($courses as $course) {
    echo '<option value="' . $course->id . '"' . ($course->id == $courseid ? ' selected' : '') . '>' . s($course->fullname) . '</option>';
}
echo i18n::get('text_select_b65ec0');
echo i18n::get('text_create_a_teaching_archive_save_ad831b') .
    i18n::get('text_your_grading_and_gradebook_permissions_088383') .
    '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="action" value="teacher">' .
    '<input type="hidden" name="courseid" value="' . $courseid . '">' . ui::language_select() . i18n::get('text_author_and_student_names_01c6e8') .
    i18n::get('text_pseudonyms_default_9e7ff1');
if (has_capability('local/tomb:exportothersdata', $context)) {
    echo i18n::get('text_real_names_a_recorded_reason_08b48e');
}
echo i18n::get('text_reason_for_including_real_names_dbf044') .
    i18n::get('text_pseudonyms_replace_author_and_name_83d56b') .
    '<button type="submit" class="tomb-button"' . (!$cangenerate ? ' disabled' : '') . i18n::get('text_create_and_receive_a_teaching_fa07a3');
echo i18n::get('text_request_archives_for_students_students_5f5fab') .
    i18n::get('text_a_teacher_making_a_request_b59457');
if ($cangenerate && has_capability('local/tomb:delegate', $context)) {
    echo '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="action" value="delegate">' .
        '<input type="hidden" name="courseid" value="' . $courseid . '">';
    foreach ($users as $user) {
        echo '<label class="tomb-course"><input type="checkbox" name="users[]" value="' . $user->id . '"><span>' . s(fullname($user)) . '</span></label>';
    }
    echo i18n::get('text_select_up_to_students_at_60d120') .
        i18n::get('text_prepare_records_for_selected_students_46c16f');
}
echo i18n::get('text_requests_you_made_for_students_a4f714');
$filters = listing::parameters();
$filters['coursefilter'] = $courseid;
$selection = has_capability('local/tomb:delegate', $context) ? new listing('delegated', $filters, array_keys($users)) : null;
$page = $selection ? $selection->page(optional_param('page', 0, PARAM_INT)) : ['records' => [], 'total' => 0, 'page' => 0, 'perpage' => 25];
$listurl = new moodle_url('/local/tomb/teacher.php', $filters + ['courseid' => $courseid]);
echo listing::form($filters, [], false, ['courseid' => $courseid]) . listing::navigation($page, $listurl) .
    i18n::get('text_owner_request_progress_receipt_d35980');
foreach ($page['records'] as $request) {
    $labels = ['queued' => i18n::get('text_accepted_79bc85'), 'running' => i18n::get('text_collecting_ce889f'), 'ready' => i18n::get('text_collection_complete_160abe'), 'failed' => i18n::get('text_needs_attention_ea81c0'), 'blocked' => i18n::get('text_delivery_blocked_896c26')];
    echo '<tr><td>' . s(fullname($users[$request->subjectid])) . '</td><td>#' . $request->id . '</td><td>' .
        s($labels[$request->status] ?? $request->status) . '</td><td>' . ($request->received ? i18n::get('text_confirmed_by_owner_ee8cda') : i18n::get('text_not_confirmed_8ac888')) . '</td></tr>';
}
echo '</table>' . listing::navigation($page, $listurl) . i18n::get('text_my_learning_archive_3c2287') . $OUTPUT->footer();
