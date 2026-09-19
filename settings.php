<?php
// License: GNU GPL v3 or later.
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_tomb_settings', get_string('settings', 'local_tomb'));
    $ADMIN->add('localplugins', $settings);
    $ADMIN->add('localplugins', new admin_externalpage('local_tomb_manage', get_string('manage', 'local_tomb'),
        new moodle_url('/local/tomb/manage.php'), 'local/tomb:manage'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('local_tomb/modeinfo', get_string('operationmode', 'local_tomb'),
            get_string('modehelp', 'local_tomb')));
        foreach (['rehearsalcohortid' => ['cohort', 0], 'deliverydeadline' => ['deadline', 0],
            'cachebytes' => ['cachebytes', 2 * 1024 ** 3], 'minfreebytes' => ['minfreebytes', 5 * 1024 ** 3],
            'cachettl' => ['cachettl', 21600], 'requestinterval' => ['requestinterval', 300],
            'downloadslots' => ['downloadslots', 1]] as $name => [$label, $default]) {
            $settings->add(new admin_setting_configtext('local_tomb/' . $name, get_string($label, 'local_tomb'),
                '', $default, PARAM_INT));
        }
        $settings->add(new admin_setting_configtext('local_tomb/cachepath', get_string('cachepath', 'local_tomb'),
            get_string('cachepath_help', 'local_tomb'), '', PARAM_RAW_TRIMMED));
        $settings->add(new admin_setting_configcheckbox('local_tomb/setupconfirmed',
            get_string('setupconfirmed', 'local_tomb'), get_string('setupconfirmed_help', 'local_tomb'), 0));
    }
}

