<?php
// License: GNU GPL v3 or later. Two-process admission race in the dedicated Tomb rehearsal only.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{config, delivery};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {
    cli_error('Use --run in Tomb rehearsal mode only');
}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$user = $DB->get_record('user', ['username' => 'tomb.alpha.learner'], '*', MUST_EXIST);
$mode = config::mode();
$lock = config::lock('personaldata', 5);
if (!$lock) {cli_error('Another Tomb operation is running');}
$process = null;
$created = 0;
try {
    $code = 'define("CLI_SCRIPT",true);require(' . var_export($CFG->dirroot . '/config.php', true) . ');' .
        '$CFG->forced_plugin_settings["local_tomb"]["requestinterval"]=0;' .
        '\\core\\session\\manager::set_user(\\core_user::get_user(' . $user->id . '));' .
        'try{$id=\\local_tomb\\local\\manager::request([' . $course->id . '],' . $user->id . ');echo json_encode(["created"=>$id]);}' .
        'catch(\\moodle_exception $e){echo json_encode(["error"=>$e->errorcode]);}';
    $process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    $waiting = false;
    for ($i = 0; $i < 200; $i++) {
        $subjectlock = config::lock('subject-' . $user->id);
        if (!$subjectlock) {$waiting = true; break;}
        $subjectlock->release();
        usleep(10000);
    }
    if (!$waiting) {throw new RuntimeException('Child did not reach admission lock');}
    // Set only the plugin mode while this test owns the same transition lock used by the UI.
    set_config('operationmode', 'disabled', 'local_tomb');
    $lock->release();
    $lock = null;
    fclose($pipes[0]);
    $result = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    $created = (int)($result['created'] ?? 0);
    if (($result['error'] ?? '') !== 'unavailable') {
        throw new RuntimeException('A request passed admission after the mode had been disabled');
    }
    mtrace('PASS: A waiting request rechecks the current mode before creating a record');
} finally {
    if ($lock) {$lock->release();}
    set_config('operationmode', $mode, 'local_tomb');
    if ($process) {
        foreach ($pipes as $pipe) {if (is_resource($pipe)) {fclose($pipe);}}
        proc_close($process);
    }
    if ($created) {
        core\session\manager::set_user(get_admin());
        delivery::purge($DB->get_record('local_tomb_request', ['id' => $created], '*', MUST_EXIST));
    }
}
