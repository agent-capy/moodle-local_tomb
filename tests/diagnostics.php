<?php
// License: GNU GPL v3 or later. Fault injection uses synthetic requests; all database changes are rolled back.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{config, delivery, diagnostics, manager, personal_data};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {
    cli_error('Use --run only with the dedicated Tomb rehearsal fixture');
}
$learner = $DB->get_record('user', ['username' => 'tomb.alpha.learner', 'idnumber' => 'tomb-alpha-fixture-learner'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'tomb.alpha.teacher', 'idnumber' => 'tomb-alpha-fixture-teacher'], '*', MUST_EXIST);
$olduser = $USER;
$oldmail = $CFG->noemailever ?? false;
$CFG->noemailever = true;
$checks = 0;
function diagnostic_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) {throw new RuntimeException('FAILED: ' . $message);}
    $checks++;
    mtrace('PASS: ' . $message);
}
function diagnostic_request(int $userid, string $status = 'queued'): object {
    global $DB;
    $request = (object)['subjectid' => $userid, 'requesterid' => $userid, 'kind' => 'learner',
        'policy' => 'pseudonymised', 'reason' => '', 'courses' => '[invalid JSON', 'outputlang' => 'ja',
        'status' => $status, 'stage' => $status, 'message' => '', 'timecreated' => time(), 'isrehearsal' => 1,
        'plan' => '{"centraloffset":123}', 'expected' => '', 'actual' => '', 'lasterror' => '',
        'privacyversion' => 1, 'totalbytes' => 22];
    $request->id = $DB->insert_record('local_tomb_request', $request);
    return $DB->get_record('local_tomb_request', ['id' => $request->id], '*', MUST_EXIST);
}
$transaction = $DB->start_delegated_transaction();
try {
    core\session\manager::set_user(get_admin());
    $request = diagnostic_request((int)$learner->id);
    manager::generate((int)$request->id);
    $report = diagnostics::report((int)$request->id);
    diagnostic_check($report['status'] === 'failed' && count($report['logs']) === 1, 'Collection failure is retained');
    $log = $report['logs'][0];
    diagnostic_check($log['phase'] === 'collection' && $log['details']['context']['stage'] === 'inventory',
        'Log identifies the actual failing phase before status is overwritten');
    diagnostic_check($log['details']['runtime']['elapsed_seconds'] >= 0 &&
        $log['details']['runtime']['process_peak_memory_bytes'] > 0, 'Failure includes duration, memory and limits');

    $ziprequest = diagnostic_request((int)$learner->id, 'ready');
    $DB->insert_record('local_tomb_cache', (object)['requestid' => $ziprequest->id, 'status' => 'queued',
        'path' => '', 'sha256' => '', 'bytes' => 0, 'updated' => time(), 'lasterror' => '']);
    delivery::assemble((int)$ziprequest->id);
    $report = diagnostics::report((int)$ziprequest->id);
    diagnostic_check($report['cache_status'] === 'failed' && $report['logs'][0]['phase'] === 'assembly',
        'Assembly failure and cache error are visible independently');
    diagnostic_check($report['legacy_assembly_error'] !== '', 'Older cache error is included in the report');

    diagnostics::begin((int)$request->id, 'collection');
    diagnostics::checkpoint('activity', 123, 456);
    $privateargument = 'DO_NOT_RETAIN_ARGUMENT';
    $throw = function($argument) {throw new RuntimeException('password=DO_NOT_RETAIN_PASSWORD email=private@example.invalid');};
    try {$throw($privateargument);} catch (Throwable $error) {diagnostics::failure((int)$request->id, 'activity', $error, true);}
    diagnostics::end();
    $report = diagnostics::report((int)$request->id);
    $json = json_encode($report);
    diagnostic_check(!str_contains($json, $privateargument) && !str_contains($json, 'DO_NOT_RETAIN_PASSWORD') &&
        !str_contains($json, 'private@example.invalid'), 'Trace arguments and common message credentials are omitted');
    diagnostic_check($report['logs'][0]['details']['context']['cmid'] === 456, 'Activity ID is preserved without its content');
    diagnostic_check(diagnostics::clean('https://example.invalid/path?token=secret /srv/private/file user@example.invalid') ===
        '[url] [path] [email]', 'URLs, local paths and emails are masked');
    diagnostic_check(mb_check_encoding(diagnostics::clean(str_repeat('記', 3000)), 'UTF-8'), 'Message truncation preserves UTF-8');
    diagnostic_check(!str_contains(diagnostics::clean('Authorization: Bearer DO_NOT_RETAIN_BEARER'), 'DO_NOT_RETAIN_BEARER'),
        'Authorization values containing spaces are masked');
    diagnostic_check(!str_contains(diagnostics::clean('Cookie: session=abc; second=DO_NOT_RETAIN_COOKIE'), 'DO_NOT_RETAIN_COOKIE'),
        'All values in an accidental cookie header are masked');

    for ($i = 0; $i < 30; $i++) {
        diagnostics::failure((int)$request->id, 'question', new RuntimeException('Sample ' . $i), true);
        diagnostics::interrupted((int)$request->id, 'assembly');
    }
    $report = diagnostics::report((int)$request->id);
    diagnostic_check(count($report['logs']) === 50, 'Warning samples and repeated failures are independently bounded');
    diagnostic_check($report['logs'][0]['details']['runtime'] === null, 'Recovery never substitutes its own memory for a dead worker');
    $DB->set_field('local_tomb_request', 'lasterror', '', ['id' => $request->id]);
    diagnostic_check(count(diagnostics::report((int)$request->id)['logs']) === 50, 'Retry does not discard failure history');
    manager::omission((int)$request->id, 123, 456, 'unsupported_module', 'Private note not exported in diagnostics');
    manager::omission((int)$request->id, 123, 456, 'unsupported_module', 'Another note');
    manager::omission((int)$request->id, 123, 789, 'render_error', 'Private note');
    $report = diagnostics::report((int)$request->id);
    diagnostic_check($report['notes_by_reason'] === ['render_error' => 1, 'unsupported_module' => 2] &&
        count($report['note_groups']) === 2, 'Notes aggregate by reason and activity independently of log sample limits');
    diagnostic_check(!str_contains(json_encode($report), 'Private note'), 'Report excludes note bodies');

    foreach ([$learner, $teacher] as $user) {
        core\session\manager::set_user($user);
        $denied = false;
        try {diagnostics::report((int)$request->id);} catch (required_capability_exception $e) {$denied = true;}
        diagnostic_check($denied, 'Learner/teacher cannot read diagnostics even for an owned request');
    }
    core\session\manager::set_user(get_admin());
    $first = $report['logs'][0]['id'];
    $DB->set_field('local_tomb_diagnostic', 'timecreated', time() - diagnostics::RETENTION - 1, ['id' => $first]);
    diagnostics::cleanup();
    diagnostic_check(!$DB->record_exists('local_tomb_diagnostic', ['id' => $first]) &&
        $DB->count_records('local_tomb_diagnostic', ['requestid' => $request->id]) === 49, 'Retention deletes only expired logs');
    delivery::purge($request);
    diagnostic_check(!$DB->record_exists('local_tomb_diagnostic', ['requestid' => $request->id]), 'Material purge also removes diagnostics');

    // A fresh synthetic subject has no earlier snapshots. Erase only this subject inside the rollback transaction.
    $userid = $DB->insert_record('user', (object)['username' => 'tomb-diagnostic-' . bin2hex(random_bytes(8)),
        'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Diagnostic', 'lastname' => 'Test',
        'email' => 'diagnostic@example.invalid', 'timecreated' => time()]);
    $personal = diagnostic_request((int)$userid, 'failed');
    diagnostics::interrupted((int)$personal->id, 'collection');
    personal_data::erase((int)$userid);
    diagnostic_check(!$DB->record_exists('local_tomb_diagnostic', ['requestid' => $personal->id]),
        'Approved privacy erasure removes linked diagnostics');
    echo 'PASS: ' . $checks . " assertions\n";
} finally {
    diagnostics::end();
    core\session\manager::set_user($olduser);
    $CFG->noemailever = $oldmail;
    try {$transaction->rollback(new RuntimeException('Expected diagnostics test rollback'));}
    catch (RuntimeException $expected) {
        if ($expected->getMessage() !== 'Expected diagnostics test rollback') {throw $expected;}
    }
}
