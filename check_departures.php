<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$date = $_GET['date'] ?? date('dmY');
$rows = $pdo->prepare("
    SELECT reunion, course, libelle, heure_depart, discipline, distance
    FROM courses WHERE date_course = :d
    ORDER BY heure_depart, reunion, course
");
$rows->execute([':d' => $date]);
echo json_encode([
    'date'    => $date,
    'courses' => $rows->fetchAll(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
