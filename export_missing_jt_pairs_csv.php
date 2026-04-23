<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
        SELECT 1
        FROM jt_reference
        WHERE driver_jockey_norm = :driver
          AND entraineur_norm = :entraineur
        LIMIT 1
    ");

    $missing = [];

    foreach ($rows as $r) {
        $driverNorm = normalizeName($r['driver_jockey']);
        $entraineurNorm = normalizeName($r['entraineur']);

        $stmtJT->execute([
            ':driver' => $driverNorm,
            ':entraineur' => $entraineurNorm
        ]);

        $exists = $stmtJT->fetchColumn();

        if (!$exists) {
            $missing[] = [
                'driver_jockey' => $r['driver_jockey'],
                'entraineur' => $r['entraineur'],
                'alpha_score' => '',
                'quintile' => ''
            ];
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="missing_jt_pairs_' . $date . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['driver_jockey', 'entraineur', 'alpha_score', 'quintile'], ';');

    foreach ($missing as $m) {
        fputcsv($output, $m, ';');
    }

    fclose($output);
    exit;

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}