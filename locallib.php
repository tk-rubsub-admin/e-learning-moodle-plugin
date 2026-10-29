<?php
defined('MOODLE_INTERNAL') || die();

function local_lmslaunch_base64url_decode(string $value): string {
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new moodle_exception('invalidtoken', 'local_lmslaunch');
    }
    return $decoded;
}

function local_lmslaunch_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function local_lmslaunch_validate_token(string $token, string $secret): array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        throw new moodle_exception('invalidtoken', 'local_lmslaunch');
    }

    [$payloadsegment, $signaturesegment] = $parts;
    $expectedsignature = local_lmslaunch_base64url_encode(hash_hmac('sha256', $payloadsegment, $secret, true));
    if (!hash_equals($expectedsignature, $signaturesegment)) {
        throw new moodle_exception('invalidtoken', 'local_lmslaunch');
    }

    $payloadjson = local_lmslaunch_base64url_decode($payloadsegment);
    $payload = json_decode($payloadjson, true);
    if (!is_array($payload)) {
        throw new moodle_exception('invalidtoken', 'local_lmslaunch');
    }

    if (($payload['iss'] ?? '') !== 'lms-api') {
        throw new moodle_exception('invalidtoken', 'local_lmslaunch');
    }

    $exp = (int)($payload['exp'] ?? 0);
    if ($exp <= time()) {
        throw new moodle_exception('invalidtoken', 'local_lmslaunch');
    }

    $redirectpath = $payload['redir'] ?? '';
    if (!is_string($redirectpath) || $redirectpath === '' || $redirectpath[0] !== '/') {
        throw new moodle_exception('invalidredirect', 'local_lmslaunch');
    }

    return $payload;
}

function local_lmslaunch_consume_token(string $apibaseurl, string $secret, int $timeout, string $token): array {
    $url = rtrim($apibaseurl, '/') . '/moodle/launch/consume';
    $body = json_encode(['token' => $token], JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Lms-Launch-Secret: ' . $secret,
            'ngrok-skip-browser-warning: 1',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($curl);
    $httpcode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $error) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch', '', null, $error);
    }

    $data = json_decode($response, true);
    if ($httpcode >= 400 || !is_array($data)) {
        $snippet = trim((string)$response);
        if (strlen($snippet) > 400) {
            $snippet = substr($snippet, 0, 400) . '...';
        }
        throw new moodle_exception(
            'launchfailed',
            'local_lmslaunch',
            '',
            null,
            'HTTP ' . $httpcode . ' from ' . $url . ' :: ' . $snippet
        );
    }

    return $data;
}

function local_lmslaunch_extract_course_id(string $redirectpath): ?int {
    if (!preg_match('/[?&]id=(\d+)/', $redirectpath, $matches)) {
        return null;
    }

    $courseid = (int)$matches[1];
    return $courseid > 0 ? $courseid : null;
}

function local_lmslaunch_fetch_course_content(
        string $apibaseurl, string $secret, int $timeout, int $courseid, ?int $moodleuserid = null
): array {
    $url = rtrim($apibaseurl, '/') . '/moodle/courses/' . $courseid . '/content';
    if ($moodleuserid !== null) {
        $url .= '?moodleUserId=' . $moodleuserid;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Lms-Launch-Secret: ' . $secret,
            'ngrok-skip-browser-warning: 1',
        ],
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($curl);
    $httpcode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $error) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch', '', null, $error);
    }

    $data = json_decode($response, true);
    if ($httpcode >= 400 || !is_array($data)) {
        $snippet = trim((string)$response);
        if (strlen($snippet) > 400) {
            $snippet = substr($snippet, 0, 400) . '...';
        }
        throw new moodle_exception(
            'launchfailed',
            'local_lmslaunch',
            '',
            null,
            'HTTP ' . $httpcode . ' from ' . $url . ' :: ' . $snippet
        );
    }

    return $data;
}

function local_lmslaunch_update_content_progress(
        string $apibaseurl,
        string $secret,
        int $timeout,
        int $courseid,
        int $itemid,
        int $moodleuserid,
        string $action,
        ?int $currentpage = null,
        ?int $totalpages = null,
        ?int $videopositionms = null,
        ?int $videodurationms = null
): array {
    if ($action !== 'start' && $action !== 'complete' && $action !== 'page' && $action !== 'video') {
        throw new moodle_exception('launchfailed', 'local_lmslaunch');
    }

    $url = rtrim($apibaseurl, '/') . '/moodle/courses/' . $courseid
        . '/items/' . $itemid . '/progress/' . $action;
    $payload = ['moodleUserId' => $moodleuserid];
    if ($action === 'page') {
        $payload['currentPage'] = $currentpage;
        $payload['totalPages'] = $totalpages;
    } else if ($action === 'video') {
        $payload['videoPositionMs'] = $videopositionms;
        $payload['videoDurationMs'] = $videodurationms;
    }
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Lms-Launch-Secret: ' . $secret,
            'ngrok-skip-browser-warning: 1',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($curl);
    $httpcode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $error) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch', '', null, $error);
    }

    $data = json_decode($response, true);
    if ($httpcode >= 400 || !is_array($data)) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch', '', null, 'HTTP ' . $httpcode);
    }

    return $data;
}

function local_lmslaunch_find_course_content_item(array $content, int $itemid): ?array {
    foreach (($content['sections'] ?? []) as $section) {
        foreach (($section['items'] ?? []) as $item) {
            if ((int)($item['id'] ?? 0) === $itemid) {
                return $item;
            }
        }
    }
    return null;
}

function local_lmslaunch_find_next_course_content_item_id(array $content, int $currentitemid): ?int {
    $sections = $content['sections'] ?? [];
    usort($sections, static function(array $left, array $right): int {
        return ((int)($left['orderIndex'] ?? 0)) <=> ((int)($right['orderIndex'] ?? 0));
    });

    $currentfound = false;
    foreach ($sections as $section) {
        $items = $section['items'] ?? [];
        usort($items, static function(array $left, array $right): int {
            return ((int)($left['orderIndex'] ?? 0)) <=> ((int)($right['orderIndex'] ?? 0));
        });
        foreach ($items as $item) {
            $itemid = (int)($item['id'] ?? 0);
            if ($currentfound && $itemid > 0 && (!array_key_exists('visible', $item) || !empty($item['visible']))) {
                return $itemid;
            }
            if ($itemid === $currentitemid) {
                $currentfound = true;
            }
        }
    }
    return null;
}

function local_lmslaunch_fetch_pdf(string $sourceurl, int $timeout): string {
    if (!filter_var($sourceurl, FILTER_VALIDATE_URL)
            || !in_array((string)parse_url($sourceurl, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new moodle_exception('invalidcontentitem', 'local_lmslaunch');
    }

    $curl = curl_init($sourceurl);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/pdf', 'ngrok-skip-browser-warning: 1'],
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => max($timeout, 30),
    ]);
    $response = curl_exec($curl);
    $httpcode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $error || $httpcode >= 400 || substr((string)$response, 0, 4) !== '%PDF') {
        throw new moodle_exception('launchfailed', 'local_lmslaunch');
    }
    return $response;
}

function local_lmslaunch_quiz_request(
        string $apibaseurl, string $secret, int $timeout, string $method, string $path, int $moodleuserid, ?array $payload = null
): array {
    $url = rtrim($apibaseurl, '/') . $path;
    if ($method === 'GET') {
        $query = $payload ?? [];
        $query['moodleUserId'] = $moodleuserid;
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    } else {
        $payload = $payload ?? [];
        $payload['moodleUserId'] = $moodleuserid;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-Lms-Launch-Secret: ' . $secret, 'ngrok-skip-browser-warning: 1'],
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($method !== 'GET') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
    $response = curl_exec($curl); $httpcode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    $data = json_decode((string)$response, true);
    if ($response === false || $error || $httpcode >= 400 || !is_array($data)) {
        throw new moodle_exception('launchfailed', 'local_lmslaunch');
    }
    return $data;
}
