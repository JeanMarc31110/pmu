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

function parseNumeric($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float)$value;
    }

    if (is_array($value)) {
        // cas fréquent : { "rapport": 650, ... } ou similaire
        if (isset($value['rapport']) && is_numeric($value['rapport'])) {
            $v = (float)$value['rapport'];

            // si rapport en centimes, on le ramène en cote simple
            if ($v > 100) {
                return round($v / 100, 2);
            }

            return $v;
        }

        if (isset($value['valeur']) && is_numeric($value['valeur'])) {
            return (float)$value['valeur'];
        }

        return null;
    }

    $clean = str_replace([' ', ','], ['', '.'], (string)$value);
    return is_numeric($clean) ? (float)$clean : null;
}

function extractOddFromRaw(array $raw): ?float
{
    $direct = $raw['dernierRapportDirect'] ?? null;
    $reference = $raw['dernierRapportReference'] ?? null;

    $odd = parseNumeric($direct);
    if ($odd !== null) {
        return $odd;
    }

    $odd = parseNumeric($reference);
    if ($odd !== null) {
        return $odd;
    }

    return null;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    $reunion = $_GET['reunion'] ?? null;
    $course = $_GET['course'] ?? null;

    if (!$date || !$reunion || !$course) {
        throw new Exception("Paramètres requis : date, reunion, course");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS market_odds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            num_pmu INTEGER NOT NULL,
            cote_probable REAL NOT NULL,
            captured_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $stmt = $pdo->prepare("
        SELECT
            num_pmu,
            nom,
            raw_json
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

    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtInsert = $pdo->prepare("
        INSERT INTO market_odds (
            date_course, reunion, course, num_pmu, cote_probable, captured_at
        ) VALUES (
            :date_course, :reunion, :course, :num_pmu, :cote_probable, CURRENT_TIMESTAMP
        )
    ");

    $inserted = 0;
    $notFound = 0;
    $summary = [];

    foreach ($participants as $p) {
        $raw = safeJsonDecode($p['raw_json']);
        if (!$raw) {
            $notFound++;
            continue;
        }

        $odd = extractOddFromRaw($raw);

        if ($odd === null) {
            $notFound++;
            $summary[] = [
                'num_pmu' => $p['num_pmu'],
                'nom' => $p['nom'],
                'cote_probable' => null,
                'status' => 'not_found'
            ];
            continue;
        }

        $stmtInsert->execute([
            ':date_course' => $date,
            ':reunion' => $reunion,
            ':course' => $course,
            ':num_pmu' => (int)$p['num_pmu'],
            ':cote_probable' => (float)$odd
        ]);

        $inserted++;
        $summary[] = [
            'num_pmu' => $p['num_pmu'],
            'nom' => $p['nom'],
            'cote_probable' => $odd,
            'status' => 'inserted'
        ];
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'reunion' => $reunion,
        'course' => $course,
        'inserted' => $inserted,
        'not_found' => $notFound,
        'summary' => $summary
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}