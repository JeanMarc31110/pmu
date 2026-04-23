<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/curl_helper.php';

if (!function_exists('toDbValue')) {
    function toDbValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

header('Content-Type: application/json; charset=utf-8');

$date = cleanDate($_GET['date'] ?? date('dmY'));

if (!$date) {
    jsonResponse([
        'success' => false,
        'message' => 'Date invalide. Utilise le format JJMMAAAA'
    ], 400);
}

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS arrivees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            arrivee_definitive TEXT,
            statut TEXT,
            categorie_statut TEXT,
            heure_depart TEXT,
            libelle TEXT,
            raw_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date_course, reunion, course)
        );
    ");

    $stmtSelectCourses = $pdo->prepare("
        SELECT date_course, reunion, course
        FROM courses
        WHERE date_course = :date
        ORDER BY reunion, course
    ");

    $stmtSelectCourses->execute([':date' => $date]);
    $courses = $stmtSelectCourses->fetchAll(PDO::FETCH_ASSOC);

    $stmtInsertArrivee = $pdo->prepare("
        INSERT INTO arrivees (
            date_course, reunion, course, arrivee_definitive, statut,
            categorie_statut, heure_depart, libelle, raw_json
        ) VALUES (
            :date_course, :reunion, :course, :arrivee_definitive, :statut,
            :categorie_statut, :heure_depart, :libelle, :raw_json
        )
        ON CONFLICT(date_course, reunion, course) DO UPDATE SET
            arrivee_definitive = excluded.arrivee_definitive,
            statut = excluded.statut,
            categorie_statut = excluded.categorie_statut,
            heure_depart = excluded.heure_depart,
            libelle = excluded.libelle,
            raw_json = excluded.raw_json
    ");

    $summary = [];
    $errors = [];
    $totalCourses = 0;
    $totalInserted = 0;

    foreach ($courses as $row) {
        $reunion = $row['reunion'];
        $course = $row['course'];
        $url = PMU_BASE_URL . '/' . $date . '/' . $reunion . '/' . $course;

        $res = httpGet($url);
        $totalCourses++;

        if (!$res['ok'] || empty($res['json'])) {
            $errors[] = [
                'date_course' => $date,
                'reunion' => $reunion,
                'course' => $course,
                'url' => $url,
                'http_code' => $res['http_code'] ?? null,
                'error' => $res['error'] ?? 'Réponse vide'
            ];
            continue;
        }

        $json = $res['json'];

        $stmtInsertArrivee->execute([
            ':date_course' => $date,
            ':reunion' => $reunion,
            ':course' => $course,
            ':arrivee_definitive' => toDbValue($json['arriveeDefinitive'] ?? null),
            ':statut' => toDbValue($json['statut'] ?? null),
            ':categorie_statut' => toDbValue($json['categorieStatut'] ?? null),
            ':heure_depart' => toDbValue($json['heureDepart'] ?? null),
            ':libelle' => toDbValue($json['libelle'] ?? null),
            ':raw_json' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $totalInserted++;

        $summary[] = [
            'reunion' => $reunion,
            'course' => $course,
            'libelle' => $json['libelle'] ?? null,
            'arrivee_definitive' => $json['arriveeDefinitive'] ?? null,
            'statut' => $json['statut'] ?? null
        ];
    }

    jsonResponse([
        'success' => true,
        'db_path' => $dbPath,
        'date' => $date,
        'total_courses_processed' => $totalCourses,
        'total_arrivees_inserted' => $totalInserted,
        'errors_count' => count($errors),
        'errors' => $errors,
        'summary' => $summary
    ]);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}