<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();

function local_tomb_extend_navigation(global_navigation $navigation): void {
    global $USER;
    if (isloggedin() && !isguestuser() && \local_tomb\local\config::allowed((int)$USER->id)) {
        $navigation->add(get_string('pluginname', 'local_tomb'), new moodle_url('/local/tomb/index.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_tomb', new pix_icon('i/files', ''));
    }
}

function local_tomb_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    // Pinned and compressed materials are internal. Only the authenticated ZIP route serves content.
    return false;
}

