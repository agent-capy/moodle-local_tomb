<?php
// License: GNU GPL v3 or later.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
use local_tomb\local\manager;
[$options, $unknown] = cli_get_params(['userid' => 0, 'courses' => '', 'help' => false], ['h' => 'help']);
if ($unknown || !$options['userid'] || $options['help']) {
    cli_writeln('Read-only inventory: php local/tomb/cli/estimate.php --userid=ID [--courses=1,2]');
    exit($options['help'] ? 0 : 1);
}
$user = core_user::get_user((int)$options['userid'], '*', MUST_EXIST);
core_user::require_active_user($user);
core\session\manager::set_user($user);
require_capability('local/tomb:exportown', context_system::instance());
$selected = $options['courses'] === '' ? [] : array_map('intval', explode(',', $options['courses']));
$result = \local_tomb\local\estimate::inventory((int)$user->id, $selected);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
