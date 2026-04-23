<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/curl_helper.php';

header('Content-Type: application/json; charset=utf-8');

$date = cleanDate($_GET['date'] ?? date('dmY'));

if (!$date) {
    jsonResponse([
        'success' => false,
        'message' => 'Date invalide. Utilise le format JJMMAAAA, ex: 16042026'
    ], 400);
}

$programmeUrl = PMU_BASE_URL . '/' . $date;
$programmeRes = httpGet($programmeUrl);

if (!$programmeRes['ok'] || empty($programmeRes['json'])) {
    jsonResponse([
        'success' => false,
        'step' => 'programme',
        'url' => $programmeUrl,
        'http_code' => $programmeRes['http_code'] ?? null,
        'error' => $programmeRes['error'] ?? 'Réponse vide',
        'data' => $programmeRes['raw'] ?? null
    ], 502);
}

$programmeFile = null;
if (!empty($programmeRes['raw'])) {
    $programmeFile = saveRawResponse('programme_' . $date, $programmeRes['raw']);
}

$programme = $programmeRes['json'];
$reunions = $programme['programme']['reunions'] ?? [];

$results = [];
$totalCourses = 0;
$totalSuccess = 0;
$totalErrors = 0;

foreach ($reunions as $reunion) {
    $numOfficiel = $reunion['numOfficiel'] ?? null;

    if ($numOfficiel === null) {
        continue;
    }

    $reunionCode = 'R' . $numOfficiel;
    $courses = $reunion['courses'] ?? [];

    foreach ($courses as $course) {
        $numOrdre = $course['numOrdre'] ?? null;

        if ($numOrdre === null) {
            continue;
        }

        $courseCode = 'C' . $numOrdre;
        $participantsUrl = PMU_BASE_URL . '/' . $date . '/' . $reunionCode . '/' . $courseCode . '/participants';

        $res = httpGet($participantsUrl);

        $savedFile = null;
        if (!empty($res['raw'])) {
            $savedFile = saveRawResponse(
                'participants_' . $date . '_' . $reunionCode . '_' . $courseCode,
                $res['raw']
            );
        }

        $ok = !empty($res['ok']);
        $totalCourses++;

        if ($ok) {
            $totalSuccess++;
        } else {
            $totalErrors++;
        }

        $results[] = [
            'reunion' => $reunionCode,
            'course' => $courseCode,
            'libelle' => $course['libelle'] ?? null,
            'heure' => $course['heureDepart'] ?? null,
            'url' => $participantsUrl,
            'success' => $ok,
            'http_code' => $res['http_code'] ?? null,
            'error' => $res['error'] ?? null,
            'saved_file' => $savedFile
        ];
    }
}

jsonResponse([
    'success' => true,
    'date' => $date,
    'programme_file' => $programmeFile,
    'total_courses' => $totalCourses,
    'total_success' => $totalSuccess,
    'total_errors' => $totalErrors,
    'results' => $results
]);