<?php

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';
require_once __DIR__ . '/method_config.php';

class MoulinettePMU2026PHP
{
    public float $bankroll;
    public float $roi_estime = 0.4603;
    public float $fraction_kelly = 0.5;
    public float $cote_seuil_min = 2.0;
    public float $max_exposition = 0.10;

    public function __construct(float $capital_initial = 100.00)
    {
        $this->bankroll = $capital_initial;
    }

    public function calculerMise(float $coteProbable): float
    {
        if ($coteProbable < $this->cote_seuil_min) {
            return 0.0;
        }

        $b = $coteProbable - 1.0;
        $p = (1.0 + $this->roi_estime) / $coteProbable;
        $fStar = ($p * ($b + 1.0) - 1.0) / $b;

        $miseSuggeree = $this->bankroll * $fStar * $this->fraction_kelly;
        $limiteHaute = $this->bankroll * $this->max_exposition;
        $miseFinale = max(min($miseSuggeree, $limiteHaute), 1.0);

        return (float) max(round($miseFinale), 1);
    }

    public function arbitrageSelection(array $chevauxQualifies): ?array
    {
        if (empty($chevauxQualifies)) {
            return null;
        }

        usort($chevauxQualifies, function ($a, $b) {
            if ($a['profil'] !== $b['profil']) {
                return $a['profil'] <=> $b['profil'];
            }

            if ($a['jt_score'] !== $b['jt_score']) {
                return $b['jt_score'] <=> $a['jt_score'];
            }

            $ha = $a['valeur_handicap'] ?? -INF;
            $hb = $b['valeur_handicap'] ?? -INF;
            return $hb <=> $ha;
        });

        return $chevauxQualifies[0];
    }
}

function safeJsonDecode(?string $value): ?array
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function extractOrdreArrivee(?string $rawJson): ?int
{
    $raw = safeJsonDecode($rawJson);
    if (!$raw) {
        return null;
    }

    // Cheval disqualifié ou distancé → compté comme défaite (ordre != 1)
    if (isset($raw['incident'])) {
        $incident = strtoupper((string)$raw['incident']);
        if (str_contains($incident, 'DISQUALIFIE') || str_contains($incident, 'DISTANCE') || str_contains($incident, 'TOMBE')) {
            return 99; // valeur arbitraire != 1 → PERDU
        }
    }

    if (isset($raw['ordreArrivee']) && is_numeric($raw['ordreArrivee'])) {
        return (int)$raw['ordreArrivee'];
    }

    if (isset($raw['ordre_arrivee']) && is_numeric($raw['ordre_arrivee'])) {
        return (int)$raw['ordre_arrivee'];
    }

    return null;
}

function extractSimpleRapport(?string $rawJson): ?float
{
    $raw = safeJsonDecode($rawJson);
    if (!$raw) {
        return null;
    }

    $direct = $raw['dernierRapportDirect'] ?? null;
    if (is_array($direct) && isset($direct['rapport']) && is_numeric($direct['rapport'])) {
        return (float)$direct['rapport'];
    }

    $reference = $raw['dernierRapportReference'] ?? null;
    if (is_array($reference) && isset($reference['rapport']) && is_numeric($reference['rapport'])) {
        return (float)$reference['rapport'];
    }

    return null;
}

function deriveProfilFromCote(?float $cote, bool $expandedMethod = false): ?int
{
    return pmu_profile_rank_from_cote($cote, $expandedMethod);
}

function buildSelectionFromRows(array $rows, MoulinettePMU2026PHP $moulinette, bool $expandedMethod = false): ?array
{
    $qualifies = [];
    foreach ($rows as $row) {
        if ((int)($row['qualifie_final'] ?? 0) !== 1) {
            continue;
        }

        $cote = extractSimpleRapport($row['participant_raw'] ?? null);
        if (!is_numeric($cote)) {
            continue;
        }

        $profil = deriveProfilFromCote((float)$cote, $expandedMethod);
        if ($profil === null) {
            continue;
        }

        $qualifies[] = [
            'num' => (int)$row['num_pmu'],
            'nom' => (string)($row['nom'] ?? ''),
            'profil' => $profil,
            'jt_score' => (float)($row['jt_score'] ?? 0),
            'cote' => (float)$cote,
            'valeur_handicap' => $row['valeur_handicap'] !== null ? (float)$row['valeur_handicap'] : null,
        ];
    }

    return $moulinette->arbitrageSelection($qualifies);
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
        if (is_array($item)) {
            if (!empty($item)) {
                $values[] = implode('-', array_map('strval', $item));
            }
            continue;
        }
        if ($item !== null && $item !== '') {
            $values[] = (string)$item;
        }
    }

    if (empty($values)) {
        return null;
    }

    return implode(' | ', $values);
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

function fetchOfficialSimpleRapportFromApi(string $dateCourse, string $reunion, string $course, int $numPmu): ?float
{
    $url = sprintf(
        'https://online.turfinfo.api.pmu.fr/rest/client/61/programme/%s/%s/%s/participants?specialisation=INTERNET',
        rawurlencode($dateCourse),
        rawurlencode($reunion),
        rawurlencode($course)
    );

    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        return null;
    }

    $data = safeJsonDecode($json);
    if (!$data || empty($data['participants']) || !is_array($data['participants'])) {
        return null;
    }

    foreach ($data['participants'] as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        if ((int)($participant['numPmu'] ?? 0) !== $numPmu) {
            continue;
        }

        $direct = $participant['dernierRapportDirect'] ?? null;
        if (is_array($direct) && isset($direct['rapport']) && is_numeric($direct['rapport'])) {
            return (float)$direct['rapport'];
        }

        $reference = $participant['dernierRapportReference'] ?? null;
        if (is_array($reference) && isset($reference['rapport']) && is_numeric($reference['rapport'])) {
            return (float)$reference['rapport'];
        }

        return null;
    }

    return null;
}

function loadD10AnalysisSnapshot(string $date): array
{
    $normalizedDate = normalizeDateForLog($date);
    $snapshotPath = __DIR__ . '/data/d10_analysis_snapshot_' . $normalizedDate . '.json';
    if (!is_file($snapshotPath)) {
        return [];
    }

    $raw = @file_get_contents($snapshotPath);
    if ($raw === false) {
        return [];
    }

    $snapshot = safeJsonDecode($raw);
    if (!$snapshot || empty($snapshot['courses']) || !is_array($snapshot['courses'])) {
        return [];
    }

    return $snapshot['courses'];
}

function normalizeDateForLog(string $date): string
{
    if (preg_match('/^\\d{8}$/', $date)) {
        $dt = DateTime::createFromFormat('dmY', $date, new DateTimeZone('Europe/Paris'));
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return $date;
}

function ensureD10TicketsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS d10_test_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            heure_depart INTEGER,
            selection_json TEXT,
            ticket TEXT,
            source TEXT NOT NULL,
            captured_at TEXT NOT NULL,
            UNIQUE(date_course, reunion, course)
        )
    ");
}

function loadD10DbTickets(PDO $pdo, string $date): array
{
    ensureD10TicketsTable($pdo);

    $stmt = $pdo->prepare("
        SELECT date_course, reunion, course, selection_json, source, captured_at
        FROM d10_test_tickets
        WHERE date_course = :date
    ");
    $stmt->execute([':date' => $date]);

    $tickets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $selection = safeJsonDecode($row['selection_json'] ?? null);
        if (!is_array($selection) || !isset($selection['num'])) {
            continue;
        }

        $courseDateKey = normalizeDateForLog((string)$row['date_course']);
        $courseId = $courseDateKey . '_' . $row['reunion'] . '_' . $row['course'];
        $tickets[$courseId] = [
            'selection' => $selection,
            'source' => (string)($row['source'] ?? 'd10_test_tickets'),
            'captured_at' => $row['captured_at'] ?? null,
        ];
    }

    return $tickets;
}

function json_response(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_today_paris(string $date): bool
{
    $today = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('dmY');
    return $date === $today;
}

function get_reunion_first_departure(PDO $pdo, string $date, string $reunion): ?int
{
    $stmt = $pdo->prepare("
        SELECT MIN(c.heure_depart) AS first_departure
        FROM courses c
        WHERE c.date_course = :date
          AND c.reunion = :reunion
          AND c.heure_depart IS NOT NULL
    ");
    $stmt->execute([':date' => $date, ':reunion' => $reunion]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    return (int)$value;
}

function assert_analysis_window(array $courses, bool $needsSingleCourse, string $date, PDO $pdo, ?string $reunionFilter): void
{
    if (!$needsSingleCourse || !is_today_paris($date)) {
        return;
    }

    if (empty($courses)) {
        throw new Exception("Course introuvable pour la fenêtre demandée.");
    }

    $firstDeparture = null;
    if (!empty($reunionFilter)) {
        $firstDeparture = get_reunion_first_departure($pdo, $date, (string)$reunionFilter);
    }
    if ($firstDeparture === null) {
        $course = $courses[0];
        $firstDeparture = !empty($course['heure_depart']) ? (int)$course['heure_depart'] : null;
    }
    if ($firstDeparture === null) {
        throw new Exception("Analyse disponible uniquement à partir de D-5 de la première course de la réunion.");
    }

    $depart = (new DateTime('@' . intdiv((int)$firstDeparture, 1000)))->setTimezone(new DateTimeZone('Europe/Paris'));
    $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
    $minutes = ($depart->getTimestamp() - $now->getTimestamp()) / 60.0;

    // La fenêtre s'ouvre à D-5 de la première course de la réunion et reste ouverte ensuite.
    if ($minutes > 5) {
        throw new Exception("Analyse disponible uniquement à partir de D-5 de la première course de la réunion.");
    }
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    $capital = isset($_GET['capital']) ? (float)$_GET['capital'] : 100.00;
    $reunionFilter = $_GET['reunion'] ?? null;
    $courseFilter = $_GET['course'] ?? null;

    if (!$date) {
        throw new Exception("Paramètre requis : date");
    }
    $expandedMethod = pmu_uses_expanded_q5_method((string)$date);

    $moulinette = new MoulinettePMU2026PHP($capital);

    $stmtCourses = $pdo->prepare("
        SELECT
            c.date_course,
            c.reunion,
            c.course,
            c.libelle,
            c.heure_depart,
            c.raw_json AS course_raw,
            a.raw_json AS arrivee_raw,
            c.discipline,
            c.distance,
            c.statut
        FROM courses c
        LEFT JOIN arrivees a
          ON a.date_course = c.date_course
         AND a.reunion = c.reunion
         AND a.course = c.course
        WHERE c.date_course = :date
        " . ($reunionFilter ? " AND c.reunion = :reunion" : "") . "
        " . ($courseFilter ? " AND c.course = :course" : "") . "
        ORDER BY c.heure_depart ASC NULLS LAST, c.reunion, c.course
    ");

    $paramsCourses = [':date' => $date];
    if ($reunionFilter) {
        $paramsCourses[':reunion'] = $reunionFilter;
    }
    if ($courseFilter) {
        $paramsCourses[':course'] = $courseFilter;
    }
    $stmtCourses->execute($paramsCourses);
    $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);
    assert_analysis_window($courses, (bool)($reunionFilter || $courseFilter), (string)$date, $pdo, $reunionFilter);

    $stmtParticipant = $pdo->prepare("
        SELECT raw_json
        FROM participants
        WHERE date_course = :date
          AND reunion = :reunion
          AND course = :course
          AND num_pmu = :num_pmu
        LIMIT 1
    ");

    $stmtInputs = $pdo->prepare("
        SELECT
            mi.num_pmu,
            mi.nom,
            mi.jt_score,
            mi.valeur_handicap,
            mi.qualifie_final,
            p.raw_json AS participant_raw
        FROM moulinette_inputs mi
        LEFT JOIN participants p
          ON p.date_course = mi.date_course
         AND p.reunion = mi.reunion
         AND p.course = mi.course
         AND p.num_pmu = mi.num_pmu
        WHERE mi.date_course = :date
          AND mi.reunion = :reunion
          AND mi.course = :course
        ORDER BY mi.num_pmu
    ");

    $details = [];
    $totalMise = 0.0;
    $totalNet = 0.0;
    $nbParis = 0;
    $nbGagnants = 0;
    $nbParisD10 = 0;
    $nbParisD10Db = 0;
    $nbParisD10Snapshot = 0;
    $nbParisEnAttente = 0;
    $normalizedDate = normalizeDateForLog((string)$date);
    $d10DbTickets = loadD10DbTickets($pdo, (string)$date);
    $d10Snapshot = loadD10AnalysisSnapshot($normalizedDate);

    foreach ($courses as $course) {
        $courseDateKey = normalizeDateForLog((string)$course['date_course']);
        $courseId = $courseDateKey . '_' . $course['reunion'] . '_' . $course['course'];
        $choix = null;
        $selectionSource = 'ABSTENTION';
        $d10TicketCourse = $d10DbTickets[$courseId] ?? ($d10Snapshot[$courseId] ?? null);
        $d10Selection = is_array($d10TicketCourse) ? ($d10TicketCourse['selection'] ?? null) : null;
        $isDbD10Ticket = isset($d10DbTickets[$courseId]);
        if (is_array($d10Selection) && isset($d10Selection['num'])) {
            $choix = [
                'num' => (int)$d10Selection['num'],
                'nom' => (string)($d10Selection['nom'] ?? ''),
                'profil' => $d10Selection['profil'] ?? null,
                'jt_score' => $d10Selection['jt_score'] ?? null,
                'cheval_score' => $d10Selection['cheval_score'] ?? null,
                'jockey_score' => $d10Selection['jockey_score'] ?? null,
                'entraineur_score' => $d10Selection['entraineur_score'] ?? null,
                'cote' => $d10Selection['cote'] ?? null
            ];
            $selectionSource = $isDbD10Ticket ? 'd10_test_tickets' : 'd10_analysis_snapshot';
        }
        $resultRaw = $course['arrivee_raw'] ?? $course['course_raw'] ?? null;
        $courseOrder = extractCourseOrder($resultRaw);

        // Convertir heure_depart (Unix ms) en heure Paris
        $heureLabel = null;
        if (!empty($course['heure_depart'])) {
            $ts = (int)($course['heure_depart'] / 1000);
            $heureLabel = (new DateTime('@'.$ts))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i');
        }

        if (!$choix) {
            $details[] = [
                'reunion'       => $course['reunion'],
                'course'        => $course['course'],
                'libelle'       => $course['libelle'],
                'heure_depart'  => $heureLabel,
                'selection'     => null,
                'selection_source' => $selectionSource,
                'ordre_arrivee' => $courseOrder,
                'mise'          => 0,
                'resultat_net'  => 0,
                'statut'        => 'ABSTENTION'
            ];
            continue;
        }

        // Le ticket joué est compté à 1 € par pari pour le bilan réel.
        // La Kelly reste un indicateur de préparation, pas la base du PnL.
        $mise = 1.0;
        $nbParisD10++;
        if ($isDbD10Ticket) {
            $nbParisD10Db++;
        } else {
            $nbParisD10Snapshot++;
        }

        $stmtParticipant->execute([
            ':date' => $course['date_course'],
            ':reunion' => $course['reunion'],
            ':course' => $course['course'],
            ':num_pmu' => $choix['num']
        ]);

        $participant = $stmtParticipant->fetch(PDO::FETCH_ASSOC);
        $ordreArrivee = extractSelectedOrderFromCourseRaw($resultRaw, (int)$choix['num']);
        if ($ordreArrivee === null && $participant) {
            $ordreArrivee = extractOrdreArrivee($participant['raw_json']);
        }

        $resultatNet = 0.0;
        $statutPari = 'PERDU';

        if ($ordreArrivee === 1) {
            $rapport = fetchOfficialSimpleRapportFromApi(
                (string)$course['date_course'],
                (string)$course['reunion'],
                (string)$course['course'],
                (int)$choix['num']
            );
            if ($rapport === null) {
                $rapport = extractSimpleRapport($participant['raw_json'] ?? null);
            }
            if ($rapport === null) {
                $resultatNet = 0.0;
                $statutPari = 'RESULTAT_INCONNU';
            } else {
                $resultatNet = round(($mise * $rapport) - $mise, 2);
                $statutPari = 'GAGNE';
                $nbGagnants++;
            }
        } elseif ($ordreArrivee === null) {
            $resultatNet = 0.0;
            $statutPari = 'RESULTAT_INCONNU';
            $nbParisEnAttente++;
        } else {
            $resultatNet = round(-$mise, 2);
            $statutPari = 'PERDU';
        }

        if ($statutPari !== 'RESULTAT_INCONNU') {
            $nbParis++;
            $totalMise += $mise;
            $totalNet += $resultatNet;
        }

        $details[] = [
            'reunion'      => $course['reunion'],
            'course'       => $course['course'],
            'libelle'      => $course['libelle'],
            'heure_depart' => $heureLabel,
            'selection' => [
                'num' => $choix['num'],
                'nom' => $choix['nom'],
                'profil' => $choix['profil'],
                'jt_score' => $choix['jt_score'],
                'cheval_score' => $choix['cheval_score'] ?? null,
                'jockey_score' => $choix['jockey_score'] ?? null,
                'entraineur_score' => $choix['entraineur_score'] ?? null,
                'cote' => $choix['cote']
            ],
            'selection_source' => $selectionSource,
            'ordre_arrivee' => $ordreArrivee,
            'mise' => round($mise, 2),
            'resultat_net' => round($resultatNet, 2),
            'statut' => $statutPari
        ];
    }

    json_response([
        'success' => true,
        'date' => $date,
        'method' => $expandedMethod ? PMU_EXPANDED_Q5_SOURCE_MODE : 'strict_method_2026',
        'bankroll_depart' => $capital,
        'nb_paris' => $nbParis,
        'nb_paris_d10' => $nbParisD10,
        'nb_paris_d10_db' => $nbParisD10Db,
        'nb_paris_d10_snapshot' => $nbParisD10Snapshot,
        'nb_paris_resultat_connu' => $nbParis,
        'nb_paris_en_attente' => $nbParisEnAttente,
        'nb_gagnants' => $nbGagnants,
        'total_mise' => round($totalMise, 2),
        'total_mise_d10' => round($nbParisD10 * 1.0, 2),
        'resultat_net_total' => round($totalNet, 2),
        'bankroll_fin_theorique' => round($capital + $totalNet, 2),
        'details' => $details
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
