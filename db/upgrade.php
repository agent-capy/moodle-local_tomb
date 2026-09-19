<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();

function xmldb_local_tomb_upgrade(int $oldversion): bool {
    if ($oldversion < 2026092000) {
        // Alpha release candidate: no schema change from the development installation.
        upgrade_plugin_savepoint(true, 2026092000, 'local', 'tomb');
    }
    return true;
}
