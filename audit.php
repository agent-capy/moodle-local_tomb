<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/tomb:viewaudit', context_system::instance());
use local_tomb\local\{audit, ui};
ui::start('Tomb 監査記録', '/local/tomb/audit.php');
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
echo '<div class="tomb-wrap"><h1>監査記録</h1><p>連鎖の整合性：' . (audit::verify() ? '確認済み' : '不一致があります') . '</p>' .
    '<p>申請・権限付き取得・通知・運用モードの変更を追跡できます。ハッシュ連鎖は外部へ保存したチェックポイントとの照合に利用できます。</p>' .
    ui::post('export', 0, '監査記録を JSONL で取得') . '<table class="tomb-table"><tr><th>日時</th><th>操作</th><th>版</th><th>実行者ID</th></tr>';
foreach ($DB->get_records('local_tomb_audit', null, 'id DESC', '*', 0, 100) as $row) {
    echo '<tr><td>' . s(userdate($row->timecreated)) . '</td><td>' . s($row->event) . '</td><td>' .
        $row->requestid . '</td><td>' . $row->actorid . '</td></tr>';
}
echo '</table><p><a href="manage.php">管理画面へ</a></p></div>' . $OUTPUT->footer();
