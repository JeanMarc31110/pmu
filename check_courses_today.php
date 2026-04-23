<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$date = $_GET['date'] ?? date('dmY');

$rows = $pdo->prepare("
    SELECT reunion, course, libelle, discipline, statut,
           heure_depart,
           (SELECT COUNT(*) FROM participants p
            WHERE p.date_course = c.date_course
              AND p.reunion = c.reunion
              AND p.course = c.course) AS nb_partants
    FROM courses c
    WHERE date_course = :d
    ORDER BY reunion, course
");
$rows->execute([':d' => $date]);
$courses = $rows->fetchAll(PDO::FETCH_ASSOC);

// Chercher spécifiquement La Cepière
$cepiere = array_filter($courses, fn($c) => stripos($c['libelle'],'cepiere') !== false || stripos($c['libelle'],'cépière') !== false);

// Lister les réunions distinctes
$reunions = [];
foreach ($courses as $c) {
    $r = $c['reunion'];
    if (!isset($reunions[$r])) $reunions[$r] = [];
    $reunions[$r][] = $c['libelle'];
}

echo json_encode([
    'date' => $date,
    'nb_courses' => count($courses),
    'courses' => $courses,
    'cepiere_trouve' => array_values($cepiere),
    'reunions_resume' => $reunions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
