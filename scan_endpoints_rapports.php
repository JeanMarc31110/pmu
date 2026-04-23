<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/curl_helper.php';

header('Content-Type: application/json; charset=utf-8');

$date = cleanDate($_GET['date'] ?? '15042026');
$reunion = $_GET['reunion'] ?? 'R1';
$course = $_GET['course'] ?? 'C1';

$base = PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course;

$urls = [
    $base,
    $base . '/rapports',
    $base . '/rapports-definitifs',
    $base . '/resultats',
    $base . '/resultat',
    $base . '/arrivee',
    $base . '/arrivees',
    $base . '/participants',
    $base . '/paris'
];

$out = [];

foreach ($urls as $url) {
    $res = httpGet($url);

    $json = $res['json'] ?? null;

    $out[] = [
        'url' => $url,
        'http_code' => $res['http_code'] ?? null,
        'error' => $res['error'] ?? null,
        'top_level_keys' => is_array($json) ? array_keys($json) : null,
        'preview' => $json
    ];
}

echo json_encode([
    'success' => true,
    'date' => $date,
    'reunion' => $reunion,
    'course' => $course,
    'tested' => $out
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);