<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();

function xmldb_local_tomb_upgrade(int $oldversion): bool {
    if ($oldversion < 2026092000) {
        // Alpha release candidate: no schema change from the development installation.
        upgrade_plugin_savepoint(true, 2026092000, 'local', 'tomb');
    }
    if ($oldversion < 2026092001) {
        global $DB;
        $manager = $DB->get_manager();
        $table = new xmldb_table('local_tomb_request');
        $field = new xmldb_field('privacyversion', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        if (!$manager->field_exists($table, $field)) {
            $manager->add_field($table, $field);
        }
        $definition = new xmldb_file(__DIR__ . '/install.xml');
        $definition->loadXMLStructure();
        $person = $definition->getStructure()->getTable('local_tomb_person');
        if (!$manager->table_exists($person)) {
            $manager->create_table($person);
        }
        upgrade_plugin_savepoint(true, 2026092001, 'local', 'tomb');
    }
    return true;
}
