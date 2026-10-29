<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lmslaunch/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);
$videopositionms = required_param('videopositionms', PARAM_INT);
$videodurationms = required_param('videodurationms', PARAM_INT);
require_sesskey();

$secret = (string)get_config('local_lmslaunch', 'shared_secret');
$apibaseurl = (string)get_config('local_lmslaunch', 'lms_api_base_url');
$timeout = max(10, (int)get_config('local_lmslaunch', 'connect_timeout'));
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$content = local_lmslaunch_fetch_course_content($apibaseurl, $secret, $timeout, $courseid, (int)$USER->id);
$item = local_lmslaunch_find_course_content_item($content, $itemid);
if ($item === null || empty($item['visible']) || !empty($item['locked'])
        || strtoupper((string)($item['type'] ?? '')) !== 'VIDEO') {
    throw new moodle_exception('invalidcontentitem', 'local_lmslaunch');
}

$progress = local_lmslaunch_update_content_progress(
    $apibaseurl, $secret, $timeout, $courseid, $itemid, (int)$USER->id,
    'video', null, null, $videopositionms, $videodurationms
);
header('Content-Type: application/json');
echo json_encode($progress, JSON_UNESCAPED_SLASHES);
