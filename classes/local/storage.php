<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

/** File API references work with local filedir and alternate stores such as ObjectFS. */
final class storage {
    private object $request;
    private int $contextid;
    private array $files = [];

    public function __construct(object $request) {
        $this->request = $request;
        $this->contextid = \context_user::instance($request->subjectid)->id;
    }

    private static function guard(int $bytes): void {
        global $CFG;
        foreach (array_unique([$CFG->dataroot, $CFG->tempdir, config::cachepath()]) as $path) {
            $free = disk_free_space($path);
            $total = disk_total_space($path);
            if ($free === false || $total === false || $free - $bytes <
                    max((int)config::get('minfreebytes', 5 * 1024 ** 3), (int)($total * 0.1))) {
                throw new \RuntimeException('Storage capacity guard interrupted generation', 1002);
            }
        }
    }

    public function add_file(\stored_file $source, int $courseid = 0, int $cmid = 0): string {
        global $DB;
        if ($source->is_directory()) {
            throw new \InvalidArgumentException('Directory is not an archive file');
        }
        $hash = $source->get_contenthash();
        if (isset($this->files[$hash])) {
            return $this->files[$hash];
        }
        // Alternate stores may hydrate a file while it is read. Reserve room for that work as well.
        self::guard((int)$source->get_filesize() * 2);
        $path = paths::file($hash, $source->get_filename());
        $fs = get_file_storage();
        $fixed = $fs->create_file_from_storedfile(['contextid' => $this->contextid, 'component' => 'local_tomb',
            'filearea' => 'source', 'itemid' => $this->request->id, 'filepath' => '/' . $hash . '/',
            'filename' => basename($path), 'userid' => $this->request->subjectid], $source);
        $blob = $DB->get_record('local_tomb_blob', ['contenthash' => $hash]);
        if (!$blob) {
            $handle = $fixed->get_content_file_handle();
            if (!is_resource($handle)) {
                throw new \RuntimeException('Cannot read stored file');
            }
            $crc = hash_init('crc32b');
            try {
                $size = hash_update_stream($crc, $handle);
            } finally {
                fclose($handle);
            }
            if ($size !== (int)$fixed->get_filesize()) {
                throw new \RuntimeException('Stored file read is incomplete');
            }
            $blob = (object)['contenthash' => $hash, 'crc' => hexdec(hash_final($crc)), 'size' => $size];
            // Generation is serialised globally; the contenthash has one CRC cache record.
            $DB->insert_record('local_tomb_blob', $blob);
        }
        if ((int)$blob->size !== (int)$fixed->get_filesize()) {
            throw new \RuntimeException('Stored file size disagrees with registry');
        }
        $this->entry($path, $fixed, 'shared', 0, (int)$blob->crc, (int)$blob->size,
            (int)$blob->size, $courseid, $cmid);
        return $this->files[$hash] = $path;
    }

    public function add_text(string $path, string $content, int $courseid = 0, int $cmid = 0): void {
        paths::validate($path);
        self::guard(strlen($content) * 2);
        $compressed = gzdeflate($content, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Cannot compress archive entry');
        }
        $file = get_file_storage()->create_file_from_string(['contextid' => $this->contextid,
            'component' => 'local_tomb', 'filearea' => 'inline', 'itemid' => $this->request->id,
            'filepath' => '/', 'filename' => sha1($path) . '.deflate', 'userid' => $this->request->subjectid], $compressed);
        $this->entry($path, $file, 'inline', 8, hexdec(hash('crc32b', $content)), strlen($content),
            strlen($compressed), $courseid, $cmid);
    }

    private function entry(string $path, \stored_file $file, string $source, int $method, int $crc,
            int $size, int $compressed, int $courseid, int $cmid): void {
        global $DB;
        $DB->insert_record('local_tomb_entry', (object)['requestid' => $this->request->id, 'zippath' => $path,
            'fileid' => $file->get_id(), 'contenthash' => $file->get_contenthash(), 'sourcetype' => $source,
            'method' => $method, 'crc' => $crc, 'size' => $size, 'compressed' => $compressed,
            'zipoffset' => 0, 'courseid' => $courseid, 'cmid' => $cmid]);
    }

    public static function plan(object $request, ?int $time = null): array {
        global $DB;
        $rows = $DB->get_records('local_tomb_entry', ['requestid' => $request->id], 'id ASC');
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = ['id' => (int)$row->id, 'path' => $row->zippath, 'fileid' => (int)$row->fileid,
                'contenthash' => $row->contenthash, 'method' => (int)$row->method, 'crc' => (int)$row->crc,
                'size' => (int)$row->size, 'compressed' => (int)$row->compressed];
        }
        return zip::plan($entries, $time ?? (int)$request->timefinished);
    }

    public static function clear(object $request, bool $metadata = false): void {
        global $DB;
        $context = \context_user::instance($request->subjectid);
        $fs = get_file_storage();
        foreach (['source', 'inline'] as $area) {
            $fs->delete_area_files($context->id, 'local_tomb', $area, $request->id);
        }
        if ($metadata) {
            $DB->delete_records('local_tomb_entry', ['requestid' => $request->id]);
            $DB->delete_records('local_tomb_omission', ['requestid' => $request->id]);
        }
    }
}
