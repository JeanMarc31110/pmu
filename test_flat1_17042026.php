<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$DATE = '17042026';
$MISE = 1.0;

$stmt = $pdo->query("
    SELECT
        mi.reunion, mi.course, mi.num_pmu, mi.nom,
        mi.cote_probable                                              AS cote_directe,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport')  AS cote_reference,
        json_extract(p.raw_json,'$.ordreArrivee')                     AS ordre,
        json_extract(p.raw_json,'$.incident')                         AS incident,
        c.libelle, c.heure_depart
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
    WHERE mi.date_course = '$DATE'
      AND mi.qualifie_final = 1
    ORDER BY mi.reunion, mi.course, mi.profil ASC, mi.jt_score DESC
");
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Arbitrage 1 cheval/course
$par_course = [];
foreach ($all as $q) {
    $key = $q['reunion'].'_'.$q['course'];
    if (!isset($par_course[$key])) $par_course[$key] = $q;
}

$rows    = [];
$net_D   = 0; $net_R   = 0;
$paris_D = 0; $paris_R = 0;
$gagnes_D= 0; $gagnes_R= 0;

foreach ($par_course as $s) {
    // Incident ?
    $incident = $s['incident'] ?? '';
    if ($incident && preg_match('/DISQUALIF|DISTANC|TOMBE/i', $incident)) {
        $ordre = 99;
    } elseif (is_numeric($s['ordre'])) {
        $ordre = (int)$s['ordre'];
    } else {
        $ordre = null;  // pas encore arrivé
    }

    if ($ordre === null) continue;  // course pas encore jouée

    $cote_D = is_numeric($s['cote_directe'])   ? (float)$s['cote_directe']   : null;
    $cote_R = is_numeric($s['cote_reference'])  ? (float)$s['cote_reference'] : null;

    // Heure lisible
    $heure = null;
    if (!empty($s['heure_depart'])) {
        $ts = (int)($s['heure_depart'] / 1000);
        $heure = (new DateTime('@'.$ts))->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i');
    }

    // Pari le matin (sélection basée sur cote_reference)
    // Paiement réel au départ : cote_directe (dividende final PMU)
    // Si cote_reference absente → on utilise cote_directe pour la sélection (déjà qualifie_final=1)
    $paris_D++;
    if ($ordre === 1) {
        // Payé à la cote finale (dividende réel)
        $paiement = $cote_D ?? $cote_R;
        $n_D = $paiement !== null ? round($MISE * $paiement - $MISE, 2) : 0;
        $gagnes_D++;
    } else {
        $n_D = -$MISE;
    }
    $net_D += $n_D;

    // Pour comparaison : simulation pure cote_reference (matin → matin)
    if ($cote_R !== null) {
        $paris_R++;
        if ($ordre === 1) {
            $n_R = round($MISE * $cote_R - $MISE, 2);
            $gagnes_R++;
        } else {
            $n_R = -$MISE;
        }
        $net_R += $n_R;
    } else {
        $n_R = null;
    }

    $rows[] = [
        'heure'       => $heure,
        'reunion'     => $s['reunion'],
        'course'      => $s['course'],
        'libelle'     => $s['libelle'],
        'cheval'      => $s['nom'],
        'ordre'       => $ordre,
        'gagne'       => $ordre === 1,
        'cote_matin'  => $cote_R,
        'cote_finale' => $cote_D,
        'diff_cote'   => ($cote_R && $cote_D) ? round($cote_D - $cote_R, 2) : null,
        'net_reel'    => $n_D,   // pari matin, payé cote finale
        'net_simul'   => $n_R,   // simulation pure cote matin
    ];
}

$roi_D = $paris_D > 0 ? round($net_D / ($paris_D * $MISE) * 100, 1) : 0;
$roi_R = $paris_R > 0 ? round($net_R / ($paris_R * $MISE) * 100, 1) : 0;

echo json_encode([
    'date'   => $DATE,
    'mise'   => $MISE . '€ fixe',
    'resume' => [
        'REEL_pari_matin_paye_cote_finale' => [
            'description' => 'Mise placée le matin, paiement au dividende final PMU',
            'paris'       => $paris_D,
            'gagnes'      => $gagnes_D,
            'hit_rate'    => $paris_D > 0 ? round($gagnes_D/$paris_D*100,1).'%' : '-',
            'total_mise'  => round($paris_D * $MISE, 2),
            'net'         => round($net_D, 2),
            'roi'         => $roi_D.'%',
        ],
        'SIMUL_pari_et_paiement_cote_matin' => [
            'description' => 'Simulation : mise ET paiement à la cote de référence du matin',
            'paris'       => $paris_R,
            'gagnes'      => $gagnes_R,
            'hit_rate'    => $paris_R > 0 ? round($gagnes_R/$paris_R*100,1).'%' : '-',
            'total_mise'  => round($paris_R * $MISE, 2),
            'net'         => round($net_R, 2),
            'roi'         => $roi_R.'%',
        ],
    ],
    'detail' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
