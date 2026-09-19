<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();
use local_tomb\local\{config, manager, ui};

ui::start('担当コースのアーカイブ', '/local/tomb/teacher.php');
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
    if (has_capability('local/tomb:exportteacher', $context, $userid) || !config::allowed((int)$userid, true)) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'teacher') {
        $id = manager::request([$courseid], (int)$USER->id, 'teacher', required_param('policy', PARAM_ALPHA),
            optional_param('reason', '', PARAM_TEXT), 0, true);
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
                $accepted[] = manager::request([$courseid], $userid);
            } catch (moodle_exception $e) {
                $failed++;
            }
        }
        $notice = count($accepted) . ' 件を受け付けました。' . ($failed ? $failed . ' 件は受付できませんでした（直近の申請・利用資格をご確認ください）。' : '');
    }
}
echo $OUTPUT->header();
echo '<div class="tomb-wrap"><div class="tomb-hero"><div class="tomb-eyebrow">TOMB / COURSE ARCHIVES</div>' .
    '<h1>授業の成果を、次につなぐ。</h1><p>担当コースの保存と、学生本人への学習記録の交付を進められます。</p></div>';
if ($notice) {
    echo $OUTPUT->notification(s($notice), 'info');
}
echo '<form method="get" class="mb-4"><label>コース <select name="courseid" class="custom-select">';
foreach ($courses as $course) {
    echo '<option value="' . $course->id . '"' . ($course->id == $courseid ? ' selected' : '') . '>' . s($course->fullname) . '</option>';
}
echo '</select></label> <button type="submit" class="tomb-button tomb-secondary">選択</button></form><div class="tomb-grid">';
echo '<section class="tomb-box"><h2>教師版を作成</h2><p class="tomb-muted">担当者に閲覧が許可された教材と在籍者の学習成果を保存します。' .
    'アルファ版の課題フィードバックと成績表は、学生本人に公開済みの内容が対象です。</p>' .
    '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="action" value="teacher">' .
    '<input type="hidden" name="courseid" value="' . $courseid . '"><label>投稿者・学生の表示 <select name="policy" class="custom-select">' .
    '<option value="pseudonymised">仮名で表示（標準）</option>';
if (has_capability('local/tomb:exportothersdata', $context)) {
    echo '<option value="full">実名で表示（理由の記録が必要）</option>';
}
echo '</select></label><label class="d-block">実名で保存する理由 <textarea name="reason" class="form-control" rows="2"></textarea></label>' .
    '<p class="tomb-muted">仮名化は投稿者・氏名欄が対象です。本文やファイル内の個人情報は書き換えません。</p>' .
    '<button type="submit" class="tomb-button">教師版を作成して受け取る →</button></form></section>';
echo '<section class="tomb-box"><h2>学生版の作成を代行</h2><p class="tomb-muted">収集完了後は本人へ通知します。学生はログインしてZIPの準備を依頼できます。' .
    '代行した教師が学生版を取得することはできません。</p>';
if (has_capability('local/tomb:delegate', $context)) {
    echo '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() . '"><input type="hidden" name="action" value="delegate">' .
        '<input type="hidden" name="courseid" value="' . $courseid . '">';
    foreach ($users as $user) {
        echo '<label class="tomb-course"><input type="checkbox" name="users[]" value="' . $user->id . '"><span>' . s(fullname($user)) . '</span></label>';
    }
    echo '<p class="tomb-muted">1回につき20名まで。検証モードでは指定コーホートの学生のみ表示します。</p>' .
        '<button type="submit" class="tomb-button">選んだ学生の記録を準備</button></form>';
}
echo '</section></div><section class="tomb-box"><h2>代行した申請</h2><table class="tomb-table"><tr><th>対象者</th><th>受付</th><th>進捗</th><th>受取確認</th></tr>';
foreach ($DB->get_records_select('local_tomb_request', 'requesterid = ? AND subjectid <> ?', [$USER->id, $USER->id], 'id DESC', '*', 0, 50) as $request) {
    if (json_decode($request->courses, true) !== [$courseid]) {
        continue;
    }
    if (!isset($users[$request->subjectid])) {
        continue;
    }
    $labels = ['queued' => '受付済み', 'running' => '収集中', 'ready' => '収集完了', 'failed' => '要確認', 'blocked' => '配信停止'];
    echo '<tr><td>' . s(fullname($users[$request->subjectid])) . '</td><td>#' . $request->id . '</td><td>' .
        s($labels[$request->status] ?? $request->status) . '</td><td>' . ($request->received ? '本人が確認済み' : '未確認') . '</td></tr>';
}
echo '</table></section><a href="index.php">本人向け画面へ</a></div>' . $OUTPUT->footer();
