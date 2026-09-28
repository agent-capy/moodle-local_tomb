<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();

use local_tomb\local\config;
use local_tomb\local\manager;
use local_tomb\local\delivery;
use local_tomb\local\audit;
use local_tomb\local\ui;
use local_tomb\local\listing;
use local_tomb\local\i18n;

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
ui::start(get_string('pluginname', 'local_tomb'), '/local/tomb/index.php', $id ? ['id' => $id] : []);
$admin = has_capability('local/tomb:manage', context_system::instance());
if (!has_capability('local/tomb:exportown', context_system::instance()) && !$admin) {
    http_response_code(403);
    throw new moodle_exception('unavailable', 'local_tomb');
}
$request = $id ? $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST) : null;
if ($request && !$admin && $request->subjectid != $USER->id) {
    http_response_code(403);
    throw new moodle_exception('unavailable', 'local_tomb');
}
$cache = $request ? $DB->get_record('local_tomb_cache', ['requestid' => $request->id]) : false;
$actions = $request ? ui::actions($request, $cache ?: null) : [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
    if ($action === 'create') {
        $courses = optional_param_array('courses', [], PARAM_INT);
        $id = manager::request($courses, (int)$USER->id, 'learner', 'pseudonymised', '', 0, true, optional_param('outputlang', i18n::language(), PARAM_ALPHA));
        redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
    }
    if (!$request) {
        throw new invalid_parameter_exception('Archive request required');
    }
    if (in_array($action, ['prepare', 'regenerate', 'received'], true) && empty($actions[$action])) {
        throw new moodle_exception('unavailable', 'local_tomb');
    }
    if ($action === 'prepare') {
        delivery::request($request);
    } else if ($action === 'regenerate') {
        $id = manager::request(json_decode($request->courses, true), (int)$request->subjectid, $request->kind,
            $request->policy, $request->reason, (int)$request->id, true, optional_param('outputlang', $request->outputlang ?? 'ja', PARAM_ALPHA));
    } else if ($action === 'received' && $request->subjectid == $USER->id && $request->downloadcount > 0) {
        $DB->set_field('local_tomb_request', 'received', time(), ['id' => $request->id]);
        audit::add('receipt_confirmed', $request->id);
    }
    redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
    } catch (Throwable $e) {
        $error = ui::request_error($e);
    }
}
$cache = $request ? $DB->get_record('local_tomb_cache', ['requestid' => $request->id]) : false;
$pending = $request && (in_array($request->status, ['queued', 'running'], true) ||
    ($cache && in_array($cache->status, ['queued', 'running', 'waiting'], true)));
if ($pending && config::allowed((int)$request->subjectid)) {
    header('Refresh: 15');
}
echo $OUTPUT->header();
if ($error) {echo $OUTPUT->notification(s($error), 'error');}
echo '<div class="tomb-wrap"><section class="tomb-hero"><div class="tomb-eyebrow">TOMB / YOUR LEARNING, PRESERVED</div>' .
    i18n::get('text_your_learning_ready_for_what_0db397') .
    i18n::get('text_keep_your_learning_records_together_d5a1e1') .
    i18n::get('text_alpha_take_your_learning_with_4c815f');
if ($admin) {
    echo '<p><a href="' . (new moodle_url('/local/tomb/manage.php'))->out() . i18n::get('text_manage_archives_e67154');
}
$teachingcourses = manager::courses((int)$USER->id, 'teacher');
if ($teachingcourses) {
    echo i18n::get('text_teaching_archives_and_student_requests_565411');
}
if ($request) {
    echo '<section class="tomb-box"><div class="tomb-eyebrow">YOUR ARCHIVE / #' . (int)$request->id . '</div>' .
        '<div class="tomb-status">' . s(ui::status($request, $cache ?: null)) . '</div>';
    $step = $request->status === 'queued' ? 0 : ($request->status === 'running' ? 1 :
        (($cache && delivery::valid($cache)) ? 3 : 2));
    echo '<ol class="tomb-steps">';
    foreach ([i18n::get('text_request_7b49c2'), i18n::get('text_collect_records_089096'), i18n::get('text_prepare_zip_ca2ccd'), i18n::get('text_download_008dc8')] as $i => $label) {
        echo '<li class="' . ($i === $step ? 'current' : '') . '">' . s($label) . '</li>';
    }
    echo '</ol><p class="tomb-notice">' . s(ui::guidance($request, $cache ?: null)) . '</p>';
    if ($pending) {
        echo i18n::get('text_you_can_close_this_page_c01a1d');
        if ($request->message) {
            echo '<p class="tomb-muted">' . s($request->message) . '</p>';
        }
        $lastcron = (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        if ($lastcron < time() - 300) {
            echo i18n::get('text_background_processing_has_not_run_050d69') .
                s(userdate($lastcron)) . '</p>';
        }
    }
    $count = $DB->count_records('local_tomb_omission', ['requestid' => $id]);
    echo '<div class="tomb-meta"><div><strong>' . count(json_decode($request->courses, true)) .
        i18n::get('text_selected_courses_9817a1') .
        ($request->totalbytes ? s(display_size($request->totalbytes)) : '—') .
        i18n::get('text_zip_size_03fff4') . $count .
        i18n::get('text_archive_notes_f827ad');
    if ($count) {
        echo i18n::get('text_archive_notes_0b25e3') . $count .
            i18n::get('text_these_include_unsupported_activities_see_6c5a94');
    }
    if ($actions['download']) {
        echo '<a class="tomb-button" href="' . (new moodle_url('/local/tomb/download.php', ['id' => $id]))->out() .
            i18n::get('text_download_learning_archive_fully_extract_ed0e13') .
            i18n::get('text_you_can_read_it_without_61394d') . s(userdate($cache->expires)) .
            i18n::get('text_you_can_prepare_the_same_037ff5');
    } else if ($actions['prepare']) {
        echo ui::post('prepare', $id, i18n::get('text_request_zip_preparation_a59435'));
    }
    if ($actions['received']) {
        echo ui::post('received', $id, i18n::get('text_i_have_extracted_and_checked_318060'), 'tomb-secondary');
    } else if ($request->received) {
        echo i18n::get('text_receipt_confirmed_ee6ba5') . s(userdate($request->received)) . '</p>';
    }
    if ($actions['regenerate']) {
        echo i18n::get('text_create_a_new_version_from_7462ef') .
            ui::post('regenerate', $id, i18n::get('text_create_a_new_version_from_b183b5'), 'tomb-secondary');
    }
    echo i18n::get('text_requested_2b4ee2') . s(userdate($request->timecreated)) .
        ($request->timecollected ? i18n::get('text_collected_0b56b8') . s(userdate($request->timestarted)) . i18n::get('text_fragment_ea4317') .
            s(userdate($request->timecollected)) : '') . i18n::get('text_learning_archive_home_8eaa85');
} else {
    echo i18n::get('text_choose_the_courses_to_keep_30e286') .
        i18n::get('text_only_content_you_are_allowed_1aa102');
    if (config::allowed((int)$USER->id, true)) {
        echo '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() .
            '"><input type="hidden" name="action" value="create">';
        $courses = manager::courses((int)$USER->id);
        foreach ($courses as $course) {
            echo '<label class="tomb-course"><input type="checkbox" name="courses[]" value="' . (int)$course->id . '"' . (in_array((int)$course->id, optional_param_array('courses', [], PARAM_INT), true) ? ' checked' : '') .
                '><span><strong>' . format_string($course->fullname) . '</strong><small>' .
                s($course->shortname) . '</small></span></label>';
        }
        if ($courses) {
            echo ui::language_select(optional_param('outputlang', i18n::language(), PARAM_ALPHA));
            echo i18n::get('text_create_and_receive_my_archive_6ecbe2') .
                i18n::get('text_request_collection_and_zip_preparation_b779d6');
        } else {
            echo i18n::get('text_there_are_no_eligible_courses_4ac920');
        }
        echo '</form>';
    } else {
        echo i18n::get('text_new_archive_requests_are_currently_1049e3');
    }
    echo i18n::get('text_your_previous_archives_7290ef');
    $filters = listing::parameters();
    $selection = new listing('own', $filters);
    $page = $selection->page(optional_param('page', 0, PARAM_INT));
    $listurl = new moodle_url('/local/tomb/index.php', $filters);
    echo listing::form($filters, manager::courses((int)$USER->id) + $teachingcourses) . listing::navigation($page, $listurl);
    $requests = $page['records'];
    foreach ($requests as $record) {
        $itemcache = $DB->get_record('local_tomb_cache', ['requestid' => $record->id]);
        echo '<div class="tomb-item"><a href="' . (new moodle_url('/local/tomb/index.php', ['id' => $record->id]))->out() .
            i18n::get('text_learning_archive_3c38e1') . (int)$record->id . '</a><small>' . s(userdate($record->timecreated)) .
            '</small><span class="tomb-badge">' . s(ui::status($record, $itemcache ?: null)) . '</span></div>';
    }
    if (!$requests) {
        echo '<p class="tomb-muted">' . s(i18n::get('emptyfilter')) . '</p>';
    }
    echo listing::navigation($page, $listurl) . '</section></div>';
}
echo '</div>' . $OUTPUT->footer();
