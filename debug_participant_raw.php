<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

function safeJsonDecode(?string $value): ?array
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? '15042026';
    $reunion = $_GET['reunion'] ?? 'R1';
    $course = $_GET['course'] ?? 'C1';
    $num = isset($_GET['num']) ? (int)$_GET['num'] : 1;

    $stmt = $pdo->prepare("
        SELECT
            date_course,
            reunion,
            course,
            num_pmu,
            nom,
            raw_json
        FROM participants
        WHERE date_course = :date
          AND reunion = :reunion
          AND course = :course
          AND num_pmu = :num
        LIMIT 1
    ");

    $stmt->execute([
        ':date' => $date,
        ':reunion' => $reunion,
        ':course' => $course,
        ':num' => $num
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Participant introuvable");
    }

    $raw = safeJsonDecode($row['raw_json']);

    echo json_encode([
        'success' => true,
        'participant' => [
            'date_course' => $row['date_course'],
            'reunion' => $row['reunion'],
            'course' => $row['course'],
            'num_pmu' => $row['num_pmu'],
            'nom' => $row['nom']
        ],
        'driver' => $raw['driver'] ?? null,
        'entraineur' => $raw['entraineur'] ?? null,
        'proprietaire' => $raw['proprietaire'] ?? null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}