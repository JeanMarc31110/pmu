<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$date = '17042026';

// Valeurs distinctes de source_mode
$modes = $pdo->query("
    SELECT source_mode, COUNT(*) as nb
    FROM moulinette_inputs
    WHERE date_course='$date'
    GROUP BY source_mode
")->fetchAll(PDO::FETCH_ASSOC);

// Runs distincts par created_at (minute)
$runs = $pdo->query("
    SELECT substr(created_at,1,16) as minute, COUNT(*) as nb_lignes,
           SUM(qualifie_final) as nb_qualifies,
           MIN(source_mode) as source_mode_exemple
    FROM moulinette_inputs
    WHERE date_course='$date'
    GROUP BY substr(created_at,1,16)
    ORDER BY minute
")->fetchAll(PDO::FETCH_ASSOC);

// Courses avec 2 sélections différentes (source_mode distinct)
// → signe d'un cheval matin remplacé par un autre à T-10min
$doubles = $pdo->query("
    SELECT mi1.reunion, mi1.course,
           mi1.num_pmu AS num_matin, mi1.nom AS nom_matin,
           mi1.source_mode AS sm1, mi1.created_at AS ca1,
           mi2.num_pmu AS num_t10, mi2.nom AS nom_t10,
           mi2.source_mode AS sm2, mi2.created_at AS ca2,
           json_extract(p1.raw_json,'$.ordreArrivee') AS ordre_matin,
           json_extract(p2.raw_json,'$.ordreArrivee') AS ordre_t10
    FROM moulinette_inputs mi1
    JOIN moulinette_inputs mi2
      ON mi2.date_course = mi1.date_course
     AND mi2.reunion = mi1.reunion
     AND mi2.course  = mi1.course
     AND mi2.num_pmu != mi1.num_pmu
     AND mi2.qualifie_final = 1
    JOIN participants p1
      ON p1.date_course=mi1.date_course AND p1.reunion=mi1.reunion
     AND p1.course=mi1.course AND p1.num_pmu=mi1.num_pmu
    JOIN participants p2
      ON p2.date_course=mi2.date_course AND p2.reunion=mi2.reunion
     AND p2.course=mi2.course AND p2.num_pmu=mi2.num_pmu
    WHERE mi1.date_course='$date' AND mi1.qualifie_final=1
      AND mi1.num_pmu < mi2.num_pmu
    ORDER BY mi1.reunion, mi1.course
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'source_modes'      => $modes,
    'runs_par_minute'   => $runs,
    'courses_avec_2_selections' => $doubles,
    'nb_doublons'       => count($doubles),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
