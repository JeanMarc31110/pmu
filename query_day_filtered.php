<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

class MoulinettePMU2026PHP
{
    public float $bankroll;
    public float $roi_estime = 0.4603;
    public float $fraction_kelly = 0.5;
    public float $cote_seuil_min = 2.0;
    public float $max_exposition = 0.10;

    public function __construct(float $capital_initial = 181.54)
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

        return round($miseFinale, 2);
    }

    public function arbitrageSelection(array $chevauxQualifies): ?array
    {
        if (empty($chevauxQualifies)) {
            return null;
        }

        usort($chevauxQualifies, function ($a, $b) {
            // 1. Profil 1 puis 2 puis 3
            if ($a['profil'] !== $b['profil']) {
                return $a['profil'] <=> $b['profil'];
            }

            // 2. JT score le plus élevé
            if ($a['jt_score'] !== $b['jt_score']) {
                return $b['jt_score'] <=> $a['jt_score'];
            }

            // 3. Valeur handicap la meilleure (la plus haute ici)
            $ha = $a['valeur_handicap'] ?? -INF;
            $hb = $b['valeur_handicap'] ?? -INF;

            if ($ha !== $hb) {
                return $hb <=> $ha;
            }

            return 0;
        });

        return $chevauxQualifies[0];
    }
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    $capital = isset($_GET['capital']) ? (float) $_GET['capital'] : 181.54;
    $reunionFilter = $_GET['reunion'] ?? null;
    $courseFilter = $_GET['course'] ?? null;

    if (!$date) {
        throw new Exception("Paramètre requis : date");
    }

    $moulinette = new MoulinettePMU2026PHP($capital);

    $sqlCourses = "
        SELECT
            c.date_course,
            c.reunion,
            c.course,
            c.libelle,
            c.heure_depart,
            c.discipline,
            c.specialite,
            c.distance,
            c.statut,
            c.categorie_statut,
            a.arrivee_definitive
        FROM courses c
        LEFT JOIN arrivees a
            ON a.date_course = c.date_course
           AND a.reunion = c.reunion
           AND a.course = c.course
        WHERE c.date_course = :date
          AND c.statut = 'FIN_COURSE'
    ";

    $paramsCourses = [':date' => $date];

    if ($reunionFilter) {
        $sqlCourses .= " AND c.reunion = :reunion";
        $paramsCourses[':reunion'] = $reunionFilter;
    }

    if ($courseFilter) {
        $sqlCourses .= " AND c.course = :course";
        $paramsCourses[':course'] = $courseFilter;
    }

    $sqlCourses .= " ORDER BY c.reunion, c.course";

    $stmtCourses = $pdo->prepare($sqlCourses);
    $stmtCourses->execute($paramsCourses);
    $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

    $stmtInputs = $pdo->prepare("
        SELECT
            mi.num_pmu,
            mi.nom,
            mi.profil,
            mi.jt_score,
            mi.cote_probable,
            mi.valeur_handicap,
            mi.qualifie_q5,
            mi.qualifie_value,
            mi.qualifie_profil,
            mi.qualifie_final,
            p.non_partant
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

    foreach ($courses as $course) {
        $stmtInputs->execute([
            ':date' => $course['date_course'],
            ':reunion' => $course['reunion'],
            ':course' => $course['course']
        ]);

        $rows = $stmtInputs->fetchAll(PDO::FETCH_ASSOC);

        $chevauxQualifies = [];
        $debug = [];

        foreach ($rows as $r) {
            $debug[] = [
                'num' => (int)$r['num_pmu'],
                'nom' => $r['nom'],
                'profil' => $r['profil'] !== null ? (int)$r['profil'] : null,
                'jt_score' => $r['jt_score'] !== null ? (float)$r['jt_score'] : null,
                'cote' => $r['cote_probable'] !== null ? (float)$r['cote_probable'] : null,
                'valeur_handicap' => $r['valeur_handicap'] !== null ? (float)$r['valeur_handicap'] : null,
                'qualifie_q5' => (int)$r['qualifie_q5'],
                'qualifie_value' => (int)$r['qualifie_value'],
                'qualifie_profil' => (int)$r['qualifie_profil'],
                'qualifie_final' => (int)$r['qualifie_final'],
                'non_partant' => !empty($r['non_partant']) ? 1 : 0
            ];

            if (!empty($r['non_partant'])) {
                continue;
            }

            // FILTRE STRICT : seulement les qualifiés finaux
            if ((int)$r['qualifie_final'] !== 1) {
                continue;
            }

            $chevauxQualifies[] = [
                'num' => (int)$r['num_pmu'],
                'nom' => $r['nom'],
                'profil' => (int)$r['profil'],
                'jt_score' => (float)$r['jt_score'],
                'cote' => (float)$r['cote_probable'],
                'valeur_handicap' => $r['valeur_handicap'] !== null ? (float)$r['valeur_handicap'] : null
            ];
        }

        $choix = $moulinette->arbitrageSelection($chevauxQualifies);

        $selection = null;
        if ($choix) {
            $selection = [
                'num' => $choix['num'],
                'nom' => $choix['nom'],
                'profil' => $choix['profil'],
                'jt_score' => $choix['jt_score'],
                'cote' => $choix['cote'],
                'valeur_handicap' => $choix['valeur_handicap'],
                'mise_kelly' => $moulinette->calculerMise((float)$choix['cote'])
            ];
        }

        $results[] = [
            'course' => [
                'date_course' => $course['date_course'],
                'reunion' => $course['reunion'],
                'course' => $course['course'],
                'libelle' => $course['libelle'],
                'heure_depart' => $course['heure_depart'],
                'discipline' => $course['discipline'],
                'specialite' => $course['specialite'],
                'distance' => $course['distance'],
                'statut' => $course['statut'],
                'arrivee_definitive' => $course['arrivee_definitive']
            ],
            'candidats_eligibles_count' => count($chevauxQualifies),
            'selection_moulinette' => $selection,
            'debug_entrees' => $debug
        ];
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'bankroll' => $moulinette->bankroll,
        'courses_count' => count($results),
        'data' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}