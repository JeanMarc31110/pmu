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

function extractDriverOrJockeyName(array $raw): ?string
{
    // Cas réel observé : chaîne directe
    if (!empty($raw['driver']) && is_string($raw['driver'])) {
        return trim($raw['driver']);
    }

    if (!empty($raw['jockey']) && is_string($raw['jockey'])) {
        return trim($raw['jockey']);
    }

    // Cas alternatif : objet avec nom
    if (!empty($raw['driver']['nom'])) {
        return trim($raw['driver']['nom']);
    }

    if (!empty($raw['jockey']['nom'])) {
        return trim($raw['jockey']['nom']);
    }

    // Variantes
    if (!empty($raw['driverNom']) && is_string($raw['driverNom'])) {
        return trim($raw['driverNom']);
    }

    if (!empty($raw['jockeyNom']) && is_string($raw['jockeyNom'])) {
        return trim($raw['jockeyNom']);
    }

    return null;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;

    $sql = "
        SELECT id, date_course, reunion, course, num_pmu, nom, driver_jockey, raw_json
        FROM participants
        WHERE 1 = 1
    ";

    $params = [];

    if ($date) {
        $sql .= " AND date_course = :date";
        $params[':date'] = $date;
    }

    $sql .= " ORDER BY date_course, reunion, course, num_pmu";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtUpdate = $pdo->prepare("
        UPDATE participants
        SET driver_jockey = :driver_jockey
        WHERE id = :id
    ");

    $updated = 0;
    $alreadyFilled = 0;
    $notFound = 0;
    $summary = [];

    foreach ($rows as $row) {
        if (!empty($row['driver_jockey'])) {
            $alreadyFilled++;
            continue;
        }

        $raw = safeJsonDecode($row['raw_json']);
        if (!$raw) {
            $notFound++;
            continue;
        }

        $name = extractDriverOrJockeyName($raw);

        if ($name) {
            $stmtUpdate->execute([
                ':driver_jockey' => $name,
                ':id' => $row['id']
            ]);

            $updated++;
            $summary[] = [
                'date_course' => $row['date_course'],
                'reunion' => $row['reunion'],
                'course' => $row['course'],
                'num_pmu' => $row['num_pmu'],
                'nom' => $row['nom'],
                'driver_jockey' => $name
            ];
        } else {
            $notFound++;
        }
    }

    echo json_encode([
        'success' => true,
        'date_filter' => $date,
        'updated' => $updated,
        'already_filled' => $alreadyFilled,
        'not_found' => $notFound,
        'summary' => $summary
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}