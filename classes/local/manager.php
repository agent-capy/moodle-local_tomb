<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class manager {
    public static function courses(int $userid): array {
        $result = [];
        foreach (enrol_get_users_courses($userid, true, 'id,fullname,shortname,visible,groupmode,groupmodeforce') as $course) {
            $context = \context_course::instance($course->id);
            if ($course->id != SITEID && ($course->visible || has_capability('moodle/course:viewhiddencourses',
                    $context, $userid))) {
                $result[$course->id] = $course;
            }
        }
        return $result;
    }

    public static function request(array $courseids, int $subjectid, string $kind = 'learner',
            string $policy = 'pseudonymised', string $reason = '', int $revisionof = 0, bool $download = false): int {
        global $DB, $USER;
        if (!config::allowed($subjectid, true)) {
            throw new \moodle_exception('unavailable', 'local_tomb');
        }
        $subject = \core_user::get_user($subjectid, '*', MUST_EXIST);
        \core_user::require_active_user($subject);
        $system = \context_system::instance();
        require_capability('local/tomb:exportown', $system, $subjectid);
        $admin = has_capability('local/tomb:manage', $system);
        if (!in_array($kind, ['learner', 'teacher'], true) ||
                !in_array($policy, ['pseudonymised', 'full'], true)) {
            throw new \invalid_parameter_exception('Invalid export policy');
        }
        if ($kind === 'learner') {
            $policy = 'pseudonymised';
        }
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        sort($courseids);
        if (!$courseids) {
            throw new \moodle_exception('selectcourses', 'local_tomb');
        }
        $available = self::courses($subjectid);
        foreach ($courseids as $id) {
            if (!isset($available[$id])) {
                throw new \moodle_exception('invalidcourseid');
            }
            $context = \context_course::instance($id);
            if ($subjectid != $USER->id && !$admin) {
                require_capability('local/tomb:delegate', $context);
                if ($available[$id]->groupmode == SEPARATEGROUPS &&
                        !has_capability('moodle/site:accessallgroups', $context) &&
                        !array_intersect(array_keys(groups_get_all_groups($id, $USER->id)),
                            array_keys(groups_get_all_groups($id, $subjectid)))) {
                    throw new \moodle_exception('unavailable', 'local_tomb');
                }
                if ($kind !== 'learner') {
                    throw new \moodle_exception('nopermissions', 'error', '', 'Teacher export for another user');
                }
            }
            if ($kind === 'teacher') {
                require_capability('local/tomb:exportteacher', $context, $subjectid);
                if ($policy === 'full') {
                    require_capability('local/tomb:exportothersdata', $context, $subjectid);
                    if (trim($reason) === '') {
                        throw new \moodle_exception('reasonrequired', 'local_tomb');
                    }
                }
            }
        }
        if ($revisionof) {
            $old = $DB->get_record('local_tomb_request', ['id' => $revisionof, 'subjectid' => $subjectid], '*', MUST_EXIST);
        }
        if (!config::capacity()) {
            throw new \moodle_exception('capacity', 'local_tomb');
        }
        $lock = config::lock('subject-' . $subjectid, 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $recent = $DB->get_records('local_tomb_request', ['subjectid' => $subjectid], 'id DESC', '*', 0, 1);
            $recent = $recent ? reset($recent) : null;
            $encoded = json_encode($courseids, JSON_THROW_ON_ERROR);
            if ($recent && in_array($recent->status, ['queued', 'running'], true) && $recent->courses === $encoded &&
                    $recent->kind === $kind && $recent->policy === $policy) {
                if ($download && $subjectid == $USER->id) {
                    $DB->set_field('local_tomb_request', 'wantdownload', 1, ['id' => $recent->id]);
                }
                return (int)$recent->id;
            }
            $interval = (int)config::get('requestinterval', 300);
            if ($recent && $recent->timecreated + $interval > time() && !$admin) {
                throw new \moodle_exception('ratelimit', 'local_tomb');
            }
            $record = (object)['subjectid' => $subjectid, 'requesterid' => (int)$USER->id, 'kind' => $kind,
                'policy' => $policy, 'reason' => trim($reason), 'courses' => $encoded,
                'status' => 'queued', 'stage' => 'queued', 'message' => '', 'timecreated' => time(),
                'revisionof' => $revisionof, 'isrehearsal' => config::mode() === 'rehearsal' ? 1 : 0,
                'plan' => '', 'expected' => '', 'actual' => '', 'lasterror' => '',
                'wantdownload' => $download && $subjectid == $USER->id ? 1 : 0];
            $record->id = $DB->insert_record('local_tomb_request', $record);
            audit::add('requested', $record->id, ['subject' => $subjectid, 'courses' => $courseids,
                'kind' => $kind, 'policy' => $policy, 'reason' => $reason]);
            self::queue((int)$record->id, 'generate');
            return (int)$record->id;
        } finally {
            $lock->release();
        }
    }

    public static function queue(int $requestid, string $type): void {
        $task = $type === 'generate' ? new \local_tomb\task\generate() : new \local_tomb\task\assemble();
        $task->set_component('local_tomb');
        $task->set_custom_data(['requestid' => $requestid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    public static function accessible(object $request, bool $download = false): bool {
        global $USER, $DB;
        $user = $DB->get_record('user', ['id' => $request->subjectid], 'id,deleted,suspended');
        if (!$user || $user->deleted || $user->suspended || !config::allowed($request->subjectid)) {
            return false;
        }
        if (($request->isrehearsal && config::mode() !== 'rehearsal') ||
                (!$request->isrehearsal && config::mode() === 'rehearsal')) {
            return false;
        }
        if (has_capability('local/tomb:manage', \context_system::instance())) {
            return true;
        }
        return $request->subjectid == $USER->id &&
            has_capability('local/tomb:exportown', \context_system::instance());
    }

    public static function progress(int $id, string $stage, string $message = ''): void {
        global $DB;
        $DB->update_record('local_tomb_request', (object)['id' => $id, 'stage' => $stage,
            'message' => $message, 'heartbeat' => time()]);
    }

    public static function omission(int $id, int $courseid, int $cmid, string $reason, string $detail): void {
        global $DB;
        $DB->insert_record('local_tomb_omission', (object)['requestid' => $id, 'courseid' => $courseid,
            'cmid' => $cmid, 'reason' => $reason, 'detail' => $detail, 'itemcount' => 1]);
    }

    public static function generate(int $id): void {
        global $DB, $USER;
        $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
        if ($request->status !== 'queued' || !config::allowed($request->subjectid, true)) {
            return;
        }
        $worker = config::lock('worker', 0);
        if (!$worker) {
            throw new \moodle_exception('workerbusy', 'local_tomb');
        }
        $olduser = $USER;
        try {
            $request = $DB->get_record('local_tomb_request', ['id' => $id], '*', MUST_EXIST);
            if ($request->status !== 'queued') {
                return;
            }
            \core\session\manager::set_user(\core_user::get_user($request->subjectid, '*', MUST_EXIST));
            \core_user::require_active_user($USER);
            require_capability('local/tomb:exportown', \context_system::instance());
            storage::clear($request, true);
            $request->status = 'running';
            $request->timestarted = time();
            $request->heartbeat = time();
            $DB->update_record('local_tomb_request', $request);
            audit::add('collecting', $id, [], 0);
            $collector = new collector($request);
            $collector->generate();
            $plan = storage::plan($request);
            foreach ($plan['entries'] as $entry) {
                $DB->set_field('local_tomb_entry', 'zipoffset', $entry['offset'], ['id' => $entry['id']]);
            }
            $request->totalbytes = $plan['totalbytes'];
            unset($plan['entries']);
            $request->plan = json_encode($plan, JSON_THROW_ON_ERROR);
            $request->status = 'ready';
            $request->stage = 'ready';
            $request->message = '';
            $request->heartbeat = time();
            $DB->update_record('local_tomb_request', $request);
            audit::add('generated', $id, ['bytes' => $request->totalbytes], 0);
            if ($DB->get_field('local_tomb_request', 'wantdownload', ['id' => $request->id])) {
                // The owner's creation form explicitly requests both collection and ZIP preparation.
                delivery::request($request);
            } else {
                self::notify($request, 'collected');
            }
        } catch (\Throwable $e) {
            $DB->update_record('local_tomb_request', (object)['id' => $id, 'status' => 'failed', 'stage' => 'failed',
                'heartbeat' => time(), 'lasterror' => get_class($e) . ': ' . substr($e->getMessage(), 0, 1000)]);
            audit::add('generation_failed', $id, ['exception' => get_class($e)], 0);
            self::notify($request, 'failed');
            mtrace('Tomb request ' . $id . ' failed: ' . get_class($e));
        } finally {
            \core\session\manager::set_user($olduser);
            $worker->release();
        }
    }

    public static function notify(object $request, string $type): bool {
        global $DB;
        try {
            $user = \core_user::get_user($request->subjectid);
            if (!$user || $user->deleted || $user->suspended) {
                audit::add('notification_unreachable', $request->id, [], 0);
                return false;
            }
            $notice = new \core\message\message();
            $notice->component = 'local_tomb';
            $notice->name = $type === 'collected' ? 'ready' : $type;
            $notice->userfrom = \core_user::get_noreply_user();
            $notice->userto = $user;
            $notice->subject = get_string('notification_' . $type, 'local_tomb');
            $notice->fullmessage = $notice->subject . "\n" . str_replace('\\n', "\n", get_string('notification_body', 'local_tomb', (object)[
                'id' => $request->id, 'size' => display_size($request->totalbytes),
                'omissions' => $DB->count_records('local_tomb_omission', ['requestid' => $request->id]),
                'deadline' => config::mode() === 'rehearsal' ? get_string('rehearsal', 'local_tomb') :
                    userdate((int)config::get('deliverydeadline', 0))]));
            $notice->fullmessageformat = FORMAT_PLAIN;
            $notice->fullmessagehtml = '';
            $notice->smallmessage = $notice->subject;
            $notice->notification = 1;
            $notice->contexturl = (new \moodle_url('/local/tomb/index.php', ['id' => $request->id]))->out(false);
            $notice->contexturlname = get_string('viewarchive', 'local_tomb');
            $success = message_send($notice);
            if ($type === 'failed') {
                // Operational failure notices contain no student download URL or learning content.
                foreach (get_admins() as $admin) {
                    if ($admin->id == $request->subjectid) {
                        continue;
                    }
                    $adminnotice = clone $notice;
                    $adminnotice->userto = $admin;
                    $adminnotice->fullmessage = 'Tomb archive #' . $request->id . ' needs administrator attention.';
                    $adminnotice->contexturl = (new \moodle_url('/local/tomb/manage.php'))->out(false);
                    $adminnotice->contexturlname = get_string('manage', 'local_tomb');
                    message_send($adminnotice);
                }
            }
            if ($success) {
                if ($type === 'ready') {
                    $DB->set_field('local_tomb_request', 'notified', time(), ['id' => $request->id]);
                }
                audit::add('notified', $request->id, ['type' => $type], 0);
            }
            return (bool)$success;
        } catch (\Throwable $e) {
            audit::add('notification_failed', $request->id, ['exception' => get_class($e)], 0);
            return false;
        }
    }
}
