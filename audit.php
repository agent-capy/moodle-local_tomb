<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/tomb:viewaudit', context_system::instance());
use local_tomb\local\{audit, ui, i18n};
ui::start(i18n::get('text_tomb_audit_log_0818c9'), '/local/tomb/audit.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    audit::add('audit_exported');
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="tomb-audit.jsonl"');
    header('Cache-Control: no-store');
    $rows = $DB->get_recordset('local_tomb_audit', null, 'id ASC');
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
    $rows->close();
    exit;
}
echo $OUTPUT->header();
echo i18n::get('text_audit_log_chain_integrity_5508a5') . (audit::verify() ? i18n::get('text_verified_1f12f1') : i18n::get('text_mismatch_detected_6b1edf')) . '</p>' .
    i18n::get('text_track_requests_authorised_downloads_notifications_a831dd') .
    ui::post('export', 0, i18n::get('text_download_audit_log_as_jsonl_05bf32')) . i18n::get('text_time_event_version_actor_id_25e767');
foreach ($DB->get_records('local_tomb_audit', null, 'id DESC', '*', 0, 100) as $row) {
    echo '<tr><td>' . s(userdate($row->timecreated)) . '</td><td>' . s($row->event) . '</td><td>' .
        $row->requestid . '</td><td>' . $row->actorid . '</td></tr>';
}
echo i18n::get('text_archive_management_23f47c') . $OUTPUT->footer();
