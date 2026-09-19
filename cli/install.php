<?php
// License: GNU GPL v3 or later.
// Install ONLY local_tomb. No core upgrade, other plugin upgrade or maintenance mode.
define('CLI_SCRIPT', true);
define('CLI_UPGRADE_RUNNING', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/upgradelib.php');
[$options, $unknown] = cli_get_params(['install' => false, 'help' => false], ['h' => 'help']);
if ($unknown || $options['help']) {
    cli_writeln('php local/tomb/cli/install.php [--install]');
    exit($unknown ? 1 : 0);
}
// Request-local developer detection refreshes the generated class map without changing site settings.
$previousdebug = $CFG->config_php_settings['debug'] ?? null;
$CFG->config_php_settings['debug'] = E_ALL;
core_component::reset();
core_component::get_plugin_list('local');
if ($previousdebug === null) {
    unset($CFG->config_php_settings['debug']);
} else {
    $CFG->config_php_settings['debug'] = $previousdebug;
}
cache_helper::invalidate_by_definition('core', 'config', [], 'local_tomb');
core_plugin_manager::reset_caches();
$manager = core_plugin_manager::instance();
$problems = [];
foreach ($manager->get_plugins() as $plugins) {
    foreach ($plugins as $plugininfo) {
        if ($plugininfo->component !== 'local_tomb' &&
                $plugininfo->get_status() !== core_plugin_manager::PLUGIN_STATUS_UPTODATE) {
            $problems[] = $plugininfo->component . ': ' . $plugininfo->get_status();
        }
    }
}
if ($problems || !empty($CFG->maintenance_enabled) || !empty($CFG->upgraderunning)) {
    cli_error('Preflight blocked: ' . implode(', ', $problems));
}
$version = null;
require($CFG->dirroot . '/version.php');
if ((string)$version !== (string)$CFG->version) {
    cli_error('Preflight blocked: core source/database version mismatch');
}
cli_writeln('Preflight OK: all other plugins match their installed versions.');
if (!$options['install']) {
    exit(0);
}
$lock = core\lock\lock_config::get_lock_factory('local_tomb')->get_lock('installation', 0);
if (!$lock) {
    cli_error('Tomb installer is already running');
}
try {
    $callback = function($component, $install, $verbose) {
        if ($component !== 'local_tomb') {
            throw new RuntimeException('Refusing to modify ' . $component);
        }
        mtrace($component . ': install/update');
    };
    upgrade_plugins('local', $callback, function() {}, false);
    // Also repairs a first installation interrupted after the schema/version savepoint.
    upgrade_component_updated('local_tomb');
    core_plugin_manager::reset_caches();
    core_component::reset();
    foreach (core_plugin_manager::instance()->get_plugins() as $plugins) {
        foreach ($plugins as $plugininfo) {
            if ($plugininfo->get_status() !== core_plugin_manager::PLUGIN_STATUS_UPTODATE) {
                throw new RuntimeException('Post-install version mismatch: ' . $plugininfo->component);
            }
        }
    }
    // Standard upgrade completion markers; otherwise normal cron remains pending.
    set_config('allversionshash', core_component::get_all_versions_hash());
    set_config('allcomponenthash', core_component::get_all_component_hash());
    get_string_manager()->reset_caches();
    cli_writeln('Installed local_tomb ' . get_config('local_tomb', 'version') . '; mode=' .
        local_tomb\local\config::mode());
} finally {
    $lock->release();
}
