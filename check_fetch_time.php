<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');

// Chercher timestamps dans participants
$cols = $pdo->query("PRAGMA table_info(participants)")->fetchAll(PDO::FETCH_ASSOC);
$col_names = array_column($cols, 'name');

// Chercher timestamps dans courses
$cols2 = $pdo->query("PRAGMA table_info(courses)")->fetchAll(PDO::FETCH_ASSOC);
$col_names2 = array_column($cols2, 'name');

// Chercher dans raw_json les champs horaire
$sample = $pdo->query("
    SELECT raw_json FROM participants
    WHERE date_course='16042026' LIMIT 1
")->fetchColumn();
$raw = json_decode($sample, true);
$heure_fields = [];
foreach ($raw ?? [] as $k => $v) {
    if (stripos($k,'heure') !== false || stripos($k,'time') !== false ||
        stripos($k,'depart') !== false || stripos($k,'start') !== false) {
        $heure_fields[$k] = $v;
    }
}

// created_at si existe
$created = null;
if (in_array('created_at', $col_names)) {
    $created = $pdo->query("
        SELECT MIN(created_at), MAX(created_at) FROM participants WHERE date_course='16042026'
    ")->fetch(PDO::FETCH_NUM);
}

// Chercher dans courses
$courses_sample = null;
if (in_array('created_at', $col_names2)) {
    $courses_sample = $pdo->query("
        SELECT MIN(created_at), MAX(created_at) FROM courses WHERE date_course='16042026'
    ")->fetch(PDO::FETCH_NUM);
}

echo json_encode([
    'participants_colonnes' => $col_names,
    'courses_colonnes'      => $col_names2,
    'created_at_participants'=> $created,
    'created_at_courses'    => $courses_sample,
    'champs_heure_raw_json' => $heure_fields,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
