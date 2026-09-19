<?php
// License: GNU GPL v3 or later.
namespace local_tomb\task;
defined('MOODLE_INTERNAL') || die();
class cleanup extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('cleanup', 'local_tomb');
    }
    public function execute() {
        \local_tomb\local\delivery::cleanup();
    }
}

