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
        if (isset($value['rapport']) && is_numeric($value['rapport'])) {
            $v = (float)$value['rapport'];
            return $v > 100 ? round($v / 100, 2) : $v;
        }

        if (isset($value['valeur']) && is_numeric($value['valeur'])) {
            return (float)$value['valeur'];
        }

        if (isset($value['rapportDirect']) && is_numeric($value['rapportDirect'])) {
            $v = (float)$value['rapportDirect'];
            return $v > 100 ? round($v / 100, 2) : $v;
        }

        if (isset($value['rapportReference']) && is_numeric($value['rapportReference'])) {
            $v = (float)$value['rapportReference'];
            return $v > 100 ? round($v / 100, 2) : $v;
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
    if (!$date) {
        throw new Exception("Paramètre requis : date");
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
            date_course,
            reunion,
            course,
            num_pmu,
            nom,
            raw_json
        FROM participants
        WHERE date_course = :date
        ORDER BY reunion, course, num_pmu
    ");

    $stmt->execute([':date' => $date]);
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
    $coursesTouched = [];
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
            continue;
        }

        $stmtInsert->execute([
            ':date_course' => $p['date_course'],
            ':reunion' => $p['reunion'],
            ':course' => $p['course'],
            ':num_pmu' => (int)$p['num_pmu'],
            ':cote_probable' => (float)$odd
        ]);

        $inserted++;
        $coursesTouched[$p['reunion'] . '_' . $p['course']] = true;

        if (count($summary) < 100) {
            $summary[] = [
                'reunion' => $p['reunion'],
                'course' => $p['course'],
                'num_pmu' => $p['num_pmu'],
                'nom' => $p['nom'],
                'cote_probable' => $odd
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'inserted' => $inserted,
        'not_found' => $notFound,
        'courses_touched' => count($coursesTouched),
        'summary' => $summary
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}