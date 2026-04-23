<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$global = $pdo->query("
    SELECT COUNT(DISTINCT date_course) total_dates,
           MIN(date_course) premiere, MAX(date_course) derniere
    FROM participants
")->fetch(PDO::FETCH_ASSOC);

$mi = $pdo->query("SELECT COUNT(DISTINCT date_course) FROM moulinette_inputs")->fetchColumn();

$dernieres = $pdo->query("
    SELECT date_course,
           COUNT(DISTINCT reunion||'_'||course) nb_courses,
           COUNT(*) nb_partants,
           SUM(CASE WHEN json_extract(raw_json,'$.ordreArrivee') IS NOT NULL THEN 1 ELSE 0 END) avec_res
    FROM participants
    GROUP BY date_course
    ORDER BY substr(date_course,5,4)||substr(date_course,3,2)||substr(date_course,1,2) DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$sans_res = $pdo->query("
    SELECT date_course FROM participants
    GROUP BY date_course
    HAVING SUM(CASE WHEN json_extract(raw_json,'$.ordreArrivee') IS NOT NULL THEN 1 ELSE 0 END) = 0
    ORDER BY substr(date_course,5,4)||substr(date_course,3,2)||substr(date_course,1,2) DESC
")->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'global'           => $global,
    'moulinette_dates' => (int)$mi,
    'dernieres_10'     => $dernieres,
    'sans_resultats'   => $sans_res,
    'aujourd_hui'      => date('dmY'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
