<?php
// License: GNU GPL v3 or later. Explicit test on fixture course only; restores the temporary capability override.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{config, delivery, manager, audit};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {
    cli_error('Use --run on the isolated rehearsal fixture only');
}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$context = context_course::instance($course->id);
$teacher = $DB->get_record('user', ['username' => 'tomb.alpha.teacher'], '*', MUST_EXIST);
$learner = $DB->get_record('user', ['username' => 'tomb.alpha.learner'], '*', MUST_EXIST);
$other = $DB->get_record('user', ['username' => 'tomb.alpha.other'], '*', MUST_EXIST);
$role = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$capability = 'local/tomb:exportothersdata';
$prior = $DB->get_record('role_capabilities', ['roleid' => $role->id, 'contextid' => $context->id, 'capability' => $capability]);
$id = null;
try {
    assign_capability($capability, CAP_ALLOW, $role->id, $context->id, true);
    core\session\manager::set_user($teacher);
    reload_all_capabilities();
    $rejected = false;
    try {
        manager::request([(int)$course->id], (int)$teacher->id, 'teacher', 'full', '');
    } catch (moodle_exception $e) {
        $rejected = $e->errorcode === 'reasonrequired';
    }
    if (!$rejected) {
        throw new RuntimeException('Named export without reason was not rejected');
    }
    mtrace('PASS: Named export requires a reason even with the capability');
    $id = manager::request([(int)$course->id], (int)$teacher->id, 'teacher', 'full', 'Isolated fixture: named export validation');
    manager::generate($id);
    $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
    if ($request->status !== 'ready') {
        throw new RuntimeException('Named export failed: ' . $request->lasterror);
    }
    $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => 'tomb-alpha-assignment'], '*', MUST_EXIST);
    $entry = $DB->get_record('local_tomb_entry', ['requestid' => $id,
        'zippath' => 'courses/c' . $course->id . '/m' . $cm->id . '/index.html'], '*', MUST_EXIST);
    $content = gzinflate(get_file_storage()->get_file_by_id($entry->fileid)->get_content());
    if (!str_contains($content, fullname($learner)) || !str_contains($content, fullname($other))) {
        throw new RuntimeException('Explicitly authorised names are missing');
    }
    mtrace('PASS: Explicitly authorised named teacher export contains permitted student names');
    $record = $DB->get_record('local_tomb_audit', ['requestid' => $id, 'event' => 'requested'], '*', MUST_EXIST);
    $details = json_decode($record->details, true, 512, JSON_THROW_ON_ERROR);
    if ($details['policy'] !== 'full' || $details['reason'] !== 'Isolated fixture: named export validation') {
        throw new RuntimeException('Named export reason was not audited');
    }
    mtrace('PASS: Named policy and explicit reason are recorded in the audit chain');
    core\session\manager::set_user(get_admin());
    delivery::purge($request);
    if (!audit::verify()) {
        throw new RuntimeException('Audit chain mismatch');
    }
    mtrace('PASS: Named test archive materials removed; audit retained. Request ' . $id);
} finally {
    core\session\manager::set_user(get_admin());
    if ($prior) {
        assign_capability($capability, $prior->permission, $role->id, $context->id, true);
    } else {
        unassign_capability($capability, $role->id, $context->id);
    }
    reload_all_capabilities();
}
