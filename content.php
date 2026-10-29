<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/lmslaunch/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

$courseid = required_param('courseid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT);
$secret = (string)get_config('local_lmslaunch', 'shared_secret');
$apibaseurl = (string)get_config('local_lmslaunch', 'lms_api_base_url');
$timeout = (int)get_config('local_lmslaunch', 'connect_timeout');
$timeout = $timeout > 0 ? $timeout : 10;

if ($courseid <= 0 || $itemid <= 0 || $secret === '' || $apibaseurl === '') {
    throw new moodle_exception('missingconfig', 'local_lmslaunch');
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

$content = local_lmslaunch_fetch_course_content($apibaseurl, $secret, $timeout, $courseid, (int)$USER->id);
$item = local_lmslaunch_find_course_content_item($content, $itemid);

if ($item === null || empty($item['visible']) || !empty($item['locked'])) {
    throw new moodle_exception('invalidcontentitem', 'local_lmslaunch');
}

$progress = [];
if (optional_param('complete', 0, PARAM_BOOL)) {
    require_sesskey();
    local_lmslaunch_update_content_progress(
        $apibaseurl, $secret, $timeout, $courseid, $itemid, (int)$USER->id, 'complete'
    );
    $nextitemid = local_lmslaunch_find_next_course_content_item_id($content, $itemid);
    if ($nextitemid !== null) {
        redirect(new moodle_url('/local/lmslaunch/content.php', ['courseid' => $courseid, 'itemid' => $nextitemid]));
    }
    redirect(new moodle_url('/local/lmslaunch/course.php', ['courseid' => $courseid]));
} else {
    $progress = local_lmslaunch_update_content_progress(
        $apibaseurl, $secret, $timeout, $courseid, $itemid, (int)$USER->id, 'start'
    );
}

$title = (string)($item['title'] ?? 'Course content');
$description = (string)($item['description'] ?? '');
$type = strtoupper((string)($item['type'] ?? 'TEXT'));
$sourceurl = (string)($item['sourceUrl'] ?? '');
$payloadjson = (string)($item['payloadJson'] ?? '');
$payload = $payloadjson === '' ? [] : json_decode($payloadjson, true);
$payload = is_array($payload) ? $payload : [];
$videocompleted = !empty($item['completed']) || !empty($progress['completedAt']);
$videoresumepositionms = $videocompleted ? 0 : max(0, (int)($progress['videoPositionMs'] ?? 0));
$videoprogressurl = (new moodle_url('/local/lmslaunch/video-progress.php', [
    'courseid' => $courseid,
    'itemid' => $itemid,
    'sesskey' => sesskey(),
]))->out(false);
$videoconfig = json_encode([
    'progressUrl' => $videoprogressurl,
    'resumePositionMs' => $videoresumepositionms,
], JSON_UNESCAPED_SLASHES);

$PAGE->set_url(new moodle_url('/local/lmslaunch/content.php', ['courseid' => $courseid, 'itemid' => $itemid]));
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagetype('local-lmslaunch-content');
$PAGE->set_pagelayout('course');
$PAGE->requires->css(new moodle_url('/local/lmslaunch/styles.css'));
$PAGE->requires->js_init_code("
var videoConfig = " . $videoconfig . ";
var video = document.getElementById('local-lmslaunch-content-video');
if (video) {
    var complete = document.getElementById('lms-video-complete');
    var completeForm = document.getElementById('lms-content-complete-form');
    var backLink = document.getElementById('lms-back-to-course');
    var lastQueuedPositionMs = videoConfig.resumePositionMs;
    var saveQueue = Promise.resolve();

    var snapshot = function() {
        if (!Number.isFinite(video.duration) || video.duration <= 0) {
            return null;
        }
        return {
            positionMs: Math.max(0, Math.min(Math.round(video.currentTime * 1000), Math.round(video.duration * 1000))),
            durationMs: Math.round(video.duration * 1000)
        };
    };

    var sendProgress = function(data, keepalive) {
        var body = new URLSearchParams();
        body.set('videopositionms', String(data.positionMs));
        body.set('videodurationms', String(data.durationMs));
        return fetch(videoConfig.progressUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString(),
            keepalive: keepalive
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('Could not save video progress');
            }
            return response;
        });
    };

    var saveProgress = function(keepalive, force) {
        var data = snapshot();
        if (!data || (!force && Math.abs(data.positionMs - lastQueuedPositionMs) < 10000)) {
            return Promise.resolve();
        }
        if (data.positionMs === lastQueuedPositionMs && !keepalive) {
            return saveQueue;
        }
        lastQueuedPositionMs = data.positionMs;
        if (keepalive) {
            return sendProgress(data, true).catch(function(error) {
                if (lastQueuedPositionMs === data.positionMs) {
                    lastQueuedPositionMs = null;
                }
                console.warn(error);
            });
        }
        saveQueue = saveQueue.catch(function() {}).then(function() {
            return sendProgress(data, false);
        }).catch(function(error) {
            if (lastQueuedPositionMs === data.positionMs) {
                lastQueuedPositionMs = null;
            }
            console.warn(error);
        });
        return saveQueue;
    };

    video.addEventListener('loadedmetadata', function() {
        if (videoConfig.resumePositionMs > 0) {
            video.currentTime = Math.min(videoConfig.resumePositionMs / 1000, Math.max(0, video.duration - 0.25));
        }
    });
    document.getElementById('local-lmslaunch-video-play').addEventListener('click', function() { video.play(); });
    document.getElementById('local-lmslaunch-video-pause').addEventListener('click', function() { video.pause(); });
    document.getElementById('local-lmslaunch-video-stop').addEventListener('click', function() {
        video.pause();
        video.currentTime = 0;
        saveProgress(false, true);
    });
    video.addEventListener('timeupdate', function() { saveProgress(false, false); });
    video.addEventListener('pause', function() { saveProgress(false, true); });
    video.addEventListener('ended', function() {
        saveProgress(false, true);
        if (complete) { complete.disabled = false; }
    });
    window.addEventListener('pagehide', function() { saveProgress(true, true); });
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') { saveProgress(true, true); }
    });
    if (backLink) {
        backLink.addEventListener('click', function(event) {
            event.preventDefault();
            saveProgress(false, true).finally(function() { window.location.href = backLink.href; });
        });
    }
    if (completeForm) {
        completeForm.addEventListener('submit', function(event) {
            if (completeForm.dataset.progressSaved === 'true') { return; }
            event.preventDefault();
            saveProgress(false, true).finally(function() {
                completeForm.dataset.progressSaved = 'true';
                completeForm.submit();
            });
        });
    }
}
");

echo $OUTPUT->header();
echo html_writer::start_div('local-lmslaunch-content-page');
echo html_writer::div(
    html_writer::link(new moodle_url('/local/lmslaunch/course.php', ['courseid' => $courseid]), get_string('backtocoursecontent', 'local_lmslaunch'), [
        'id' => 'lms-back-to-course',
    ]),
    'mb-3'
);
echo html_writer::tag('span', s($type), ['class' => 'badge bg-success mb-2']);
echo html_writer::tag('h2', s($title));
if ($description !== '') {
    echo html_writer::div(nl2br(s($description)), 'text-muted mb-4');
}

if (($type === 'VIDEO') && $sourceurl !== '') {
    echo html_writer::tag('video', 'Your browser does not support this video format.', [
        'id' => 'local-lmslaunch-content-video',
        'src' => $sourceurl,
        'preload' => 'metadata',
        'class' => 'w-100 rounded bg-dark mb-2',
        'style' => 'max-width: 900px; max-height: 520px;',
    ]);
    echo html_writer::div(
        html_writer::tag('button', 'เริ่ม', ['id' => 'local-lmslaunch-video-play', 'type' => 'button', 'class' => 'btn btn-success me-2']) .
        html_writer::tag('button', 'หยุดชั่วคราว', ['id' => 'local-lmslaunch-video-pause', 'type' => 'button', 'class' => 'btn btn-warning me-2']) .
        html_writer::tag('button', 'หยุด', ['id' => 'local-lmslaunch-video-stop', 'type' => 'button', 'class' => 'btn btn-danger']),
        'mb-3'
    );
} else if ($type === 'PDF' && $sourceurl !== '') {
    $pdfurl = (new moodle_url('/local/lmslaunch/pdf.php', ['courseid' => $courseid, 'itemid' => $itemid]))->out(false);
    $progressurl = (new moodle_url('/local/lmslaunch/pdf-progress.php', [
        'courseid' => $courseid,
        'itemid' => $itemid,
        'sesskey' => sesskey(),
    ]))->out(false);
    echo html_writer::start_div('border rounded p-3 bg-white');
    echo html_writer::tag('div', '', ['id' => 'lms-pdf-error', 'class' => 'alert alert-danger d-none']);
    echo html_writer::start_div('text-center');
    echo html_writer::tag('canvas', '', [
        'id' => 'lms-pdf-canvas',
        'class' => 'border',
        'style' => 'display: block; width: 100%; max-width: 820px; height: auto; margin: 0 auto;',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('d-flex align-items-center justify-content-between mb-3');
    echo html_writer::tag('button', get_string('previouspage', 'local_lmslaunch'), [
        'id' => 'lms-pdf-previous', 'type' => 'button', 'class' => 'btn btn-outline-secondary', 'disabled' => 'disabled',
    ]);
    echo html_writer::tag('strong', get_string('pdfpagestatusloading', 'local_lmslaunch'), ['id' => 'lms-pdf-page-status']);
    echo html_writer::tag('button', get_string('nextpage', 'local_lmslaunch'), [
        'id' => 'lms-pdf-next', 'type' => 'button', 'class' => 'btn btn-primary', 'disabled' => 'disabled',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::tag('script', '', ['src' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js']);
    $pdfconfig = json_encode(['pdfUrl' => $pdfurl, 'progressUrl' => $progressurl], JSON_UNESCAPED_SLASHES);
    echo '<script>window.addEventListener("load", async function() {'
        . 'const config = ' . $pdfconfig . ';'
        . 'const previous = document.getElementById("lms-pdf-previous");'
        . 'const next = document.getElementById("lms-pdf-next");'
        . 'const status = document.getElementById("lms-pdf-page-status");'
        . 'const error = document.getElementById("lms-pdf-error");'
        . 'const canvas = document.getElementById("lms-pdf-canvas");'
        . 'const complete = document.getElementById("lms-pdf-complete");'
        . 'const context = canvas.getContext("2d"); let documentPdf; let currentPage = 1;'
        . 'const showError = (message) => { error.textContent = message; error.classList.remove("d-none"); };'
        . 'const saveProgress = async () => { const response = await fetch(config.progressUrl + "&currentpage=" + currentPage + "&totalpages=" + documentPdf.numPages, {credentials: "same-origin"}); if (!response.ok) { throw new Error("Could not save PDF progress"); } };'
        . 'const render = async () => { const page = await documentPdf.getPage(currentPage); const viewport = page.getViewport({scale: 1.5}); canvas.width = viewport.width; canvas.height = viewport.height; await page.render({canvasContext: context, viewport: viewport}).promise; status.textContent = "Page " + currentPage + " / " + documentPdf.numPages; previous.disabled = currentPage === 1; next.disabled = currentPage === documentPdf.numPages; complete.disabled = true; await saveProgress(); complete.disabled = currentPage !== documentPdf.numPages; };'
        . 'const changePage = async (page) => { currentPage = page; await render(); };'
        . 'try { if (!window.pdfjsLib) { throw new Error("PDF viewer could not be loaded"); } window.pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js"; documentPdf = await window.pdfjsLib.getDocument(config.pdfUrl).promise; previous.addEventListener("click", async () => { if (currentPage > 1) { await changePage(currentPage - 1); } }); next.addEventListener("click", async () => { if (currentPage < documentPdf.numPages) { await changePage(currentPage + 1); } }); await render(); } catch (exception) { showError(exception.message || "Could not load PDF"); }'
        . '});</script>';
} else if ($type === 'FILE' && $sourceurl !== '') {
    echo html_writer::link($sourceurl, get_string('openresource', 'local_lmslaunch'), ['class' => 'btn btn-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer']);
} else if ($type === 'LINK' && $sourceurl !== '') {
    echo html_writer::link($sourceurl, get_string('openresource', 'local_lmslaunch'), ['class' => 'btn btn-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer']);
} else if ($type === 'QUIZ') {
    echo html_writer::link(new moodle_url('/local/lmslaunch/quiz.php', ['courseid' => $courseid, 'itemid' => $itemid]), get_string('startquiz', 'local_lmslaunch'), ['class' => 'btn btn-primary']);
} else {
    $text = (string)($payload['content'] ?? $payload['text'] ?? $payloadjson);
    echo html_writer::div(nl2br(s($text)), 'border rounded p-3 bg-white');
}

echo html_writer::start_tag('form', [
    'id' => 'lms-content-complete-form',
    'method' => 'post',
    'action' => (new moodle_url('/local/lmslaunch/content.php', ['courseid' => $courseid, 'itemid' => $itemid]))->out(false),
    'class' => 'mt-4 text-end',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'complete', 'value' => '1']);
echo html_writer::tag('button', 'เนื้อหาถัดไป', [
    'id' => $type === 'PDF' ? 'lms-pdf-complete' : ($type === 'VIDEO' ? 'lms-video-complete' : null),
    'type' => 'submit',
    'class' => 'btn btn-success',
    'disabled' => ($type === 'PDF' || ($type === 'VIDEO' && !$videocompleted)) ? 'disabled' : null,
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
