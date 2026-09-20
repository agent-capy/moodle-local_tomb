<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class delivery {
    public static function request(object $request): void {
        global $DB;
        if (!manager::accessible($request, true) || $request->status !== 'ready' || $request->timepurged) {
            throw new \moodle_exception('unavailable', 'local_tomb');
        }
        $lock = config::lock('archive-' . $request->id, 5);
        if (!$lock) {
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        try {
            $cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id]);
            if ($cache && self::valid($cache)) {
                return;
            }
            if ($cache && in_array($cache->status, ['queued', 'running', 'waiting'], true)) {
                return;
            }
            if ($cache) {
                self::remove_file($cache);
                $cache->status = 'queued';
                $cache->bytes = 0;
                $cache->updated = time();
                $cache->lasterror = '';
                $DB->update_record('local_tomb_cache', $cache);
            } else {
                $DB->insert_record('local_tomb_cache', (object)['requestid' => $request->id, 'status' => 'queued',
                    'path' => '', 'sha256' => '', 'bytes' => 0, 'updated' => time(), 'lasterror' => '']);
            }
            manager::queue((int)$request->id, 'assemble');
            audit::add('download_requested', $request->id);
        } finally {
            $lock->release();
        }
    }

    public static function valid(object $cache): bool {
        return $cache->status === 'ready' && $cache->expires > time() && self::owned_path($cache->path) &&
            is_file($cache->path) && filesize($cache->path) === (int)$cache->bytes;
    }

    private static function owned_path(string $path): bool {
        return dirname($path) === config::cachepath() &&
            (bool)preg_match('/^tomb-[0-9]+-[a-f0-9]+\.(zip|part)$/D', basename($path));
    }

    public static function remove_file(object $cache): void {
        if ($cache->path && self::owned_path($cache->path) && is_file($cache->path)) {
            if (!unlink($cache->path)) {
                throw new \RuntimeException('Cannot remove Tomb cache');
            }
        }
    }

    private static function reserve(object $cache, int $size): bool {
        global $DB;
        $quota = config::lock('cachequota', 5);
        if (!$quota) {
            return false;
        }
        try {
            $limit = (int)config::get('cachebytes', 2 * 1024 ** 3);
            if ($size > $limit) {
                $cache->status = 'oversize';
                $cache->lasterror = 'capacity_setting_required';
                $cache->bytes = 0;
                $cache->updated = time();
                $DB->update_record('local_tomb_cache', $cache);
                return false;
            }
            $used = (int)$DB->get_field_sql('SELECT COALESCE(SUM(bytes), 0) FROM {local_tomb_cache} WHERE id <> ?',
                [$cache->id]);
            if ($used + $size > $limit || !config::capacity($size)) {
                $others = $DB->get_records('local_tomb_cache', ['status' => 'ready'], 'updated ASC');
                foreach ($others as $old) {
                    if ($old->id == $cache->id) {
                        continue;
                    }
                    $lock = config::lock('archive-' . $old->requestid);
                    if (!$lock) {
                        continue;
                    }
                    try {
                        self::remove_file($old);
                        $used -= (int)$old->bytes;
                        $old->status = 'expired';
                        $old->bytes = 0;
                        $old->path = '';
                        $DB->update_record('local_tomb_cache', $old);
                    } finally {
                        $lock->release();
                    }
                    if ($used + $size <= $limit && config::capacity($size)) {
                        break;
                    }
                }
            }
            if ($used + $size > $limit || !config::capacity($size)) {
                $cache->status = 'waiting';
                $cache->bytes = 0;
                $cache->updated = time();
                $DB->update_record('local_tomb_cache', $cache);
                return false;
            }
            $cache->status = 'running';
            $cache->bytes = $size;
            $cache->updated = time();
            $DB->update_record('local_tomb_cache', $cache);
            return true;
        } finally {
            $quota->release();
        }
    }

    public static function assemble(int $id): void {
        global $DB;
        $request = $DB->get_record('local_tomb_request', ['id' => $id]);
        if (!$request || !config::allowed($request->subjectid) || $request->status !== 'ready' || $request->timepurged) {
            return;
        }
        $worker = config::lock('worker');
        if (!$worker) {
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        $lock = config::lock('archive-' . $id);
        if (!$lock) {
            $worker->release();
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        $started = microtime(true);
        $memorybefore = memory_get_usage(true);
        $cache = null;
        $part = null;
        $finished = null;
        try {
            // Privacy erasure may have retired the version before the worker lock was acquired.
            $request = $DB->get_record('local_tomb_request', ['id' => $id]);
            if (!$request || $request->status !== 'ready' || $request->timepurged || !config::allowed($request->subjectid)) {
                return;
            }
            $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id], '*', MUST_EXIST);
            if (self::valid($cache) || !in_array($cache->status, ['queued', 'waiting', 'running'], true)) {
                return;
            }
            if (!self::reserve($cache, (int)$request->totalbytes)) {
                return;
            }
            $plan = storage::plan($request);
            $fixed = json_decode($request->plan, true, 512, JSON_THROW_ON_ERROR);
            if ($plan['totalbytes'] !== (int)$request->totalbytes || $plan['centraloffset'] !== $fixed['centraloffset']) {
                throw new \RuntimeException('Fixed assembly plan changed', 1001);
            }
            $part = config::cachepath() . '/tomb-' . $id . '-' . bin2hex(random_bytes(12)) . '.part';
            $cache->path = $part;
            $DB->update_record('local_tomb_cache', $cache);
            $last = 0;
            $heartbeat = function() use (&$last, $cache) {
                global $DB;
                if (time() - $last >= 10) {
                    if (!config::capacity()) {
                        throw new \RuntimeException('Capacity guard interrupted assembly');
                    }
                    $DB->set_field('local_tomb_cache', 'updated', time(), ['id' => $cache->id]);
                    $last = time();
                }
            };
            $sha = zip::assemble($plan, $part, function(array $entry) {
                $file = get_file_storage()->get_file_by_id($entry['fileid']);
                if (!$file || $file->get_contenthash() !== $entry['contenthash']) {
                    throw new \RuntimeException('Archive material is unavailable', 1001);
                }
                return $file->get_content_file_handle();
            }, $heartbeat);
            $finished = substr($part, 0, -5) . '.zip';
            if (!rename($part, $finished)) {
                throw new \RuntimeException('Cannot finalise ZIP cache');
            }
            if (!touch($finished, (int)$request->timefinished)) {
                throw new \RuntimeException('Cannot set stable archive modification time');
            }
            $cache->path = $finished;
            $cache->sha256 = $sha;
            $cache->expires = time() + min(21600, max(60, (int)config::get('cachettl', 21600)));
            $cache->updated = time();
            $cache->status = 'ready';
            $cache->lasterror = '';
            $DB->update_record('local_tomb_cache', $cache);
            $DB->set_field('local_tomb_request', 'lasterror', '', ['id' => $id, 'status' => 'ready']);
            estimate::measured($id, 'assembly', $started, $memorybefore);
            audit::add('zip_ready', $id, ['sha256' => $sha, 'bytes' => $request->totalbytes], 0);
            manager::notify($request, 'ready');
        } catch (\Throwable $e) {
            if ($part && is_file($part)) {
                unlink($part);
            }
            if ($finished && is_file($finished)) {
                unlink($finished);
            }
            if ($cache) {
                $cache->status = 'failed';
                $cache->bytes = 0;
                $cache->path = '';
                $cache->updated = time();
                $cache->lasterror = get_class($e) . ': ' . substr($e->getMessage(), 0, 1000);
                $DB->update_record('local_tomb_cache', $cache);
            }
            if ($e->getCode() === 1001) {
                $DB->update_record('local_tomb_request', (object)['id' => $id, 'status' => 'blocked',
                    'stage' => 'blocked', 'lasterror' => 'archive_material_unavailable']);
            }
            audit::add('assembly_failed', $id, ['exception' => get_class($e), 'material' => $e->getCode() === 1001], 0);
            manager::notify($request, 'failed');
        } finally {
            $lock->release();
            $worker->release();
        }
    }

    public static function purge(object $request): void {
        global $DB;
        require_capability('local/tomb:manage', \context_system::instance());
        if (!$request->isrehearsal && (int)config::get('deliverydeadline', 0) > time()) {
            throw new \moodle_exception('retentionactive', 'local_tomb');
        }
        $worker = config::lock('worker');
        if (!$worker) {
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        $lock = config::lock('archive-' . $request->id);
        if (!$lock) {
            $worker->release();
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        try {
            $cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id]);
            if ($cache) {
                self::remove_file($cache);
                $DB->delete_records('local_tomb_cache', ['id' => $cache->id]);
            }
            storage::clear($request, false);
            $DB->set_field('local_tomb_request', 'timepurged', time(), ['id' => $request->id]);
            audit::add('materials_purged', $request->id);
        } finally {
            $lock->release();
            $worker->release();
        }
    }

    public static function cleanup(): void {
        global $DB;
        // A dead worker cannot hold this lock. Do not infer failure from time alone.
        $worker = config::lock('worker');
        if ($worker) {
            try {
                foreach ($DB->get_records_select('local_tomb_request', 'status = ? AND heartbeat < ?',
                        ['running', time() - 600]) as $request) {
                    $DB->update_record('local_tomb_request', (object)['id' => $request->id, 'status' => 'failed',
                        'stage' => 'failed', 'lasterror' => 'Worker interrupted; request a new version']);
                    audit::add('worker_interrupted', $request->id, [], 0);
                    manager::notify($request, 'failed');
                }
                foreach ($DB->get_records_select('local_tomb_cache', 'status = ? AND updated < ?',
                        ['running', time() - 600]) as $cache) {
                    $lock = config::lock('archive-' . $cache->requestid);
                    if (!$lock) {
                        continue;
                    }
                    try {
                        self::remove_file($cache);
                        $cache->status = 'queued';
                        $cache->bytes = 0;
                        $cache->path = '';
                        $DB->update_record('local_tomb_cache', $cache);
                        audit::add('assembly_interrupted', $cache->requestid, [], 0);
                    } finally {
                        $lock->release();
                    }
                }
            } finally {
                $worker->release();
            }
        }
        $caches = $DB->get_records('local_tomb_cache', null, 'id ASC');
        foreach ($caches as $cache) {
            if ($cache->status === 'ready' && $cache->expires <= time()) {
                $lock = config::lock('archive-' . $cache->requestid);
                if (!$lock) {
                    continue;
                }
                try {
                    self::remove_file($cache);
                    $cache->status = 'expired';
                    $cache->bytes = 0;
                    $cache->path = '';
                    $DB->update_record('local_tomb_cache', $cache);
                } finally {
                    $lock->release();
                }
            } else if (in_array($cache->status, ['queued', 'waiting'], true)) {
                manager::queue((int)$cache->requestid, 'assemble');
            }
        }
        foreach ($DB->get_records('local_tomb_request', ['status' => 'queued']) as $request) {
            if (config::allowed($request->subjectid, true)) {
                manager::queue((int)$request->id, 'generate');
            }
        }
    }
}
