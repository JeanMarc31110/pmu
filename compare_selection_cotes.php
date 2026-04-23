<?php
/**
 * compare_selection_cotes.php
 * Pour chaque course d'avril 2026, compare le cheval sélectionné
 * avec les cotes de référence (matin) vs les cotes directes (avant départ).
 */
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$MOIS   = '04';
$ANNEE  = '2026';

// Seuils profil
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
        $cote  = is_numeric($ch[$coteField]) ? (float)$ch[$coteField] : null;
        $profil = deriveProfilFromCote($cote);
        if ($profil !== null && $cote >= 2.0) {
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

// Récupérer tous les chevaux qualifiés Q5 d'avril avec les deux cotes
$stmt = $pdo->prepare("
    SELECT
        mi.date_course, mi.reunion, mi.course,
        mi.num_pmu, mi.nom, mi.jt_score, mi.qualifie_q5,
        mi.cote_probable                                              AS cote_directe,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport')  AS cote_reference,
        json_extract(p.raw_json,'$.ordreArrivee')                     AS ordre
    FROM moulinette_inputs mi
    JOIN participants p
      ON p.date_course = mi.date_course
     AND p.reunion     = mi.reunion
     AND p.course      = mi.course
     AND p.num_pmu     = mi.num_pmu
    WHERE mi.date_course LIKE :pattern
      AND mi.qualifie_q5 = 1
    ORDER BY mi.date_course, mi.reunion, mi.course, mi.num_pmu
");
$stmt->execute([':pattern' => '%'.$MOIS.$ANNEE]);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper par course
$par_course = [];
foreach ($all as $r) {
    $key = $r['date_course'].'_'.$r['reunion'].'_'.$r['course'];
    $par_course[$key][] = $r;
}

$changements    = [];
$nb_total       = 0;
$nb_change      = 0;
$nb_abstention_dir_seulement = 0;  // abstention avec direct, jouée avec référence
$nb_abstention_ref_seulement = 0;  // abstention avec référence, jouée avec direct
$nb_cheval_diff = 0;               // course jouée dans les deux cas mais cheval différent

foreach ($par_course as $key => $chevaux) {
    $nb_total++;
    $parts   = explode('_', $key);
    $date    = $parts[0];
    $reunion = $parts[1];
    $course  = $parts[2];

    $sel_dir = selectHorse($chevaux, 'cote_directe');
    $sel_ref = selectHorse($chevaux, 'cote_reference');

    $differ = false;
    $raison = null;

    if ($sel_dir === null && $sel_ref === null) {
        // Abstention dans les deux cas → pas de changement
        continue;
    } elseif ($sel_dir !== null && $sel_ref === null) {
        $differ = true; $raison = 'jouee_avec_directe_seulement';
        $nb_abstention_ref_seulement++;
    } elseif ($sel_dir === null && $sel_ref !== null) {
        $differ = true; $raison = 'jouee_avec_reference_seulement';
        $nb_abstention_dir_seulement++;
    } elseif ($sel_dir['num_pmu'] !== $sel_ref['num_pmu']) {
        $differ = true; $raison = 'cheval_different';
        $nb_cheval_diff++;
    }

    if ($differ) {
        $nb_change++;
        $ordre_dir = null; $coted_dir = null;
        foreach ($chevaux as $ch) {
            if ($sel_dir && $ch['num_pmu'] == $sel_dir['num_pmu']) {
                $ordre_dir = is_numeric($ch['ordre'])        ? (int)$ch['ordre']          : null;
                $coted_dir = is_numeric($ch['cote_directe']) ? (float)$ch['cote_directe'] : null;
            }
        }
        $ordre_ref = null; $coted_ref = null;
        foreach ($chevaux as $ch) {
            if ($sel_ref && $ch['num_pmu'] == $sel_ref['num_pmu']) {
                $ordre_ref = is_numeric($ch['ordre'])        ? (int)$ch['ordre']          : null;
                $coted_ref = is_numeric($ch['cote_directe']) ? (float)$ch['cote_directe'] : null;
            }
        }

        $changements[] = [
            'date'          => $date,
            'reunion'       => $reunion,
            'course'        => $course,
            'raison'        => $raison,
            'sel_directe'   => $sel_dir ? [
                'num'          => $sel_dir['num_pmu'],
                'nom'          => $sel_dir['nom'],
                'cote_sel'     => $sel_dir['_cote_used'],  // cote utilisée pour sélection
                'cote_paiement'=> $coted_dir,              // dividende réel PMU
                'profil'       => $sel_dir['_profil_used'],
                'ordre'        => $ordre_dir,
                'gagne'        => $ordre_dir === 1,
            ] : null,
            'sel_reference' => $sel_ref ? [
                'num'          => $sel_ref['num_pmu'],
                'nom'          => $sel_ref['nom'],
                'cote_sel'     => $sel_ref['_cote_used'],  // cote matin (sélection)
                'cote_paiement'=> $coted_ref,              // dividende réel PMU
                'profil'       => $sel_ref['_profil_used'],
                'ordre'        => $ordre_ref,
                'gagne'        => $ordre_ref === 1,
            ] : null,
        ];
    }
}

$MISE = 1.0;

// P&L global sur TOUTES les courses (pas seulement les changements)
// On repart de tous les chevaux pour calculer les deux sélections complètes
$pnl_dir = ['paris'=>0,'gagnes'=>0,'net'=>0.0];
$pnl_ref = ['paris'=>0,'gagnes'=>0,'net'=>0.0];

foreach ($par_course as $key => $chevaux) {
    $sel_d = selectHorse($chevaux, 'cote_directe');
    $sel_r = selectHorse($chevaux, 'cote_reference');

    // Paiement toujours à la cote DIRECTE (dividende PMU réel)
    // Seule la sélection du cheval change entre les deux méthodes

    if ($sel_d) {
        foreach ($chevaux as $ch) {
            if ($ch['num_pmu'] == $sel_d['num_pmu']) {
                $ord    = is_numeric($ch['ordre'])        ? (int)$ch['ordre']          : null;
                $cote_d = is_numeric($ch['cote_directe']) ? (float)$ch['cote_directe'] : null;
                if ($ord !== null && $cote_d !== null) {
                    $pnl_dir['paris']++;
                    if ($ord === 1) {
                        $pnl_dir['net'] += round($MISE * $cote_d - $MISE, 2);
                        $pnl_dir['gagnes']++;
                    } else {
                        $pnl_dir['net'] -= $MISE;
                    }
                }
                break;
            }
        }
    }

    if ($sel_r) {
        foreach ($chevaux as $ch) {
            if ($ch['num_pmu'] == $sel_r['num_pmu']) {
                $ord    = is_numeric($ch['ordre'])        ? (int)$ch['ordre']          : null;
                $cote_d = is_numeric($ch['cote_directe']) ? (float)$ch['cote_directe'] : null;
                if ($ord !== null && $cote_d !== null) {
                    $pnl_ref['paris']++;
                    if ($ord === 1) {
                        // Payé au dividende réel (cote directe), sélection faite sur cote matin
                        $pnl_ref['net'] += round($MISE * $cote_d - $MISE, 2);
                        $pnl_ref['gagnes']++;
                    } else {
                        $pnl_ref['net'] -= $MISE;
                    }
                }
                break;
            }
        }
    }
}

// Stats sur les changements uniquement
$cheval_diff_dir_gagne = count(array_filter($changements,
    fn($c) => $c['raison']==='cheval_different' && $c['sel_directe'] && $c['sel_directe']['gagne']));
$cheval_diff_ref_gagne = count(array_filter($changements,
    fn($c) => $c['raison']==='cheval_different' && $c['sel_reference'] && $c['sel_reference']['gagne']));

// P&L sur les seules courses en changement
$pnl_chg_dir = ['paris'=>0,'gagnes'=>0,'net'=>0.0];
$pnl_chg_ref = ['paris'=>0,'gagnes'=>0,'net'=>0.0];
foreach ($changements as $c) {
    // Paiement toujours à cote_paiement (dividende réel PMU)
    if ($c['sel_directe'] && $c['sel_directe']['ordre'] !== null && $c['sel_directe']['cote_paiement'] !== null) {
        $pnl_chg_dir['paris']++;
        if ($c['sel_directe']['gagne']) {
            $pnl_chg_dir['net'] += round($MISE * $c['sel_directe']['cote_paiement'] - $MISE, 2);
            $pnl_chg_dir['gagnes']++;
        } else {
            $pnl_chg_dir['net'] -= $MISE;
        }
    }
    if ($c['sel_reference'] && $c['sel_reference']['ordre'] !== null && $c['sel_reference']['cote_paiement'] !== null) {
        $pnl_chg_ref['paris']++;
        if ($c['sel_reference']['gagne']) {
            $pnl_chg_ref['net'] += round($MISE * $c['sel_reference']['cote_paiement'] - $MISE, 2);
            $pnl_chg_ref['gagnes']++;
        } else {
            $pnl_chg_ref['net'] -= $MISE;
        }
    }
}

function pnlResume(array $p, float $mise): array {
    $hr  = $p['paris'] > 0 ? round($p['gagnes']/$p['paris']*100,1) : 0;
    $roi = $p['paris'] > 0 ? round($p['net']/($p['paris']*$mise)*100,1) : 0;
    return [
        'paris'   => $p['paris'],
        'gagnes'  => $p['gagnes'],
        'hit_pct' => $hr,
        'net'     => round($p['net'],2),
        'roi_pct' => $roi,
    ];
}

// Cas spécifiques : jouée matin → PERDU, départ → cheval différent → GAGNÉ
$ref_perd_direct_gagne = array_values(array_filter($changements, fn($c) =>
    $c['raison'] === 'cheval_different'
    && $c['sel_reference'] !== null
    && $c['sel_reference']['ordre'] !== null
    && $c['sel_reference']['ordre'] !== 1
    && $c['sel_directe'] !== null
    && $c['sel_directe']['ordre'] === 1
));

// Cas inverses : jouée départ → PERDU, matin → cheval différent → GAGNÉ
$direct_perd_ref_gagne = array_values(array_filter($changements, fn($c) =>
    $c['raison'] === 'cheval_different'
    && $c['sel_directe'] !== null
    && $c['sel_directe']['ordre'] !== null
    && $c['sel_directe']['ordre'] !== 1
    && $c['sel_reference'] !== null
    && $c['sel_reference']['ordre'] === 1
));

// Cas : abstention matin (hors filtre), jouée départ → GAGNÉ
$abstention_matin_direct_gagne = array_values(array_filter($changements, fn($c) =>
    $c['raison'] === 'jouee_avec_directe_seulement'
    && $c['sel_directe'] !== null
    && $c['sel_directe']['ordre'] === 1
));

// Cas : jouée matin → GAGNÉ, abstention départ
$matin_gagne_abstention_direct = array_values(array_filter($changements, fn($c) =>
    $c['raison'] === 'jouee_avec_reference_seulement'
    && $c['sel_reference'] !== null
    && $c['sel_reference']['ordre'] === 1
));

echo json_encode([
    'periode'      => "Avril $ANNEE",
    'mise_fixe'    => $MISE.'€',

    'global_toutes_courses' => [
        'nb_courses'    => $nb_total,
        'cote_directe'  => pnlResume($pnl_dir, $MISE),
        'cote_reference'=> pnlResume($pnl_ref, $MISE),
        'diff_net'      => round($pnl_ref['net'] - $pnl_dir['net'], 2),
    ],

    'sur_courses_avec_changement' => [
        'nb_courses'    => $nb_change,
        'pct_total'     => round($nb_change/$nb_total*100,1).'%',
        'cote_directe'  => pnlResume($pnl_chg_dir, $MISE),
        'cote_reference'=> pnlResume($pnl_chg_ref, $MISE),
        'diff_net'      => round($pnl_chg_ref['net'] - $pnl_chg_dir['net'], 2),
    ],

    'detail_changements' => [
        'cheval_different'            => $nb_cheval_diff,
        'jouee_direct_abstention_ref' => $nb_abstention_ref_seulement,
        'jouee_ref_abstention_direct' => $nb_abstention_dir_seulement,
    ],

    'cas_cles' => [
        'matin_perd_depart_gagne_cheval_diff' => [
            'nb'     => count($ref_perd_direct_gagne),
            'detail' => $ref_perd_direct_gagne,
        ],
        'depart_perd_matin_gagne_cheval_diff' => [
            'nb'     => count($direct_perd_ref_gagne),
            'detail' => $direct_perd_ref_gagne,
        ],
        'abstention_matin_direct_gagne' => [
            'nb'     => count($abstention_matin_direct_gagne),
            'detail' => $abstention_matin_direct_gagne,
        ],
        'matin_gagne_abstention_direct' => [
            'nb'     => count($matin_gagne_abstention_direct),
            'detail' => $matin_gagne_abstention_direct,
        ],
    ],
    'changements' => $changements,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
