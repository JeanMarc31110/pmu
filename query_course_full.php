<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    $reunion = $_GET['reunion'] ?? null;
    $course = $_GET['course'] ?? null;

    if (!$date || !$reunion || !$course) {
        throw new Exception("Paramètres requis : date, reunion, course");
    }

    $stmtCourse = $pdo->prepare("
        SELECT
            c.date_course,
            c.reunion,
            c.course,
            c.libelle,
            c.heure_depart,
            c.discipline,
            c.specialite,
            c.distance,
            c.categorie_participants,
            c.statut,
            c.categorie_statut,
            a.arrivee_definitive
        FROM courses c
        LEFT JOIN arrivees a
            ON a.date_course = c.date_course
           AND a.reunion = c.reunion
           AND a.course = c.course
        WHERE c.date_course = :date
          AND c.reunion = :reunion
          AND c.course = :course
        LIMIT 1
    ");

    $stmtCourse->execute([
        ':date' => $date,
        ':reunion' => $reunion,
        ':course' => $course
    ]);

    $courseData = $stmtCourse->fetch(PDO::FETCH_ASSOC);

    if (!$courseData) {
        throw new Exception("Course introuvable dans la base");
    }

    $stmtParticipants = $pdo->prepare("
        SELECT
            num_pmu,
            num_partant,
            nom,
            age,
            sexe,
            race,
            driver_jockey,
            entraineur,
            proprietaire,
            musique,
            corde,
            handicap_valeur,
            poids,
            gains,
            indicateur_inedit,
            supplement,
            non_partant
        FROM participants
        WHERE date_course = :date
          AND reunion = :reunion
          AND course = :course
        ORDER BY num_pmu
    ");

    $stmtParticipants->execute([
        ':date' => $date,
        ':reunion' => $reunion,
        ':course' => $course
    ]);

    $participants = $stmtParticipants->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'course' => $courseData,
        'participants_count' => count($participants),
        'participants' => $participants
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}