<?php
/**
 * double_mise_changement.php
 * Stratégie "double mise SI changement à T-10min" — Avril 2026
 *
 * Proxy : cote_ref = cote d'ouverture PMU (matin)
 *         cote_dir = dividende final (T-10min / départ)
 *
 * Profil : P1=5-8, P2=3-5, P3=2-3, null=hors profil
 *
 * 4 stratégies comparées :
 *   A. Matin seul       : 1€ si profil_ref valide (cote_ref 2-8)
 *   B. T-10min seul     : 1€ si profil_dir valide (cote_dir 2-8)
 *   C. Double TOUJOURS  : 1€ matin + 1€ T-10min si profil_dir valide
 *   D. Double SI CHANGE : 1€ matin toujours (si profil_ref valide)
 *                        + 1€ T-10min EN PLUS si le profil a changé
 *                        (entré, sorti, ou changé de bracket)
 *                        Si profil_ref null & profil_dir valide → 1€ T-10min seul
 *
 * Payout : toujours à cote_dir (dividende réel)
 * Courses terminées uniquement (ordreArrivee != null)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("
    SELECT
        mi.date_course, mi.reunion, mi.course,
        mi.num_pmu, mi.nom, mi.jt_score, mi.profil,
        json_extract(p.raw_json,'$.dernierRapportDirect.rapport')    AS cote_dir,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport') AS cote_ref,
        json_extract(p.raw_json,'$.ordreArrivee')                    AS ordre_arrive,
        c.heure_depart
    FROM moulinette_inputs mi
    JOIN participants p
      ON p.date_course=mi.date_course AND p.reunion=mi.reunion
     AND p.course=mi.course AND p.num_pmu=mi.num_pmu
    LEFT JOIN courses c
      ON c.date_course=mi.date_course AND c.reunion=mi.reunion
     AND c.course=mi.course
    WHERE mi.qualifie_final = 1
      AND mi.date_course LIKE '__042026'
      AND json_extract(p.raw_json,'$.ordreArrivee') IS NOT NULL
    ORDER BY
        substr(mi.date_course,5,4)||substr(mi.date_course,3,2)||substr(mi.date_course,1,2) ASC,
        c.heure_depart ASC NULLS LAST,
        mi.profil ASC, mi.jt_score DESC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 1 cheval par course (meilleur profil/jt_score)
$par_course = [];
foreach ($rows as $r) {
    $key = $r['date_course'].'_'.$r['reunion'].'_'.$r['course'];
    if (!isset($par_course[$key])) $par_course[$key] = $r;
}

function profil_from(?float $c): ?int {
    if ($c === null) return null;
    if ($c >= 5.0 && $c <= 8.0) return 1;
    if ($c >= 3.0 && $c <  5.0) return 2;
    if ($c >= 2.0 && $c <  3.0) return 3;
    return null;
}

function maxDD(array $tl): float {
    $peak = 0.0; $dd = 0.0;
    foreach ($tl as $v) {
        if ($v > $peak) $peak = $v;
        $d = $peak - $v; if ($d > $dd) $dd = $d;
    }
    return round($dd, 2);
}

function maxLoss(array $tl): int {
    $s = 0; $m = 0; $prev = null;
    foreach ($tl as $v) {
        if ($prev === null) { $prev = $v; continue; }
        if ($v < $prev) { $s++; if ($s > $m) $m = $s; }
        else $s = 0;
        $prev = $v;
    }
    return $m;
}

// Initialisation
$st = [];
foreach (['matin','depart','double_toujours','double_change'] as $k) {
    $st[$k] = ['paris'=>0,'gagnes'=>0,'abstentions'=>0,'net'=>0.0,'tl'=>[],'mise_totale'=>0.0];
}
$run = array_fill_keys(array_keys($st), 0.0);

// Compteurs changements
$cnt = ['confirmes'=>0,'entres'=>0,'sortis'=>0,'bracket'=>0,'total'=>0];

foreach ($par_course as $c) {
    $cote_dir = is_numeric($c['cote_dir']) ? (float)$c['cote_dir'] : null;
    $cote_ref = is_numeric($c['cote_ref']) ? (float)$c['cote_ref'] : null;
    $cote_pay = $cote_dir ?? $cote_ref;
    if (!$cote_pay || $cote_pay < 1.0) continue;

    $p_ref = profil_from($cote_ref);
    $p_dir = profil_from($cote_dir);
    $gagne = (is_numeric($c['ordre_arrive']) && (int)$c['ordre_arrive'] === 1);

    // Classement du changement
    $cnt['total']++;
    $change = ($p_ref !== $p_dir);
    if (!$change)                   $cnt['confirmes']++;
    elseif ($p_ref===null && $p_dir!==null) $cnt['entres']++;
    elseif ($p_ref!==null && $p_dir===null) $cnt['sortis']++;
    else                            $cnt['bracket']++;

    // ── A : Matin seul ───────────────────────────────────────────────────────
    if ($p_ref !== null) {
        $st['matin']['paris']++;
        $st['matin']['mise_totale'] += 1.0;
        $d = $gagne ? round($cote_pay - 1.0, 2) : -1.0;
        $st['matin']['net'] += $d;
        if ($gagne) $st['matin']['gagnes']++;
        $run['matin'] = round($run['matin'] + $d, 2);
    } else { $st['matin']['abstentions']++; }
    $st['matin']['tl'][] = $run['matin'];

    // ── B : T-10min seul ─────────────────────────────────────────────────────
    if ($p_dir !== null) {
        $st['depart']['paris']++;
        $st['depart']['mise_totale'] += 1.0;
        $d = $gagne ? round($cote_dir - 1.0, 2) : -1.0;
        $st['depart']['net'] += $d;
        if ($gagne) $st['depart']['gagnes']++;
        $run['depart'] = round($run['depart'] + $d, 2);
    } else { $st['depart']['abstentions']++; }
    $st['depart']['tl'][] = $run['depart'];

    // ── C : Double TOUJOURS ───────────────────────────────────────────────────
    if ($p_ref !== null) {
        $mise = ($p_dir !== null) ? 2.0 : 1.0;
        $st['double_toujours']['paris'] += (int)round($mise);
        $st['double_toujours']['mise_totale'] += $mise;
        $d = $gagne ? round($mise * ($cote_pay - 1.0), 2) : -$mise;
        $st['double_toujours']['net'] += $d;
        if ($gagne) $st['double_toujours']['gagnes']++;
        $run['double_toujours'] = round($run['double_toujours'] + $d, 2);
    } else { $st['double_toujours']['abstentions']++; }
    $st['double_toujours']['tl'][] = $run['double_toujours'];

    // ── D : Double SI CHANGEMENT ─────────────────────────────────────────────
    // Matin : 1€ si profil_ref valide
    // T-10min extra : +1€ si changement de profil (entré, sorti, bracket)
    $mise_d = 0.0;
    if ($p_ref !== null)  $mise_d += 1.0; // pari matin
    if ($change && $p_dir !== null) $mise_d += 1.0; // 2e pari si changement ET toujours en profil
    // Note : si sorti (p_dir=null) → pas de 2e pari (on a déjà misé 1€ matin)
    // Note : si entré (p_ref=null) → 1€ T-10min seulement

    if ($mise_d > 0.0) {
        $st['double_change']['paris'] += (int)round($mise_d);
        $st['double_change']['mise_totale'] += $mise_d;
        $d = $gagne ? round($mise_d * ($cote_pay - 1.0), 2) : -$mise_d;
        $st['double_change']['net'] += $d;
        if ($gagne) $st['double_change']['gagnes']++;
        $run['double_change'] = round($run['double_change'] + $d, 2);
    } else { $st['double_change']['abstentions']++; }
    $st['double_change']['tl'][] = $run['double_change'];
}

// Résultats
$result = [];
$labels = [
    'matin'          => '🌅 Matin seul (1€)',
    'depart'         => '🏁 T-10min seul (1€)',
    'double_toujours'=> '🔥 Double TOUJOURS (1€+1€)',
    'double_change'  => '⚡ Double SI CHANGEMENT',
];
foreach ($st as $k => $s) {
    $result[$k] = [
        'label'         => $labels[$k],
        'paris'         => $s['paris'],
        'mise_totale'   => round($s['mise_totale'], 2),
        'gagnes'        => $s['gagnes'],
        'taux'          => $s['paris']>0 ? round($s['gagnes']/$s['paris']*100,1) : 0,
        'net_total'     => round($s['net'], 2),
        'roi_par_euro'  => $s['mise_totale']>0 ? round($s['net']/$s['mise_totale']*100,1) : 0,
        'max_drawdown'  => maxDD($s['tl']),
        'serie_perdante'=> maxLoss($s['tl']),
    ];
}

echo json_encode([
    'periode'       => 'Avril 2026',
    'courses_total' => $cnt['total'],
    'repartition_changements' => [
        'confirmes'   => $cnt['confirmes'],
        'entres_T10'  => $cnt['entres'],
        'sortis_T10'  => $cnt['sortis'],
        'bracket_change' => $cnt['bracket'],
    ],
    'strategies'    => $result,
    'verdict' => (function() use ($result) {
        $nets = array_combine(array_keys($result), array_column($result, 'net_total'));
        arsort($nets);
        $best = array_key_first($nets);
        $labels = ['matin'=>'Matin seul','depart'=>'T-10min seul','double_toujours'=>'Double toujours','double_change'=>'Double si changement'];
        return $labels[$best].' est la stratégie la plus rentable (+'.round($nets[$best],2).'€ net sur avril).';
    })(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
