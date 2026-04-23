<?php
/**
 * drawdown_avril.php
 * Calcule le drawdown maximum sur avril 2026
 * Mise fixe 1€/cheval, sélection sur cotes du MATIN (dernierRapportReference.rapport)
 * Paiement au dividende réel PMU (cote_directe = cote_probable)
 */
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$MISE = 1.0;

function deriveProfilFromCote(?float $c): ?int {
    if ($c === null)               return null;
    if ($c >= 5.0 && $c <= 8.0)   return 1;
    if ($c >= 3.0 && $c < 5.0)    return 2;
    if ($c >= 2.0 && $c < 3.0)    return 3;
    return null;
}

function selectHorse(array $chevaux, string $coteField): ?array {
    $candidats = [];
    foreach ($chevaux as $ch) {
        $cote   = is_numeric($ch[$coteField]) ? (float)$ch[$coteField] : null;
        $profil = deriveProfilFromCote($cote);
        if ($profil !== null) {
            $candidats[] = array_merge($ch, ['_cote_used' => $cote, '_profil_used' => $profil]);
        }
    }
    if (!$candidats) return null;
    usort($candidats, function($a, $b) {
        if ($a['_profil_used'] !== $b['_profil_used']) return $a['_profil_used'] <=> $b['_profil_used'];
        return $b['jt_score'] <=> $a['jt_score'];
    });
    return $candidats[0];
}

// Récupérer tous les chevaux Q5 d'avril avec les deux cotes + heure
$stmt = $pdo->prepare("
    SELECT
        mi.date_course, mi.reunion, mi.course,
        mi.num_pmu, mi.nom, mi.jt_score, mi.qualifie_q5,
        mi.cote_probable                                              AS cote_directe,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport')  AS cote_reference,
        json_extract(p.raw_json,'$.ordreArrivee')                     AS ordre,
        json_extract(p.raw_json,'$.incident')                         AS incident,
        c.heure_depart
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
    WHERE mi.date_course LIKE :pattern
      AND mi.qualifie_q5 = 1
    ORDER BY mi.date_course, mi.reunion, mi.course, mi.num_pmu
");
$stmt->execute([':pattern' => '%042026']);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper par course
$par_course = [];
foreach ($all as $r) {
    $key = $r['date_course'].'_'.$r['reunion'].'_'.$r['course'];
    $par_course[$key][] = $r;
}

// Pour chaque course : sélection sur cote_reference, paiement sur cote_directe
$bets = [];
foreach ($par_course as $key => $chevaux) {
    $sel = selectHorse($chevaux, 'cote_reference');
    if ($sel === null) continue;

    // Trouver le résultat réel du cheval sélectionné
    $cheval_data = null;
    foreach ($chevaux as $ch) {
        if ($ch['num_pmu'] == $sel['num_pmu']) {
            $cheval_data = $ch;
            break;
        }
    }
    if (!$cheval_data) continue;

    // Résultat
    $incident = $cheval_data['incident'] ?? '';
    if ($incident && preg_match('/DISQUALIF|DISTANC|TOMBE/i', $incident)) {
        $ordre = 99;
    } elseif (is_numeric($cheval_data['ordre'])) {
        $ordre = (int)$cheval_data['ordre'];
    } else {
        $ordre = null; // pas encore arrivé
    }

    if ($ordre === null) continue; // course pas jouée

    $cote_dir = is_numeric($cheval_data['cote_directe']) ? (float)$cheval_data['cote_directe'] : null;
    if ($cote_dir === null) continue; // pas de dividende

    // Gain/perte
    if ($ordre === 1) {
        $net = round($MISE * $cote_dir - $MISE, 2);
        $gagne = true;
    } else {
        $net = -$MISE;
        $gagne = false;
    }

    // Tri chronologique : convertir date DDMMYYYY en YYYYMMDD + heure_depart ms
    $date = $cheval_data['date_course']; // format DDMMYYYY
    $date_sort = substr($date,4,4).substr($date,2,2).substr($date,0,2); // YYYYMMDD
    $heure_ms = is_numeric($cheval_data['heure_depart']) ? (int)$cheval_data['heure_depart'] : 0;

    // Heure lisible
    $heure_label = null;
    if ($heure_ms > 0) {
        $ts = (int)($heure_ms / 1000);
        $heure_label = (new DateTime('@'.$ts))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i');
    }

    $bets[] = [
        'date'         => $date,
        'date_sort'    => $date_sort,
        'heure_ms'     => $heure_ms,
        'heure'        => $heure_label,
        'reunion'      => $cheval_data['reunion'],
        'course'       => $cheval_data['course'],
        'cheval'       => $sel['nom'],
        'num'          => $sel['num_pmu'],
        'cote_matin'   => $sel['_cote_used'],
        'cote_finale'  => $cote_dir,
        'ordre'        => $ordre,
        'gagne'        => $gagne,
        'net'          => $net,
    ];
}

// Trier chronologiquement
usort($bets, function($a, $b) {
    if ($a['date_sort'] !== $b['date_sort']) return strcmp($a['date_sort'], $b['date_sort']);
    return $a['heure_ms'] <=> $b['heure_ms'];
});

// Calculer P&L cumulé et drawdown
$cumul     = 0.0;
$peak      = 0.0;
$max_dd    = 0.0;
$max_dd_from = null;
$max_dd_to   = null;
$peak_date   = null;

// Pour le graphe
$equity_curve = [];

foreach ($bets as &$bet) {
    $cumul = round($cumul + $bet['net'], 2);
    $bet['cumul'] = $cumul;

    if ($cumul > $peak) {
        $peak      = $cumul;
        $peak_date = $bet['date'].'_R'.$bet['reunion'].'C'.$bet['course'];
    }

    $dd = round($peak - $cumul, 2);
    $bet['drawdown'] = $dd;

    if ($dd > $max_dd) {
        $max_dd      = $dd;
        $max_dd_from = $peak_date;
        $max_dd_to   = $bet['date'].'_R'.$bet['reunion'].'C'.$bet['course'];
    }

    $equity_curve[] = [
        'label'  => $bet['date'].'_R'.$bet['reunion'].'C'.$bet['course'],
        'heure'  => $bet['heure'],
        'cumul'  => $cumul,
        'dd'     => $dd,
    ];
}
unset($bet);

// Stats globales
$nb_paris  = count($bets);
$nb_gagnes = count(array_filter($bets, fn($b) => $b['gagne']));
$net_total = end($bets)['cumul'] ?? 0;
$hit_rate  = $nb_paris > 0 ? round($nb_gagnes / $nb_paris * 100, 1) : 0;
$roi       = $nb_paris > 0 ? round($net_total / ($nb_paris * $MISE) * 100, 1) : 0;

// Séquences perdantes (pour contexte du drawdown)
$current_streak = 0;
$max_streak     = 0;
$streak_start   = null;
$max_streak_start = null;
$max_streak_end   = null;
$temp_start       = null;

foreach ($bets as $b) {
    if (!$b['gagne']) {
        $current_streak++;
        if ($temp_start === null) $temp_start = $b['date'].'_R'.$b['reunion'].'C'.$b['course'];
        if ($current_streak > $max_streak) {
            $max_streak       = $current_streak;
            $max_streak_start = $temp_start;
            $max_streak_end   = $b['date'].'_R'.$b['reunion'].'C'.$b['course'];
        }
    } else {
        $current_streak = 0;
        $temp_start     = null;
    }
}

echo json_encode([
    'periode'  => 'Avril 2026',
    'methode'  => 'Sélection sur cote_matin (reference), paiement au dividende réel (cote_directe)',
    'mise'     => $MISE.'€ fixe',

    'resume' => [
        'nb_paris'        => $nb_paris,
        'nb_gagnes'       => $nb_gagnes,
        'hit_rate_pct'    => $hit_rate,
        'total_mise'      => round($nb_paris * $MISE, 2),
        'net_total'       => $net_total,
        'roi_pct'         => $roi,
    ],

    'drawdown' => [
        'max_drawdown_eur'   => $max_dd,
        'de'                 => $max_dd_from,
        'a'                  => $max_dd_to,
        'max_losing_streak'  => $max_streak,
        'streak_de'          => $max_streak_start,
        'streak_a'           => $max_streak_end,
    ],

    'equity_curve' => $equity_curve,
    'detail'       => $bets,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
