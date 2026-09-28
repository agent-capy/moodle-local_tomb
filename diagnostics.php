<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/tomb:manage', context_system::instance());

use local_tomb\local\{audit, diagnostics, i18n, ui};

$id = required_param('id', PARAM_INT);
ui::start(i18n::get('diagnostics'), '/local/tomb/diagnostics.php');
$PAGE->set_url(new moodle_url('/local/tomb/diagnostics.php', ['id' => $id]));
$PAGE->set_cacheable(false);
$report = diagnostics::report($id);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    audit::add('diagnostics_exported', $id);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="tomb-diagnostics-' . $id . '.json"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
echo $OUTPUT->header();
echo '<div class="tomb-wrap"><h2>' . s(i18n::get('diagnostics')) . ' #' . $id . '</h2><p>' .
    s(i18n::get('diagnostics_help')) . '</p>';
echo ui::post('export', $id, i18n::get('diagnostics_download'));
echo '<p>' . s(i18n::get('diagnostics_state', (object)['status' => $report['status'],
    'stage' => $report['stage'], 'cache' => $report['cache_status'] ?? '—'])) . '</p>';
foreach (['legacy_collection_error', 'legacy_assembly_error'] as $field) {
    if ($report[$field] !== '') {
        echo '<p><strong>' . s(i18n::get('diagnostics_' . $field)) . '</strong></p><pre>' . s($report[$field]) . '</pre>';
    }
}
echo '<h3>' . s(i18n::get('diagnostics_errors')) . '</h3>';
if (!$report['logs']) {echo '<p>' . s(i18n::get('diagnostics_empty')) . '</p>';}
foreach ($report['logs'] as $log) {
    $data = $log['details'];
    $error = $data['error'];
    $summary = $error['causes'][0]['message'] ?? $error['message'] ?? i18n::get('diagnostics_interrupted');
    echo '<section class="tomb-panel"><h4>' . s(userdate($log['timecreated'])) . ' · ' .
        s(i18n::get('diagnostics_' . $log['severity'])) . ' · ' . s($log['phase']) . '</h4><p>' . s($summary) . '</p>';
    echo '<p>' . s(i18n::get('diagnostics_context', (object)$data['context'])) . '</p>';
    if ($data['runtime']) {
        $runtime = $data['runtime'];
        echo '<p>' . s(i18n::get('diagnostics_runtime', (object)[
            'seconds' => $runtime['elapsed_seconds'], 'memory' => display_size($runtime['memory_bytes']),
            'peak' => display_size($runtime['process_peak_memory_bytes']), 'limit' => $runtime['memory_limit'],
            'time' => $runtime['max_execution_time']])) . '</p>';
    }
    echo '<details><summary>' . s(i18n::get('diagnostics_details')) . '</summary><pre class="tomb-diagnostic-json">' .
        s(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . '</pre></details></section>';
}
echo '<h3>' . s(i18n::get('diagnostics_notes', array_sum($report['notes_by_reason']))) . '</h3><p>' .
    s(i18n::get('diagnostics_notes_help')) . '</p>';
$label = function(string $reason): string {
    return get_string_manager()->string_exists('reason_' . $reason, 'local_tomb') ? i18n::get('reason_' . $reason) : $reason;
};
if ($report['notes_by_reason']) {
    $table = new html_table();
    $table->head = [i18n::get('diagnostics_reason'), i18n::get('diagnostics_count')];
    foreach ($report['notes_by_reason'] as $reason => $count) {$table->data[] = [s($label($reason)), (int)$count];}
    echo html_writer::table($table);
    $table = new html_table();
    $table->head = [i18n::get('diagnostics_course'), i18n::get('diagnostics_activity'),
        i18n::get('diagnostics_reason'), i18n::get('diagnostics_count')];
    foreach ($report['note_groups'] as $group) {
        $table->data[] = [(int)$group->courseid, (int)$group->cmid, s($label($group->reason)), (int)$group->total];
    }
    echo html_writer::table($table);
    if ($report['note_groups_truncated']) {echo '<p>' . s(i18n::get('diagnostics_truncated')) . '</p>';}
}
echo '<p>' . html_writer::link(new moodle_url('/local/tomb/manage.php'), i18n::get('manage')) . '</p></div>';
echo $OUTPUT->footer();
