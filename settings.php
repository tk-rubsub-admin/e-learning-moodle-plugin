<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_lmslaunch', get_string('pluginname', 'local_lmslaunch'));

    $settings->add(new admin_setting_configtext(
        'local_lmslaunch/lms_api_base_url',
        get_string('lmsapibaseurl', 'local_lmslaunch'),
        get_string('lmsapibaseurl_desc', 'local_lmslaunch'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_lmslaunch/shared_secret',
        get_string('sharedsecret', 'local_lmslaunch'),
        get_string('sharedsecret_desc', 'local_lmslaunch'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmslaunch/connect_timeout',
        get_string('connecttimeout', 'local_lmslaunch'),
        get_string('connecttimeout_desc', 'local_lmslaunch'),
        '10',
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
