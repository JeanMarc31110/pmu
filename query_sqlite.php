<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $action = $_GET['action'] ?? 'courses';
    $date   = $_GET['date'] ?? null;
    $reunion = $_GET['reunion'] ?? null;
    $course  = $_GET['course'] ?? null;

    switch ($action) {
        case 'courses':
            $sql = "SELECT date_course, reunion, course, libelle, heure_depart, discipline, distance
                    FROM courses";
            $params = [];
            $where = [];

            if ($date) {
                $where[] = "date_course = :date";
                $params[':date'] = $date;
            }

            if ($reunion) {
                $where[] = "reunion = :reunion";
                $params[':reunion'] = $reunion;
            }

            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY date_course, reunion, course";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'action' => 'courses',
                'count' => $stmt->rowCount(),
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'participants':
            if (!$date || !$reunion || !$course) {
                throw new Exception("Paramètres requis : date, reunion, course");
            }

            $stmt = $pdo->prepare("
                SELECT
                    date_course,
                    reunion,
                    course,
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
                    non_partant
                FROM participants
                WHERE date_course = :date
                  AND reunion = :reunion
                  AND course = :course
                ORDER BY num_pmu
            ");

            $stmt->execute([
                ':date' => $date,
                ':reunion' => $reunion,
                ':course' => $course
            ]);

            echo json_encode([
                'success' => true,
                'action' => 'participants',
                'count' => $stmt->rowCount(),
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'count_participants':
            $stmt = $pdo->prepare("
                SELECT
                    date_course,
                    reunion,
                    course,
                    COUNT(*) AS nb_partants
                FROM participants
                GROUP BY date_course, reunion, course
                ORDER BY date_course, reunion, course
            ");

            $stmt->execute();

            echo json_encode([
                'success' => true,
                'action' => 'count_participants',
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        default:
            throw new Exception("Action inconnue. Utilise : courses, participants, count_participants");
    }

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}