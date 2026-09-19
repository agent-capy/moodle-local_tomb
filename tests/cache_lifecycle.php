<?php
// License: GNU GPL v3 or later.
// Explicit fault injection on a retained, disposable Tomb fixture archive only.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\{audit, config, delivery};
[$options, $unknown] = cli_get_params(['request' => 0, 'corrupt' => false, 'run' => false, 'hold' => ''], []);
if ($unknown || !$options['request'] || (!$options['hold'] && $options['run'] === $options['corrupt'])) {
    cli_error('Use --request=ID --corrupt, then request its download as the owner, then --request=ID --run');
}
$request = $DB->get_record('local_tomb_request', ['id' => (int)$options['request']], '*', MUST_EXIST);
$subject = core_user::get_user($request->subjectid);
$courseid = (int)config::get('fixturecourseid', 0);
if (config::mode() !== 'rehearsal' || !$request->isrehearsal || $request->timepurged ||
        $request->status !== 'ready' || $subject->username !== 'tomb.alpha.learner' ||
        json_decode($request->courses, true) !== [$courseid]) {
    cli_error('Refusing to alter anything except the dedicated learner fixture archive');
}
if ($options['hold']) {
    $locks = [];
    $held = [];
    try {
        foreach ($DB->get_records('local_tomb_cache', ['status' => 'ready']) as $row) {
            if (($row->requestid == $request->id) !== ($options['hold'] === 'this')) {
                continue;
            }
            $lock = config::lock('archive-' . $row->requestid, 5);
            if (!$lock) {
                throw new RuntimeException('Another archive is in use');
            }
            $locks[] = $lock;
            $held[] = $row;
        }
        echo json_encode($held, JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
        // Parent closes stdin even on failure; do not leave locks behind.
        fread(STDIN, 1);
    } finally {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }
    exit(0);
}
function tomb_cache_hold(string $scope): array {
    global $request;
    $process = proc_open([PHP_BINARY, __FILE__, '--request=' . $request->id, '--hold=' . $scope],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    $rows = json_decode(fgets($pipes[1]), false, 512, JSON_THROW_ON_ERROR);
    return [$process, $pipes, $rows];
}
function tomb_cache_release(array $holder): void {
    [$process, $pipes] = $holder;
    fwrite($pipes[0], "X");
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
}
core\session\manager::set_user($subject);
$cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
$hash = $cache->sha256;
$checks = 0;
function tomb_cache_check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $name);
    }
    $checks++;
    mtrace('PASS: ' . $name);
}
if ($options['corrupt']) {
    $lock = config::lock('archive-' . $request->id, 5);
    if (!$lock || !delivery::valid($cache)) {
        cli_error('Cache must be idle and ready');
    }
    try {
        $handle = fopen($cache->path, 'r+b');
        fseek($handle, -1, SEEK_END);
        $byte = ord(fread($handle, 1));
        fseek($handle, -1, SEEK_END);
        fwrite($handle, chr($byte ^ 1));
        fclose($handle);
        tomb_cache_check(filesize($cache->path) === (int)$cache->bytes && hash_file('sha256', $cache->path) !== $hash,
            'Only the disposable ZIP cache was corrupted, with its size unchanged');
    } finally {
        $lock->release();
    }
    exit(0);
}
tomb_cache_check($cache->status === 'failed' && $cache->lasterror === 'cache_checksum_mismatch' &&
    !$cache->path && !$cache->bytes, 'HTTP delivery rejected corruption and released the cache reservation');
tomb_cache_check($request->status === 'ready', 'Cache-only damage preserved the completed content version');
delivery::request($request);
delivery::assemble((int)$request->id);
$cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
tomb_cache_check(delivery::valid($cache) && hash_equals($hash, $cache->sha256), 'Cache rebuild has the original SHA-256');

$forced = $CFG->forced_plugin_settings['local_tomb'] ?? [];
$holder = tomb_cache_hold('others');
$protected = $holder[2];
try {
    // Independent DB connection: MySQL locks can be re-entered on the same connection.
    delivery::remove_file($cache);
    $cache->status = 'expired';
    $cache->bytes = 0;
    $cache->path = '';
    $DB->update_record('local_tomb_cache', $cache);
    delivery::request($request);
    $CFG->forced_plugin_settings['local_tomb']['minfreebytes'] = PHP_INT_MAX;
    delivery::assemble((int)$request->id);
    $cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
    tomb_cache_check($cache->status === 'waiting' && !$cache->bytes && !$cache->path,
        'Low-space assembly waits without a partial file or reserved bytes');
    foreach ($protected as $other) {
        tomb_cache_check(is_file($other->path) && hash_equals($other->sha256, hash_file('sha256', $other->path)),
            'An in-use archive was not evicted: ' . $other->requestid);
    }
} finally {
    $CFG->forced_plugin_settings['local_tomb'] = $forced;
    tomb_cache_release($holder);
}
delivery::assemble((int)$request->id);
$cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
tomb_cache_check(delivery::valid($cache) && hash_equals($hash, $cache->sha256), 'Capacity recovery rebuilds the same version');

$holder = tomb_cache_hold('this');
try {
    $DB->set_field('local_tomb_cache', 'expires', time() - 1, ['id' => $cache->id]);
    delivery::cleanup();
    tomb_cache_check(is_file($cache->path), 'Expiry cleanup protects an archive held by a download');
} finally {
    tomb_cache_release($holder);
}
delivery::cleanup();
tomb_cache_check(!is_file($cache->path), 'Expiry cleanup removes the cache after the download lock is released');
delivery::request($request);
delivery::assemble((int)$request->id);
$cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
$cache->status = 'running';
$cache->updated = time() - 700;
$DB->update_record('local_tomb_cache', $cache);
delivery::cleanup();
$recovered = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
tomb_cache_check($recovered->status === 'queued' && !$recovered->bytes && !$recovered->path,
    'Interrupted assembly releases its file and reservation before requeueing');
delivery::assemble((int)$request->id);
$cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id], '*', MUST_EXIST);
tomb_cache_check(delivery::valid($cache) && hash_equals($hash, $cache->sha256), 'Interrupted assembly recovers identical bytes');
tomb_cache_check(audit::verify(), 'Audit chain remains valid');
mtrace('PASS: ' . $checks . ' cache lifecycle assertions');
