<?php
// License: GNU GPL v3 or later.
namespace local_tomb\task;
defined('MOODLE_INTERNAL') || die();
class assemble extends \core\task\adhoc_task {
    public function execute() {
        \local_tomb\local\delivery::assemble((int)$this->get_custom_data()->requestid);
    }
}

