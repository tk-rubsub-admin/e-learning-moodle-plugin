<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lmslaunch/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

$courseid = required_param('courseid', PARAM_INT);
$secret = (string)get_config('local_lmslaunch', 'shared_secret');
$apibaseurl = (string)get_config('local_lmslaunch', 'lms_api_base_url');
$timeout = (int)get_config('local_lmslaunch', 'connect_timeout');
$timeout = $timeout > 0 ? $timeout : 10;

if ($courseid <= 0 || $secret === '' || $apibaseurl === '') {
    throw new moodle_exception('missingconfig', 'local_lmslaunch');
}

$course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
if (!$course) {
    throw new moodle_exception('invalidcourseid');
}

require_login($course);

$content = local_lmslaunch_fetch_course_content($apibaseurl, $secret, $timeout, $courseid, (int)$USER->id);
$sections = $content['sections'] ?? [];
$courseTitle = (string)($content['displayName'] ?? $content['fullName'] ?? $course->fullname ?? 'Course');
$courseSummary = (string)($content['summary'] ?? '');
$enrollmentstatus = (string)($content['enrollmentStatus'] ?? '');
$coursestartedat = (string)($content['courseStartedAt'] ?? '');
$coursecompletedat = (string)($content['courseCompletedAt'] ?? '');

$PAGE->set_url(new moodle_url('/local/lmslaunch/course.php', ['courseid' => $courseid]));
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title($courseTitle);
$PAGE->set_heading($courseTitle);
$PAGE->set_pagetype('local-lmslaunch-course');
$PAGE->set_pagelayout('course');
$PAGE->requires->css(new moodle_url('/local/lmslaunch/styles.css'));

echo $OUTPUT->header();

echo html_writer::start_div('local-lmslaunch-course-page');
echo html_writer::start_div('mb-3');
echo html_writer::tag('h2', s($courseTitle));
if ($enrollmentstatus !== '') {
    $statuslabels = [
        'NOT_STARTED' => get_string('coursestatusnotstarted', 'local_lmslaunch'),
        'IN_PROGRESS' => get_string('coursestatusinprogress', 'local_lmslaunch'),
        'COMPLETED' => get_string('coursestatuscompleted', 'local_lmslaunch'),
    ];
    $statusclasses = [
        'NOT_STARTED' => 'badge bg-secondary',
        'IN_PROGRESS' => 'badge bg-warning text-dark',
        'COMPLETED' => 'badge bg-success',
    ];
    $statuslabel = $statuslabels[$enrollmentstatus] ?? $enrollmentstatus;
    $statusclass = $statusclasses[$enrollmentstatus] ?? 'badge bg-secondary';
    echo html_writer::div(
        html_writer::span(s($statuslabel), $statusclass),
        'mb-2'
    );
    if ($enrollmentstatus === 'COMPLETED' && $coursecompletedat !== '') {
        echo html_writer::div(
            get_string('coursecompletedat', 'local_lmslaunch', userdate(strtotime($coursecompletedat))),
            'small text-muted mb-2'
        );
    } else if ($enrollmentstatus === 'IN_PROGRESS' && $coursestartedat !== '') {
        echo html_writer::div(
            get_string('coursestartedat', 'local_lmslaunch', userdate(strtotime($coursestartedat))),
            'small text-muted mb-2'
        );
    }
}
if ($courseSummary !== '') {
    echo html_writer::div(nl2br(s($courseSummary)), 'text-muted');
}

if (empty($sections)) {
    echo html_writer::div('No course sections were returned from CarbonEdge.', 'alert alert-warning');
} else {
    foreach ($sections as $section) {
        $sectionTitle = (string)($section['title'] ?? ('Section ' . ($section['orderIndex'] ?? '')));
        $displaySectionTitle = 'บทที่ ' . $sectionTitle;
        $sectionDescription = (string)($section['description'] ?? '');
        $items = $section['items'] ?? [];

        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h4', s($displaySectionTitle), ['class' => 'card-title']);
        if ($sectionDescription !== '') {
            echo html_writer::div(nl2br(s($sectionDescription)), 'card-text text-muted mb-3');
        }

        if (empty($items)) {
            echo html_writer::div('No activities in this section yet.', 'alert alert-light mb-0');
        } else {
            foreach ($items as $item) {
                $itemTitle = (string)($item['title'] ?? ('Item ' . ($item['orderIndex'] ?? '')));
                $itemDescription = (string)($item['description'] ?? '');
                $type = strtoupper((string)($item['type'] ?? 'ITEM'));
                $renderKind = (string)($item['renderKind'] ?? 'unknown');
                $durationSeconds = $item['durationSeconds'] ?? null;
                $sourceUrl = (string)($item['sourceUrl'] ?? '');
                $payloadJson = (string)($item['payloadJson'] ?? '');

                $meta = [];
                if (!empty($item['required'])) {
                    $meta[] = html_writer::span('Required', 'badge bg-warning text-dark me-2');
                }
                if (!empty($item['completed'])) {
                    $meta[] = html_writer::span('Completed', 'badge bg-success me-2');
                }
                if (!empty($item['locked'])) {
                    $meta[] = html_writer::span('Locked', 'badge bg-secondary me-2');
                }
                $isVisible = !array_key_exists('visible', $item) || (bool)$item['visible'];
                if (!$isVisible) {
                    $meta[] = html_writer::span('Hidden', 'badge bg-dark me-2');
                }
                if (!empty($durationSeconds)) {
                    $meta[] = html_writer::span((string)$durationSeconds . 's', 'badge bg-info text-dark me-2');
                }

                echo html_writer::start_div('border rounded p-3 mb-3 bg-white');
                echo html_writer::tag('div', implode(' ', $meta), ['class' => 'mb-2']);
                echo html_writer::tag('h5', s($itemTitle), ['class' => 'mb-2']);
                if ($itemDescription !== '') {
                    echo html_writer::div(nl2br(s($itemDescription)), 'text-muted mb-2');
                }

                if ($type === 'QUIZ' && $payloadJson !== '') {
                    $decoded = json_decode($payloadJson, true);
                    if (is_array($decoded) && isset($decoded['questions']) && is_array($decoded['questions'])) {
                        echo html_writer::div('Questions: ' . count($decoded['questions']), 'small text-muted mb-2');
                    }
                }

                if ($isVisible && !empty($item['id'])) {
                    $playerpath = $type === 'QUIZ'
                        ? '/local/lmslaunch/quiz.php'
                        : '/local/lmslaunch/content.php';
                    $buttonlabel = $type === 'QUIZ' ? 'ทำแบบทดสอบ' : 'เข้าสู่เนื้อหา';
                    $playerurl = new moodle_url($playerpath, [
                        'courseid' => $courseid,
                        'itemid' => (int)$item['id'],
                    ]);
                    $button = !empty($item['locked'])
                        ? html_writer::tag('span', 'ล็อกอยู่', ['class' => 'btn btn-sm btn-secondary disabled', 'aria-disabled' => 'true'])
                        : html_writer::link($playerurl, $buttonlabel, ['class' => 'btn btn-sm btn-primary']);
                    echo html_writer::div(
                        $button,
                        'mt-2'
                    );
                }

                echo html_writer::end_div();
            }
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
