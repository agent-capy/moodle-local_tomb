<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class audit {
    public static function add(string $event, int $requestid = 0, array $details = [], ?int $actorid = null): void {
        global $DB, $USER;
        $lock = config::lock('audit', 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $last = $DB->get_records('local_tomb_audit', null, 'id DESC', '*', 0, 1);
            $last = $last ? reset($last) : false;
            $record = (object)['requestid' => $requestid, 'actorid' => $actorid ?? (int)$USER->id,
                'event' => $event, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'timecreated' => time(), 'prevhash' => $last ? $last->eventhash : str_repeat('0', 64)];
            $record->eventhash = self::hash($record);
            $DB->insert_record('local_tomb_audit', $record);
        } finally {
            $lock->release();
        }
    }

    public static function hash(object $record): string {
        return hash('sha256', implode("\n", [$record->prevhash, $record->requestid, $record->actorid,
            $record->event, $record->timecreated, $record->details]));
    }

    /** Privacy erasure is an explicit new audit epoch, not an undetectable rewrite of an old checkpoint. */
    public static function erase_person(int $userid, array $requestids): void {
        global $DB;
        $lock = config::lock('audit', 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $rows = $DB->get_records('local_tomb_audit', null, 'id ASC');
            $last = $rows ? end($rows) : null;
            $oldhead = $last ? $last->eventhash : str_repeat('0', 64);
            $previous = str_repeat('0', 64);
            $count = 0;
            foreach ($rows as $row) {
                if ((int)$row->actorid === $userid || in_array((int)$row->requestid, array_map('intval', $requestids), true)) {
                    $row->actorid = 0;
                    $row->requestid = 0;
                    $row->event = 'privacy_redacted';
                    $row->details = '{}';
                    $count++;
                }
                $row->prevhash = $previous;
                $row->eventhash = self::hash($row);
                $DB->update_record('local_tomb_audit', $row);
                $previous = $row->eventhash;
            }
            $checkpoint = (object)['requestid' => 0, 'actorid' => 0, 'event' => 'privacy_checkpoint',
                'details' => json_encode(['previoushead' => $oldhead, 'redacted' => $count], JSON_THROW_ON_ERROR),
                'timecreated' => time(), 'prevhash' => $previous];
            $checkpoint->eventhash = self::hash($checkpoint);
            $DB->insert_record('local_tomb_audit', $checkpoint);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    public static function verify(): bool {
        global $DB;
        $records = $DB->get_recordset('local_tomb_audit', null, 'id ASC');
        $previous = str_repeat('0', 64);
        try {
            foreach ($records as $record) {
                if (!hash_equals($previous, $record->prevhash) || !hash_equals($record->eventhash, self::hash($record))) {
                    return false;
                }
                $previous = $record->eventhash;
            }
            return true;
        } finally {
            $records->close();
        }
    }
}

