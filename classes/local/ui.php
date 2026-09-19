<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class ui {
    public static function start(string $title, string $url, array $params = []): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url($url, $params));
        $PAGE->set_title($title);
        $PAGE->set_heading(get_string('pluginname', 'local_tomb'));
        $PAGE->set_pagelayout('standard');
        $PAGE->requires->css('/local/tomb/styles.css');
    }

    public static function post(string $action, int $id, string $label, string $class = ''): string {
        return '<form method="post" action=""><input type="hidden" name="sesskey" value="' . sesskey() .
            '"><input type="hidden" name="action" value="' . s($action) . '"><input type="hidden" name="id" value="' .
            $id . '"><button type="submit" class="tomb-button ' . s($class) . '">' . s($label) . '</button></form>';
    }

    public static function status(object $request, ?object $cache = null): string {
        if ($request->timepurged) {
            return '取得用データの保管は終了しました';
        }
        if ($request->status === 'failed' || $request->status === 'blocked') {
            return '記録の準備に確認が必要です';
        }
        if ($request->status === 'queued') {
            return '順番をお待ちください';
        }
        if ($request->status === 'running') {
            return '学習記録をまとめています';
        }
        if ($cache && delivery::valid($cache)) {
            return '学習記録を受け取れます';
        }
        return match ($cache->status ?? '') {
            'queued', 'running' => 'ダウンロード用の ZIP を準備しています',
            'waiting' => '空き容量が確保できるまで待機しています',
            'oversize' => '管理者による保存容量の確認が必要です',
            'failed' => 'ZIP の準備をやり直してください',
            'expired' => '同じ記録をもう一度準備できます',
            default => '学習記録の準備が完了しました',
        };
    }
}

