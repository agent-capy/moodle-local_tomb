<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

/** Records the people represented in immutable snapshots; never expose this index in a learning ZIP. */
final class personal_data {
    public static function record(object $request, int $userid, string $key, array $data): void {
        global $DB;
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid])) {
            return;
        }
        $where = ['requestid' => $request->id, 'userid' => $userid, 'itemkey' => sha1($key)];
        $row = $DB->get_record('local_tomb_person', $where);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $compressed = gzdeflate($json, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Cannot compress privacy snapshot');
        }
        $payload = 'deflate:' . base64_encode($compressed);
        if ($row) {
            $row->payload = $payload;
            $DB->update_record('local_tomb_person', $row);
        } else {
            $DB->insert_record('local_tomb_person', (object)($where + ['payload' => $payload]));
        }
    }

    public static function decode(string $payload): object {
        if (str_starts_with($payload, 'deflate:')) {
            $bytes = base64_decode(substr($payload, 8), true);
            $payload = $bytes === false ? false : gzinflate($bytes);
            if ($payload === false) {
                throw new \moodle_exception('privacy:missingmaterial', 'local_tomb');
            }
        }
        return json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
    }

    public static function fragment(object $request, int $userid, string $key, string $path, string $html): void {
        $files = [];
        if (preg_match_all('/(?:href|src|poster)="([^"]+)"/u', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('~(?:^|/)(_files/[a-f0-9]{2}/[a-f0-9]+\.[a-z0-9]+)$~', $url, $found)) {
                    $files[] = $found[1];
                }
            }
        }
        self::record($request, $userid, $key, ['type' => 'contribution', 'page' => $path,
            'html' => $html, 'files' => array_values(array_unique($files))]);
    }

    /** Index only the rendered manual comment, never the student's surrounding answer. */
    public static function question_comment(object $request, \question_attempt $qa, string $path, string $html): void {
        [$comment, $format, $step] = $qa->get_manual_comment();
        if (!$step || !$comment) {
            return;
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            $comments = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " comment ")]');
            $fragment = '';
            foreach ($comments as $node) {
                $fragment .= $document->saveHTML($node);
            }
            if ($fragment !== '') {
                self::fragment($request, (int)$step->get_user_id(), 'quiz-comment:' . $qa->get_database_id(), $path, $fragment);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Pre-index alpha snapshots cannot safely be treated as complete privacy responses. */
    public static function has_legacy(int $userid): bool {
        global $DB;
        $created = (int)$DB->get_field('user', 'timecreated', ['id' => $userid]);
        return $DB->record_exists_select('local_tomb_request',
            'privacyversion = 0 AND timepurged = 0 AND timecreated >= ?', [$created]);
    }

    public static function has_data(int $userid): bool {
        global $DB;
        return self::has_legacy($userid) || $DB->record_exists_select('local_tomb_request',
            'subjectid = ? OR requesterid = ?', [$userid, $userid]) ||
            $DB->record_exists('local_tomb_person', ['userid' => $userid]) ||
            $DB->record_exists('local_tomb_audit', ['actorid' => $userid]);
    }

    /** Invoked only by an approved Moodle Privacy API request; it deliberately overrides delivery retention. */
    public static function erase(int $userid): void {
        global $DB;
        if (self::has_legacy($userid)) {
            throw new \moodle_exception('privacy:legacy', 'local_tomb');
        }
        if (!self::has_data($userid)) {
            return;
        }
        $locks = [];
        try {
            foreach (['personaldata', 'worker'] as $name) {
                $lock = config::lock($name, 10);
                if (!$lock) {
                    throw new \moodle_exception('workerbusy', 'local_tomb');
                }
                $locks[] = $lock;
            }
            $requests = $DB->get_records_sql('SELECT DISTINCT r.* FROM {local_tomb_request} r
                LEFT JOIN {local_tomb_person} p ON p.requestid = r.id
                WHERE r.subjectid = :owner OR p.userid = :person ORDER BY r.id', ['owner' => $userid, 'person' => $userid]);
            $delegated = $DB->get_records('local_tomb_request', ['requesterid' => $userid]);
            $affected = array_unique(array_merge(array_keys($requests), array_keys($delegated)));
            // Acquire every archive lock before the first irreversible file deletion.
            foreach ($affected as $id) {
                $lock = config::lock('archive-' . $id, 10);
                if (!$lock) {
                    throw new \moodle_exception('workerbusy', 'local_tomb');
                }
                $locks[] = $lock;
            }
            foreach ($requests as $request) {
                $cache = $DB->get_record('local_tomb_cache', ['requestid' => $request->id]);
                if ($cache) {
                    delivery::remove_file($cache);
                    $DB->delete_records('local_tomb_cache', ['id' => $cache->id]);
                }
                storage::clear($request, true);
                if ((int)$request->subjectid === $userid) {
                    $DB->delete_records('local_tomb_request', ['id' => $request->id]);
                    $DB->set_field('local_tomb_request', 'revisionof', 0, ['revisionof' => $request->id]);
                } else {
                    // Never edit part of a finished ZIP: retire the entire snapshot which contains the erased contribution.
                    $DB->update_record('local_tomb_request', (object)['id' => $request->id, 'status' => 'blocked',
                        'stage' => 'blocked', 'timepurged' => time(), 'totalbytes' => 0, 'plan' => '', 'expected' => '',
                        'actual' => '', 'reason' => '', 'message' => '', 'lasterror' => 'privacy_erasure', 'wantdownload' => 0]);
                }
            }
            $DB->set_field('local_tomb_request', 'requesterid', 0, ['requesterid' => $userid]);
            foreach ($delegated as $request) {
                $DB->set_field('local_tomb_request', 'reason', '', ['id' => $request->id]);
            }
            foreach ($DB->get_records('task_adhoc', ['component' => 'local_tomb']) as $task) {
                $data = json_decode($task->customdata, true);
                if (isset($requests[$data['requestid'] ?? 0])) {
                    $DB->delete_records('task_adhoc', ['id' => $task->id]);
                }
            }
            $DB->delete_records('local_tomb_person', ['userid' => $userid]);
            $DB->delete_records_select('local_tomb_blob',
                'NOT EXISTS (SELECT 1 FROM {local_tomb_entry} e WHERE e.contenthash = {local_tomb_blob}.contenthash)');
            audit::erase_person($userid, $affected);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }
}
