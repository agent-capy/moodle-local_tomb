<?php
// License: GNU GPL v3 or later. Transactional metadata fixtures; no source content or shared DB reset.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{audit, config, estimate, i18n, listing, manager, ui};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {cli_error('Use --run on the Tomb rehearsal fixture');}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$learner = $DB->get_record('user', ['username' => 'tomb.alpha.learner'], '*', MUST_EXIST);
$other = $DB->get_record('user', ['username' => 'tomb.alpha.other'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'tomb.alpha.teacher'], '*', MUST_EXIST);
$checks = 0;
function iteration_check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) {throw new RuntimeException('FAILED: ' . $name);}
    $checks++;
    mtrace('PASS: ' . $name);
}
$transaction = $DB->start_delegated_transaction();
$prior = $USER;
$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$ids = [];
try {
    for ($i = 0; $i < 125; $i++) {
        $row = (object)['subjectid' => $learner->id, 'requesterid' => $teacher->id, 'kind' => 'learner',
            'policy' => 'pseudonymised', 'reason' => '', 'courses' => '[' . $course->id . ']', 'status' => 'ready',
            'stage' => 'ready', 'message' => '', 'timecreated' => 1, 'isrehearsal' => 1, 'privacyversion' => 1,
            'plan' => '', 'expected' => '', 'actual' => '', 'lasterror' => '', 'received' => $i % 2 ? 1 : 0];
        $ids[] = $DB->insert_record('local_tomb_request', $row);
    }
    $row->courses = '[' . $course->id . '0]';
    $wrongcourse = $DB->insert_record('local_tomb_request', $row);
    $row->courses = '[' . $course->id . ']';
    $row->subjectid = $other->id;
    $otherowner = $DB->insert_record('local_tomb_request', $row);
    $row->subjectid = $learner->id;
    $row->status = 'failed';
    $failed = $DB->insert_record('local_tomb_request', $row);
    $filters = ['scope' => 'rehearsal', 'coursefilter' => $course->id, 'statusfilter' => 'ready', 'receiptfilter' => ''];
    core\session\manager::set_user($learner);
    $selection = new listing('own', $filters);
    $page = $selection->page(0);
    iteration_check(count($page['records']) === 25 && (int)array_key_first($page['records']) === end($ids), 'Own history has a bounded page with newest first');
    $found = [];
    for ($i = 0; $i < ceil($page['total'] / 25); $i++) {
        $found = array_merge($found, array_map('intval', array_keys($selection->page($i)['records'])));
    }
    iteration_check(!array_diff($ids, $found) && count($found) === count(array_unique($found)), 'All 125 metadata fixtures are reachable without duplicates');
    iteration_check(!in_array($wrongcourse, $found) && !in_array($otherowner, $found) && !in_array($failed, $found),
        'Course token, owner and status filters independently exclude unrelated records');
    $exported = [];
    $rows = $selection->recordset();
    foreach ($rows as $record) {$exported[] = (int)$record->id;}
    $rows->close();
    iteration_check($exported === $found, 'CSV selection and paged selection use the same ordered records');
    $filters['receiptfilter'] = 'confirmed';
    $confirmed = new listing('own', $filters);
    $rows = $confirmed->recordset();
    $valid = true;
    foreach ($rows as $record) {$valid = $valid && (bool)$record->received;}
    $rows->close();
    iteration_check($valid && $confirmed->page(0)['total'] >= 62, 'Confirmed receipt filter is applied before paging');
    $rejected = false;
    try {new listing('admin', $filters);} catch (required_capability_exception $e) {$rejected = true;}
    iteration_check($rejected, 'Student cannot select the administrator audience');
    core\session\manager::set_user($teacher);
    $filters['receiptfilter'] = '';
    $delegated = new listing('delegated', $filters, [(int)$learner->id]);
    iteration_check($delegated->page(0)['total'] >= 125 && (new listing('delegated', $filters, []))->page(0)['total'] === 0,
        'Delegated history is scoped before paging to currently eligible students');
    $request = $DB->get_record('local_tomb_request', ['id' => $ids[0]], '*', MUST_EXIST);
    iteration_check(!manager::accessible($request, true), 'Listing a delegated request does not confer download permission');
    core\session\manager::set_user($learner);
    $request->lasterror = 'privacy_erasure'; $request->timepurged = 1;
    iteration_check(ui::state($request) === 'privacy' && !array_filter(ui::actions($request)), 'Privacy retirement has no retry, download or regeneration action');
    $request->lasterror = ''; $request->timepurged = 0;
    $cache = (object)['status' => 'failed'];
    iteration_check(ui::state($request, $cache) === 'retry' && ui::actions($request, $cache)['prepare'], 'ZIP-only failure offers preparation of the same completed version');
    $cache->status = 'oversize';
    iteration_check(ui::state($request, $cache) === 'oversize' && !ui::actions($request, $cache)['prepare'], 'Oversize archive asks for an administrator setting change instead of a futile retry');
    $request->isrehearsal = 0;
    $CFG->forced_plugin_settings['local_tomb']['operationmode'] = 'active';
    $CFG->forced_plugin_settings['local_tomb']['deliverydeadline'] = time() - 1;
    iteration_check(ui::state($request) === 'deadline' && !array_filter(ui::actions($request)), 'Passed deadline has an explanatory state and no content actions');
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    $before = $DB->count_records('local_tomb_request');
    $budget = estimate::inventory((int)$learner->id, [(int)$course->id]);
    iteration_check(!$budget['zip_created'] && !$budget['is_upper_bound'] && $budget['known_shared_source_bytes'] > 0 &&
        $DB->count_records('local_tomb_request') === $before, 'Read-only capacity inventory reports known bytes and explicitly unresolved material');
    iteration_check(i18n::get('state_retry', null, 'en') === 'Please retry ZIP preparation' &&
        i18n::get('state_retry', null, 'ja') === 'ZIP の準備をやり直してください', 'Japanese and English labels are independent of the current session');
    // Cross the chunk boundary with synthetic events, all rolled back with this transaction.
    core\session\manager::set_user(get_admin());
    $lock = config::lock('audit', 10);
    if (!$lock) {throw new RuntimeException('Audit busy');}
    try {
        $tail = $DB->get_records('local_tomb_audit', null, 'id DESC', '*', 0, 1);
        $head = $tail ? reset($tail)->eventhash : str_repeat('0', 64);
        for ($i = 0; $i < 300; $i++) {
            $event = (object)['requestid' => $ids[0], 'actorid' => 0, 'event' => 'test', 'details' => '{}', 'timecreated' => 1, 'prevhash' => $head];
            $event->eventhash = audit::hash($event); $head = $event->eventhash;
            $DB->insert_record('local_tomb_audit', $event);
        }
    } finally {$lock->release();}
    audit::erase_person(0, [$ids[0]]);
    iteration_check(audit::verify(), 'Chunked audit redaction preserves chain integrity across more than 250 rows');
    mtrace('PASS: ' . $checks . ' iteration assertions');
} finally {
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    core\session\manager::set_user($prior);
    try {$transaction->rollback(new RuntimeException('tomb_fixture_rollback'));}
    catch (RuntimeException $e) {if ($e->getMessage() !== 'tomb_fixture_rollback') {throw $e;}}
}
