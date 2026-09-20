<?php
// License: GNU GPL v3 or later. Real Privacy API writer, dedicated synthetic accounts, no PHPUnit bootstrap.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
use core_privacy\local\request\{approved_contextlist, approved_userlist, userlist, writer};
use local_tomb\privacy\provider;
use local_tomb\local\{audit, config, delivery, manager, personal_data, storage};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {
    cli_error('Use --run on the dedicated rehearsal fixture only');
}
$DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
core\session\manager::set_user(get_admin());
$users = [];
$requests = [];
$originals = [];
$checks = 0;
function privacy_check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) {throw new RuntimeException('FAILED: ' . $name);}
    $checks++;
    mtrace('PASS: ' . $name);
}
function privacy_fixture_request(object $owner, int $requester, string $secret, string $kind = 'learner', ?object $fileowner = null): object {
    global $DB, $requests, $originals;
    $request = (object)['subjectid' => $owner->id, 'requesterid' => $requester, 'kind' => $kind,
        'policy' => 'pseudonymised', 'reason' => 'TOMB_PRIVACY_REASON', 'courses' => '[]', 'status' => 'ready',
        'stage' => 'ready', 'message' => '', 'timecreated' => time(), 'timefinished' => time(), 'isrehearsal' => 1,
        'privacyversion' => 1, 'plan' => '', 'expected' => '{}', 'actual' => '{}', 'lasterror' => ''];
    $request->id = $DB->insert_record('local_tomb_request', $request);
    $requests[] = $request->id;
    $store = new storage($request);
    $fileowner = $fileowner ?? $owner;
    $file = get_file_storage()->create_file_from_string(['contextid' => context_user::instance($fileowner->id)->id,
        'component' => 'user', 'filearea' => 'private', 'itemid' => 0, 'filepath' => '/', 'filename' => 'privacy-test-' . $request->id . '.txt',
        'userid' => $fileowner->id], $secret . '_FILE');
    $originals[] = $file;
    $store->add_file($file);
    $store->add_text('index.html', '<p>' . $secret . '</p>');
    $plan = storage::plan($request);
    $request->totalbytes = $plan['totalbytes'];
    unset($plan['entries']);
    $request->plan = json_encode($plan);
    $DB->update_record('local_tomb_request', $request);
    audit::add('requested', $request->id, ['subject' => $owner->id, 'reason' => $request->reason], $requester);
    return $DB->get_record('local_tomb_request', ['id' => $request->id], '*', MUST_EXIST);
}
try {
    foreach (['owner', 'contributor', 'unrelated'] as $role) {
        $id = user_create_user((object)['username' => 'tomb.privacy.' . $role . '.' . bin2hex(random_bytes(4)),
            'auth' => 'nologin', 'password' => '', 'firstname' => 'Tomb privacy', 'lastname' => $role,
            'email' => 'tomb-privacy-' . $role . '@example.invalid', 'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id, 'idnumber' => 'tomb-privacy-fixture'], false, false);
        $users[$role] = core_user::get_user($id, '*', MUST_EXIST);
        cohort_add_member((int)config::get('rehearsalcohortid'), $id);
    }
    $a = privacy_fixture_request($users['owner'], (int)$users['owner']->id, 'OWNER_PRIVATE_SENTINEL');
    $b = privacy_fixture_request($users['contributor'], (int)$users['contributor']->id, 'CONTRIBUTOR_OWN_SENTINEL');
    $c = privacy_fixture_request($users['unrelated'], (int)$users['contributor']->id, 'UNRELATED_PRIVATE_SENTINEL');
    personal_data::fragment($a, (int)$users['contributor']->id, 'forum:synthetic', 'index.html', '<p>CONTRIBUTION_SNAPSHOT_SENTINEL</p>');
    $teacherarchive = privacy_fixture_request($users['contributor'], (int)$users['contributor']->id,
        'TEACHER_CLASSMATE_PRIVATE_SENTINEL', 'teacher', $users['unrelated']);
    personal_data::fragment($teacherarchive, (int)$users['contributor']->id, 'teacher-note', 'index.html', '<p>TEACHER_OWN_NOTE_SENTINEL</p>');
    $userid = (int)$users['contributor']->id;
    // Add a grading step in memory only. Do not save or regrade the existing fixture attempt.
    $attempt = $DB->get_record_sql('SELECT a.* FROM {quiz_attempts} a JOIN {quiz} q ON q.id = a.quiz
        WHERE q.course = ? AND a.preview = 0 ORDER BY a.id LIMIT 1', [(int)config::get('fixturecourseid')]);
    $usage = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
    $qa = $usage->get_question_attempt($usage->get_slots()[0]);
    core\session\manager::set_user($users['contributor']);
    $qa->manual_grade('MANUAL_GRADER_COMMENT_SENTINEL', null, FORMAT_HTML);
    core\session\manager::set_user(get_admin());
    personal_data::question_comment($a, $qa, 'index.html', '<div class="que"><div class="answer">STUDENT_ANSWER_SECRET</div></div>');
    privacy_check(!$DB->record_exists('local_tomb_person', ['requestid' => $a->id, 'userid' => $userid,
        'itemkey' => sha1('quiz-comment:' . $qa->get_database_id())]), 'Unrendered manual comments are not copied into the privacy index');
    personal_data::question_comment($a, $qa, 'index.html', '<div class="que"><div class="answer">STUDENT_ANSWER_SECRET</div>' .
        '<div class="comment clearfix"><p>MANUAL_GRADER_COMMENT_SENTINEL</p></div></div>');
    $comment = personal_data::decode($DB->get_field('local_tomb_person', 'payload', ['requestid' => $a->id,
        'userid' => $userid, 'itemkey' => sha1('quiz-comment:' . $qa->get_database_id())], MUST_EXIST));
    privacy_check(str_contains($comment->html, 'MANUAL_GRADER_COMMENT_SENTINEL') && !str_contains($comment->html, 'STUDENT_ANSWER_SECRET'),
        'Manual quiz feedback is attributed to its grader without copying the student answer into the grader response');
    $context = context_user::instance($userid);
    privacy_check(array_map('intval', provider::get_contexts_for_userid($userid)->get_contextids()) === [(int)$context->id],
        'Privacy context covers owned records, delegation and contributions');
    $list = new userlist($context, 'local_tomb');
    provider::get_users_in_context($list);
    privacy_check(array_map('intval', $list->get_userids()) === [$userid], 'User list is scoped to the logical owner context');
    provider::delete_data_for_user(new approved_contextlist($users['contributor'], 'local_tomb', [context_system::instance()->id]));
    provider::delete_data_for_users(new approved_userlist($context, 'local_tomb', [(int)$users['owner']->id]));
    privacy_check($DB->record_exists('local_tomb_request', ['id' => $b->id]), 'Unapproved context or unrelated user list cannot erase records');

    writer::reset();
    provider::export_user_data(new approved_contextlist($users['contributor'], 'local_tomb', [$context->id]));
    $export = writer::with_context($context)->finalise_content();
    $zip = new ZipArchive();
    privacy_check($zip->open($export, ZipArchive::CHECKCONS) === true, 'Real Moodle privacy writer returns a valid export ZIP');
    $content = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {$content .= $zip->getFromIndex($i);}
    $zip->close();
    privacy_check(str_contains($content, 'CONTRIBUTOR_OWN_SENTINEL') && str_contains($content, 'CONTRIBUTOR_OWN_SENTINEL_FILE'),
        'Owned snapshot and pinned attachment are included');
    privacy_check(str_contains($content, 'CONTRIBUTION_SNAPSHOT_SENTINEL'), 'Own contribution copied into another archive is included');
    privacy_check(!str_contains($content, 'OWNER_PRIVATE_SENTINEL') && !str_contains($content, 'UNRELATED_PRIVATE_SENTINEL'),
        'Contribution export and delegated metadata do not disclose another owner snapshot');
    privacy_check(str_contains($content, 'TEACHER_OWN_NOTE_SENTINEL') && !str_contains($content, 'TEACHER_CLASSMATE_PRIVATE_SENTINEL'),
        'A teacher privacy response contains personal contributions, not an entire class archive');
    unlink($export);
    writer::reset();

    $olduser = $DB->get_record('user', ['username' => 'tomb.alpha.learner']);
    if (personal_data::has_legacy((int)$olduser->id)) {
        $rejected = false;
        try {provider::export_user_data(new approved_contextlist($olduser, 'local_tomb', [context_user::instance($olduser->id)->id]));}
        catch (moodle_exception $e) {$rejected = $e->errorcode === 'privacy:legacy';}
        privacy_check($rejected, 'Legacy snapshots are explicitly gated instead of returning an incomplete privacy response');
    }

    core\session\manager::set_user($users['contributor']);
    delivery::request($b);
    delivery::assemble((int)$b->id);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $b->id], '*', MUST_EXIST);
    privacy_check(delivery::valid($cache), 'Privacy fixture has a ready delivery cache before erasure');
    $code = 'define("CLI_SCRIPT", true); require(' . var_export($CFG->dirroot . '/config.php', true) . ');' .
        '$lock=\\local_tomb\\local\\config::lock("archive-' . $b->id . '",5);if(!$lock){exit(2);}echo "READY\\n";fflush(STDOUT);fread(STDIN,1);$lock->release();';
    $process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    try {
        privacy_check(trim(fgets($pipes[1])) === 'READY', 'Independent process holds the download lock');
        $busy = false;
        try {provider::delete_data_for_user(new approved_contextlist($users['contributor'], 'local_tomb', [$context->id]));}
        catch (moodle_exception $e) {$busy = $e->errorcode === 'workerbusy';}
        privacy_check($busy && is_file($cache->path) && $DB->record_exists('local_tomb_request', ['id' => $a->id]),
            'Privacy erasure waits for downloads and makes no partial deletions when a lock is busy');
    } finally {
        fwrite($pipes[0], 'X'); foreach ($pipes as $pipe) {fclose($pipe);} proc_close($process);
    }
    $DB->set_field('user', 'suspended', 1, ['id' => $userid]);
    privacy_check(!manager::notify($b, 'failed') && $DB->record_exists('local_tomb_audit',
        ['requestid' => $b->id, 'event' => 'administrator_notified']), 'An unreachable owner does not suppress administrator failure notification');
    $DB->set_field('user', 'suspended', 0, ['id' => $userid]);

    provider::delete_data_for_users(new approved_userlist($context, 'local_tomb', [$userid]));
    privacy_check(!$DB->record_exists('local_tomb_request', ['id' => $b->id]) && !is_file($cache->path),
        'Approved erasure removes own request and completed delivery cache');
    $events = $DB->count_records('local_tomb_audit');
    manager::generate((int)$b->id);
    delivery::assemble((int)$b->id);
    privacy_check($DB->count_records('local_tomb_audit') === $events &&
        !$DB->record_exists('local_tomb_cache', ['requestid' => $b->id]),
        'Already queued tasks for an erased request finish without recreating data or sending failures');
    $a = $DB->get_record('local_tomb_request', ['id' => $a->id], '*', MUST_EXIST);
    privacy_check($a->status === 'blocked' && $a->timepurged && !$DB->record_exists('local_tomb_entry', ['requestid' => $a->id]),
        'Another snapshot containing the erased contribution is retired as a whole');
    $c = $DB->get_record('local_tomb_request', ['id' => $c->id], '*', MUST_EXIST);
    privacy_check($c->status === 'ready' && !$c->requesterid && $c->reason === '' &&
        $DB->record_exists('local_tomb_entry', ['requestid' => $c->id]), 'Delegation erasure preserves the unrelated owner content');
    privacy_check(!$DB->record_exists('local_tomb_person', ['userid' => $userid]) &&
        !$DB->record_exists('local_tomb_audit', ['actorid' => $userid]), 'Contribution index and actor-identifying audit fields are removed');
    privacy_check(audit::verify() && $DB->record_exists('local_tomb_audit', ['event' => 'privacy_checkpoint']),
        'Audit redaction creates an explicit new checkpoint and a valid chain');
    privacy_check(get_file_storage()->get_file_by_id($originals[1]->get_id()) !== false,
        'Tomb erasure does not remove the original Moodle source file');
    privacy_check(!provider::get_contexts_for_userid($userid)->get_contextids(), 'Erased subject has no residual Tomb context');
    core\session\manager::set_user(get_admin());
    delivery::purge($c);
    $references = $DB->get_records('local_tomb_person', ['requestid' => $c->id, 'userid' => $users['unrelated']->id]);
    $minimal = (bool)$references;
    foreach ($references as $reference) {
        $data = personal_data::decode($reference->payload);
        $minimal = $minimal && $data->type === 'retained_inventory_reference' && count(get_object_vars($data)) === 1;
    }
    privacy_check($minimal, 'Ordinary material purge removes contribution text but retains inventory identity references for privacy erasure');
    provider::delete_data_for_all_users_in_context(context_user::instance($users['unrelated']->id));
    privacy_check(!$DB->record_exists('local_tomb_request', ['id' => $c->id]), 'Whole-context deletion removes only its owner data');
    mtrace('PASS: ' . $checks . ' privacy assertions');
} finally {
    core\session\manager::set_user(get_admin());
    foreach ($users as $user) {
        personal_data::erase((int)$user->id);
        cohort_remove_member((int)config::get('rehearsalcohortid'), (int)$user->id);
    }
    foreach ($originals as $file) {$file->delete();}
    foreach ($users as $user) {delete_user(core_user::get_user($user->id));}
    writer::reset();
}
