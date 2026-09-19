<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;

/** Portable archive paths, independent of the Moodle runtime. */
final class paths {
    public static function validate(string $path): string {
        if ($path === '' || strlen($path) > 220 || !preg_match('~^[a-zA-Z0-9_./-]+$~D', $path)) {
            throw new \InvalidArgumentException('Invalid archive path');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || substr($segment, -1) === '.' ||
                    preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
                throw new \InvalidArgumentException('Unsafe archive path');
            }
        }
        return $path;
    }

    public static function relative(string $from, string $to): string {
        self::validate($from);
        self::validate($to);
        $base = explode('/', dirname($from));
        if ($base === ['.']) {
            $base = [];
        }
        $target = explode('/', $to);
        while ($base && $target && $base[0] === $target[0]) {
            array_shift($base);
            array_shift($target);
        }
        return str_repeat('../', count($base)) . implode('/', $target);
    }

    public static function file(string $hash, string $filename): string {
        if (!preg_match('/^[a-f0-9]{40}$/D', $hash)) {
            throw new \InvalidArgumentException('Invalid content hash');
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,8}$/D', $ext) ? '.' . $ext : '.bin';
        return '_files/' . substr($hash, 0, 2) . '/' . $hash . $ext;
    }

    public static function escape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

