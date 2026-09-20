<?php
// License: GNU GPL v3 or later.
namespace local_tomb\privacy;
defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\{approved_contextlist, approved_userlist, contextlist, userlist, writer};
use local_tomb\local\{config, personal_data};

/** Moodle's approved requests use a logical user context, including contributions copied into others' snapshots. */
final class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_user_data_provider, \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        foreach ([
            'local_tomb_request' => ['subjectid', 'requesterid', 'reason', 'courses', 'status', 'timecreated',
                'timestarted', 'timecollected', 'timefinished', 'revisionof', 'policy', 'kind', 'notified',
                'lastdownload', 'received', 'downloadcount', 'plan', 'expected', 'actual', 'lasterror'],
            'local_tomb_person' => ['requestid', 'userid', 'payload'],
            'local_tomb_entry' => ['requestid', 'zippath', 'fileid', 'contenthash'],
            'local_tomb_omission' => ['requestid', 'courseid', 'cmid', 'reason', 'detail'],
            'local_tomb_cache' => ['requestid', 'status', 'path', 'sha256', 'bytes', 'expires'],
            'local_tomb_blob' => ['contenthash', 'size', 'crc'],
            'local_tomb_audit' => ['requestid', 'actorid', 'event', 'details', 'timecreated'],
        ] as $table => $fields) {
            $collection->add_database_table($table, array_fill_keys($fields, 'privacy:metadata:details'),
                'privacy:metadata:' . $table);
        }
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        if (personal_data::has_data($userid)) {
            $list->add_user_context($userid);
        }
        return $list;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel === CONTEXT_USER && personal_data::has_data((int)$context->instanceid)) {
            $userlist->add_user((int)$context->instanceid);
        }
    }

    private static function approved(approved_contextlist $list): bool {
        foreach ($list->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_USER && (int)$context->instanceid === (int)$list->get_user()->id) {
                return true;
            }
        }
        return false;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::approved($contextlist)) {
            return;
        }
        $userid = (int)$contextlist->get_user()->id;
        if (personal_data::has_legacy($userid)) {
            throw new \moodle_exception('privacy:legacy', 'local_tomb');
        }
        $lock = config::lock('personaldata', 10);
        if (!$lock) {
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        $worker = config::lock('worker', 10);
        if (!$worker) {
            $lock->release();
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        try {
            $writer = writer::with_context(\context_user::instance($userid));
            $requests = $DB->get_records_select('local_tomb_request', 'subjectid = ? OR requesterid = ?', [$userid, $userid], 'id');
            foreach ($requests as $request) {
                $path = ['Tomb', 'request-' . $request->id];
                $data = ['id' => $request->id, 'kind' => $request->kind, 'policy' => $request->policy,
                    'requested_at' => $request->timecreated, 'courses' => json_decode($request->courses, true),
                    'reason' => $request->reason, 'status' => $request->status];
                if ((int)$request->subjectid === $userid) {
                    $data += ['collected_at' => $request->timecollected, 'finished_at' => $request->timefinished,
                        'notification_at' => $request->notified, 'downloads' => $request->downloadcount,
                        'last_download_at' => $request->lastdownload, 'receipt_at' => $request->received,
                        'purged_at' => $request->timepurged, 'previous_version' => $request->revisionof,
                        'omissions' => array_values($DB->get_records('local_tomb_omission', ['requestid' => $request->id]))];
                    if (!$request->timepurged && $request->kind === 'learner') {
                        foreach ($DB->get_records('local_tomb_entry', ['requestid' => $request->id], 'id') as $entry) {
                            self::export_entry($writer, $path, $entry);
                        }
                    }
                } else {
                    $data['delegated_request'] = true;
                }
                $writer->export_data($path, (object)$data);
            }
            foreach ($DB->get_records('local_tomb_person', ['userid' => $userid], 'id') as $person) {
                $request = $DB->get_record('local_tomb_request', ['id' => $person->requestid]);
                if (!$request || ((int)$request->subjectid === $userid && $request->kind === 'learner')) {
                    continue;
                }
                $data = personal_data::decode($person->payload);
                $path = ['Tomb', 'contributions', 'request-' . $request->id, $person->itemkey];
                if ($request->timepurged) {
                    $writer->export_data($path, (object)['type' => 'retained_inventory_reference', 'purged_at' => $request->timepurged]);
                    continue;
                }
                $writer->export_data($path, $data);
                foreach ($data->files ?? [] as $zippath) {
                    $entry = $DB->get_record('local_tomb_entry', ['requestid' => $request->id, 'zippath' => $zippath]);
                    if ($entry) {
                        self::export_entry($writer, $path, $entry);
                    }
                }
            }
            $events = [];
            foreach ($DB->get_records('local_tomb_audit', ['actorid' => $userid], 'id') as $row) {
                $events[] = (object)['id' => $row->id, 'event' => $row->event, 'requestid' => $row->requestid,
                    'timecreated' => $row->timecreated, 'details' => json_decode($row->details)];
            }
            $writer->export_data(['Tomb', 'operations'], (object)['events' => $events]);
        } finally {
            $worker->release();
            $lock->release();
        }
    }

    private static function export_entry(\core_privacy\local\request\content_writer $writer, array $path, object $entry): void {
        $file = get_file_storage()->get_file_by_id($entry->fileid);
        if (!$file) {
            throw new \moodle_exception('privacy:missingmaterial', 'local_tomb');
        }
        $path[] = 'materials';
        if ($entry->sourcetype === 'inline') {
            $content = gzinflate($file->get_content());
            if ($content === false) {
                throw new \moodle_exception('privacy:missingmaterial', 'local_tomb');
            }
            $parts = explode('/', $entry->zippath);
            $name = array_pop($parts);
            $writer->export_custom_file(array_merge($path, $parts), $name, $content);
        } else {
            $writer->export_file($path, $file);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::approved($contextlist)) {
            personal_data::erase((int)$contextlist->get_user()->id);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel === CONTEXT_USER) {
            personal_data::erase((int)$context->instanceid);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel === CONTEXT_USER && in_array((int)$context->instanceid,
                array_map('intval', $userlist->get_userids()), true)) {
            personal_data::erase((int)$context->instanceid);
        }
    }
}
