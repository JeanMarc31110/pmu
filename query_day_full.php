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

    if (!$date) {
        throw new Exception("Paramètre requis : date");
    }

    $stmtCourses = $pdo->prepare("
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
        ORDER BY c.reunion, c.course
    ");

    $stmtCourses->execute([':date' => $date]);
    $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

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

    $result = [];

    foreach ($courses as $course) {
        $stmtParticipants->execute([
            ':date' => $course['date_course'],
            ':reunion' => $course['reunion'],
            ':course' => $course['course']
        ]);

        $participants = $stmtParticipants->fetchAll(PDO::FETCH_ASSOC);

        $result[] = [
            'course' => $course,
            'participants_count' => count($participants),
            'participants' => $participants
        ];
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'courses_count' => count($result),
        'data' => $result
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}