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

