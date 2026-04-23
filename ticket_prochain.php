<?php
/**
 * ticket_prochain.php
 * Retourne le ticket de jeu pour la prochaine course qualifiée.
 * Paramètre : ?capital=100 (bankroll pour Kelly)
 *             ?date=17042026 (optionnel, défaut = aujourd'hui)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$CAPITAL    = isset($_GET['capital']) ? (float)$_GET['capital'] : 100.0;
$ROI        = 0.4603;
$FRAC_KELLY = 0.5;
$MAX_EXPO   = 0.10;
$COTE_MIN   = 2.0;
$MISE_FIXE  = 1.0;

// Date du jour en format DDMMYYYY
$tz   = new DateTimeZone('Europe/Paris');
$now  = new DateTime('now', $tz);

if (!empty($_GET['date'])) {
    $date_str = $_GET['date'];
} else {
    $date_str = $now->format('d') . $now->format('m') . $now->format('Y');
}

$now_ms = (int)($now->getTimestamp() * 1000);

function mise_kelly(float $cote, float $roi, float $frac, float $maxExpo, float $capital): float {
    if ($cote < 2.0) return 0.0;
    $b = $cote - 1.0;
    $p = (1.0 + $roi) / $cote;
    $f = ($p * ($b + 1.0) - 1.0) / $b * $frac;
    $m = min($f * $capital, $maxExpo * $capital);
    return max(round($m), 1); // arrondi à l'euro, minimum 1€
}

function deriveProfilFromCote(?float $c): ?int {
    if ($c === null)               return null;
    if ($c >= 5.0 && $c <= 8.0)   return 1;
    if ($c >= 3.0 && $c < 5.0)    return 2;
    if ($c >= 2.0 && $c < 3.0)    return 3;
    return null;
}

// Récupérer toutes les courses du jour avec leurs candidats qualifiés
$stmt = $pdo->prepare("
    SELECT
        mi.reunion, mi.course,
        mi.num_pmu, mi.nom, mi.jt_score, mi.profil,
        json_extract(p.raw_json,'$.dernierRapportDirect.rapport') AS cote_directe,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport') AS cote_reference,
        json_extract(p.raw_json,'$.ordreArrivee') AS ordre,
        c.libelle, c.heure_depart, c.discipline
    FROM moulinette_inputs mi
    JOIN participants p
      ON p.date_course = mi.date_course
     AND p.reunion     = mi.reunion
     AND p.course      = mi.course
     AND p.num_pmu     = mi.num_pmu
    LEFT JOIN courses c
      ON c.date_course = mi.date_course
     AND c.reunion     = mi.reunion
     AND c.course      = mi.course
    WHERE mi.date_course = :date
      AND mi.qualifie_final = 1
    ORDER BY c.heure_depart ASC NULLS LAST, mi.reunion, mi.course, mi.profil ASC, mi.jt_score DESC
");
$stmt->execute([':date' => $date_str]);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper par course
$par_course = [];
foreach ($all as $r) {
    $key = $r['reunion'].'_'.$r['course'];
    if (!isset($par_course[$key])) {
        $par_course[$key] = [
            'reunion'     => $r['reunion'],
            'course'      => $r['course'],
            'libelle'     => $r['libelle'],
            'discipline'  => $r['discipline'],
            'heure_depart'=> is_numeric($r['heure_depart']) ? (int)$r['heure_depart'] : null,
            'candidats'   => [],
        ];
    }
    $par_course[$key]['candidats'][] = $r;
}

// Identifier la prochaine course :
// 1. Non encore arrivée (ordreArrivee null pour au moins un cheval)
// 2. Départ dans le futur (heure_depart > now - 5min pour tolérance)
$TOLERANCE_MS = 5 * 60 * 1000; // 5 min de tolérance (si légèrement passé)

$prochaine = null;
$toutes_courses = [];

foreach ($par_course as $key => $course) {
    $hd = $course['heure_depart'];
    if ($hd === null) continue;

    // La course n'est pas encore passée (avec tolérance de 5 min)
    if ($hd < $now_ms - $TOLERANCE_MS) continue;

    // Sélection du cheval (premier candidat après tri profil/jt_score)
    $sel = $course['candidats'][0] ?? null;
    if (!$sel) continue;

    $cote_dir = is_numeric($sel['cote_directe'])  ? (float)$sel['cote_directe']  : null;
    $cote_ref = is_numeric($sel['cote_reference']) ? (float)$sel['cote_reference'] : null;

    // Cote pour Kelly : directe en priorité, sinon référence
    $cote_kelly = $cote_dir ?? $cote_ref;
    $miseKelly = $cote_kelly !== null ? mise_kelly($cote_kelly, $ROI, $FRAC_KELLY, $MAX_EXPO, $CAPITAL) : $MISE_FIXE;
    $mise = $MISE_FIXE;

    $profil = is_numeric($sel['profil']) ? (int)$sel['profil'] : null;

    // Heure lisible
    $ts_sec = (int)($hd / 1000);
    $dt = (new DateTime('@'.$ts_sec))->setTimezone($tz);
    $heure_label = $dt->format('H:i');

    // Temps restant en secondes
    $restant_sec = $ts_sec - $now->getTimestamp();

    $entry = [
        'reunion'       => $course['reunion'],
        'course'        => $course['course'],
        'libelle'       => $course['libelle'],
        'heure_depart'  => $heure_label,
        'heure_ms'      => $hd,
        'restant_sec'   => $restant_sec,
        'discipline'    => $course['discipline'],
        'num_cheval'    => (int)$sel['num_pmu'],
        'nom_cheval'    => $sel['nom'],
        'profil'        => $profil,
        'jt_score'      => round((float)$sel['jt_score'], 4),
        'cote_matin'    => $cote_ref,
        'cote_finale'   => $cote_dir,
        'mise'          => $mise,
        'mise_kelly'    => $miseKelly,
        'alerte_10min'  => ($restant_sec > 0 && $restant_sec <= 600), // <= 10 min
        'ordre_arrive'  => is_numeric($sel['ordre']) ? (int)$sel['ordre'] : null,
    ];

    $toutes_courses[] = $entry;

    if ($prochaine === null) {
        $prochaine = $entry;
    }
}

// Heure actuelle Paris
$heure_actuelle = $now->format('H:i:s');

echo json_encode([
    'date'           => $date_str,
    'heure_actuelle' => $heure_actuelle,
    'capital'        => $CAPITAL,
    'prochaine'      => $prochaine,
    'toutes_courses' => $toutes_courses,
    'nb_courses'     => count($toutes_courses),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
