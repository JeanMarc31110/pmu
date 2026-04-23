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

function safeJsonDecode(?string $value): ?array
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function extractOrdreArrivee(array $raw): ?int
{
    if (!array_key_exists('ordreArrivee', $raw)) {
        return null;
    }

    $v = $raw['ordreArrivee'];

    if ($v === null || $v === '') {
        return null;
    }

    if (is_numeric($v)) {
        return (int)$v;
    }

    return null;
}

function computeAlphaScore(int $nbCourses, int $wins, int $places23, int $places45): float
{
    if ($nbCourses <= 0) {
        return 0.0;
    }

    // Formule simple, stable, et directement exploitable
    return round(((5 * $wins) + (3 * $places23) + (1 * $places45)) / $nbCourses, 4);
}

function quintileLabelFromRank(int $index, int $total): string
{
    if ($total <= 0) {
        return 'Q1';
    }

    $ratio = ($index + 1) / $total;

    if ($ratio <= 0.20) return 'Q5';
    if ($ratio <= 0.40) return 'Q4';
    if ($ratio <= 0.60) return 'Q3';
    if ($ratio <= 0.80) return 'Q2';
    return 'Q1';
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $minCourses = isset($_GET['min_courses']) ? max(1, (int)$_GET['min_courses']) : 3;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jt_reference (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_jockey_norm TEXT NOT NULL,
            entraineur_norm TEXT NOT NULL,
            alpha_score REAL NOT NULL,
            quintile TEXT NOT NULL,
            source_label TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(driver_jockey_norm, entraineur_norm)
        );
    ");

    $stmt = $pdo->query("
        SELECT
            driver_jockey,
            entraineur,
            raw_json
        FROM participants
        WHERE driver_jockey IS NOT NULL
          AND entraineur IS NOT NULL
          AND raw_json IS NOT NULL
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $agg = [];

    foreach ($rows as $row) {
        $driver = normalizeName($row['driver_jockey']);
        $entraineur = normalizeName($row['entraineur']);

        if ($driver === null || $entraineur === null) {
            continue;
        }

        $raw = safeJsonDecode($row['raw_json']);
        if (!$raw) {
            continue;
        }

        $ordre = extractOrdreArrivee($raw);

        $key = $driver . '||' . $entraineur;

        if (!isset($agg[$key])) {
            $agg[$key] = [
                'driver_jockey_norm' => $driver,
                'entraineur_norm' => $entraineur,
                'nb_courses' => 0,
                'wins' => 0,
                'places23' => 0,
                'places45' => 0
            ];
        }

        $agg[$key]['nb_courses']++;

        if ($ordre === 1) {
            $agg[$key]['wins']++;
        } elseif ($ordre === 2 || $ordre === 3) {
            $agg[$key]['places23']++;
        } elseif ($ordre === 4 || $ordre === 5) {
            $agg[$key]['places45']++;
        }
    }

    // on filtre les couples trop peu représentés
    $couples = array_values(array_filter($agg, function ($r) use ($minCourses) {
        return $r['nb_courses'] >= $minCourses;
    }));

    foreach ($couples as &$c) {
        $c['alpha_score'] = computeAlphaScore(
            $c['nb_courses'],
            $c['wins'],
            $c['places23'],
            $c['places45']
        );
    }
    unset($c);

    // tri décroissant par alpha_score, puis nb_courses
    usort($couples, function ($a, $b) {
        if ($a['alpha_score'] !== $b['alpha_score']) {
            return $b['alpha_score'] <=> $a['alpha_score'];
        }
        return $b['nb_courses'] <=> $a['nb_courses'];
    });

    $total = count($couples);

    foreach ($couples as $i => &$c) {
        $c['quintile'] = quintileLabelFromRank($i, $total);
    }
    unset($c);

    $stmtUpsert = $pdo->prepare("
        INSERT INTO jt_reference (
            driver_jockey_norm, entraineur_norm, alpha_score, quintile, source_label, updated_at
        ) VALUES (
            :driver_jockey_norm, :entraineur_norm, :alpha_score, :quintile, :source_label, CURRENT_TIMESTAMP
        )
        ON CONFLICT(driver_jockey_norm, entraineur_norm) DO UPDATE SET
            alpha_score = excluded.alpha_score,
            quintile = excluded.quintile,
            source_label = excluded.source_label,
            updated_at = CURRENT_TIMESTAMP
    ");

    $count = 0;
    $preview = [];

    foreach ($couples as $c) {
        $stmtUpsert->execute([
            ':driver_jockey_norm' => $c['driver_jockey_norm'],
            ':entraineur_norm' => $c['entraineur_norm'],
            ':alpha_score' => $c['alpha_score'],
            ':quintile' => $c['quintile'],
            ':source_label' => 'derived_from_participants_base'
        ]);

        if ($count < 50) {
            $preview[] = [
                'driver_jockey_norm' => $c['driver_jockey_norm'],
                'entraineur_norm' => $c['entraineur_norm'],
                'nb_courses' => $c['nb_courses'],
                'wins' => $c['wins'],
                'places23' => $c['places23'],
                'places45' => $c['places45'],
                'alpha_score' => $c['alpha_score'],
                'quintile' => $c['quintile']
            ];
        }

        $count++;
    }

    echo json_encode([
        'success' => true,
        'min_courses' => $minCourses,
        'participants_scanned' => count($rows),
        'jt_pairs_built' => $count,
        'preview' => $preview
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}