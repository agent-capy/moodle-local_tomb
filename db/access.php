<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/tomb:exportown' => ['captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL, 'archetypes' => ['user' => CAP_ALLOW]],
    'local/tomb:exportteacher' => ['captype' => 'read', 'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL, 'archetypes' => ['editingteacher' => CAP_ALLOW, 'teacher' => CAP_ALLOW]],
    'local/tomb:delegate' => ['captype' => 'write', 'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL, 'archetypes' => ['editingteacher' => CAP_ALLOW]],
    'local/tomb:exportothersdata' => ['captype' => 'read', 'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL, 'archetypes' => []],
    'local/tomb:manage' => ['captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL, 'archetypes' => ['manager' => CAP_ALLOW]],
    'local/tomb:viewaudit' => ['captype' => 'read', 'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL, 'archetypes' => []],
];

