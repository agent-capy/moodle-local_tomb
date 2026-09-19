<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;

/** Deterministic ZIP/Zip64 layout. No compression or live course reads during assembly. */
final class zip {
    private const U32 = 0xffffffff;

    private static function u64(int $value): string {
        if ($value < 0 || PHP_INT_SIZE < 8) {
            throw new \RuntimeException('ZIP assembly requires nonnegative 64-bit integers');
        }
        return pack('V2', $value & self::U32, ($value >> 32) & self::U32);
    }

    private static function stamp(int $time): array {
        $year = max(1980, min(2107, (int)gmdate('Y', $time)));
        return [((int)gmdate('H', $time) << 11) | ((int)gmdate('i', $time) << 5) |
            ((int)gmdate('s', $time) >> 1), (($year - 1980) << 9) | ((int)gmdate('n', $time) << 5) |
            (int)gmdate('j', $time)];
    }

    public static function local_header(array $entry, int $time): string {
        $name = paths::validate($entry['path']);
        [$dos, $date] = self::stamp($time);
        $large = $entry['size'] >= self::U32 || $entry['compressed'] >= self::U32;
        $extra = $large ? pack('vv', 1, 16) . self::u64($entry['size']) . self::u64($entry['compressed']) : '';
        return pack('VvvvvvVVVvv', 0x04034b50, $large ? 45 : 20, 0, $entry['method'], $dos, $date,
            $entry['crc'], $large ? self::U32 : $entry['compressed'], $large ? self::U32 : $entry['size'],
            strlen($name), strlen($extra)) . $name . $extra;
    }

    public static function central_header(array $entry, int $time): string {
        $name = paths::validate($entry['path']);
        [$dos, $date] = self::stamp($time);
        $large = $entry['size'] >= self::U32 || $entry['compressed'] >= self::U32;
        $offsetlarge = $entry['offset'] >= self::U32;
        $body = $large ? self::u64($entry['size']) . self::u64($entry['compressed']) : '';
        if ($offsetlarge) {
            $body .= self::u64($entry['offset']);
        }
        $extra = $body === '' ? '' : pack('vv', 1, strlen($body)) . $body;
        return pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 45, ($large || $offsetlarge) ? 45 : 20,
            0, $entry['method'], $dos, $date, $entry['crc'], $large ? self::U32 : $entry['compressed'],
            $large ? self::U32 : $entry['size'], strlen($name), strlen($extra), 0, 0, 0, 0,
            $offsetlarge ? self::U32 : $entry['offset']) . $name . $extra;
    }

    public static function trailer(int $count, int $size, int $offset): string {
        $large = $count >= 0xffff || $size >= self::U32 || $offset >= self::U32;
        $result = '';
        if ($large) {
            $result = pack('V', 0x06064b50) . self::u64(44) . pack('vvVV', 45, 45, 0, 0) .
                self::u64($count) . self::u64($count) . self::u64($size) . self::u64($offset) .
                pack('VV', 0x07064b50, 0) . self::u64($offset + $size) . pack('V', 1);
        }
        return $result . pack('VvvvvVVv', 0x06054b50, 0, 0, $large ? 0xffff : $count,
            $large ? 0xffff : $count, $large ? self::U32 : $size, $large ? self::U32 : $offset, 0);
    }

    /** Entries must already have fixed paths, sizes, compression and CRCs. */
    public static function plan(array $entries, int $time): array {
        $offset = 0;
        $central = 0;
        $seen = [];
        foreach ($entries as &$entry) {
            paths::validate($entry['path']);
            if (isset($seen[$entry['path']]) || !in_array($entry['method'], [0, 8], true) ||
                    $entry['size'] < 0 || $entry['compressed'] < 0 ||
                    ($entry['method'] === 0 && $entry['size'] !== $entry['compressed'])) {
                throw new \InvalidArgumentException('Invalid or duplicate ZIP entry');
            }
            $seen[$entry['path']] = true;
            $entry['offset'] = $offset;
            $offset += strlen(self::local_header($entry, $time)) + $entry['compressed'];
            $central += strlen(self::central_header($entry, $time));
        }
        unset($entry);
        $total = $offset + $central + strlen(self::trailer(count($entries), $central, $offset));
        return ['entries' => $entries, 'time' => $time, 'centraloffset' => $offset,
            'centralsize' => $central, 'totalbytes' => $total];
    }

    private static function write($output, string $bytes): void {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot write ZIP data');
            }
            $offset += $written;
        }
    }

    /** $open returns a readable stream of the already-stored (possibly compressed) bytes. */
    public static function assemble(array $plan, string $target, callable $open, ?callable $heartbeat = null): string {
        $output = fopen($target, 'xb');
        if (!$output) {
            throw new \RuntimeException('Cannot create ZIP staging file');
        }
        try {
            foreach ($plan['entries'] as $entry) {
                if ($heartbeat) {
                    $heartbeat();
                }
                if (ftell($output) !== $entry['offset']) {
                    throw new \RuntimeException('ZIP offset mismatch');
                }
                self::write($output, self::local_header($entry, $plan['time']));
                $input = $open($entry);
                if (!is_resource($input)) {
                    throw new \RuntimeException('Archive material is unavailable', 1001);
                }
                $hash = hash_init('sha1');
                $count = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false || ($chunk === '' && !feof($input))) {
                            throw new \RuntimeException('Cannot read archive material', 1001);
                        }
                        $count += strlen($chunk);
                        if ($count > $entry['compressed']) {
                            throw new \RuntimeException('Archive material size changed', 1001);
                        }
                        hash_update($hash, $chunk);
                        self::write($output, $chunk);
                        if ($heartbeat) {
                            $heartbeat();
                        }
                    }
                } finally {
                    fclose($input);
                }
                $actualhash = hash_final($hash);
                if ($count !== $entry['compressed'] || !hash_equals($entry['contenthash'], $actualhash)) {
                    throw new \RuntimeException('Archive material integrity check failed', 1001);
                }
            }
            foreach ($plan['entries'] as $entry) {
                self::write($output, self::central_header($entry, $plan['time']));
            }
            self::write($output, self::trailer(count($plan['entries']), $plan['centralsize'], $plan['centraloffset']));
            if (ftell($output) !== $plan['totalbytes']) {
                throw new \RuntimeException('ZIP total size mismatch');
            }
        } catch (\Throwable $e) {
            fclose($output);
            unlink($target);
            throw $e;
        }
        fclose($output);
        try {
            self::verify($plan, $target, $heartbeat);
        } catch (\Throwable $e) {
            unlink($target);
            throw $e;
        }
        return hash_file('sha256', $target);
    }

    public static function verify(array $plan, string $target, ?callable $heartbeat = null): void {
        $archive = new \ZipArchive();
        if ($archive->open($target, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('ZIP validation failed');
        }
        try {
            if ($archive->numFiles !== count($plan['entries'])) {
                throw new \RuntimeException('ZIP entry count mismatch');
            }
            foreach ($plan['entries'] as $i => $entry) {
                if ($heartbeat) {
                    $heartbeat();
                }
                $stat = $archive->statIndex($i);
                if (!$stat || $stat['name'] !== $entry['path'] || $stat['size'] !== $entry['size']) {
                    throw new \RuntimeException('ZIP entry metadata mismatch');
                }
                $stream = $archive->getStream($entry['path']);
                if (!$stream) {
                    throw new \RuntimeException('ZIP entry cannot be read');
                }
                $crc = hash_init('crc32b');
                $read = 0;
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if ($chunk === false || ($chunk === '' && !feof($stream))) {
                            throw new \RuntimeException('ZIP entry read failed');
                        }
                        $read += strlen($chunk);
                        hash_update($crc, $chunk);
                        if ($heartbeat) {
                            $heartbeat();
                        }
                    }
                } finally {
                    fclose($stream);
                }
                if ($read !== $entry['size'] || hexdec(hash_final($crc)) !== $entry['crc']) {
                    throw new \RuntimeException('ZIP entry CRC mismatch');
                }
            }
        } finally {
            $archive->close();
        }
    }
}
