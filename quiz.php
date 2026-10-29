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
$result = null;
if (optional_param('submitquiz', 0, PARAM_BOOL)) {
    require_sesskey();
    $attemptid = required_param('attemptid', PARAM_INT);
    $definition = local_lmslaunch_quiz_request(
        $apibaseurl, $secret, $timeout, 'GET',
        '/moodle/courses/' . $courseid . '/items/' . $itemid . '/quiz',
        (int)$USER->id, ['attemptId' => $attemptid]
    );
    $answers = [];
    foreach (($definition['questions'] ?? []) as $question) {
        $questionid = (int)$question['id'];
        if (($question['type'] ?? '') === 'MULTIPLE_CHOICE') {
            $selected = optional_param_array('answer_' . $questionid, [], PARAM_INT);
        } else {
            $selectedoption = optional_param('answer_' . $questionid, 0, PARAM_INT);
            $selected = $selectedoption > 0 ? [$selectedoption] : [];
        }
        $answers[] = ['questionId' => $questionid, 'optionIds' => array_values($selected)];
    }
    $result = local_lmslaunch_quiz_request($apibaseurl, $secret, $timeout, 'POST', '/moodle/courses/' . $courseid . '/items/' . $itemid . '/quiz/attempts/' . $attemptid . '/submit', (int)$USER->id, ['answers' => $answers]);
    $attempt = $result;
} else {
    $attempt = local_lmslaunch_quiz_request($apibaseurl, $secret, $timeout, 'POST', '/moodle/courses/' . $courseid . '/items/' . $itemid . '/quiz/attempts', (int)$USER->id);
    $definition = local_lmslaunch_quiz_request(
        $apibaseurl, $secret, $timeout, 'GET',
        '/moodle/courses/' . $courseid . '/items/' . $itemid . '/quiz',
        (int)$USER->id, ['attemptId' => (int)$attempt['attemptId']]
    );
}
$PAGE->set_url(new moodle_url('/local/lmslaunch/quiz.php', ['courseid' => $courseid, 'itemid' => $itemid]));
$PAGE->set_context(context_course::instance($courseid)); $PAGE->set_title('Quiz'); $PAGE->set_heading($course->fullname); $PAGE->set_pagetype('local-lmslaunch-quiz'); $PAGE->set_pagelayout('course');
$PAGE->requires->css(new moodle_url('/local/lmslaunch/styles.css'));
echo $OUTPUT->header(); echo html_writer::link(new moodle_url('/local/lmslaunch/course.php', ['courseid' => $courseid]), get_string('backtocoursecontent', 'local_lmslaunch'));
if ($result !== null) {
    $passed = !empty($result['passed']);
    $score = (int)($result['score'] ?? 0);
    $maxscore = (int)($result['maxScore'] ?? 0);
    $percentage = $maxscore > 0 ? (int)round(($score / $maxscore) * 100) : 0;
    $reviews = is_array($result['questions'] ?? null) ? $result['questions'] : [];
    $reviewsbyid = [];
    foreach ($reviews as $review) {
        $reviewsbyid[(int)($review['questionId'] ?? 0)] = $review;
    }
    $questionsbyid = [];
    $orderedreviews = [];
    foreach (($definition['questions'] ?? []) as $question) {
        $questionid = (int)($question['id'] ?? 0);
        $questionsbyid[$questionid] = $question;
        if (isset($reviewsbyid[$questionid])) {
            $orderedreviews[] = $reviewsbyid[$questionid];
        }
    }
    $reviews = $orderedreviews;
    $totalquestions = count($definition['questions'] ?? []);
    $correctanswers = count(array_filter($reviews, static function(array $review): bool {
        return !empty($review['correct']);
    }));
    echo html_writer::start_div('mt-4');
    echo html_writer::tag('h2', get_string('quizresulttitle', 'local_lmslaunch'), ['class' => 'mb-3']);
    echo html_writer::div(
        get_string($passed ? 'quizpassed' : 'quizfailed', 'local_lmslaunch'),
        $passed ? 'alert alert-success' : 'alert alert-warning'
    );

    echo html_writer::start_div('row g-3 mb-4');
    $summaries = [
        [get_string('quizscore', 'local_lmslaunch'), $score . ' / ' . $maxscore],
        [get_string('quizpercentage', 'local_lmslaunch'), $percentage . '%'],
        [get_string('quizcorrectanswers', 'local_lmslaunch'), $correctanswers . ' / ' . $totalquestions],
        [get_string('quizattemptnumber', 'local_lmslaunch'), (string)($result['attemptNumber'] ?? 1)],
    ];
    foreach ($summaries as [$label, $value]) {
        echo html_writer::start_div('col-6 col-lg-3');
        echo html_writer::start_div('card h-100 border-0 bg-light');
        echo html_writer::start_div('card-body text-center');
        echo html_writer::div(s($label), 'small text-muted mb-1');
        echo html_writer::tag('strong', s($value), ['class' => 'fs-4']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    if (!empty($reviews)) {
        echo html_writer::tag('h3', get_string('quizreviewtitle', 'local_lmslaunch'), ['class' => 'h5 mb-3']);
        foreach ($reviews as $index => $review) {
            $questionid = (int)($review['questionId'] ?? 0);
            $question = $questionsbyid[$questionid] ?? [];
            $correct = !empty($review['correct']);
            $prompt = (string)($question['prompt'] ?? get_string('quizquestion', 'local_lmslaunch'));
            $earnedpoints = (int)($review['earnedPoints'] ?? 0);
            $questionpoints = (int)($question['points'] ?? 0);
            $feedback = trim((string)($review['feedback'] ?? ''));

            echo html_writer::start_div('card mb-3 ' . ($correct ? 'border-success' : 'border-warning'));
            echo html_writer::start_div('card-body');
            echo html_writer::start_div('d-flex justify-content-between align-items-start gap-3');
            echo html_writer::tag('h4', s(($index + 1) . '. ' . $prompt), ['class' => 'h6 mb-2']);
            echo html_writer::span(
                get_string($correct ? 'quizanswercorrect' : 'quizanswerincorrect', 'local_lmslaunch'),
                $correct ? 'badge bg-success' : 'badge bg-warning text-dark'
            );
            echo html_writer::end_div();
            echo html_writer::div(
                get_string('quizearnedpoints', 'local_lmslaunch', (object)[
                    'earned' => $earnedpoints,
                    'total' => $questionpoints,
                ]),
                'small text-muted'
            );
            if ($feedback !== '') {
                echo html_writer::div(nl2br(s($feedback)), 'alert alert-light mt-3 mb-0');
            }
            echo html_writer::end_div();
            echo html_writer::end_div();
        }
    }

    $courseurl = new moodle_url('/local/lmslaunch/course.php', ['courseid' => $courseid]);
    echo html_writer::div(
        html_writer::link($courseurl, get_string('quiznexttocourse', 'local_lmslaunch'), ['class' => 'btn btn-primary btn-lg px-4']),
        'text-end mt-4'
    );
    echo html_writer::end_div();
} else {
    echo html_writer::tag('h2', s('Quiz'), ['class' => 'mt-3']);
    if (!empty($attempt['expiresAt'])) {
        echo html_writer::div('', 'alert alert-info', ['id' => 'lms-quiz-timer']);
        echo '<script>window.addEventListener("load", function(){const e=new Date(' . json_encode($attempt['expiresAt']) . ').getTime(); const n=document.getElementById("lms-quiz-timer"); const t=window.setInterval(function(){const s=Math.max(0,Math.ceil((e-Date.now())/1000)); n.textContent="Time remaining: "+Math.floor(s/60)+":"+String(s%60).padStart(2,"0"); if(!s) { clearInterval(t); document.getElementById("lms-quiz-form").submit(); }},1000);});</script>';
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'lms-quiz-form']); echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submitquiz', 'value' => '1']); echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $attempt['attemptId']]);
    foreach (($definition['questions'] ?? []) as $number => $question) { echo html_writer::start_div('card mt-3'); echo html_writer::start_div('card-body'); echo html_writer::tag('h5', s(($number + 1) . '. ' . $question['prompt'])); foreach (($question['options'] ?? []) as $option) { $inputtype = $question['type'] === 'MULTIPLE_CHOICE' ? 'checkbox' : 'radio'; $name = 'answer_' . $question['id'] . ($inputtype === 'checkbox' ? '[]' : ''); echo html_writer::start_div('form-check'); echo html_writer::empty_tag('input', ['class' => 'form-check-input', 'type' => $inputtype, 'name' => $name, 'value' => $option['id'], 'id' => 'option_' . $option['id']]); echo html_writer::tag('label', s($option['text']), ['class' => 'form-check-label', 'for' => 'option_' . $option['id']]); echo html_writer::end_div(); } echo html_writer::end_div(); echo html_writer::end_div(); }
    echo html_writer::tag('button', get_string('submitquiz', 'local_lmslaunch'), ['class' => 'btn btn-success mt-4', 'type' => 'submit']); echo html_writer::end_tag('form');
}
echo $OUTPUT->footer();
