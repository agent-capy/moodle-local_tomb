<?php
// License: GNU GPL v3 or later.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_login();

use local_tomb\local\config;
use local_tomb\local\manager;
use local_tomb\local\delivery;
use local_tomb\local\audit;

$id = required_param('id', PARAM_INT);
$request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
if (!manager::accessible($request, true) || $request->status !== 'ready' || $request->timepurged) {
    http_response_code(403);
    throw new moodle_exception('unavailable', 'local_tomb');
}
$slot = null;
for ($i = 0; $i < min(8, max(1, (int)config::get('downloadslots', 1))); $i++) {
    $slot = config::lock('download-' . $i);
    if ($slot) {
        break;
    }
}
if (!$slot) {
    header('Retry-After: 15');
    http_response_code(429);
    throw new moodle_exception('workerbusy', 'local_tomb');
}
$lock = config::lock('archive-' . $id);
if (!$lock) {
    $slot->release();
    header('Retry-After: 15');
    http_response_code(429);
    throw new moodle_exception('workerbusy', 'local_tomb');
}
register_shutdown_function(function() use ($lock, $slot) {
    $lock->release();
    $slot->release();
});
$cache = $DB->get_record('local_tomb_cache', ['requestid' => $id]);
if (!$cache || !delivery::valid($cache)) {
    redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
}
if (!hash_equals($cache->sha256, hash_file('sha256', $cache->path))) {
    delivery::remove_file($cache);
    $cache->status = 'failed';
    $cache->bytes = 0;
    $cache->path = '';
    $cache->lasterror = 'cache_checksum_mismatch';
    $DB->update_record('local_tomb_cache', $cache);
    audit::add('cache_invalid', $id);
    redirect(new moodle_url('/local/tomb/index.php', ['id' => $id]));
}
$etag = '"' . $cache->sha256 . '"';
header_register_callback(function() {
    header('Cache-Control: private, no-store, must-revalidate');
});
header('ETag: ' . $etag);
if (!empty($_SERVER['HTTP_IF_RANGE'])) {
    $condition = $_SERVER['HTTP_IF_RANGE'];
    if ($condition !== $etag && (strtotime($condition) === false || strtotime($condition) < $request->timefinished)) {
        unset($_SERVER['HTTP_RANGE']);
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    $DB->execute('UPDATE {local_tomb_request} SET downloadcount = downloadcount + 1, lastdownload = ? WHERE id = ?',
        [time(), $id]);
    audit::add('download_started', $id, ['range' => isset($_SERVER['HTTP_RANGE']), 'subject' => $request->subjectid]);
}
send_file($cache->path, 'tomb-' . $id . '.zip', 0, 0, false, true, 'application/zip', false,
    ['cacheability' => 'private']);
