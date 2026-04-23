<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/curl_helper.php';

header('Content-Type: application/json; charset=utf-8');

$action  = $_GET['action'] ?? 'programme';
$date    = cleanDate($_GET['date'] ?? date('dmY'));
$reunion = strtoupper(trim($_GET['reunion'] ?? 'R1'));
$course  = strtoupper(trim($_GET['course'] ?? 'C1'));

if (!$date) {
    jsonResponse([
        'success' => false,
        'message' => 'Date invalide. Utilise le format JJMMAAAA, ex: 16042026'
    ], 400);
}

switch ($action) {
    case 'programme':
        $url = PMU_BASE_URL . '/' . $date;
        $result = httpGet($url);

        $saved = null;
        if (!empty($result['raw'])) {
            $saved = saveRawResponse('programme_' . $date, $result['raw']);
        }

        jsonResponse([
            'success' => $result['ok'],
            'action' => 'programme',
            'url' => $url,
            'http_code' => $result['http_code'],
            'error' => $result['error'],
            'saved_file' => $saved,
            'data' => $result['json'] ?? $result['raw']
        ], $result['ok'] ? 200 : 502);

    case 'participants':
        $url = PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course . '/participants';
        $result = httpGet($url);

        $saved = null;
        if (!empty($result['raw'])) {
            $saved = saveRawResponse('participants_' . $date . '_' . $reunion . '_' . $course, $result['raw']);
        }

        jsonResponse([
            'success' => $result['ok'],
            'action' => 'participants',
            'url' => $url,
            'http_code' => $result['http_code'],
            'error' => $result['error'],
            'saved_file' => $saved,
            'data' => $result['json'] ?? $result['raw']
        ], $result['ok'] ? 200 : 502);

    case 'rapports':
        $possibleUrls = [
            PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course . '/rapports',
            PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course . '/rapports-definitifs',
            PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course
        ];

        $tests = [];

        foreach ($possibleUrls as $url) {
            $res = httpGet($url);

            $saved = null;
            if (!empty($res['raw'])) {
                $saved = saveRawResponse('rapports_' . $date . '_' . $reunion . '_' . $course, $res['raw']);
            }

            $tests[] = [
                'url' => $url,
                'http_code' => $res['http_code'],
                'success' => $res['ok'],
                'error' => $res['error'],
                'saved_file' => $saved,
                'data' => $res['json'] ?? $res['raw']
            ];

            if ($res['ok']) {
                jsonResponse([
                    'success' => true,
                    'action' => 'rapports',
                    'message' => 'Une réponse a été trouvée sur un endpoint testé.',
                    'tested' => $tests
                ]);
            }
        }

        jsonResponse([
            'success' => false,
            'action' => 'rapports',
            'message' => 'Aucun endpoint de rapports n’a répondu correctement.',
            'tested' => $tests
        ], 502);

    default:
        jsonResponse([
            'success' => false,
            'message' => 'Action inconnue. Utilise: programme, participants, rapports'
        ], 400);
}