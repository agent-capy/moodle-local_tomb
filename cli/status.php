<?php
// License: GNU GPL v3 or later.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
use local_tomb\local\{audit, config};
$result = ['version' => get_config('local_tomb', 'version'), 'mode' => config::mode(),
    'capacity_available' => config::capacity(), 'audit_valid' => audit::verify(),
    'last_scheduled_task' => (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}'),
    'requests' => array_values($DB->get_records('local_tomb_request', null, 'id DESC',
        'id,subjectid,status,stage,totalbytes,timecreated,timefinished,lasterror', 0, 20)),
    'caches' => array_values($DB->get_records('local_tomb_cache', null, 'id DESC',
        'id,requestid,status,bytes,expires,lasterror', 0, 20))];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
