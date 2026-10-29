<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lmslaunch/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

$tokenparam = optional_param('token', '', PARAM_RAW_TRIMMED);
$secret = (string)get_config('local_lmslaunch', 'shared_secret');
$apibaseurl = (string)get_config('local_lmslaunch', 'lms_api_base_url');
$timeout = (int)get_config('local_lmslaunch', 'connect_timeout');
$timeout = $timeout > 0 ? $timeout : 10;

if ($tokenparam === '' || $secret === '' || $apibaseurl === '') {
    throw new moodle_exception('missingconfig', 'local_lmslaunch');
}

$token = local_lmslaunch_base64url_decode($tokenparam);
$payload = local_lmslaunch_validate_token($token, $secret);
$launch = local_lmslaunch_consume_token($apibaseurl, $secret, $timeout, $token);

$userid = (int)($launch['moodleUserId'] ?? 0);
$redirectpath = $launch['redirectPath'] ?? '';
if ($userid <= 0 || !is_string($redirectpath) || $redirectpath === '' || $redirectpath[0] !== '/') {
    throw new moodle_exception('launchfailed', 'local_lmslaunch');
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
if (!$user) {
    throw new moodle_exception('usernotfound', 'local_lmslaunch');
}
if (!empty($user->suspended)) {
    throw new moodle_exception('usersuspended', 'local_lmslaunch');
}

complete_user_login($user);

$courseid = local_lmslaunch_extract_course_id($redirectpath);
if ($courseid !== null) {
    redirect($CFG->wwwroot . '/local/lmslaunch/course.php?courseid=' . $courseid);
}

redirect($CFG->wwwroot . $redirectpath);
