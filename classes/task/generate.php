<?php
// License: GNU GPL v3 or later.
namespace local_tomb\task;
defined('MOODLE_INTERNAL') || die();
class generate extends \core\task\adhoc_task {
    public function execute() {
        \local_tomb\local\manager::generate((int)$this->get_custom_data()->requestid);
    }
}

