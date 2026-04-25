<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';
$historicalDbPath = 'C:/Users/rigai/Desktop/pmu_data/pmu.db';
const PMU_HISTORY_START_ISO = '2025-01-01';

require_once __DIR__ . '/method_config.php';

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

function deriveProfilFromCote(?float $cote, bool $expandedMethod = false): ?int
{
    return pmu_profile_rank_from_cote($cote, $expandedMethod);
}

function dateToIsoForHistory(string $date): ?string
{
    $ymd = pmu_date_to_ymd($date);
    if ($ymd === null) {
        return null;
    }

    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

function parseArrivalRank($value): ?int
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (int)$value;
}

function computeAlphaScore(int $nbCourses, int $wins, int $places23, int $places45): float
{
    if ($nbCourses <= 0) {
        return 0.0;
    }

    return round(((5 * $wins) + (3 * $places23) + $places45) / $nbCourses, 4);
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

function newScoreBucket(string $name): array
{
    return [
        'name' => $name,
        'nb_courses' => 0,
        'wins' => 0,
        'places23' => 0,
        'places45' => 0,
    ];
}

function addArrivalToBucket(array &$bucket, ?int $ordre): void
{
    $bucket['nb_courses']++;

    if ($ordre === 1) {
        $bucket['wins']++;
    } elseif ($ordre === 2 || $ordre === 3) {
        $bucket['places23']++;
    } elseif ($ordre === 4 || $ordre === 5) {
        $bucket['places45']++;
    }
}

function finalizeScoreReference(array $agg, int $minCourses = 3): array
{
    $items = array_values(array_filter($agg, function (array $row) use ($minCourses): bool {
        return $row['nb_courses'] >= $minCourses;
    }));

    foreach ($items as &$item) {
        $item['alpha_score'] = computeAlphaScore(
            $item['nb_courses'],
            $item['wins'],
            $item['places23'],
            $item['places45']
        );
    }
    unset($item);

    usort($items, function (array $a, array $b): int {
        if ($a['alpha_score'] !== $b['alpha_score']) {
            return $b['alpha_score'] <=> $a['alpha_score'];
        }
        return $b['nb_courses'] <=> $a['nb_courses'];
    });

    $reference = [];
    $total = count($items);
    foreach ($items as $index => $item) {
        $reference[$item['name']] = [
            'alpha_score' => $item['alpha_score'],
            'quintile' => quintileLabelFromRank($index, $total),
            'nb_courses' => $item['nb_courses'],
        ];
    }

    return $reference;
}

function buildTemporalJtReference(string $historicalDbPath, string $date, int $minCourses = 3): array
{
    if (!file_exists($historicalDbPath)) {
        throw new Exception("Base historique introuvable : " . $historicalDbPath);
    }

    $targetIso = dateToIsoForHistory($date);
    if ($targetIso === null) {
        throw new Exception("Date invalide pour l'historique : " . $date);
    }

    $history = new PDO('sqlite:' . $historicalDbPath);
    $history->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $history->prepare("
        SELECT
            nom,
            COALESCE(NULLIF(TRIM(driver), ''), NULLIF(TRIM(jockey), '')) AS driver_jockey,
            entraineur,
            ordre_arrivee,
            ordreArrivee
        FROM partants
        WHERE db_date_iso >= :history_start
          AND db_date_iso <= :target_date
          AND COALESCE(NULLIF(TRIM(driver), ''), NULLIF(TRIM(jockey), '')) IS NOT NULL
          AND NULLIF(TRIM(entraineur), '') IS NOT NULL
    ");
    $stmt->execute([
        ':history_start' => PMU_HISTORY_START_ISO,
        ':target_date' => $targetIso,
    ]);

    $pairAgg = [];
    $horseAgg = [];
    $jockeyAgg = [];
    $trainerAgg = [];
    $rowsScanned = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rowsScanned++;
        $horse = normalizeName($row['nom'] ?? null);
        $driver = normalizeName($row['driver_jockey'] ?? null);
        $entraineur = normalizeName($row['entraineur'] ?? null);
        $ordre = parseArrivalRank($row['ordre_arrivee'] ?? null) ?? parseArrivalRank($row['ordreArrivee'] ?? null);

        if ($horse !== null) {
            if (!isset($horseAgg[$horse])) {
                $horseAgg[$horse] = newScoreBucket($horse);
            }
            addArrivalToBucket($horseAgg[$horse], $ordre);
        }

        if ($driver !== null) {
            if (!isset($jockeyAgg[$driver])) {
                $jockeyAgg[$driver] = newScoreBucket($driver);
            }
            addArrivalToBucket($jockeyAgg[$driver], $ordre);
        }

        if ($entraineur !== null) {
            if (!isset($trainerAgg[$entraineur])) {
                $trainerAgg[$entraineur] = newScoreBucket($entraineur);
            }
            addArrivalToBucket($trainerAgg[$entraineur], $ordre);
        }

        if ($driver !== null && $entraineur !== null) {
            $key = $driver . '||' . $entraineur;
            if (!isset($pairAgg[$key])) {
                $pairAgg[$key] = newScoreBucket($key);
            }
            addArrivalToBucket($pairAgg[$key], $ordre);
        }
    }

    $pairReference = finalizeScoreReference($pairAgg, $minCourses);
    $horseReference = finalizeScoreReference($horseAgg, $minCourses);
    $jockeyReference = finalizeScoreReference($jockeyAgg, $minCourses);
    $trainerReference = finalizeScoreReference($trainerAgg, $minCourses);

    return [
        'target_iso' => $targetIso,
        'history_start_iso' => PMU_HISTORY_START_ISO,
        'rows_scanned' => $rowsScanned,
        'pairs_built' => count($pairReference),
        'horses_built' => count($horseReference),
        'jockeys_built' => count($jockeyReference),
        'trainers_built' => count($trainerReference),
        'reference' => $pairReference,
        'horse_reference' => $horseReference,
        'jockey_reference' => $jockeyReference,
        'trainer_reference' => $trainerReference,
    ];
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
    $columns = array_map(
        fn(array $row): string => (string)$row['name'],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN " . $column . " " . $definition);
    }
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
    $expandedMethod = pmu_uses_expanded_q5_method((string)$date);
    $jtReference = buildTemporalJtReference($historicalDbPath, (string)$date);

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
    ensureColumn($pdo, 'moulinette_inputs', 'cheval_score', 'REAL');
    ensureColumn($pdo, 'moulinette_inputs', 'jockey_score', 'REAL');
    ensureColumn($pdo, 'moulinette_inputs', 'entraineur_score', 'REAL');

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
            alpha_score, quintile, profil, jt_score, cheval_score, jockey_score, entraineur_score, cote_probable, valeur_handicap,
            qualifie_q5, qualifie_value, qualifie_profil, qualifie_final, source_mode, updated_at
        ) VALUES (
            :date_course, :reunion, :course, :num_pmu, :nom, :driver_jockey, :entraineur,
            :alpha_score, :quintile, :profil, :jt_score, :cheval_score, :jockey_score, :entraineur_score, :cote_probable, :valeur_handicap,
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
            cheval_score = excluded.cheval_score,
            jockey_score = excluded.jockey_score,
            entraineur_score = excluded.entraineur_score,
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
        'cheval_reference_missing' => 0,
        'jockey_reference_missing' => 0,
        'entraineur_reference_missing' => 0,
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
        $chevalNorm = normalizeName($p['nom']);

        $alphaScore = null;
        $quintile = null;
        $chevalScore = null;
        $jockeyScore = null;
        $entraineurScore = null;

        if ($chevalNorm !== null) {
            $horse = $jtReference['horse_reference'][$chevalNorm] ?? null;
            if ($horse) {
                $chevalScore = parseNumeric($horse['alpha_score']);
            } else {
                $stats['cheval_reference_missing']++;
            }
        } else {
            $stats['cheval_reference_missing']++;
        }

        if ($driverNorm !== null) {
            $jockey = $jtReference['jockey_reference'][$driverNorm] ?? null;
            if ($jockey) {
                $jockeyScore = parseNumeric($jockey['alpha_score']);
            } else {
                $stats['jockey_reference_missing']++;
            }
        } else {
            $stats['jockey_reference_missing']++;
        }

        if ($entraineurNorm !== null) {
            $trainer = $jtReference['trainer_reference'][$entraineurNorm] ?? null;
            if ($trainer) {
                $entraineurScore = parseNumeric($trainer['alpha_score']);
            } else {
                $stats['entraineur_reference_missing']++;
            }
        } else {
            $stats['entraineur_reference_missing']++;
        }

        if ($driverNorm !== null && $entraineurNorm !== null) {
            $jt = $jtReference['reference'][$driverNorm . '||' . $entraineurNorm] ?? null;

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
        $profil = deriveProfilFromCote($coteProbable, $expandedMethod);

        $qualifieQ5 = ($quintile === 'Q5') ? 1 : 0;
        $qualifieValue = (
            $coteProbable !== null
            && $coteProbable >= 2.0
            && (!$expandedMethod || $coteProbable <= 20.0)
        ) ? 1 : 0;
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
            ':cheval_score' => $chevalScore,
            ':jockey_score' => $jockeyScore,
            ':entraineur_score' => $entraineurScore,
            ':cote_probable' => $coteProbable,
            ':valeur_handicap' => $valeurHandicap,
            ':qualifie_q5' => $qualifieQ5,
            ':qualifie_value' => $qualifieValue,
            ':qualifie_profil' => $qualifieProfil,
            ':qualifie_final' => $qualifieFinal,
            ':source_mode' => $expandedMethod ? PMU_EXPANDED_Q5_SOURCE_MODE : 'strict_method_2026'
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
                'cheval_score' => $chevalScore,
                'jockey_score' => $jockeyScore,
                'entraineur_score' => $entraineurScore,
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
        'method' => $expandedMethod ? PMU_EXPANDED_Q5_SOURCE_MODE : 'strict_method_2026',
        'jt_reference_source' => [
            'db_path' => $historicalDbPath,
            'history_start_iso' => $jtReference['history_start_iso'],
            'target_iso_included' => $jtReference['target_iso'],
            'rows_scanned' => $jtReference['rows_scanned'],
            'pairs_built' => $jtReference['pairs_built'],
            'horses_built' => $jtReference['horses_built'],
            'jockeys_built' => $jtReference['jockeys_built'],
            'trainers_built' => $jtReference['trainers_built'],
        ],
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
