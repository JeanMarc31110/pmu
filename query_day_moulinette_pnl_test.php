<?php

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

function json_response(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function safeJsonDecode(?string $value): ?array
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function extractSimpleRapport(?string $rawJson): ?float
{
    $raw = safeJsonDecode($rawJson);
    if (!$raw) {
        return null;
    }
    foreach (['dernierRapportDirect', 'dernierRapportReference'] as $key) {
        $report = $raw[$key] ?? null;
        if (is_array($report) && isset($report['rapport']) && is_numeric($report['rapport'])) {
            return (float)$report['rapport'];
        }
    }
    return null;
}

function extractOrdreArrivee(?string $rawJson): ?int
{
    $raw = safeJsonDecode($rawJson);
    if (!$raw) {
        return null;
    }
    $incident = strtoupper((string)($raw['incident'] ?? ''));
    if (str_contains($incident, 'DISQUALIFIE') || str_contains($incident, 'DISTANCE') || str_contains($incident, 'TOMBE')) {
        return 99;
    }
    foreach (['ordreArrivee', 'ordre_arrivee'] as $key) {
        if (isset($raw[$key]) && is_numeric($raw[$key])) {
            return (int)$raw[$key];
        }
    }
    return null;
}

function extractCourseOrder(?string $rawJson): ?string
{
    $raw = safeJsonDecode($rawJson);
    if (!$raw) {
        return null;
    }
    $order = $raw['ordreArrivee'] ?? $raw['ordre_arrivee'] ?? null;
    if (!is_array($order) || empty($order)) {
        return null;
    }
    $values = [];
    foreach ($order as $item) {
        $cell = is_array($item) ? $item : [$item];
        $values[] = implode('-', array_map('strval', $cell));
    }
    return implode(' | ', array_filter($values, static fn($value) => $value !== ''));
}

function extractSelectedOrderFromCourseRaw(?string $rawJson, int $numPmu): ?int
{
    $raw = safeJsonDecode($rawJson);
    if (!$raw) {
        return null;
    }
    $order = $raw['ordreArrivee'] ?? $raw['ordre_arrivee'] ?? null;
    if (!is_array($order) || empty($order)) {
        return null;
    }
    foreach ($order as $index => $item) {
        $values = is_array($item) ? $item : [$item];
        foreach ($values as $value) {
            if ((int)$value === $numPmu) {
                return (int)$index + 1;
            }
        }
    }
    return null;
}

try {
    $date = $_GET['date'] ?? null;
    $capital = isset($_GET['capital']) ? (float)$_GET['capital'] : 100.00;
    if (!$date || !preg_match('/^\d{8}$/', (string)$date)) {
        throw new Exception('Paramètre requis : date au format JJMMAAAA');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS d10_test_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            libelle TEXT,
            heure_depart TEXT,
            minutes_left INTEGER,
            selection_json TEXT,
            captured_at TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'test_live_d10_moulinette',
            UNIQUE(date_course, reunion, course)
        )
    ");

    $stmtCourses = $pdo->prepare("
        SELECT
            c.date_course,
            c.reunion,
            c.course,
            c.libelle,
            c.heure_depart,
            c.raw_json AS course_raw,
            a.raw_json AS arrivee_raw,
            t.selection_json,
            t.captured_at,
            t.minutes_left,
            t.source
        FROM courses c
        LEFT JOIN arrivees a
          ON a.date_course = c.date_course
         AND a.reunion = c.reunion
         AND a.course = c.course
        LEFT JOIN d10_test_tickets t
          ON t.date_course = c.date_course
         AND t.reunion = c.reunion
         AND t.course = c.course
        WHERE c.date_course = :date
        ORDER BY c.heure_depart ASC NULLS LAST, c.reunion, c.course
    ");
    $stmtCourses->execute([':date' => $date]);
    $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

    $stmtParticipant = $pdo->prepare("
        SELECT raw_json
        FROM participants
        WHERE date_course = :date
          AND reunion = :reunion
          AND course = :course
          AND num_pmu = :num_pmu
        LIMIT 1
    ");

    $details = [];
    $nbParis = 0;
    $nbParisTest = 0;
    $nbParisEnAttente = 0;
    $nbGagnants = 0;
    $totalMise = 0.0;
    $totalNet = 0.0;

    foreach ($courses as $course) {
        $resultRaw = $course['arrivee_raw'] ?? $course['course_raw'] ?? null;
        $courseOrder = extractCourseOrder($resultRaw);
        $heureLabel = null;
        if (!empty($course['heure_depart'])) {
            $heureLabel = (new DateTime('@' . intdiv((int)$course['heure_depart'], 1000)))
                ->setTimezone(new DateTimeZone('Europe/Paris'))
                ->format('H:i');
        }

        $selection = safeJsonDecode($course['selection_json'] ?? null);
        if (!$selection || !isset($selection['num'])) {
            $details[] = [
                'reunion' => $course['reunion'],
                'course' => $course['course'],
                'libelle' => $course['libelle'],
                'heure_depart' => $heureLabel,
                'selection' => null,
                'selection_source' => 'ABSTENTION',
                'ordre_arrivee' => $courseOrder,
                'mise' => 0,
                'resultat_net' => 0,
                'statut' => 'ABSTENTION',
            ];
            continue;
        }

        $mise = 1.0;
        $nbParisTest++;
        $num = (int)$selection['num'];
        $stmtParticipant->execute([
            ':date' => $course['date_course'],
            ':reunion' => $course['reunion'],
            ':course' => $course['course'],
            ':num_pmu' => $num,
        ]);
        $participant = $stmtParticipant->fetch(PDO::FETCH_ASSOC);
        $ordreArrivee = extractSelectedOrderFromCourseRaw($resultRaw, $num);
        if ($ordreArrivee === null && $participant) {
            $ordreArrivee = extractOrdreArrivee($participant['raw_json']);
        }

        if ($ordreArrivee === 1) {
            $rapport = extractSimpleRapport($participant['raw_json'] ?? null);
            if ($rapport === null) {
                $statut = 'RESULTAT_INCONNU';
                $resultatNet = 0.0;
                $nbParisEnAttente++;
            } else {
                $statut = 'GAGNE';
                $resultatNet = round($mise * $rapport - $mise, 2);
                $nbGagnants++;
            }
        } elseif ($ordreArrivee === null) {
            $statut = 'RESULTAT_INCONNU';
            $resultatNet = 0.0;
            $nbParisEnAttente++;
        } else {
            $statut = 'PERDU';
            $resultatNet = -$mise;
        }

        if ($statut !== 'RESULTAT_INCONNU') {
            $nbParis++;
            $totalMise += $mise;
            $totalNet += $resultatNet;
        }

        $details[] = [
            'reunion' => $course['reunion'],
            'course' => $course['course'],
            'libelle' => $course['libelle'],
            'heure_depart' => $heureLabel,
            'selection' => [
                'num' => $num,
                'nom' => (string)($selection['nom'] ?? ''),
                'profil' => $selection['profil'] ?? null,
                'jt_score' => $selection['jt_score'] ?? null,
                'cote' => $selection['cote'] ?? null,
            ],
            'selection_source' => $course['source'] ?: 'test_live_d10_moulinette',
            'captured_at' => $course['captured_at'],
            'minutes_left' => $course['minutes_left'],
            'ordre_arrivee' => $ordreArrivee,
            'mise' => round($mise, 2),
            'resultat_net' => round($resultatNet, 2),
            'statut' => $statut,
        ];
    }

    json_response([
        'success' => true,
        'mode' => 'test_live_d10_no_snapshot',
        'date' => $date,
        'bankroll_depart' => $capital,
        'nb_paris' => $nbParis,
        'nb_paris_test_d10' => $nbParisTest,
        'nb_paris_resultat_connu' => $nbParis,
        'nb_paris_en_attente' => $nbParisEnAttente,
        'nb_gagnants' => $nbGagnants,
        'total_mise' => round($totalMise, 2),
        'resultat_net_total' => round($totalNet, 2),
        'bankroll_fin_theorique' => round($capital + $totalNet, 2),
        'details' => $details,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'mode' => 'test_live_d10_no_snapshot',
        'message' => $e->getMessage(),
    ]);
}
