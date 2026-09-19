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
        return self::get('operationmode', 'disabled');
    }

    public static function allowed(int $userid, bool $generation = false): bool {
        $mode = self::mode();
        if (!in_array($mode, ['rehearsal', 'active', 'delivery_only'], true) ||
                ($generation && $mode === 'delivery_only')) {
            return false;
        }
        if ($mode === 'rehearsal') {
            global $DB;
            $cohort = (int)self::get('rehearsalcohortid', 0);
            return $cohort && $DB->record_exists('cohort_members', ['cohortid' => $cohort, 'userid' => $userid]);
        }
        $deadline = (int)self::get('deliverydeadline', 0);
        return $deadline > time() && (bool)self::get('setupconfirmed', 0);
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

