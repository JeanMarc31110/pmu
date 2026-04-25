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

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $date = $_GET['date'] ?? null;
    $reunion = $_GET['reunion'] ?? null;
    $course = $_GET['course'] ?? null;
    $capital = isset($_GET['capital']) ? (float)$_GET['capital'] : 181.54;

    if (!$date || !$reunion || !$course) {
        throw new Exception("Paramètres requis : date, reunion, course");
    }

    $moulinette = new MoulinettePMU2026PHP($capital);

    $stmtCourse = $pdo->prepare("
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
            a.arrivee_definitive
        FROM courses c
        LEFT JOIN arrivees a
          ON a.date_course = c.date_course
         AND a.reunion = c.reunion
         AND a.course = c.course
        WHERE c.date_course = :date
          AND c.reunion = :reunion
          AND c.course = :course
        LIMIT 1
    ");

    $stmtCourse->execute([
        ':date' => $date,
        ':reunion' => $reunion,
        ':course' => $course
    ]);

    $courseRow = $stmtCourse->fetch(PDO::FETCH_ASSOC);

    if (!$courseRow) {
        throw new Exception("Course introuvable");
    }

    $stmtInputs = $pdo->prepare("
        SELECT
            num_pmu,
            nom,
            profil,
            jt_score,
            cheval_score,
            jockey_score,
            entraineur_score,
            cote_probable,
            valeur_handicap,
            qualifie_q5,
            qualifie_value,
            qualifie_profil,
            qualifie_final
        FROM moulinette_inputs
        WHERE date_course = :date
          AND reunion = :reunion
          AND course = :course
        ORDER BY num_pmu
    ");

    $stmtInputs->execute([
        ':date' => $date,
        ':reunion' => $reunion,
        ':course' => $course
    ]);

    $rows = $stmtInputs->fetchAll(PDO::FETCH_ASSOC);

    $qualifies = [];
    $debug = [];

    foreach ($rows as $r) {
        $debug[] = [
            'num' => (int)$r['num_pmu'],
            'nom' => $r['nom'],
            'profil' => $r['profil'] !== null ? (int)$r['profil'] : null,
            'jt_score' => $r['jt_score'] !== null ? (float)$r['jt_score'] : null,
            'cheval_score' => $r['cheval_score'] !== null ? (float)$r['cheval_score'] : null,
            'jockey_score' => $r['jockey_score'] !== null ? (float)$r['jockey_score'] : null,
            'entraineur_score' => $r['entraineur_score'] !== null ? (float)$r['entraineur_score'] : null,
            'cote' => $r['cote_probable'] !== null ? (float)$r['cote_probable'] : null,
            'valeur_handicap' => $r['valeur_handicap'] !== null ? (float)$r['valeur_handicap'] : null,
            'qualifie_q5' => (int)$r['qualifie_q5'],
            'qualifie_value' => (int)$r['qualifie_value'],
            'qualifie_profil' => (int)$r['qualifie_profil'],
            'qualifie_final' => (int)$r['qualifie_final']
        ];

        if ((int)$r['qualifie_final'] === 1) {
            $qualifies[] = [
                'num' => (int)$r['num_pmu'],
                'nom' => $r['nom'],
                'profil' => (int)$r['profil'],
                'jt_score' => (float)$r['jt_score'],
                'cheval_score' => $r['cheval_score'] !== null ? (float)$r['cheval_score'] : null,
                'jockey_score' => $r['jockey_score'] !== null ? (float)$r['jockey_score'] : null,
                'entraineur_score' => $r['entraineur_score'] !== null ? (float)$r['entraineur_score'] : null,
                'cote' => (float)$r['cote_probable'],
                'valeur_handicap' => $r['valeur_handicap'] !== null ? (float)$r['valeur_handicap'] : null
            ];
        }
    }

    $choix = $moulinette->arbitrageSelection($qualifies);

    echo json_encode([
        'success' => true,
        'date' => $date,
        'bankroll' => $moulinette->bankroll,
        'course' => $courseRow,
        'candidats_eligibles_count' => count($qualifies),
        'selection_moulinette' => $choix ? [
            'num' => $choix['num'],
            'nom' => $choix['nom'],
            'profil' => $choix['profil'],
            'jt_score' => $choix['jt_score'],
            'cheval_score' => $choix['cheval_score'] ?? null,
            'jockey_score' => $choix['jockey_score'] ?? null,
            'entraineur_score' => $choix['entraineur_score'] ?? null,
            'cote' => $choix['cote'],
            'valeur_handicap' => $choix['valeur_handicap'],
            'mise_kelly' => $moulinette->calculerMise($choix['cote'])
        ] : null,
        'debug_entrees' => $debug
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
