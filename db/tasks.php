<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();
$tasks = [[
    'classname' => '\\local_tomb\\task\\cleanup', 'blocking' => 0,
    'minute' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
]];

