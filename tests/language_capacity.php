<?php
// License: GNU GPL v3 or later. At most five sequential, bounded fixture archives; purges only this run's material.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');
use local_tomb\local\{audit, config, delivery, estimate, manager};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {cli_error('Use --run only on the dedicated Tomb fixture');}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$originaluser = $USER;
$originalanguage = force_current_language('ja');
$ids = []; $results = []; $checks = 0;
function language_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) {throw new RuntimeException('FAILED: ' . $message);}
    $checks++; mtrace('PASS: ' . $message);
}
try {
    $CFG->forced_plugin_settings['local_tomb']['requestinterval'] = 0;
    foreach ([['learner', 'ja'], ['learner', 'en'], ['teacher', 'ja'], ['teacher', 'en'], ['other', 'en']] as [$role, $lang]) {
        if (moodle_needs_upgrading() || !empty($CFG->maintenance_enabled) || !config::capacity()) {
            throw new RuntimeException('Shared-site preflight changed; no next request started');
        }
        $user = $DB->get_record('user', ['username' => 'tomb.alpha.' . $role, 'idnumber' => 'tomb-alpha-fixture-' . $role], '*', MUST_EXIST);
        core\session\manager::set_user($user);
        $kind = $role === 'teacher' ? 'teacher' : 'learner';
        $budget = estimate::inventory((int)$user->id, [(int)$course->id], $kind);
        if ($budget['temporary_zip_budget_bytes'] > 64 * 1024 ** 2) {throw new RuntimeException('Fixture budget exceeds 64 MiB');}
        $id = manager::request([(int)$course->id], (int)$user->id, $kind, 'pseudonymised', '', 0, false, $lang);
        $ids[] = $id;
        manager::generate($id);
        $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
        language_check($request->status === 'ready' && $request->outputlang === $lang, $role . '/' . $lang . ' finishes with the requested language');
        if ($request->totalbytes > 64 * 1024 ** 2) {throw new RuntimeException('Actual fixture ZIP exceeds 64 MiB; assembly skipped');}
        delivery::request($request); delivery::assemble($id);
        $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id], '*', MUST_EXIST);
        $zip = new ZipArchive();
        language_check(delivery::valid($cache) && $zip->open($cache->path, ZipArchive::CHECKCONS) === true, 'Completed ZIP passes an independent ZIP reader');
        $home = $zip->getFromName('index.html');
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        language_check(str_contains($home, '<html lang="' . $lang . '"') && $manifest['output_language'] === $lang &&
            str_contains($home, $lang === 'en' ? 'Your learning, carried forward.' : '学びを、これからへ。') &&
            str_contains($home, $course->fullname), 'Interface language changes while the original course title is preserved');
        $grades = $zip->getFromName('courses/c' . $course->id . '/grades.html');
        language_check(str_contains($grades, 'TOMB_PRIVATE_FEEDBACK_SECRET') === ($kind === 'teacher'), 'Language choice does not change hidden-grade permissions');
        $zip->close();
        $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
        $metrics = json_decode($request->metrics, true);
        language_check(isset($metrics['collection'], $metrics['assembly']) && $metrics['materials']['zip_bytes'] === (int)$cache->bytes &&
            $metrics['collection']['process_peak_bytes'] > 0, 'Collection and assembly record real timings, memory and storage');
        $hash = $cache->sha256;
        if ($lang === 'en' && $role === 'learner') {
            $lock = config::lock('archive-' . $id, 5);
            if (!$lock) {throw new RuntimeException('Fixture cache in use');}
            try {
                delivery::remove_file($cache);
                $cache->status = 'expired'; $cache->path = ''; $cache->bytes = 0;
                $DB->update_record('local_tomb_cache', $cache);
            } finally {$lock->release();}
            force_current_language('ja');
            delivery::request($request); delivery::assemble($id);
            $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id], '*', MUST_EXIST);
            language_check(delivery::valid($cache) && $cache->sha256 === $hash, 'Changing session language does not alter reconstructed English ZIP bytes');
        }
        $results[] = ['role' => $role, 'language' => $lang, 'requestid' => $id, 'budget' => $budget,
            'metrics' => $metrics, 'sha256' => $hash];
        core\session\manager::set_user(get_admin());
        delivery::purge($request);
    }
    language_check(audit::verify(), 'Audit chain remains valid after all five bounded requests and cleanup');
    echo json_encode(['assertions' => $checks, 'requests' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    core\session\manager::set_user(get_admin());
    foreach ($ids as $id) {
        $request = $DB->get_record('local_tomb_request', ['id' => $id]);
        if ($request && !$request->timepurged) {delivery::purge($request);}
    }
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    core\session\manager::set_user($originaluser);
    force_current_language($originalanguage);
}
