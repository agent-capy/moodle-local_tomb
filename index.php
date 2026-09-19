<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_login();

use local_tomb\local\config;
use local_tomb\local\manager;
use local_tomb\local\delivery;
use local_tomb\local\audit;
use local_tomb\local\ui;

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
ui::start(get_string('pluginname', 'local_tomb'), '/local/tomb/index.php', $id ? ['id' => $id] : []);
$admin = has_capability('local/tomb:manage', context_system::instance());
if (!config::allowed((int)$USER->id) && !$admin) {
    http_response_code(403);
    throw new moodle_exception('unavailable', 'local_tomb');
}
$request = $id ? $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST) : null;
if ($request && !manager::accessible($request)) {
    http_response_code(403);
    throw new moodle_exception('unavailable', 'local_tomb');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'create') {
        $courses = optional_param_array('courses', [], PARAM_INT);
        $id = manager::request($courses, (int)$USER->id, 'learner', 'pseudonymised', '', 0, true);
        redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
    }
    if (!$request) {
        throw new invalid_parameter_exception('Archive request required');
    }
    if ($action === 'prepare') {
        delivery::request($request);
    } else if ($action === 'regenerate') {
        $id = manager::request(json_decode($request->courses, true), (int)$request->subjectid, $request->kind,
            $request->policy, $request->reason, (int)$request->id, true);
    } else if ($action === 'received' && $request->subjectid == $USER->id && $request->downloadcount > 0) {
        $DB->set_field('local_tomb_request', 'received', time(), ['id' => $request->id]);
        audit::add('receipt_confirmed', $request->id);
    }
    redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
}
$cache = $request ? $DB->get_record('local_tomb_cache', ['requestid' => $request->id]) : false;
$pending = $request && (in_array($request->status, ['queued', 'running'], true) ||
    ($cache && in_array($cache->status, ['queued', 'running', 'waiting'], true)));
if ($pending) {
    header('Refresh: 5');
}
echo $OUTPUT->header();
echo '<div class="tomb-wrap"><section class="tomb-hero"><div class="tomb-eyebrow">TOMB / YOUR LEARNING, PRESERVED</div>' .
    '<h1>学んだことを、これからの自分へ。</h1><p>教材、提出した課題、フィードバック、成績。<br>' .
    'あなたの学習記録をひとつにまとめて、いつでも読み返せる形で手元に残せます。</p>' .
    '<span class="tomb-pill">ALPHA · 学習記録の持ち出し</span></section>';
if ($admin) {
    echo '<p><a href="' . (new moodle_url('/local/tomb/manage.php'))->out() . '">管理画面</a></p>';
}
foreach (manager::courses((int)$USER->id) as $course) {
    if (has_capability('local/tomb:exportteacher', context_course::instance($course->id))) {
        echo '<p><a href="teacher.php">担当コースの保存・学生版の作成代行</a></p>';
        break;
    }
}
if ($request) {
    echo '<section class="tomb-box"><div class="tomb-eyebrow">YOUR ARCHIVE / #' . (int)$request->id . '</div>' .
        '<div class="tomb-status">' . s(ui::status($request, $cache ?: null)) . '</div>';
    $step = $request->status === 'queued' ? 0 : ($request->status === 'running' ? 1 :
        (($cache && delivery::valid($cache)) ? 3 : 2));
    echo '<ol class="tomb-steps">';
    foreach (['01 受付', '02 学習記録の収集', '03 ZIP の準備', '04 お受け取り'] as $i => $label) {
        echo '<li class="' . ($i === $step ? 'current' : '') . '">' . s($label) . '</li>';
    }
    echo '</ol>';
    if ($pending) {
        echo '<p class="tomb-muted">この画面を閉じても処理は続きます。ZIP の検証が終わると通知が届きます。</p>';
        if ($request->message) {
            echo '<p class="tomb-muted">' . s($request->message) . '</p>';
        }
        $lastcron = (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        if ($lastcron < time() - 300) {
            echo '<p class="tomb-warning">バックグラウンド処理がしばらく動いていません。管理者へお知らせください。最終実行：' .
                s(userdate($lastcron)) . '</p>';
        }
    }
    $count = $DB->count_records('local_tomb_omission', ['requestid' => $id]);
    echo '<div class="tomb-meta"><div><strong>' . count(json_decode($request->courses, true)) .
        '</strong><span class="tomb-muted">選択したコース</span></div><div><strong>' .
        ($request->totalbytes ? s(display_size($request->totalbytes)) : '—') .
        '</strong><span class="tomb-muted">ZIP サイズ</span></div><div><strong>' . $count .
        '</strong><span class="tomb-muted">保存内容の注記</span></div></div>';
    if ($count) {
        echo '<p class="tomb-warning">未対応の活動など、保存内容に ' . $count .
            ' 件の注記があります。ZIP 内の「保存内容について」で確認できます。</p>';
    }
    if ($cache && delivery::valid($cache) && !$request->timepurged && $request->status === 'ready') {
        echo '<a class="tomb-button" href="' . (new moodle_url('/local/tomb/download.php', ['id' => $id]))->out() .
            '">学習記録をダウンロード ↓</a><p class="tomb-muted">ZIP をすべて展開して index.html を開いてください。' .
            '閲覧時のネット接続やログインは不要です。<br>ZIP の一時保存は最長 ' . s(userdate($cache->expires)) .
            ' までです。終了後も取得期限内は同じ版を再準備できます。</p>';
    } else if ($request->status === 'ready' && !$pending && !$request->timepurged) {
        echo ui::post('prepare', $id, 'ZIP の準備を依頼する');
    }
    if ($request->downloadcount > 0 && $request->subjectid == $USER->id && !$request->received) {
        echo ui::post('received', $id, '展開して記録を確認しました', 'tomb-secondary');
    } else if ($request->received) {
        echo '<p class="tomb-muted">受け取り確認済み：' . s(userdate($request->received)) . '</p>';
    }
    if (!$pending && config::allowed($request->subjectid, true)) {
        echo '<hr><p class="tomb-muted">現在の学習内容から新しい版を作る場合はこちら。以前の版も保管してください。</p>' .
            ui::post('regenerate', $id, '現在の内容から新しい版を作る', 'tomb-secondary');
    }
    echo '<p class="tomb-muted">受付：' . s(userdate($request->timecreated)) .
        ($request->timecollected ? '<br>収集：' . s(userdate($request->timestarted)) . ' 〜 ' .
            s(userdate($request->timecollected)) : '') . '</p></section><a href="index.php">学習記録のホームへ</a>';
} else {
    echo '<div class="tomb-grid"><section class="tomb-box"><h2>残したいコースを選ぶ</h2>' .
        '<p class="tomb-muted">本人に閲覧が許可された内容を保存します。生成中に確認できた削除は反映されます。</p>';
    if (config::allowed((int)$USER->id, true)) {
        echo '<form method="post"><input type="hidden" name="sesskey" value="' . sesskey() .
            '"><input type="hidden" name="action" value="create">';
        $courses = manager::courses((int)$USER->id);
        foreach ($courses as $course) {
            echo '<label class="tomb-course"><input type="checkbox" name="courses[]" value="' . (int)$course->id .
                '"><span><strong>' . format_string($course->fullname) . '</strong><small>' .
                s($course->shortname) . '</small></span></label>';
        }
        if ($courses) {
            echo '<button class="tomb-button" type="submit">記録を作成して受け取る →</button>' .
                '<p class="tomb-muted">収集と ZIP 準備を依頼します。完了後に通知します。</p>';
        } else {
            echo '<p class="tomb-muted">現在、対象となるコースはありません。</p>';
        }
        echo '</form>';
    } else {
        echo '<p class="tomb-muted">新しい記録の作成は現在受け付けていません。</p>';
    }
    echo '</section><section class="tomb-box"><h2>これまでに作成した記録</h2>';
    $requests = $DB->get_records('local_tomb_request', ['subjectid' => $USER->id], 'id DESC', '*', 0, 50);
    foreach ($requests as $record) {
        $itemcache = $DB->get_record('local_tomb_cache', ['requestid' => $record->id]);
        echo '<div class="tomb-item"><a href="' . (new moodle_url('/local/tomb/index.php', ['id' => $record->id]))->out() .
            '">学習記録 #' . (int)$record->id . '</a><small>' . s(userdate($record->timecreated)) .
            '</small><span class="tomb-badge">' . s(ui::status($record, $itemcache ?: null)) . '</span></div>';
    }
    if (!$requests) {
        echo '<p class="tomb-muted">作成した記録がここに並びます。<br>最初の記録を作成してみましょう。</p>';
    }
    echo '</section></div>';
}
echo '</div>' . $OUTPUT->footer();
