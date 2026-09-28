<?php
// License: GNU GPL v3 or later. Uses only the dedicated Tomb fixture; all DB changes are rolled back.
if (!defined('CLI_SCRIPT')) {define('CLI_SCRIPT', true);}
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{config, delivery, estimate, manager};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {
    cli_error('Use --run only on the dedicated Tomb rehearsal fixture');
}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'tomb.alpha.teacher', 'idnumber' => 'tomb-alpha-fixture-teacher'], '*', MUST_EXIST);
$learner = $DB->get_record('user', ['username' => 'tomb.alpha.learner', 'idnumber' => 'tomb-alpha-fixture-learner'], '*', MUST_EXIST);
$context = context_course::instance($course->id);
$roles = $DB->get_records_menu('role', [], '', 'shortname,id');
$assignment = $DB->get_record('role_assignments', ['userid' => $teacher->id, 'contextid' => $context->id,
    'roleid' => $roles['editingteacher']], '*', MUST_EXIST);
$olduser = $USER;
$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$ids = [];
$checks = 0;
function teacher_access_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) {throw new RuntimeException('FAILED: ' . $message);}
    $checks++;
    mtrace('PASS: ' . $message);
}
function teacher_access_reject(callable $call, string $message): void {
    try {$call();} catch (moodle_exception $e) {
        teacher_access_check(true, $message);
        return;
    }
    throw new RuntimeException('FAILED: ' . $message);
}
function teacher_access_generate(object $user, string $kind): void {
    global $DB, $course, $ids;
    core\session\manager::set_user($user);
    reload_all_capabilities();
    $id = manager::request([(int)$course->id], (int)$user->id, $kind, 'pseudonymised', '', 0, true);
    $ids[] = $id;
    manager::generate($id);
    $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
    teacher_access_check($request->status === 'ready', $kind . ' collection completes: ' . $request->lasterror);
    teacher_access_check($request->totalbytes < 64 * 1024 * 1024, 'Fixture archive stays below 64 MiB');
    delivery::assemble($id);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id], '*', MUST_EXIST);
    teacher_access_check(delivery::valid($cache) && manager::accessible($request, true), 'Owner can receive the completed ZIP');
    $zip = new ZipArchive();
    teacher_access_check($zip->open($cache->path, ZipArchive::CHECKCONS) === true, 'Independent reader verifies the ZIP');
    try {
        teacher_access_check($zip->locateName('courses/c' . $course->id . '/index.html') !== false,
            'Requested course is present, rather than an empty archive');
        teacher_access_check($zip->locateName('courses/c' . $course->id . '/grades.html') !== false,
            'Course grade page is present');
    } finally {$zip->close();}
}
$transaction = $DB->start_delegated_transaction();
try {
    $CFG->forced_plugin_settings['local_tomb']['requestinterval'] = 0;
    core\session\manager::set_user($teacher);
    reload_all_capabilities();
    teacher_access_check(isset(manager::courses((int)$teacher->id, 'teacher')[$course->id]),
        'Enrolled editing teacher sees the course');
    $DB->set_field('role_assignments', 'roleid', $roles['teacher'], ['id' => $assignment->id]);
    reload_all_capabilities();
    teacher_access_check(has_capability('local/tomb:exportteacher', $context) &&
        !has_capability('local/tomb:delegate', $context), 'Non-editing teacher can export without delegation rights');
    teacher_access_generate($teacher, 'teacher');
    $DB->set_field('role_assignments', 'roleid', $roles['editingteacher'], ['id' => $assignment->id]);
    reload_all_capabilities();
    foreach ($DB->get_records('enrol', ['courseid' => $course->id]) as $enrol) {
        $DB->delete_records('user_enrolments', ['enrolid' => $enrol->id, 'userid' => $teacher->id]);
    }
    assign_capability('moodle/course:view', CAP_PREVENT, $roles['editingteacher'], $context->id, true);
    reload_all_capabilities();
    teacher_access_check(!isset(manager::courses((int)$teacher->id, 'teacher')[$course->id]),
        'Teacher without enrolment or course access cannot select the course');
    teacher_access_reject(fn() => manager::request([(int)$course->id], (int)$teacher->id, 'teacher'),
        'Direct request without course access is rejected');
    assign_capability('moodle/course:view', CAP_ALLOW, $roles['editingteacher'], $context->id, true);
    reload_all_capabilities();
    teacher_access_check(has_capability('moodle/course:view', $context) && !is_enrolled($context, $teacher, '', true),
        'Regression fixture has course access without an enrolment');
    teacher_access_check(isset(manager::courses((int)$teacher->id, 'teacher')[$course->id]),
        'Teacher with course access sees an unenrolled course');
    teacher_access_check(!isset(manager::courses((int)$teacher->id)[$course->id]),
        'Learner course selection still requires active enrolment');
    $inventory = estimate::inventory((int)$teacher->id, [(int)$course->id], 'teacher');
    teacher_access_check(in_array((int)$course->id, array_column($inventory['courses'], 'id'), true),
        'Teacher capacity estimate includes the unenrolled course');
    teacher_access_generate($teacher, 'teacher');
    teacher_access_reject(fn() => manager::request([(int)$course->id], (int)$teacher->id, 'teacher', 'full', 'Fixture test'),
        'Unenrolled teacher still needs explicit permission to export real names');
    assign_capability('local/tomb:exportteacher', CAP_PROHIBIT, $roles['editingteacher'], $context->id, true);
    reload_all_capabilities();
    teacher_access_check(!isset(manager::courses((int)$teacher->id, 'teacher')[$course->id]),
        'Course access alone does not confer teacher export permission');
    teacher_access_reject(fn() => manager::request([(int)$course->id], (int)$teacher->id, 'teacher'),
        'Revoked teacher export permission rejects direct requests');
    assign_capability('local/tomb:exportteacher', CAP_ALLOW, $roles['editingteacher'], $context->id, true);
    reload_all_capabilities();
    $DB->delete_records('cohort_members', ['cohortid' => config::current('rehearsalcohortid'), 'userid' => $teacher->id]);
    teacher_access_check(!config::allowed((int)$teacher->id, true), 'Teacher outside the rehearsal cohort remains ineligible');
    teacher_access_reject(fn() => manager::request([(int)$course->id], (int)$teacher->id, 'teacher'),
        'Direct teacher request cannot bypass rehearsal membership');
    $id = manager::request([(int)$course->id], (int)$learner->id);
    $ids[] = $id;
    teacher_access_check($DB->record_exists('local_tomb_request', ['id' => $id, 'subjectid' => $learner->id]),
        'Teacher outside cohort can still delegate for an eligible learner');
    core\session\manager::set_user($learner);
    reload_all_capabilities();
    teacher_access_check(!isset(manager::courses((int)$learner->id, 'teacher')[$course->id]),
        'Learner does not receive teacher course selection');
    teacher_access_reject(fn() => manager::request([(int)$course->id], (int)$learner->id, 'teacher'),
        'Learner cannot request a teacher archive');
    teacher_access_generate($learner, 'learner');
} finally {
    try {
        core\session\manager::set_user(get_admin());
        foreach (array_unique($ids) as $id) {
            $request = $DB->get_record('local_tomb_request', ['id' => $id]);
            if ($request) {delivery::purge($request);}
        }
    } finally {
        try {$transaction->rollback(new RuntimeException('tomb_teacher_access_rollback'));}
        catch (RuntimeException $e) {if ($e->getMessage() !== 'tomb_teacher_access_rollback') {throw $e;}}
        // MUC role definitions are outside the DB transaction and must not retain temporary permissions.
        cache::make('core', 'roledefs')->delete_many([$roles['editingteacher'], $roles['teacher']]);
        $context->mark_dirty();
        $CFG->forced_plugin_settings['local_tomb'] = $forced;
        core\session\manager::set_user($olduser);
        reload_all_capabilities();
    }
}
mtrace('PASS: ' . $checks . ' teacher access checks; fixture changes rolled back and generated materials removed.');
