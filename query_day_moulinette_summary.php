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

function parse_json_array(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function extract_real_report_from_participant(?string $rawJson): ?float
{
    $raw = parse_json_array($rawJson);
    if (!$raw) {
        return null;
    }
    $direct = $raw['dernierRapportDirect']['rapport'] ?? null;
    if (is_numeric($direct)) {
        return (float)$direct;
    }
    $reference = $raw['dernierRapportReference']['rapport'] ?? null;
    if (is_numeric($reference)) {
        return (float)$reference;
    }
    return null;
}

function derive_profil_from_cote(?float $cote, bool $expandedMethod = false): ?int
{
    return pmu_profile_rank_from_cote($cote, $expandedMethod);
}

function fetch_live_course_reports(string $date, string $reunion, string $course): array
{
    $url = sprintf(
        'https://online.turfinfo.api.pmu.fr/rest/client/61/programme/%s/%s/%s/participants?specialisation=INTERNET',
        rawurlencode($date),
        rawurlencode($reunion),
        rawurlencode($course)
    );
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) return [];
    $data = parse_json_array($json);
    if (!$data || empty($data['participants']) || !is_array($data['participants'])) return [];

    $out = [];
    foreach ($data['participants'] as $p) {
        if (!is_array($p)) continue;
        $num = (int)($p['numPmu'] ?? 0);
        if ($num <= 0) continue;
        $direct = $p['dernierRapportDirect']['rapport'] ?? null;
        $ref = $p['dernierRapportReference']['rapport'] ?? null;
        if (is_numeric($direct)) {
            $out[$num] = (float)$direct;
        } elseif (is_numeric($ref)) {
            $out[$num] = (float)$ref;
        }
    }
    return $out;
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $date = $_GET['date'] ?? null;
    $capital = isset($_GET['capital']) ? (float)$_GET['capital'] : 100.00;
    $reunionFilter = $_GET['reunion'] ?? null;
    $courseFilter = $_GET['course'] ?? null;
    $liveRequested = isset($_GET['live']) && in_array(strtolower((string)$_GET['live']), ['1', 'true', 'yes', 'on'], true);

    if (!$date) {
        throw new Exception("Paramètre requis : date");
    }
    $expandedMethod = pmu_uses_expanded_q5_method((string)$date);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $moulinette = new MoulinettePMU2026PHP($capital);

    // On part des courses réellement présentes dans moulinette_inputs
    $stmtCourses = $pdo->prepare("
        SELECT DISTINCT
            mi.date_course,
            mi.reunion,
            mi.course,
            c.libelle,
            c.heure_depart,
            c.discipline,
            c.distance,
            c.statut
        FROM moulinette_inputs mi
        LEFT JOIN courses c
          ON c.date_course = mi.date_course
         AND c.reunion = mi.reunion
         AND c.course = mi.course
        WHERE mi.date_course = :date
        " . ($reunionFilter ? " AND mi.reunion = :reunion" : "") . "
        " . ($courseFilter ? " AND mi.course = :course" : "") . "
        ORDER BY c.heure_depart ASC NULLS LAST, mi.reunion, mi.course
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

    $stmtInputs = $pdo->prepare("
        SELECT
            mi.num_pmu,
            mi.nom,
            mi.jt_score,
            mi.cheval_score,
            mi.jockey_score,
            mi.entraineur_score,
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

    $results = [];
    $coursesJouees = 0;
    $abstentions = 0;

    foreach ($courses as $course) {
        $stmtInputs->execute([
            ':date' => $course['date_course'],
            ':reunion' => $course['reunion'],
            ':course' => $course['course']
        ]);

        $rows = $stmtInputs->fetchAll(PDO::FETCH_ASSOC);
        $useLiveForCourse = $liveRequested || (is_today_paris((string)$date) && (bool)($reunionFilter || $courseFilter));
        $liveReportsByNum = $useLiveForCourse
            ? fetch_live_course_reports((string)$course['date_course'], (string)$course['reunion'], (string)$course['course'])
            : [];

        $qualifies = [];
        foreach ($rows as $r) {
            if ((int)$r['qualifie_final'] === 1) {
                $num = (int)$r['num_pmu'];
                $cote = $useLiveForCourse
                    ? ($liveReportsByNum[$num] ?? null)
                    : extract_real_report_from_participant($r['participant_raw'] ?? null);
                if (!is_numeric($cote)) {
                    // Aucun live/réel disponible => pas de sélection estimée.
                    continue;
                }
                $cote = (float)$cote;
                $profil = derive_profil_from_cote($cote, $expandedMethod);
                if ($profil === null) {
                    continue;
                }
                $qualifies[] = [
                    'num' => $num,
                    'nom' => $r['nom'],
                    'profil' => $profil,
                    'jt_score' => (float)$r['jt_score'],
                    'cheval_score' => $r['cheval_score'] !== null ? (float)$r['cheval_score'] : null,
                    'jockey_score' => $r['jockey_score'] !== null ? (float)$r['jockey_score'] : null,
                    'entraineur_score' => $r['entraineur_score'] !== null ? (float)$r['entraineur_score'] : null,
                    'cote' => $cote,
                    'cote_source' => $useLiveForCourse ? 'live_api' : 'participant_report',
                    'valeur_handicap' => $r['valeur_handicap'] !== null ? (float)$r['valeur_handicap'] : null
                ];
            }
        }

        $choix = $moulinette->arbitrageSelection($qualifies);

        if ($choix) {
            $coursesJouees++;
        } else {
            $abstentions++;
        }

        // Convertir heure_depart (Unix ms) en heure Paris
        $heureLabel = null;
        if (!empty($course['heure_depart'])) {
            $ts = (int)($course['heure_depart'] / 1000);
            $heureLabel = (new DateTime('@'.$ts))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i');
        }

        $results[] = [
            'reunion' => $course['reunion'],
            'course' => $course['course'],
            'libelle' => $course['libelle'],
            'heure_depart' => $heureLabel,
            'discipline' => $course['discipline'],
            'distance' => $course['distance'],
            'statut' => $course['statut'],
            'candidats_eligibles_count' => count($qualifies),
            'selection' => $choix ? [
                'num' => $choix['num'],
                'nom' => $choix['nom'],
                'profil' => $choix['profil'],
                'jt_score' => $choix['jt_score'],
                'cheval_score' => $choix['cheval_score'] ?? null,
                'jockey_score' => $choix['jockey_score'] ?? null,
                'entraineur_score' => $choix['entraineur_score'] ?? null,
                'cote' => $choix['cote'],
                'cote_source' => $choix['cote_source'] ?? null,
                'valeur_handicap' => $choix['valeur_handicap'],
                'mise' => 1.0,
                'mise_kelly' => $moulinette->calculerMise($choix['cote'])
            ] : null
        ];
    }

    json_response([
        'success' => true,
        'date' => $date,
        'method' => $expandedMethod ? PMU_EXPANDED_Q5_SOURCE_MODE : 'strict_method_2026',
        'bankroll' => $moulinette->bankroll,
        'courses_count' => count($results),
        'courses_jouees' => $coursesJouees,
        'abstentions' => $abstentions,
        'data' => $results
    ]);

} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
