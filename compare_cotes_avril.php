<?php
/**
 * compare_cotes_avril.php
 * Compare P&L avril 2026 :
 *  - Version A : cote_probable stockée (référence matin dans moulinette_inputs)
 *  - Version B : dernierRapportDirect.rapport (cote finale payée au moment de la course)
 *
 * Kelly calculé sur bankroll fixe 100€ (pas de compounding) pour une comparaison propre.
 */
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$CAPITAL    = 100.0;
$ROI        = 0.4603;
$FRAC_KELLY = 0.5;
$MAX_EXPO   = 0.10;
$COTE_MIN   = 2.0;

// Kelly sur bankroll FIXE (100€) — pas de compounding
function mise_kelly(float $cote, float $roi, float $frac, float $maxExpo, float $capital): float {
    if ($cote < 2.0) return 0.0;
    $b = $cote - 1.0;
    $p = (1.0 + $roi) / $cote;
    $f = ($p * ($b + 1.0) - 1.0) / $b * $frac;
    return (float) max(round(min($f * $capital, $maxExpo * $capital)), 1);
}

// Récupérer toutes les sélections finales d'avril
$stmt = $pdo->prepare("
    SELECT
        mi.date_course, mi.reunion, mi.course,
        mi.num_pmu, mi.nom,
        mi.cote_probable                                              AS cote_finale,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport')  AS cote_reference,
        json_extract(p.raw_json,'$.ordreArrivee')                     AS ordre,
        json_extract(p.raw_json,'$.incident')                         AS incident,
        mi.profil, mi.jt_score
    FROM moulinette_inputs mi
    JOIN participants p
      ON p.date_course = mi.date_course
     AND p.reunion     = mi.reunion
     AND p.course      = mi.course
     AND p.num_pmu     = mi.num_pmu
    WHERE mi.date_course LIKE '%042026'
      AND mi.qualifie_final = 1
    ORDER BY
        substr(mi.date_course,5,4)||substr(mi.date_course,3,2)||substr(mi.date_course,1,2),
        mi.reunion, mi.course, mi.profil ASC, mi.jt_score DESC
");
$stmt->execute();
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Arbitrage : 1 cheval par course
$par_course = [];
foreach ($all as $q) {
    $key = $q['date_course'].'_'.$q['reunion'].'_'.$q['course'];
    if (!isset($par_course[$key])) $par_course[$key] = $q; // premier = meilleur (ORDER BY déjà fait)
}

// Calcul P&L
$stats_A = ['paris'=>0,'gagnes'=>0,'mise'=>0,'net'=>0,'inconnus'=>0,'sans_cote'=>0];
$stats_B = ['paris'=>0,'gagnes'=>0,'mise'=>0,'net'=>0,'inconnus'=>0,'sans_cote'=>0];

$rows = [];

foreach ($par_course as $s) {
    // A = cote de référence (ouverture/matin)
    // B = cote directe finale (avant départ = ce que stocke cote_probable)
    $cote_A = is_numeric($s['cote_reference']) ? (float)$s['cote_reference'] : null;
    $cote_B = is_numeric($s['cote_finale'])    ? (float)$s['cote_finale']    : null;

    // Résultat réel
    $incident = $s['incident'] ?? '';
    if ($incident && preg_match('/DISQUALIF|DISTANC|TOMBE/i', $incident)) {
        $ordre = 99;
    } elseif (is_numeric($s['ordre'])) {
        $ordre = (int)$s['ordre'];
    } else {
        $ordre = null;
    }

    // ── Version A ──
    if ($cote_A !== null && $cote_A >= $COTE_MIN) {
        $m_A = mise_kelly($cote_A, $ROI, $FRAC_KELLY, $MAX_EXPO, $CAPITAL);
        if ($ordre === null) {
            $n_A = 0; $st_A = 'INCONNU'; $stats_A['inconnus']++;
        } elseif ($ordre === 1) {
            $n_A = round($m_A * $cote_A - $m_A, 2); $st_A = 'GAGNE'; $stats_A['gagnes']++;
            $stats_A['paris']++; $stats_A['mise'] += $m_A; $stats_A['net'] += $n_A;
        } else {
            $n_A = -$m_A; $st_A = 'PERDU';
            $stats_A['paris']++; $stats_A['mise'] += $m_A; $stats_A['net'] += $n_A;
        }
    } else {
        $m_A = 0; $n_A = 0; $st_A = 'COTE_INVALIDE'; $stats_A['sans_cote']++;
    }

    // ── Version B ──
    if ($cote_B !== null && $cote_B >= $COTE_MIN) {
        $m_B = mise_kelly($cote_B, $ROI, $FRAC_KELLY, $MAX_EXPO, $CAPITAL);
        if ($ordre === null) {
            $n_B = 0; $st_B = 'INCONNU'; $stats_B['inconnus']++;
        } elseif ($ordre === 1) {
            $n_B = round($m_B * $cote_B - $m_B, 2); $st_B = 'GAGNE'; $stats_B['gagnes']++;
            $stats_B['paris']++; $stats_B['mise'] += $m_B; $stats_B['net'] += $n_B;
        } else {
            $n_B = -$m_B; $st_B = 'PERDU';
            $stats_B['paris']++; $stats_B['mise'] += $m_B; $stats_B['net'] += $n_B;
        }
    } elseif ($cote_B === null) {
        $m_B = 0; $n_B = 0; $st_B = 'PAS_DE_RAPPORT';
        $stats_B['sans_cote']++;
    } else {
        $m_B = 0; $n_B = 0; $st_B = 'COTE_INVALIDE'; $stats_B['sans_cote']++;
    }

    $rows[] = [
        'date'      => $s['date_course'],
        'r'         => $s['reunion'],
        'c'         => $s['course'],
        'cheval'    => $s['nom'],
        'ordre'     => $ordre,
        'cote_reference' => $cote_A,
        'cote_directe'   => $cote_B,
        'diff'           => ($cote_A && $cote_B) ? round($cote_B - $cote_A, 2) : null,
        'mise_A'    => $m_A,
        'mise_B'    => $m_B,
        'net_A'     => $n_A,
        'net_B'     => $n_B,
        'statut_A'  => $st_A,
        'statut_B'  => $st_B,
    ];
}

function resume(array $s, float $capital): array {
    $hr  = $s['paris'] > 0 ? round($s['gagnes'] / $s['paris'] * 100, 1) : 0;
    $roi = $s['mise']  > 0 ? round($s['net']    / $s['mise']  * 100, 2) : 0;
    return [
        'nb_paris'     => $s['paris'],
        'nb_gagnes'    => $s['gagnes'],
        'inconnus'     => $s['inconnus'],
        'sans_cote'    => $s['sans_cote'],
        'hit_rate_pct' => $hr,
        'total_mise'   => round($s['mise'], 2),
        'total_net'    => round($s['net'],  2),
        'bankroll_fin' => round($capital + $s['net'], 2),
        'roi_pct'      => $roi,
    ];
}

// Analyse des écarts de cote (quand les deux sont dispo)
$ecarts = array_filter($rows, fn($r) => $r['diff'] !== null && $r['ordre'] !== null);
$hausse = array_filter($ecarts, fn($r) => $r['diff'] > 0);  // cote monte (derive)
$baisse = array_filter($ecarts, fn($r) => $r['diff'] < 0);  // cote baisse (raccourcit)

$gagnants_A = array_filter($rows, fn($r) => $r['statut_A'] === 'GAGNE');
$gagnants_B = array_filter($rows, fn($r) => $r['statut_B'] === 'GAGNE');

echo json_encode([
    'periode'            => 'Avril 2026',
    'capital_depart'     => $CAPITAL,
    'methode_kelly'      => 'fixe sur 100€ (pas de compounding)',
    'nb_selections'      => count($par_course),
    'resume' => [
        'A_cote_reference_ouverture' => resume($stats_A, $CAPITAL),
        'B_cote_directe_finale'      => resume($stats_B, $CAPITAL),
    ],
    'analyse_ecarts' => [
        'nb_courses_avec_deux_cotes' => count($ecarts),
        'nb_cote_monte'  => count($hausse),
        'nb_cote_baisse' => count($baisse),
        'ecart_moyen'    => count($ecarts) > 0
            ? round(array_sum(array_column(array_values($ecarts),'diff')) / count($ecarts), 2) : 0,
    ],
    'detail' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
