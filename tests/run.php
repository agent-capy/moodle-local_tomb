<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// License: GNU GPL v3 or later. Pure tests: never boots Moodle or touches its database.
require_once(__DIR__ . '/../classes/local/paths.php');
require_once(__DIR__ . '/../classes/local/zip.php');
require_once(__DIR__ . '/../classes/local/html.php');

use local_tomb\local\paths;
use local_tomb\local\zip;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $callable, string $message): void {
    try {
        $callable();
    } catch (Throwable $e) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

foreach (['../a', '/a', 'a//b', 'a/./b', 'CON.txt', 'a/aux', 'a\\b', 'a/.', '日本語.txt'] as $bad) {
    rejects(fn() => paths::validate($bad), 'Reject unsafe path: ' . $bad);
}
check(paths::relative('courses/c8/m1/index.html', '_files/ab/file.pdf') === '../../../_files/ab/file.pdf',
    'Relative path across directories');
check(paths::relative('index.html', 'courses/c8/index.html') === 'courses/c8/index.html', 'Root path');
$safe = \local_tomb\local\html::rewrite('<p onclick="alert(1)">日本語<script>alert(2)</script>' .
    '<img src="secret" onerror="alert(3)" alt="removed"><a href="allowed">file</a>' .
    '<a href="javascript:alert(4)">bad</a><iframe src="https://example.com"></iframe></p>',
    fn($url) => $url === 'allowed' ? '../files/readme.txt' : null);
check(!preg_match('/<script|onclick|onerror|javascript:|<iframe|src="secret"/', $safe), 'Unsafe original markup removed');
check(str_contains($safe, 'href="../files/readme.txt"') && str_contains($safe, '日本語'),
    'Authorised links and text retained');

$directory = sys_get_temp_dir() . '/tomb-unit-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$buffers = [];
$entries = [];
foreach (['index.html' => '<h1>学習記録</h1>', 'courses/c8/page.html' => str_repeat('Hello 日本語 ', 200),
    '_files/ab/data.bin' => random_bytes(10000), 'empty.txt' => ''] as $name => $content) {
    $method = str_ends_with($name, '.html') ? 8 : 0;
    $stored = $method === 8 ? gzdeflate($content, 6) : $content;
    $buffers[$name] = $stored;
    $entries[] = ['path' => $name, 'method' => $method, 'size' => strlen($content),
        'compressed' => strlen($stored), 'crc' => hexdec(hash('crc32b', $content)), 'contenthash' => sha1($stored)];
}
$open = function(array $entry) use (&$buffers) {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $buffers[$entry['path']]);
    rewind($stream);
    return $stream;
};
try {
    $plan = zip::plan($entries, 1700000000);
    $first = zip::assemble($plan, $directory . '/first.zip', $open);
    $second = zip::assemble($plan, $directory . '/second.zip', $open);
    check($first === $second, 'Regeneration of delivery cache is byte-identical');
    check(filesize($directory . '/first.zip') === $plan['totalbytes'], 'Declared ZIP size');
    $reader = new ZipArchive();
    check($reader->open($directory . '/first.zip') === true, 'Independent ZIP reader');
    check($reader->getFromName('index.html') === '<h1>学習記録</h1>', 'Unicode content round trip');
    check($reader->getFromName('empty.txt') === '', 'Empty stored entry');
    check($reader->getFromName('_files/ab/data.bin') === $buffers['_files/ab/data.bin'], 'Binary round trip');
    $reader->close();
    rejects(fn() => zip::plan([$entries[0], $entries[0]], 1700000000), 'Duplicate names');
    $buffers['index.html'] = str_repeat('x', strlen($buffers['index.html']));
    rejects(fn() => zip::assemble($plan, $directory . '/bad.zip', $open), 'Corrupt source rejected');
    check(!file_exists($directory . '/bad.zip'), 'Incomplete output removed');

    $large = ['path' => 'large.bin', 'method' => 0, 'crc' => 0, 'size' => 0x100000003,
        'compressed' => 0x100000003, 'offset' => 0x100000006];
    $local = zip::local_header($large, 1700000000);
    check(unpack('v', substr($local, 4, 2))[1] === 45, 'Zip64 version');
    check(unpack('V', substr($local, 18, 4))[1] === 0xffffffff, 'Zip64 size sentinel');
    $extra = substr($local, 30 + strlen('large.bin'));
    check(unpack('v', $extra)[1] === 1 && unpack('v', substr($extra, 2))[1] === 16, 'Zip64 local extra');
    check(unpack('V2', substr($extra, 4, 8)) === [1 => 3, 2 => 1], '64-bit little endian size');
    $central = zip::central_header($large, 1700000000);
    check(unpack('V', substr($central, 42, 4))[1] === 0xffffffff, 'Zip64 offset sentinel');

    if (in_array('--zip64', $argv, true)) {
        $many = [];
        for ($i = 0; $i < 65536; $i++) {
            $many[] = ['path' => 'f' . $i, 'method' => 0, 'size' => 0, 'compressed' => 0,
                'crc' => 0, 'contenthash' => sha1('')];
        }
        $manyplan = zip::plan($many, 1700000000);
        zip::assemble($manyplan, $directory . '/zip64.zip', fn() => fopen('php://temp', 'r'));
        $reader = new ZipArchive();
        check($reader->open($directory . '/zip64.zip') === true && $reader->numFiles === 65536,
            'Independent reader accepts 65,536-entry Zip64');
        $reader->close();
    }
    $selected = \local_tomb\local\html::rewrite('<select><option>wrong</option><option selected>saved answer</option></select>', fn() => null);
    check($selected === 'saved answer', 'Stored matching-question selection survives sanitisation');
    check(\local_tomb\local\html::article_digest('<article>記録</article><footer>old date</footer>') ===
        \local_tomb\local\html::article_digest('<article>記録</article><footer>new date</footer>'),
        'Revision comparison excludes volatile provenance footer');
    check(\local_tomb\local\html::article_digest('<article>旧内容</article>') !==
        \local_tomb\local\html::article_digest('<article>新内容</article>'), 'Revision comparison detects changed learning content');
    \local_tomb\local\html::check_links(['courses/c1/index.html' => '<a href="../../index.html">Home</a><img src="../../_files/a.png">'],
        ['index.html', 'courses/c1/index.html', '_files/a.png']);
    check(true, 'Final relative references resolve within archive');
    rejects(fn() => \local_tomb\local\html::check_links(['index.html' => '<a href="missing.html">Missing</a>'], ['index.html']),
        'Broken final reference blocks publication');
    rejects(fn() => \local_tomb\local\html::check_links(['index.html' => '<img src="https://example.invalid/a.png">'], ['index.html']),
        'Remote final reference blocks publication');
    rejects(fn() => \local_tomb\local\html::check_links(['index.html' => '<a href="../secret.txt">Escape</a>'], ['index.html']),
        'Final reference cannot escape archive');

    if (in_array('--large-file', $argv, true)) {
        $size = 0x100000000 + 17;
        check(disk_free_space($directory) > $size * 2 + 1024 ** 3, 'Space available for optional large-file test');
        $source = $directory . '/large.bin';
        $handle = fopen($source, 'wb');
        ftruncate($handle, $size);
        fclose($handle);
        $entry = ['path' => 'large.bin', 'method' => 0, 'size' => $size, 'compressed' => $size,
            'crc' => hexdec(hash_file('crc32b', $source)), 'contenthash' => hash_file('sha1', $source)];
        $largeplan = zip::plan([$entry], 1700000000);
        zip::assemble($largeplan, $directory . '/large.zip', fn() => fopen($source, 'rb'));
        $reader = new ZipArchive();
        check($reader->open($directory . '/large.zip') === true && $reader->statName('large.bin')['size'] === $size,
            'Independent reader accepts a real 4 GiB + 17-byte entry');
        $reader->close();
        echo 'Large-file ZIP bytes: ' . filesize($directory . '/large.zip') . '; peak PHP memory: ' . memory_get_peak_usage(true) . "\n";
    }
    echo 'PASS: ' . $checks . " assertions\n";
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
