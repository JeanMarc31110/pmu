<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

function normalizeName(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim(mb_strtoupper($value, 'UTF-8'));
    $value = preg_replace('/\s+/', ' ', $value);

    return $value === '' ? null : $value;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;

    if (!$date) {
        throw new Exception("Paramètre requis : date");
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            driver_jockey,
            entraineur
        FROM participants
        WHERE date_course = :date
          AND driver_jockey IS NOT NULL
          AND entraineur IS NOT NULL
        ORDER BY driver_jockey, entraineur
    ");

    $stmt->execute([':date' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtJT = $pdo->prepare("
        SELECT alpha_score, quintile
        FROM jt_reference
        WHERE driver_jockey_norm = :driver
          AND entraineur_norm = :entraineur
        LIMIT 1
    ");

    $missing = [];
    $existing = [];

    foreach ($rows as $r) {
        $driverNorm = normalizeName($r['driver_jockey']);
        $entraineurNorm = normalizeName($r['entraineur']);

        $stmtJT->execute([
            ':driver' => $driverNorm,
            ':entraineur' => $entraineurNorm
        ]);

        $jt = $stmtJT->fetch(PDO::FETCH_ASSOC);

        if ($jt) {
            $existing[] = [
                'driver_jockey' => $r['driver_jockey'],
                'entraineur' => $r['entraineur'],
                'alpha_score' => $jt['alpha_score'],
                'quintile' => $jt['quintile']
            ];
        } else {
            $missing[] = [
                'driver_jockey' => $r['driver_jockey'],
                'entraineur' => $r['entraineur']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'existing_count' => count($existing),
        'missing_count' => count($missing),
        'existing' => $existing,
        'missing' => $missing
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}