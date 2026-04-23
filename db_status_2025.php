<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Toutes les dates présentes, triées chronologiquement
$dates = $pdo->query("
    SELECT date_course,
           COUNT(DISTINCT reunion||'_'||course) nb_courses,
           COUNT(*) nb_partants,
           SUM(CASE WHEN json_extract(raw_json,'$.ordreArrivee') IS NOT NULL THEN 1 ELSE 0 END) avec_resultats
    FROM participants
    GROUP BY date_course
    ORDER BY substr(date_course,5,4)||substr(date_course,3,2)||substr(date_course,1,2)
")->fetchAll(PDO::FETCH_ASSOC);

// Séparer 2025 et 2026
$d2025 = array_filter($dates, fn($d) => substr($d['date_course'],4,4) === '2025');
$d2026 = array_filter($dates, fn($d) => substr($d['date_course'],4,4) === '2026');

function resume(array $arr): array {
    $arr = array_values($arr);
    if (!$arr) return ['nb_dates'=>0,'premiere'=>null,'derniere'=>null,'sans_resultats'=>[]];
    $sans_res = array_values(array_map(fn($d)=>$d['date_course'],
        array_filter($arr, fn($d)=>$d['avec_resultats']==0)));
    return [
        'nb_dates'      => count($arr),
        'premiere'      => $arr[0]['date_course'],
        'derniere'      => end($arr)['date_course'],
        'sans_resultats'=> $sans_res,
        'detail'        => $arr,
    ];
}

echo json_encode([
    '2025' => resume($d2025),
    '2026' => resume($d2026),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
