<?php
// License: GNU GPL v3 or later. Keep a frozen content version when subsequent ZIP admission fails.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{config, delivery, manager};
[$options, $unknown] = cli_get_params(['run' => false], []);
if (!$options['run'] || $unknown || config::mode() !== 'rehearsal') {cli_error('Use --run in Tomb rehearsal only');}
$course = $DB->get_record('course', ['id' => config::get('fixturecourseid'), 'idnumber' => 'tomb-alpha-fixture'], '*', MUST_EXIST);
$user = $DB->get_record('user', ['username' => 'tomb.alpha.learner'], '*', MUST_EXIST);
$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$id = 0;
$process = null;
$worker = config::lock('worker', 5);
if (!$worker) {cli_error('Another Tomb worker is running');}
try {
    core\session\manager::set_user($user);
    $CFG->forced_plugin_settings['local_tomb']['requestinterval'] = 0;
    $id = manager::request([(int)$course->id], (int)$user->id, 'learner', 'pseudonymised', '', 0, true);
    $code = 'define("CLI_SCRIPT",true);require(' . var_export($CFG->dirroot . '/config.php', true) . ');' .
        '$lock=\\local_tomb\\local\\config::lock("archive-' . $id . '",5);if(!$lock){exit(2);}echo "READY\\n";fflush(STDOUT);fread(STDIN,1);$lock->release();';
    $process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (trim(fgets($pipes[1])) !== 'READY') {throw new RuntimeException('Lock holder failed');}
    $worker->release();
    $worker = null;
    manager::generate($id);
    $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
    if ($request->status !== 'ready' || !$request->totalbytes || !$request->plan ||
            !$DB->record_exists('local_tomb_audit', ['requestid' => $id, 'event' => 'preparation_failed'])) {
        throw new RuntimeException('ZIP admission failure changed or lost the completed content version');
    }
    mtrace('PASS: Completed content remains ready when the next ZIP preparation lock is busy');
    $plan = $request->plan;
    fwrite($pipes[0], 'X'); foreach ($pipes as $pipe) {fclose($pipe);} proc_close($process); $process = null;
    delivery::request($request);
    delivery::assemble($id);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id]);
    if (!delivery::valid($cache) || $DB->get_field('local_tomb_request', 'lasterror', ['id' => $id]) !== '' || $DB->get_field('local_tomb_request', 'plan', ['id' => $id]) !== $plan) {
        throw new RuntimeException('Retry changed the fixed version or failed to prepare it');
    }
    mtrace('PASS: Retrying preparation produces a verified ZIP from the unchanged plan');
} finally {
    if ($worker) {$worker->release();}
    if ($process) {fwrite($pipes[0], 'X'); foreach ($pipes as $pipe) {fclose($pipe);} proc_close($process);}
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    core\session\manager::set_user(get_admin());
    if ($id) {delivery::purge($DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST));}
}
