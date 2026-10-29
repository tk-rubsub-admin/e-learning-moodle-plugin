<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lmslaunch/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);
$secret = (string)get_config('local_lmslaunch', 'shared_secret');
$apibaseurl = (string)get_config('local_lmslaunch', 'lms_api_base_url');
$timeout = max(10, (int)get_config('local_lmslaunch', 'connect_timeout'));

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$content = local_lmslaunch_fetch_course_content($apibaseurl, $secret, $timeout, $courseid);
$item = local_lmslaunch_find_course_content_item($content, $itemid);
if ($item === null || empty($item['visible']) || strtoupper((string)($item['type'] ?? '')) !== 'PDF') {
    throw new moodle_exception('invalidcontentitem', 'local_lmslaunch');
}

$sourceurl = (string)($item['sourceUrl'] ?? '');
$sourcepath = parse_url($sourceurl, PHP_URL_PATH);
if ($apibaseurl !== ''
        && is_string($sourcepath)
        && preg_match('#(?:^|/)content-files/([a-f0-9-]{36}\.pdf)$#i', $sourcepath, $matches)) {
    // Files uploaded through lms-api should be fetched over the private Docker
    // network. The public source URL can be unreachable from the Moodle
    // container when the Docker host does not support public-IP hairpinning.
    $sourceurl = rtrim($apibaseurl, '/') . '/content-files/' . $matches[1];
}

$pdf = local_lmslaunch_fetch_pdf($sourceurl, $timeout);
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: inline; filename="course-content.pdf"');
echo $pdf;
