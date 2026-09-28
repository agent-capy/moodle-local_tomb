<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/tomb:manage', context_system::instance());

use local_tomb\local\{audit, config, delivery, manager, ui, listing, i18n};

ui::start(i18n::get('text_archive_management_1608f3'), '/local/tomb/manage.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$filters = listing::parameters();
$rehearsal = $filters['scope'] === 'rehearsal' ? 1 : 0;
$selection = new listing('admin', $filters);
$page = $selection->page(optional_param('page', 0, PARAM_INT));
$listurl = new moodle_url('/local/tomb/manage.php', $filters + ['page' => $page['page']]);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if ($action === 'csv') {
            audit::add('receipt_report_exported', 0, $filters);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tomb-receipts.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['request', 'subject', 'name', 'kind', 'mode', 'status', 'notified', 'download_started', 'receipt_confirmed'], ',', '"', '');
            $records = $selection->recordset();
            foreach ($records as $record) {
                $user = core_user::get_user($record->subjectid);
                $name = $user ? fullname($user) : '(deleted)';
                if (preg_match('/^[\s]*[=+@-]/u', $name)) {
                    $name = "'" . $name;
                }
                fputcsv($out, [$record->id, $record->subjectid, $name, $record->kind, $record->isrehearsal ? 'rehearsal' : 'production',
                    $record->status, $record->notified, $record->lastdownload, $record->received], ',', '"', '');
            }
            $records->close();
            fclose($out);
            exit;
        } else if ($action === 'mode') {
            config::set_mode(required_param('mode', PARAM_ALPHAEXT));
        } else if ($action === 'purge') {
            if (!required_param('confirm', PARAM_BOOL)) {
                throw new invalid_parameter_exception('Confirmation required');
            }
            delivery::purge($DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST));
        } else if ($action === 'remind') {
            $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
            if ($request->status === 'ready' && !$request->timepurged && !$request->received && manager::accessible($request)) {
                manager::notify($request, 'reminder');
                audit::add('reminder_requested', $id);
            }
        }
        redirect($listurl);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
echo $OUTPUT->header();
echo '<div class="tomb-wrap"><div class="tomb-hero"><span class="tomb-eyebrow">TOMB / ADMINISTRATION</span>' .
    i18n::get('text_help_every_learner_receive_their_e5ba7f');
if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}
$legacycount = $DB->count_records_select('local_tomb_request', 'privacyversion = 0 AND timepurged = 0');
if ($legacycount) {
    echo i18n::get('text_retained_archives_from_the_earlier_8f03f7') . $legacycount .
        i18n::get('text_their_zips_remain_unchanged_an_516986');
}
$counts = $DB->get_records_sql('SELECT status, COUNT(*) AS total FROM {local_tomb_request} WHERE isrehearsal = ? GROUP BY status', [$rehearsal]);
echo '<div class="tomb-meta">';
foreach (['queued' => i18n::get('text_queued_312b15'), 'running' => i18n::get('text_collecting_57c08c'), 'ready' => i18n::get('text_collected_a39cf9'), 'failed' => i18n::get('text_needs_attention_ea81c0'), 'blocked' => i18n::get('text_delivery_blocked_896c26')] as $status => $label) {
    echo '<div><strong>' . (int)($counts[$status]->total ?? 0) . '</strong><span>' . s($label) . '</span></div>';
}
echo i18n::get('text_operation_mode_rehearsal_is_limited_d7bbfa') .
    '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() . '">' .
    '<input type="hidden" name="action" value="mode"><select name="mode" class="custom-select mr-2">';
foreach (['disabled' => i18n::get('text_disabled_7a2074'), 'rehearsal' => i18n::get('text_rehearsal_026c7b'), 'active' => i18n::get('text_production_5ede69'), 'delivery_only' => i18n::get('text_delivery_only_45c19a')] as $mode => $label) {
    echo '<option value="' . $mode . '"' . (config::mode() === $mode ? ' selected' : '') . '>' . $label . '</option>';
}
echo i18n::get('text_change_mode_a_href_27df15') .
    (new moodle_url('/admin/settings.php', ['section' => 'local_tomb_settings'])) . i18n::get('text_storage_deadline_and_rehearsal_settings_71a2a1');
echo i18n::get('text_student_and_batch_requests_by_b0955b');
if (has_capability('local/tomb:viewaudit', context_system::instance())) {
    echo i18n::get('text_inspect_and_export_the_audit_ffe4e8');
}
$filtercourses = $DB->get_records_select('course', 'id <> ?', [SITEID], 'fullname', 'id,fullname');
echo i18n::get('text_archives_f923b9') . listing::form($filters, $filtercourses, true) .
    ui::post('csv', 0, i18n::get('text_download_receipts_matching_these_filters_b209d2'), 'tomb-secondary', $filters) .
    listing::navigation($page, $listurl) .
    i18n::get('text_receipts_are_tracked_per_version_150456') .
    i18n::get('text_version_owner_status_receipt_actions_ce456f');
$requests = $page['records'];
foreach ($requests as $request) {
    $subject = core_user::get_user($request->subjectid);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id]);
    echo '<tr><td>#' . $request->id . '<br><small>' . s(userdate($request->timecreated)) . '</small></td><td>' .
        s($subject ? fullname($subject) : '(deleted)') . '</td><td>' . s(ui::status($request, $cache ?: null)) .
        '<br><small>' . s($request->message) . '</small></td><td>' . ($request->received ? i18n::get('text_receipt_confirmed_by_owner_446a62') :
        ($request->downloadcount ? i18n::get('text_download_start_recorded_042417') : ($request->notified ? i18n::get('text_notified_not_collected_4e1dca') : i18n::get('text_awaiting_notification_40f676')))) . '</td><td>';
    if (manager::accessible($request) && !$request->timepurged) {
        echo '<a href="' . (new moodle_url('/local/tomb/index.php', ['id' => $request->id])) . i18n::get('text_details_d29166');
        if ($request->status === 'ready' && !$request->received) {
            echo ui::post('remind', (int)$request->id, i18n::get('text_send_a_reminder_to_the_8e950a'), 'tomb-secondary');
        }
    }
    if (!$request->timepurged && ($request->isrehearsal || (int)config::get('deliverydeadline', 0) <= time())) {
        echo i18n::get('text_delete_retained_materials_60c411') .
            '<input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="action" value="purge">' .
            '<input type="hidden" name="id" value="' . $request->id . '"><label><input type="checkbox" name="confirm" value="1" required> ' .
            i18n::get('text_i_understand_that_this_version_2ea840');
    }
    echo '<p>' . html_writer::link(new moodle_url('/local/tomb/diagnostics.php', ['id' => $request->id]),
        i18n::get('diagnostics')) . '</p>';
    if (!empty($request->metrics)) {echo ui::measurements($request->metrics);}
    if ($request->lasterror) {
        echo i18n::get('text_error_details_a19207') . s($request->lasterror) . '</pre></details>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>' . listing::navigation($page, $listurl) . '<p>' . s(i18n::get('receiptexplanation')) . '</p></div>' .
    '<p><a href="' . new moodle_url('/local/tomb/index.php') . i18n::get('text_my_learning_archive_56d216');
echo $OUTPUT->footer();
