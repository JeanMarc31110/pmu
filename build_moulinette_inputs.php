<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

function parseNumeric($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float)$value;
    }

    $clean = str_replace([' ', ','], ['', '.'], (string)$value);
    return is_numeric($clean) ? (float)$clean : null;
}

function normalizeName(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim(mb_strtoupper($value, 'UTF-8'));
    $value = preg_replace('/\s+/', ' ', $value);

    return $value === '' ? null : $value;
}

function deriveProfilFromCote(?float $cote): ?int
{
    if ($cote === null) {
        return null;
    }

    if ($cote >= 5.0 && $cote <= 8.0) {
        return 1;
    }

    if ($cote >= 3.0 && $cote < 5.0) {
        return 2;
    }

    if ($cote >= 2.0 && $cote < 3.0) {
        return 3;
    }

    return null;
}

function json_response(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    $reunion = $_GET['reunion'] ?? null;
    $course = $_GET['course'] ?? null;

    if (!$date) {
        throw new Exception("Paramètre requis : date");
    }

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS moulinette_inputs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            num_pmu INTEGER NOT NULL,
            nom TEXT,
            driver_jockey TEXT,
            entraineur TEXT,
            alpha_score REAL,
            quintile TEXT,
            profil INTEGER,
            jt_score REAL,
            cote_probable REAL,
            valeur_handicap REAL,
            qualifie_q5 INTEGER DEFAULT 0,
            qualifie_value INTEGER DEFAULT 0,
            qualifie_profil INTEGER DEFAULT 0,
            qualifie_final INTEGER DEFAULT 0,
            source_mode TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date_course, reunion, course, num_pmu)
        );
    ");

    $sql = "
        SELECT
            p.date_course,
            p.reunion,
            p.course,
            p.num_pmu,
            p.nom,
            p.driver_jockey,
            p.entraineur,
            p.handicap_valeur,
            p.non_partant
        FROM participants p
        WHERE p.date_course = :date
    ";

    $params = [':date' => $date];

    if ($reunion) {
        $sql .= " AND p.reunion = :reunion";
        $params[':reunion'] = $reunion;
    }

    if ($course) {
        $sql .= " AND p.course = :course";
        $params[':course'] = $course;
    }

    $sql .= " ORDER BY p.reunion, p.course, p.num_pmu";

    $stmtParticipants = $pdo->prepare($sql);
    $stmtParticipants->execute($params);
    $participants = $stmtParticipants->fetchAll(PDO::FETCH_ASSOC);

    $stmtJT = $pdo->prepare("
        SELECT alpha_score, quintile
        FROM jt_reference
        WHERE driver_jockey_norm = :driver_jockey_norm
          AND entraineur_norm = :entraineur_norm
        LIMIT 1
    ");

    $stmtOdds = $pdo->prepare("
        SELECT cote_probable
        FROM market_odds
        WHERE date_course = :date_course
          AND reunion = :reunion
          AND course = :course
          AND num_pmu = :num_pmu
        ORDER BY datetime(captured_at) DESC, id DESC
        LIMIT 1
    ");

    $stmtUpsert = $pdo->prepare("
        INSERT INTO moulinette_inputs (
            date_course, reunion, course, num_pmu, nom, driver_jockey, entraineur,
            alpha_score, quintile, profil, jt_score, cote_probable, valeur_handicap,
            qualifie_q5, qualifie_value, qualifie_profil, qualifie_final, source_mode, updated_at
        ) VALUES (
            :date_course, :reunion, :course, :num_pmu, :nom, :driver_jockey, :entraineur,
            :alpha_score, :quintile, :profil, :jt_score, :cote_probable, :valeur_handicap,
            :qualifie_q5, :qualifie_value, :qualifie_profil, :qualifie_final, :source_mode, CURRENT_TIMESTAMP
        )
        ON CONFLICT(date_course, reunion, course, num_pmu) DO UPDATE SET
            nom = excluded.nom,
            driver_jockey = excluded.driver_jockey,
            entraineur = excluded.entraineur,
            alpha_score = excluded.alpha_score,
            quintile = excluded.quintile,
            profil = excluded.profil,
            jt_score = excluded.jt_score,
            cote_probable = excluded.cote_probable,
            valeur_handicap = excluded.valeur_handicap,
            qualifie_q5 = excluded.qualifie_q5,
            qualifie_value = excluded.qualifie_value,
            qualifie_profil = excluded.qualifie_profil,
            qualifie_final = excluded.qualifie_final,
            source_mode = excluded.source_mode,
            updated_at = CURRENT_TIMESTAMP
    ");

    $rowsUpserted = 0;
    $stats = [
        'participants_total' => 0,
        'non_partants_exclus' => 0,
        'jt_reference_missing' => 0,
        'odds_missing' => 0,
        'qualifies_q5' => 0,
        'qualifies_value' => 0,
        'qualifies_profile' => 0,
        'qualifies_final' => 0
    ];
    $summary = [];

    foreach ($participants as $p) {
        $stats['participants_total']++;

        if (!empty($p['non_partant'])) {
            $stats['non_partants_exclus']++;
            continue;
        }

        $driverNorm = normalizeName($p['driver_jockey']);
        $entraineurNorm = normalizeName($p['entraineur']);

        $alphaScore = null;
        $quintile = null;

        if ($driverNorm !== null && $entraineurNorm !== null) {
            $stmtJT->execute([
                ':driver_jockey_norm' => $driverNorm,
                ':entraineur_norm' => $entraineurNorm
            ]);
            $jt = $stmtJT->fetch(PDO::FETCH_ASSOC);

            if ($jt) {
                $alphaScore = parseNumeric($jt['alpha_score']);
                $quintile = $jt['quintile'];
            } else {
                $stats['jt_reference_missing']++;
            }
        } else {
            $stats['jt_reference_missing']++;
        }

        $stmtOdds->execute([
            ':date_course' => $p['date_course'],
            ':reunion' => $p['reunion'],
            ':course' => $p['course'],
            ':num_pmu' => $p['num_pmu']
        ]);
        $odds = $stmtOdds->fetch(PDO::FETCH_ASSOC);

        $coteProbable = null;
        if ($odds) {
            $coteProbable = parseNumeric($odds['cote_probable']);
        } else {
            $stats['odds_missing']++;
        }

        $valeurHandicap = parseNumeric($p['handicap_valeur']);
        $profil = deriveProfilFromCote($coteProbable);

        $qualifieQ5 = ($quintile === 'Q5') ? 1 : 0;
        $qualifieValue = ($coteProbable !== null && $coteProbable >= 2.0) ? 1 : 0;
        $qualifieProfil = ($profil !== null) ? 1 : 0;
        $qualifieFinal = ($qualifieQ5 && $qualifieValue && $qualifieProfil) ? 1 : 0;

        if ($qualifieQ5) $stats['qualifies_q5']++;
        if ($qualifieValue) $stats['qualifies_value']++;
        if ($qualifieProfil) $stats['qualifies_profile']++;
        if ($qualifieFinal) $stats['qualifies_final']++;

        $stmtUpsert->execute([
            ':date_course' => $p['date_course'],
            ':reunion' => $p['reunion'],
            ':course' => $p['course'],
            ':num_pmu' => $p['num_pmu'],
            ':nom' => $p['nom'],
            ':driver_jockey' => $p['driver_jockey'],
            ':entraineur' => $p['entraineur'],
            ':alpha_score' => $alphaScore,
            ':quintile' => $quintile,
            ':profil' => $profil,
            ':jt_score' => $alphaScore,
            ':cote_probable' => $coteProbable,
            ':valeur_handicap' => $valeurHandicap,
            ':qualifie_q5' => $qualifieQ5,
            ':qualifie_value' => $qualifieValue,
            ':qualifie_profil' => $qualifieProfil,
            ':qualifie_final' => $qualifieFinal,
            ':source_mode' => 'strict_method_2026'
        ]);

        if (count($summary) < 100) {
            $summary[] = [
                'reunion' => $p['reunion'],
                'course' => $p['course'],
                'num_pmu' => $p['num_pmu'],
                'nom' => $p['nom'],
                'driver_jockey' => $p['driver_jockey'],
                'entraineur' => $p['entraineur'],
                'alpha_score' => $alphaScore,
                'quintile' => $quintile,
                'cote_probable' => $coteProbable,
                'profil' => $profil,
                'valeur_handicap' => $valeurHandicap,
                'qualifie_final' => $qualifieFinal
            ];
        }

        $rowsUpserted++;
    }

    json_response([
        'success' => true,
        'date' => $date,
        'rows_upserted' => $rowsUpserted,
        'stats' => $stats,
        'summary' => $summary
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}