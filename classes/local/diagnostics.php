<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

/** Bounded operational diagnostics. Never put these records in a learning archive. */
final class diagnostics {
    public const RETENTION = 30 * DAYSECS;
    public const LIMIT = 25;
    private static ?array $active = null;
    private static ?string $reserve = null;
    private static bool $registered = false;

    public static function begin(int $id, string $phase): void {
        if (!self::$registered) {
            // Moodle runs these callbacks while the database is still available.
            \core_shutdown_manager::register_function([self::class, 'shutdown']);
            self::$registered = true;
        }
        self::$active = ['requestid' => $id, 'phase' => $phase, 'stage' => $phase,
            'courseid' => 0, 'cmid' => 0, 'started' => microtime(true),
            'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')];
        self::$reserve = str_repeat('R', 512 * 1024);
    }

    public static function checkpoint(string $stage, int $courseid = 0, int $cmid = 0): void {
        if (self::$active !== null) {
            self::$active['stage'] = $stage;
            self::$active['courseid'] = $courseid;
            self::$active['cmid'] = $cmid;
        }
    }

    public static function end(): void {
        self::$active = null;
        self::$reserve = null;
    }

    /** Remove common credentials, query strings, emails and local absolute paths. */
    public static function clean(string $text): string {
        global $CFG;
        $text = substr($text, 0, 8192);
        $text = preg_replace('~https?://[^\s<>"\']+~i', '[url]', $text);
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = preg_replace('/\b(authorization|cookie|set-cookie)\s*:\s*[^\r\n]*/i', '$1: [redacted]', $text);
        $text = preg_replace('/\b(password|passwd|pwd|token|sesskey|secret|authorization|cookie|api[_-]?key)\b[\s"\']*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[redacted]', $text);
        foreach (['dataroot', 'dirroot'] as $root) {
            if (!empty($CFG->$root)) {$text = str_replace($CFG->$root, '[' . $root . ']', $text);}
        }
        $text = preg_replace('~(?<![\w\]])(?:[A-Z]:[\\\\/]|/)(?:[^\s<>"\':,()]+[\\\\/])*[^\s<>"\':,()]+~', '[path]', $text);
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $text);
        return mb_strcut($text, 0, 2000, 'UTF-8');
    }

    private static function location(string $file, int $line): array {
        global $CFG;
        return ['file' => str_starts_with($file, $CFG->dirroot . '/') ?
            substr($file, strlen($CFG->dirroot) + 1) : basename($file), 'line' => $line];
    }

    private static function exception(\Throwable $error): array {
        $causes = [];
        do {
            $trace = [];
            foreach (array_slice($error->getTrace(), 0, 20) as $frame) {
                // Deliberately exclude arguments, objects and Moodle debuginfo (which can contain SQL/data).
                $trace[] = self::location($frame['file'] ?? '', (int)($frame['line'] ?? 0)) +
                    ['call' => self::clean(($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''))];
            }
            $causes[] = ['class' => get_class($error), 'code' => self::clean((string)$error->getCode()),
                'errorcode' => self::clean((string)($error instanceof \moodle_exception ? $error->errorcode : '')),
                'message' => self::clean($error->getMessage()),
                'location' => self::location($error->getFile(), $error->getLine()), 'trace' => $trace];
            $error = $error->getPrevious();
        } while ($error && count($causes) < 3);
        return $causes;
    }

    public static function failure(int $id, string $phase, \Throwable $error, bool $warning = false): void {
        try {
            self::write($id, $phase, $warning ? 'warning' : 'error', ['type' => 'exception',
                'causes' => self::exception($error)]);
        } catch (\Throwable $ignored) {
            self::fallback($id, $phase);
        }
    }

    /** SIGKILL/server failure has no PHP error; do not guess its cause or report cleanup's memory as the worker's. */
    public static function interrupted(int $id, string $phase): void {
        try {self::write($id, $phase, 'error', ['type' => 'interrupted']);}
        catch (\Throwable $ignored) {self::fallback($id, $phase);}
    }

    public static function shutdown(): void {
        if (self::$active === null) {return;}
        $error = error_get_last();
        $active = self::$active;
        self::$reserve = null;
        try {
            $fatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            self::write($active['requestid'], $active['phase'], 'error', $fatal ?
                ['type' => 'fatal', 'php_error_type' => $error['type'], 'message' => self::clean($error['message']),
                    'location' => self::location($error['file'], $error['line'])] : ['type' => 'interrupted']);
            // Leave state repair and notification to the existing lock-aware cleanup task.
        } catch (\Throwable $ignored) {
            self::fallback($active['requestid'], $active['phase']);
        } finally {self::end();}
    }

    private static function fallback(int $id, string $phase): void {
        error_log('Tomb diagnostics unavailable: request=' . $id . ' phase=' . $phase . '; inspect the PHP/cron log.');
    }

    public static function notes(int $id): array {
        global $DB;
        $reasons = $DB->get_records_sql('SELECT reason, SUM(itemcount) AS total FROM {local_tomb_omission}
            WHERE requestid = ? GROUP BY reason ORDER BY reason', [$id]);
        return array_map(fn($row) => (int)$row->total, $reasons);
    }

    private static function write(int $id, string $phase, string $severity, array $error): void {
        global $DB, $CFG;
        $request = $DB->get_record('local_tomb_request', ['id' => $id]);
        if (!$request || $request->timepurged) {return;}
        $where = ['requestid' => $id, 'severity' => $severity];
        if ($severity === 'warning' && $DB->count_records('local_tomb_diagnostic', $where) >= self::LIMIT) {return;}
        $active = self::$active && self::$active['requestid'] === $id ? self::$active : null;
        $runtime = $active ? ['elapsed_seconds' => round(microtime(true) - $active['started'], 3),
            'memory_bytes' => memory_get_usage(true), 'process_peak_memory_bytes' => memory_get_peak_usage(true),
            'memory_limit' => $active['memory_limit'], 'max_execution_time' => $active['max_execution_time']] : null;
        $data = ['schema' => 1, 'error' => $error,
            'context' => ['stage' => $active['stage'] ?? $request->stage,
                'courseid' => $active['courseid'] ?? 0, 'cmid' => $active['cmid'] ?? 0,
                'status' => $request->status, 'kind' => $request->kind, 'policy' => $request->policy,
                'heartbeat' => (int)$request->heartbeat], 'runtime' => $runtime,
            'counts' => ['stored_entries' => $DB->count_records('local_tomb_entry', ['requestid' => $id]),
                'notes_by_reason' => self::notes($id)],
            'versions' => ['tomb' => get_config('local_tomb', 'version'), 'moodle' => $CFG->version,
                'php' => PHP_VERSION, 'sapi' => PHP_SAPI]];
        $DB->insert_record('local_tomb_diagnostic', (object)($where + ['phase' => $phase, 'timecreated' => time(),
            'details' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]));
        // Keep the most recent terminal failures, independently of the first warning samples.
        $older = $DB->get_records('local_tomb_diagnostic', $where, 'id DESC', 'id', self::LIMIT, 100);
        if ($older) {$DB->delete_records_list('local_tomb_diagnostic', 'id', array_keys($older));}
    }

    /** Protected at the data boundary too; a learner/teacher request owner cannot fetch technical diagnostics. */
    public static function report(int $id): array {
        global $DB;
        require_capability('local/tomb:manage', \context_system::instance());
        $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
        $cache = $DB->get_record('local_tomb_cache', ['requestid' => $id]);
        $logs = [];
        foreach ($DB->get_records('local_tomb_diagnostic', ['requestid' => $id], 'id DESC', '*', 0, 2 * self::LIMIT) as $row) {
            $logs[] = ['id' => (int)$row->id, 'timecreated' => (int)$row->timecreated,
                'phase' => $row->phase, 'severity' => $row->severity, 'details' => json_decode($row->details, true)];
        }
        $groups = $DB->get_records_sql('SELECT MIN(id) AS id, courseid, cmid, reason, SUM(itemcount) AS total
            FROM {local_tomb_omission} WHERE requestid = ? GROUP BY courseid, cmid, reason ORDER BY MIN(id)', [$id], 0, 101);
        return ['schema' => 1, 'requestid' => $id, 'generated_at' => time(), 'status' => $request->status,
            'stage' => $request->stage, 'cache_status' => $cache->status ?? null,
            'legacy_collection_error' => self::clean($request->lasterror),
            'legacy_assembly_error' => self::clean($cache->lasterror ?? ''),
            'notes_by_reason' => self::notes($id), 'note_groups' => array_values(array_slice($groups, 0, 100)),
            'note_groups_truncated' => count($groups) > 100, 'logs' => $logs,
            'limits' => ['first_warning_samples' => self::LIMIT, 'latest_errors' => self::LIMIT, 'retention_days' => 30]];
    }

    public static function cleanup(): void {
        global $DB;
        $DB->delete_records_select('local_tomb_diagnostic', 'timecreated < ?', [time() - self::RETENTION]);
    }
}
