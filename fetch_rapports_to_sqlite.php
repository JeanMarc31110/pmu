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

$url = PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course;
$res = httpGet($url);
$json = $res['json'] ?? null;

$paris = $json['paris'] ?? [];
$out = [];

foreach ($paris as $i => $pari) {
    $out[] = [
        'index' => $i,
        'typePari' => $pari['typePari'] ?? null,
        'codePari' => $pari['codePari'] ?? null,
        'enVente' => $pari['enVente'] ?? null,
        'misEnPaiement' => $pari['misEnPaiement'] ?? null,
        'keys' => is_array($pari) ? array_keys($pari) : null,
        'rapport_keys' => array_values(array_filter(
            is_array($pari) ? array_keys($pari) : [],
            fn($k) => stripos((string)$k, 'rapport') !== false
        )),
        'pari' => $pari
    ];
}

echo json_encode([
    'success' => true,
    'script' => 'fetch_rapports_to_sqlite.php',
    'url' => $url,
    'http_code' => $res['http_code'] ?? null,
    'error' => $res['error'] ?? null,
    'paris_count' => count($paris),
    'data' => $out
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);