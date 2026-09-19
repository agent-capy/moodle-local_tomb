<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/tomb:manage', context_system::instance());

use local_tomb\local\{audit, config, delivery, manager, ui};

ui::start('アーカイブ管理', '/local/tomb/manage.php');
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$scope = optional_param('scope', config::mode() === 'rehearsal' ? 'rehearsal' : 'production', PARAM_ALPHA);
$rehearsal = $scope === 'rehearsal' ? 1 : 0;
$unreceived = optional_param('unreceived', 0, PARAM_BOOL);
$where = 'isrehearsal = ?' . ($unreceived ? ' AND received = 0 AND timepurged = 0' : '');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if ($action === 'csv') {
            audit::add('receipt_report_exported', 0, ['rehearsal' => $rehearsal, 'unreceived' => $unreceived]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tomb-receipts.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['request', 'subject', 'name', 'kind', 'mode', 'status', 'notified', 'download_started', 'receipt_confirmed'], ',', '"', '');
            $records = $DB->get_recordset_select('local_tomb_request', $where, [$rehearsal], 'id DESC');
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
            $mode = required_param('mode', PARAM_ALPHAEXT);
            if (!in_array($mode, ['disabled', 'rehearsal', 'active', 'delivery_only'], true)) {
                throw new invalid_parameter_exception('Invalid mode');
            }
            if ($mode === 'rehearsal' && !$DB->record_exists('cohort',
                    ['id' => (int)config::get('rehearsalcohortid', 0)])) {
                throw new moodle_exception('unavailable', 'local_tomb');
            }
            if (in_array($mode, ['active', 'delivery_only']) &&
                    (!(bool)config::get('setupconfirmed', 0) || (int)config::get('deliverydeadline', 0) <= time())) {
                throw new moodle_exception('unavailable', 'local_tomb');
            }
            audit::add('mode_changed', 0, ['from' => config::mode(), 'to' => $mode]);
            set_config('operationmode', $mode, 'local_tomb');
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
        redirect(new moodle_url('/local/tomb/manage.php'));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
echo $OUTPUT->header();
echo '<div class="tomb-wrap"><div class="tomb-hero"><span class="tomb-eyebrow">TOMB / ADMINISTRATION</span>' .
    '<h1>学習記録を、確実に届ける。</h1><p>生成の進捗、配信の状態、本人の受取確認をまとめて確認できます。</p></div>';
if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}
$counts = $DB->get_records_sql('SELECT status, COUNT(*) AS total FROM {local_tomb_request} WHERE isrehearsal = ? GROUP BY status', [$rehearsal]);
echo '<div class="tomb-meta">';
foreach (['queued' => '受付', 'running' => '生成中', 'ready' => '生成済み', 'failed' => '要確認', 'blocked' => '配信停止'] as $status => $label) {
    echo '<div><strong>' . (int)($counts[$status]->total ?? 0) . '</strong><span>' . s($label) . '</span></div>';
}
echo '</div><div class="tomb-box"><h2>運用モード</h2><p>検証モードでは指定したコーホートの利用者のみが対象になります。</p>' .
    '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() . '">' .
    '<input type="hidden" name="action" value="mode"><select name="mode" class="custom-select mr-2">';
foreach (['disabled' => '無効', 'rehearsal' => '検証', 'active' => '本番', 'delivery_only' => '配信のみ'] as $mode => $label) {
    echo '<option value="' . $mode . '"' . (config::mode() === $mode ? ' selected' : '') . '>' . $label . '</option>';
}
echo '</select><button class="tomb-button" type="submit">モードを変更</button></form><p class="mt-3"><a href="' .
    (new moodle_url('/admin/settings.php', ['section' => 'local_tomb_settings'])) . '">容量・期限・検証対象の設定</a></p></div>';
echo '<p><a href="teacher.php">コースごとの学生版作成・一括受付</a></p>';
if (has_capability('local/tomb:viewaudit', context_system::instance())) {
    echo '<p><a href="audit.php">監査記録の確認・書き出し</a></p>';
}
echo '<div class="tomb-box"><h2>アーカイブ一覧</h2><form method="get"><label>集計対象 <select name="scope" class="custom-select">' .
    '<option value="production"' . (!$rehearsal ? ' selected' : '') . '>本番</option>' .
    '<option value="rehearsal"' . ($rehearsal ? ' selected' : '') . '>検証</option></select></label> ' .
    '<label><input type="checkbox" name="unreceived" value="1"' . ($unreceived ? ' checked' : '') . '> 未受領のみ</label> ' .
    '<button class="tomb-button tomb-secondary" type="submit">表示を更新</button></form>' .
    ui::post('csv', 0, '表示条件の受領状況を CSV で取得', 'tomb-secondary') .
    '<p class="tomb-muted">検証と本番を分け、版ごとの受領状況を表示します。</p><div class="table-responsive"><table class="table"><thead><tr>' .
    '<th>版</th><th>対象者</th><th>状態</th><th>受取状況</th><th>操作</th></tr></thead><tbody>';
$requests = $DB->get_records_select('local_tomb_request', $where, [$rehearsal], 'id DESC', '*', 0, 100);
foreach ($requests as $request) {
    $subject = core_user::get_user($request->subjectid);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id]);
    echo '<tr><td>#' . $request->id . '<br><small>' . s(userdate($request->timecreated)) . '</small></td><td>' .
        s($subject ? fullname($subject) : '(deleted)') . '</td><td>' . s(ui::status($request, $cache ?: null)) .
        '<br><small>' . s($request->message) . '</small></td><td>' . ($request->received ? '本人が受取確認済み' :
        ($request->downloadcount ? 'ダウンロード開始を記録' : ($request->notified ? '通知済み・未受取' : '通知待ち'))) . '</td><td>';
    if (manager::accessible($request) && !$request->timepurged) {
        echo '<a href="' . (new moodle_url('/local/tomb/index.php', ['id' => $request->id])) . '">詳細</a>';
        if ($request->status === 'ready' && !$request->received) {
            echo ui::post('remind', (int)$request->id, '本人に再通知', 'tomb-secondary');
        }
    }
    if (!$request->timepurged && ($request->isrehearsal || (int)config::get('deliverydeadline', 0) <= time())) {
        echo '<details><summary>取得用データの削除</summary><form method="post">' .
            '<input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="action" value="purge">' .
            '<input type="hidden" name="id" value="' . $request->id . '"><label><input type="checkbox" name="confirm" value="1" required> ' .
            'この版を再取得できなくなることを確認しました</label><button class="tomb-button" type="submit">この版の材料を削除</button></form></details>';
    }
    if ($request->lasterror) {
        echo '<details><summary>エラー情報</summary><pre>' . s($request->lasterror) . '</pre></details>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div><p>最新100件を表示。通知、ダウンロード開始、本人の受取確認はそれぞれ別に記録されます。</p></div>' .
    '<p><a href="' . new moodle_url('/local/tomb/index.php') . '">本人向け画面へ</a></p></div>';
echo $OUTPUT->footer();
