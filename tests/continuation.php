<?php
// License: GNU GPL v3 or later. No shared database reset; all writes use dedicated Tomb fixtures.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
use local_tomb\local\{audit, config, delivery, manager};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {
    cli_error('Use --run only on the dedicated rehearsal fixture');
}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$context = context_course::instance($course->id);
$teacher = $DB->get_record('user', ['username' => 'tomb.alpha.teacher'], '*', MUST_EXIST);
$learner = $DB->get_record('user', ['username' => 'tomb.alpha.learner'], '*', MUST_EXIST);
$cm = get_coursemodule_from_id('assign', $DB->get_field('course_modules', 'id',
    ['course' => $course->id, 'idnumber' => 'tomb-alpha-assignment']), $course->id);
$assignment = new assign(context_module::instance($cm->id), $cm, $course);
$item = grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance]);
$priorhidden = $item->hidden;
$role = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$capabilities = ['moodle/grade:viewhidden', 'local/tomb:exportothersdata'];
$priorcaps = [];
foreach ($capabilities as $capability) {
    $priorcaps[$capability] = $DB->get_record('role_capabilities',
        ['roleid' => $role->id, 'contextid' => $context->id, 'capability' => $capability]);
}
$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$ids = [];
$checks = 0;
function continuation_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) {throw new RuntimeException('FAILED: ' . $message);}
    $checks++;
    mtrace('PASS: ' . $message);
}
function continuation_generate(object $user, string $kind, string $policy = 'pseudonymised'): object {
    global $DB, $course, $ids;
    core\session\manager::set_user($user);
    reload_all_capabilities();
    $id = manager::request([(int)$course->id], (int)$user->id, $kind, $policy, $policy === 'full' ? 'Isolated capability regression' : '');
    $ids[] = $id;
    manager::generate($id);
    $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
    continuation_check($request->status === 'ready', 'Generated ' . $kind . ' archive #' . $id . ': ' . $request->lasterror);
    return $request;
}
function continuation_page(object $request, string $path): string {
    global $DB;
    $entry = $DB->get_record('local_tomb_entry', ['requestid' => $request->id, 'zippath' => $path], '*', MUST_EXIST);
    return gzinflate(get_file_storage()->get_file_by_id($entry->fileid)->get_content());
}
try {
    $CFG->forced_plugin_settings['local_tomb']['requestinterval'] = 0;
    core\session\manager::set_user(get_admin());
    $before = config::mode();
    $CFG->forced_plugin_settings['local_tomb']['setupconfirmed'] = 1;
    $CFG->forced_plugin_settings['local_tomb']['deliverydeadline'] = time() + 3600;
    $rejected = false;
    try {config::set_mode('active');} catch (moodle_exception $e) {$rejected = $e->errorcode === 'purge_rehearsal_first';}
    continuation_check($rejected && config::mode() === $before, 'Production transition refuses retained rehearsal snapshots');

    $item->set_hidden(1);
    assign_capability('moodle/grade:viewhidden', CAP_ALLOW, $role->id, $context->id, true);
    $teacherrequest = continuation_generate($teacher, 'teacher');
    $gradepath = 'courses/c' . $course->id . '/grades.html';
    $assignpath = 'courses/c' . $course->id . '/m' . $cm->id . '/index.html';
    $teachergrades = continuation_page($teacherrequest, $gradepath);
    continuation_check(str_contains($teachergrades, 'TOMB_PRIVATE_FEEDBACK_SECRET'), 'Teacher with hidden-grade capability receives private grade feedback');
    $teacherassignment = continuation_page($teacherrequest, $assignpath);
    continuation_check(str_contains($teacherassignment, '学生には未公開') && str_contains($teacherassignment, '平均と分布の違い'),
        'Assignment grader receives unpublished assignment feedback with an explicit label');
    continuation_check($DB->record_exists('local_tomb_person', ['requestid' => $teacherrequest->id, 'userid' => $learner->id]),
        'Teacher snapshot indexes the represented student for Privacy API');
    $learnerrequest = continuation_generate($learner, 'learner');
    continuation_check(!str_contains(continuation_page($learnerrequest, $gradepath), 'TOMB_PRIVATE_FEEDBACK_SECRET'),
        'Learner archive still excludes hidden grades and feedback');
    continuation_check(!str_contains(continuation_page($learnerrequest, $assignpath), '平均と分布の違い'),
        'Learner archive excludes unpublished assignment feedback');

    assign_capability('moodle/grade:viewhidden', CAP_PROHIBIT, $role->id, $context->id, true);
    $restricted = continuation_generate($teacher, 'teacher');
    continuation_check(!str_contains(continuation_page($restricted, $gradepath), 'TOMB_PRIVATE_FEEDBACK_SECRET'),
        'Teacher without hidden-grade capability does not receive private gradebook feedback');
    assign_capability('local/tomb:exportothersdata', CAP_ALLOW, $role->id, $context->id, true);
    $named = continuation_generate($teacher, 'teacher', 'full');
    continuation_check(manager::accessible($named), 'Named snapshot accessible while explicit permission exists');
    assign_capability('local/tomb:exportothersdata', CAP_PROHIBIT, $role->id, $context->id, true);
    reload_all_capabilities();
    continuation_check(!manager::accessible($named, true), 'Revoking named-export permission blocks an already completed named snapshot');
    continuation_check(audit::verify(), 'Audit chain remains valid');
    mtrace('PASS: ' . $checks . ' continuation assertions');
} finally {
    core\session\manager::set_user(get_admin());
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    $item->set_hidden($priorhidden);
    foreach ($priorcaps as $capability => $prior) {
        if ($prior) {assign_capability($capability, $prior->permission, $role->id, $context->id, true);}
        else {unassign_capability($capability, $role->id, $context->id);}
    }
    reload_all_capabilities();
    foreach ($ids as $id) {
        $request = $DB->get_record('local_tomb_request', ['id' => $id]);
        if ($request && !$request->timepurged) {delivery::purge($request);}
    }
}
