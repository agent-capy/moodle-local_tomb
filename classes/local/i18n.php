<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class i18n {
    public static function language(?string $language = null): string {
        return str_starts_with($language ?? current_language(), 'ja') ? 'ja' : 'en';
    }

    public static function get(string $key, $a = null, ?string $language = null): string {
        return get_string_manager()->get_string($key, 'local_tomb', $a, self::language($language));
    }
}
