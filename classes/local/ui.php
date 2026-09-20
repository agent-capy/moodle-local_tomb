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

    public static function post(string $action, int $id, string $label, string $class = '', array $hidden = []): string {
        $fields = '';
        foreach ($hidden as $key => $value) {
            $fields .= '<input type="hidden" name="' . s($key) . '" value="' . s($value) . '">';
        }
        return '<form method="post" action=""><input type="hidden" name="sesskey" value="' . sesskey() .
            '"><input type="hidden" name="action" value="' . s($action) . '"><input type="hidden" name="id" value="' .
            $id . '">' . $fields . ($action === 'regenerate' ? self::language_select() : '') . '<button type="submit" class="tomb-button ' . s($class) . '">' . s($label) . '</button></form>';
    }

    public static function request_error(\Throwable $error): string {
        $code = $error instanceof \moodle_exception ? $error->errorcode : 'internal';
        audit::add('request_rejected', 0, ['code' => $code, 'exception' => get_class($error)]);
        return in_array($code, ['ratelimit', 'unavailable', 'capacity', 'workerbusy', 'selectcourses', 'reasonrequired'], true) ?
            i18n::get($code) : i18n::get('requestfailed');
    }

    public static function measurements(string $json): string {
        $metrics = json_decode($json, true) ?: [];
        $html = '<details><summary>' . s(i18n::get('measurements')) . '</summary><dl>';
        foreach (['collection', 'assembly'] as $phase) {
            if (!isset($metrics[$phase])) {continue;}
            $html .= '<dt>' . s(i18n::get('metric' . $phase)) . '</dt><dd>' .
                s(format_float($metrics[$phase]['seconds'], 2) . ' ' . i18n::get('metricseconds')) .
                ' · ' . s(i18n::get('metricpeak') . ': ' . display_size($metrics[$phase]['process_peak_bytes'])) . '</dd>';
        }
        foreach (['shared_logical_bytes' => 'metricshared', 'inline_stored_bytes' => 'metricinline',
                'privacy_index_bytes' => 'metricprivacy', 'zip_bytes' => 'metriczip'] as $field => $label) {
            if (isset($metrics['materials'][$field])) {
                $html .= '<dt>' . s(i18n::get($label)) . '</dt><dd>' . s(display_size($metrics['materials'][$field])) . '</dd>';
            }
        }
        return $html . '</dl><p>' . s(i18n::get('processpeaknote')) . '</p><p>' . s(i18n::get('metricscopyhelp')) . '</p></details>';
    }

    public static function language_select(?string $selected = null): string {
        $selected = $selected ?? i18n::language();
        $html = '<label class="d-block">' . s(i18n::get('outputlanguage')) . '<select class="custom-select" name="outputlang">';
        foreach (['ja' => i18n::get('languageja'), 'en' => i18n::get('languageen')] as $code => $label) {
            $html .= '<option value="' . $code . '"' . ($code === $selected ? ' selected' : '') . '>' . s($label) . '</option>';
        }
        return $html . '</select></label>';
    }

    public static function state(object $request, ?object $cache = null): string {
        if ($request->lasterror === 'privacy_erasure') {return 'privacy';}
        if ($request->timepurged) {return 'purged';}
        $deadline = (int)config::current('deliverydeadline', 0);
        if (!$request->isrehearsal && $deadline && $deadline <= time()) {return 'deadline';}
        if (!config::allowed((int)$request->subjectid) || !manager::accessible($request, true) ||
                (bool)$request->isrehearsal !== (config::mode() === 'rehearsal')) {return 'unavailable';}
        if ($request->status === 'blocked') {return 'blocked';}
        if ($request->status === 'failed') {return 'failed';}
        if ($request->status === 'queued') {return 'queued';}
        if ($request->status === 'running') {return 'running';}
        if ($cache && delivery::valid($cache)) {return 'download';}
        return match ($cache->status ?? '') {
            'queued', 'running' => 'assembling',
            'waiting' => 'waiting',
            'oversize' => 'oversize',
            'failed' => 'retry',
            'expired', 'ready' => 'expiredcache',
            default => $request->lasterror ? 'retry' : 'collected',
        };
    }

    public static function status(object $request, ?object $cache = null): string {
        return i18n::get('state_' . self::state($request, $cache));
    }

    public static function guidance(object $request, ?object $cache = null): string {
        return i18n::get('help_' . self::state($request, $cache));
    }

    public static function actions(object $request, ?object $cache = null): array {
        global $USER;
        $state = self::state($request, $cache);
        $access = manager::accessible($request, true);
        return ['download' => $access && $state === 'download',
            'prepare' => $access && in_array($state, ['collected', 'retry', 'expiredcache'], true),
            'regenerate' => $state !== 'privacy' && !in_array($state, ['queued', 'running', 'assembling', 'waiting'], true) &&
                config::allowed((int)$request->subjectid, true) && $access,
            'received' => $access && !$request->timepurged && $request->status === 'ready' &&
                $request->subjectid == $USER->id && $request->downloadcount > 0 && !$request->received];
    }
}
