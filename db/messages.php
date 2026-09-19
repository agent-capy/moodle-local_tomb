<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();
$messageproviders = [
    'ready' => ['defaults' => ['popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        'email' => MESSAGE_PERMITTED]],
    'failed' => ['defaults' => ['popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        'email' => MESSAGE_PERMITTED]],
    'reminder' => ['defaults' => ['popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        'email' => MESSAGE_PERMITTED]],
];

