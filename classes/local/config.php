<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class config {
    public static function get(string $name, $default = null) {
        $value = get_config('local_tomb', $name);
        return $value === false ? $default : $value;
    }

    public static function mode(): string {
        return self::current('operationmode', 'disabled');
    }

    /** Authorization settings must not come from another process's stale request-local cache. */
    public static function current(string $name, $default = null) {
        global $CFG, $DB;
        if (array_key_exists($name, $CFG->forced_plugin_settings['local_tomb'] ?? [])) {
            return $CFG->forced_plugin_settings['local_tomb'][$name];
        }
        $value = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_tomb', 'name' => $name]);
        return $value === false ? $default : $value;
    }

    public static function set_mode(string $mode): void {
        global $DB;
        require_capability('local/tomb:manage', \context_system::instance());
        if (!in_array($mode, ['disabled', 'rehearsal', 'active', 'delivery_only'], true)) {
            throw new \invalid_parameter_exception('Invalid operation mode');
        }
        $locks = [];
        try {
            foreach (['personaldata', 'worker'] as $name) {
                $lock = self::lock($name, 5);
                if (!$lock) {
                    throw new \moodle_exception('workerbusy', 'local_tomb');
                }
                $locks[] = $lock;
            }
            if ($mode === 'rehearsal' && !$DB->record_exists('cohort', ['id' => (int)self::current('rehearsalcohortid', 0)])) {
                throw new \moodle_exception('unavailable', 'local_tomb');
            }
            if (in_array($mode, ['active', 'delivery_only'], true)) {
                if (!(bool)self::current('setupconfirmed', 0) || (int)self::current('deliverydeadline', 0) <= time()) {
                    throw new \moodle_exception('unavailable', 'local_tomb');
                }
                if ($DB->record_exists_select('local_tomb_request', 'timepurged = 0 AND (isrehearsal = 1 OR privacyversion = 0)')) {
                    throw new \moodle_exception('purge_rehearsal_first', 'local_tomb');
                }
            }
            audit::add('mode_changed', 0, ['from' => self::mode(), 'to' => $mode]);
            set_config('operationmode', $mode, 'local_tomb');
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    public static function allowed(int $userid, bool $generation = false): bool {
        $mode = self::mode();
        if (!in_array($mode, ['rehearsal', 'active', 'delivery_only'], true) ||
                ($generation && $mode === 'delivery_only')) {
            return false;
        }
        if ($mode === 'rehearsal') {
            global $DB;
            $cohort = (int)self::current('rehearsalcohortid', 0);
            return $cohort && $DB->record_exists('cohort_members', ['cohortid' => $cohort, 'userid' => $userid]);
        }
        $deadline = (int)self::current('deliverydeadline', 0);
        return $deadline > time() && (bool)self::current('setupconfirmed', 0);
    }

    public static function cachepath(): string {
        global $CFG;
        $path = self::get('cachepath', '');
        if (!$path) {
            $path = $CFG->dataroot . '/local_tomb_cache';
        }
        if (!is_dir($path) && !mkdir($path, $CFG->directorypermissions, true) && !is_dir($path)) {
            throw new \moodle_exception('cannotcreatedir');
        }
        $real = realpath($path);
        $webroot = realpath($CFG->dirroot);
        if (!$real || $real === $webroot || str_starts_with($real . '/', $webroot . '/')) {
            throw new \moodle_exception('invalidcachepath', 'local_tomb');
        }
        return $real;
    }

    public static function lock(string $name, int $wait = 0) {
        return \core\lock\lock_config::get_lock_factory('local_tomb')->get_lock($name, $wait);
    }

    public static function capacity(int $bytes = 0): bool {
        $path = self::cachepath();
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        return $free !== false && $total !== false &&
            $free - $bytes >= max((int)self::get('minfreebytes', 5 * 1024 ** 3), (int)($total * 0.1));
    }
}

