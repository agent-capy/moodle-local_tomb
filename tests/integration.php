<?php
// License: GNU GPL v3 or later.
// Explicit opt-in tests on the isolated fixture. No site reset and no PHPUnit bootstrap.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
use local_tomb\local\{audit, config, delivery, manager};
[$options, $unknown] = cli_get_params(['run' => false, 'help' => false], ['h' => 'help']);
if (!$options['run'] || $unknown) {
    cli_writeln('php local/tomb/tests/integration.php --run (requires the isolated Tomb fixture in rehearsal mode)');
    exit($options['help'] ? 0 : 1);
}
$courseid = (int)config::get('fixturecourseid', 0);
$course = $DB->get_record('course', ['id' => $courseid, 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
if (config::mode() !== 'rehearsal') {
    cli_error('Refusing to test outside rehearsal mode');
}
$learner = $DB->get_record('user', ['username' => 'tomb.alpha.learner'], '*', MUST_EXIST);
$other = $DB->get_record('user', ['username' => 'tomb.alpha.other'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'tomb.alpha.teacher'], '*', MUST_EXIST);
$admin = get_admin();
$checks = [];
function tomb_check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $name);
    }
    $checks[] = $name;
    mtrace('PASS: ' . $name);
}
function tomb_reject(callable $call, string $name): void {
    try {
        $call();
    } catch (moodle_exception $e) {
        tomb_check(true, $name);
        return;
    }
    throw new RuntimeException('FAILED: ' . $name);
}
function tomb_generate(int $id): object {
    global $DB;
    for ($i = 0; $i < 30; $i++) {
        try {
            manager::generate($id);
        } catch (moodle_exception $e) {
            if ($e->errorcode !== 'workerbusy') {
                throw $e;
            }
            usleep(200000);
            continue;
        }
        $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
        if ($request->status === 'ready') {
            return $request;
        }
        if ($request->status === 'failed') {
            throw new RuntimeException($request->lasterror);
        }
        usleep(200000);
    }
    throw new RuntimeException('Generation did not finish during integration test');
}
$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$quizcm = $DB->get_record('course_modules', ['course' => $courseid, 'idnumber' => 'tomb-alpha-quiz'], '*', MUST_EXIST);
$quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
$restorequiz = clone $quiz;
$newids = [];
$temporarycm = null;
try {
    core\session\manager::set_user($admin);
    $sample = $DB->get_record_select('local_tomb_request', 'subjectid = ? AND status = ? AND timepurged = 0',
        [$learner->id, 'ready'], '*', IGNORE_MULTIPLE);
    tomb_check((bool)$sample, 'Existing owner archive is available');
    core\session\manager::set_user($teacher);
    tomb_check(!manager::accessible($sample), 'Teacher is not the owner of a delegated archive');
    tomb_reject(fn() => manager::request([$courseid], (int)$teacher->id, 'teacher', 'full', 'Integration test'),
        'Named teacher exports require an explicitly granted capability');
    core\session\manager::set_user($other);
    tomb_check(!manager::accessible($sample), 'Different student cannot access owner archive');
    tomb_reject(fn() => manager::request([$courseid], (int)$other->id, 'teacher'), 'Student cannot request teacher archive');
    core\session\manager::set_user($learner);
    tomb_check(manager::accessible($sample), 'Owner can access own completed archive');
    $CFG->forced_plugin_settings['local_tomb']['operationmode'] = 'disabled';
    tomb_check(!manager::accessible($sample), 'Disabled mode refuses delivery');
    $CFG->forced_plugin_settings['local_tomb']['operationmode'] = 'delivery_only';
    $CFG->forced_plugin_settings['local_tomb']['setupconfirmed'] = 1;
    $CFG->forced_plugin_settings['local_tomb']['deliverydeadline'] = time() + 600;
    tomb_check(!config::allowed((int)$learner->id, true), 'Delivery-only mode refuses new content generation');
    tomb_check(!manager::accessible($sample), 'Rehearsal archives are not delivered as production archives');
    $CFG->forced_plugin_settings['local_tomb']['deliverydeadline'] = time() - 1;
    tomb_check(!config::allowed((int)$learner->id), 'Expired delivery deadline stops delivery');
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    core\session\manager::set_user($admin);
    $CFG->forced_plugin_settings['local_tomb']['minfreebytes'] = PHP_INT_MAX;
    tomb_reject(fn() => manager::request([$courseid], (int)$learner->id), 'Low-space guard rejects new generation before writing');
    $CFG->forced_plugin_settings['local_tomb'] = $forced;

    // A material existed at request time and is removed before HTML collection starts.
    $worker = config::lock('worker', 5);
    if (!$worker) {
        throw new RuntimeException('Another Tomb worker is running; rerun the bounded integration test');
    }
    try {
        $info = add_moduleinfo((object)['modulename' => 'page', 'module' => $DB->get_field('modules', 'id', ['name' => 'page']),
            'name' => 'TOMB_DELETED_SOURCE_SENTINEL', 'section' => 1, 'visible' => 1, 'groupmode' => 0, 'groupingid' => 0,
            'intro' => '', 'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0, 'printlastmodified' => 0,
            'content' => '<p>TOMB_DELETED_CONTENT_SENTINEL</p>', 'contentformat' => FORMAT_HTML, 'completion' => 0], $course);
        $temporarycm = (int)$info->coursemodule;
        $id = manager::request([$courseid], (int)$learner->id);
        $newids[] = $id;
        $duplicate = manager::request([$courseid], (int)$learner->id);
        tomb_check($duplicate === $id, 'Repeated pending creation reuses one request');
        course_delete_module($temporarycm);
        $temporarycm = null;
    } finally {
        $worker->release();
    }
    $request = tomb_generate($id);
    tomb_check($request->status === 'ready', 'Generate after a source deletion');
    delivery::request($request);
    delivery::request($request);
    tomb_check($DB->count_records('local_tomb_cache', ['requestid' => $id]) === 1, 'Repeated download preparation reuses one cache job');
    $CFG->forced_plugin_settings['local_tomb']['cachebytes'] = 1;
    delivery::assemble($id);
    tomb_check($DB->get_field('local_tomb_cache', 'status', ['requestid' => $id]) === 'oversize',
        'Single archive above cache capacity reports a setting problem');
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    delivery::request($request);
    delivery::assemble($id);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id], '*', MUST_EXIST);
    tomb_check(delivery::valid($cache), 'Verified ZIP is available');
    $reader = new ZipArchive();
    $reader->open($cache->path);
    $manifest = json_decode($reader->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $alltext = '';
    for ($i = 0; $i < $reader->numFiles; $i++) {
        $name = $reader->getNameIndex($i);
        if (str_ends_with($name, '.html') || $name === 'manifest.json') {
            $alltext .= $reader->getFromIndex($i);
        }
    }
    $reader->close();
    tomb_check(!str_contains($alltext, 'TOMB_DELETED_'), 'Deleted source is absent from pages, navigation and manifest');
    tomb_check(!str_contains($alltext, 'TOMB_OTHER_STUDENT_SECRET'), 'Other student submission is absent');
    tomb_check(!str_contains($alltext, 'TOMB_PRIVATE_FEEDBACK_SECRET'), 'Private grade feedback is absent');
    tomb_check(!str_contains($alltext, 'TOMB_TIMED_FORUM_'), 'Timed forum discussion is absent');
    tomb_check(!str_contains($alltext, 'TOMB_OTHER_GROUP_SECRET') && str_contains($alltext, 'ばらつきの説明を分担'),
        'Separate-group forum contains only the learner group');
    tomb_check(!str_contains($alltext, 'TOMB_QANDA_REPLY_SECRET') && str_contains($alltext, 'まず自分の考えを投稿してから'),
        'Q&A replies remain hidden before the learner posts');
    foreach (['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match'] as $type) {
        tomb_check((bool)preg_match('/class="que ' . $type . '\b/', $alltext), 'Saved question type renders: ' . $type);
    }
    tomb_check(!in_array('render_error', array_column($manifest['omissions'], 'reason'), true), 'Supported providers render without errors');
    $hash = $cache->sha256;
    // Expire only the test cache; retained material must rebuild to the identical bytes.
    delivery::remove_file($cache);
    $cache->status = 'expired';
    $cache->path = '';
    $cache->bytes = 0;
    $DB->update_record('local_tomb_cache', $cache);
    delivery::request($request);
    delivery::assemble($id);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id], '*', MUST_EXIST);
    tomb_check(hash_equals($hash, $cache->sha256), 'Cache rebuild is byte-identical');

    // Removing an archive's pinned reference is distinct from deleting the live course source.
    delivery::remove_file($cache);
    $cache->status = 'expired';
    $cache->path = '';
    $cache->bytes = 0;
    $DB->update_record('local_tomb_cache', $cache);
    $entries = $DB->get_records('local_tomb_entry', ['requestid' => $id, 'sourcetype' => 'shared'], 'id ASC', '*', 0, 1);
    $entry = reset($entries);
    $file = get_file_storage()->get_file_by_id($entry->fileid);
    tomb_check($file->get_component() === 'local_tomb' && $file->get_itemid() == $id, 'Fault injection is confined to the test archive');
    $file->delete();
    delivery::request($request);
    delivery::assemble($id);
    tomb_check($DB->get_field('local_tomb_request', 'status', ['id' => $id]) === 'blocked', 'Missing pinned material blocks that version');
    tomb_check($DB->get_field('local_tomb_request', 'status', ['id' => $sample->id]) === 'ready', 'A different version remains available');
    $entrycount = $DB->count_records('local_tomb_entry', ['requestid' => $id]);
    delivery::purge($request);
    tomb_check($DB->get_field('local_tomb_request', 'timepurged', ['id' => $id]) > 0 &&
        $DB->count_records('local_tomb_entry', ['requestid' => $id]) === $entrycount, 'Explicit cleanup retains plans and inventory');

    // Quiz review policies apply to actual saved attempts, with no regrading or redraw.
    $before = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id], 'id ASC');
    $stepsbefore = $DB->count_records_sql('SELECT COUNT(*) FROM {question_attempt_steps} s JOIN {question_attempts} qa
        ON qa.id = s.questionattemptid JOIN {quiz_attempts} a ON a.uniqueid = qa.questionusageid WHERE a.quiz = ?', [$quiz->id]);
    foreach (['reviewcorrectness', 'reviewmarks', 'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer', 'reviewoverallfeedback'] as $field) {
        $DB->set_field('quiz', $field, 0, ['id' => $quiz->id]);
    }
    rebuild_course_cache($courseid, true);
    $restrictedid = manager::request([$courseid], (int)$learner->id, 'learner', 'pseudonymised', '', $id);
    $newids[] = $restrictedid;
    $restricted = tomb_generate($restrictedid);
    $quizentry = $DB->get_record('local_tomb_entry', ['requestid' => $restrictedid,
        'zippath' => 'courses/c' . $courseid . '/m' . $quizcm->id . '/index.html'], '*', MUST_EXIST);
    $quizhtml = gzinflate(get_file_storage()->get_file_by_id($quizentry->fileid)->get_content());
    tomb_check(!str_contains($quizhtml, 'class="rightanswer"') && !str_contains($quizhtml, 'class="generalfeedback"'),
        'Closed right-answer and feedback fields are not emitted');
    tomb_check(str_contains($quizhtml, 'que ') && str_contains($quizhtml, '受験 1'), 'Visible saved questions and responses remain');
    tomb_check(serialize($before) === serialize($DB->get_records('quiz_attempts', ['quiz' => $quiz->id], 'id ASC')),
        'Export does not alter quiz attempts');
    tomb_check($stepsbefore === $DB->count_records_sql('SELECT COUNT(*) FROM {question_attempt_steps} s JOIN {question_attempts} qa
        ON qa.id = s.questionattemptid JOIN {quiz_attempts} a ON a.uniqueid = qa.questionusageid WHERE a.quiz = ?', [$quiz->id]),
        'Export does not append question steps or regrade');
    delivery::purge($restricted);
    tomb_check(audit::verify(), 'Audit chain remains consistent after generation, faults and cleanup');
    echo json_encode(['passed' => count($checks), 'checks' => $checks, 'fixture_requests' => $newids],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    core\session\manager::set_user($admin);
    foreach (['reviewcorrectness', 'reviewmarks', 'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer', 'reviewoverallfeedback'] as $field) {
        $DB->set_field('quiz', $field, $restorequiz->$field, ['id' => $quiz->id]);
    }
    if ($temporarycm) {
        course_delete_module($temporarycm);
    }
    rebuild_course_cache($courseid, true);
}
