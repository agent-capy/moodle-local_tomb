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
$result = ['subjectid' => (int)$user->id, 'courses' => [], 'expected_pages' => 2, 'zip_created' => false];
foreach (manager::courses((int)$user->id) as $course) {
    if ($selected && !in_array((int)$course->id, $selected, true)) {
        continue;
    }
    $modules = [];
    $info = get_fast_modinfo($course, $user->id);
    foreach ($info->get_cms() as $cm) {
        if ($cm->uservisible && empty($cm->deletioninprogress)) {
            $modules[$cm->modname] = ($modules[$cm->modname] ?? 0) + 1;
        }
    }
    $result['expected_pages'] += 2 + array_sum($modules);
    $result['courses'][] = ['id' => (int)$course->id, 'name' => $course->fullname, 'visible_activities' => $modules];
}
$result['note'] = 'Inventory only. Activity content, current review permissions and final byte sizes are resolved during collection.';
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
