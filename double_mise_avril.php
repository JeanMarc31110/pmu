<?php
/**
 * double_mise_avril.php  v2
 * Compare 3 stratégies sur avril 2026 (mise plate 1€) :
 *   A. Matin seul   : 1€ si cote_reference (matin) en profil P1/P2/P3 (2.0-8.0)
 *   B. T-10min seul : 1€ si cote_directe (finale) en profil (2.0-8.0)
 *                     → peut sélectionner un cheval différent si la cote a bougé
 *   C. Double mise  : 1€ matin + 1€ T-10min (si toujours en profil finale)
 *                     → 2€ si confirmé, 1€ si sorti du profil à T-10min
 *
 * Payout toujours au dividende RÉEL = cote_directe (dernierRapportDirect)
 * Sélection matin  = cote_reference (dernierRapportReference) en profil
 * Sélection T-10min= cote_directe  en profil
 *
 * Seules les courses TERMINÉES (ordreArrivee != null) sont comptées.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Récupérer les courses avril 2026, qualifiées, TERMINÉES ──────────────────
$stmt = $pdo->prepare("
    SELECT
        mi.date_course,
        mi.reunion, mi.course,
        mi.num_pmu, mi.nom,
        mi.jt_score, mi.profil,
        json_extract(p.raw_json,'$.dernierRapportDirect.rapport')    AS cote_directe,
        json_extract(p.raw_json,'$.dernierRapportReference.rapport') AS cote_reference,
        json_extract(p.raw_json,'$.ordreArrivee')                    AS ordre_arrive,
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
    WHERE mi.qualifie_final = 1
      AND mi.date_course LIKE '__042026'
      AND json_extract(p.raw_json,'$.ordreArrivee') IS NOT NULL
    ORDER BY
        substr(mi.date_course,5,4)||substr(mi.date_course,3,2)||substr(mi.date_course,1,2) ASC,
        c.heure_depart ASC NULLS LAST,
        mi.profil ASC,
        mi.jt_score DESC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Dédoublonner : 1 cheval par course (premier = meilleur profil/jt_score) ──
$par_course = [];
foreach ($rows as $r) {
    $key = $r['date_course'].'_'.$r['reunion'].'_'.$r['course'];
    if (!isset($par_course[$key])) {
        $par_course[$key] = $r;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function inProfil(?float $cote): bool {
    return $cote !== null && $cote >= 2.0 && $cote <= 8.0;
}

function maxDrawdown(array $tl): float {
    $peak = 0.0; $dd = 0.0;
    foreach ($tl as $v) {
        if ($v > $peak) $peak = $v;
        $d = $peak - $v; if ($d > $dd) $dd = $d;
    }
    return round($dd, 2);
}

function losingStreak(array $tl): int {
    $streak = 0; $max = 0; $prev = null;
    foreach ($tl as $v) {
        if ($prev === null) { $prev = $v; continue; }
        if ($v < $prev) { $streak++; if ($streak > $max) $max = $streak; }
        else $streak = 0;
        $prev = $v;
    }
    return $max;
}

// ── Calcul des 3 stratégies ───────────────────────────────────────────────────
$stats = [
    'matin'  => ['paris'=>0,'gagnes'=>0,'abstentions'=>0,'net'=>0.0,'tl'=>[]],
    'depart' => ['paris'=>0,'gagnes'=>0,'abstentions'=>0,'net'=>0.0,'tl'=>[]],
    'double' => ['paris'=>0,'gagnes'=>0,'abstentions'=>0,'net'=>0.0,'tl'=>[]],
];
$run = ['matin'=>0.0,'depart'=>0.0,'double'=>0.0];

// Compteurs pour analyser les différences matin vs T-10min
$diff = [
    'meme_cheval'         => 0,  // même sélection, confirmée
    'sorti_profil_T10'    => 0,  // cheval matin sort du profil à T-10min
    'total_terminees'     => count($par_course),
];

foreach ($par_course as $c) {
    $cote_dir = is_numeric($c['cote_directe'])  ? (float)$c['cote_directe']  : null;
    $cote_ref = is_numeric($c['cote_reference']) ? (float)$c['cote_reference'] : null;

    // Payout toujours à cote_directe (dividende réel PMU)
    // Si cote_directe absent, fallback cote_reference
    $cote_pay = $cote_dir ?? $cote_ref;
    if (!$cote_pay || $cote_pay < 1.0) continue;

    $gagne = (is_numeric($c['ordre_arrive']) && (int)$c['ordre_arrive'] === 1);

    // Éligibilité matin et T-10min
    $ok_matin  = inProfil($cote_ref);  // sélectionné le matin
    $ok_depart = inProfil($cote_dir);  // toujours en profil à T-10min

    // Stats différences
    if ($ok_matin && $ok_depart)  $diff['meme_cheval']++;
    if ($ok_matin && !$ok_depart) $diff['sorti_profil_T10']++;

    // ── A : Matin seul ───────────────────────────────────────────────────────
    if ($ok_matin) {
        $stats['matin']['paris']++;
        $delta = $gagne ? round($cote_pay - 1.0, 2) : -1.0;
        $stats['matin']['net'] += $delta;
        if ($gagne) $stats['matin']['gagnes']++;
        $run['matin'] = round($run['matin'] + $delta, 2);
    } else {
        $stats['matin']['abstentions']++;
    }
    $stats['matin']['tl'][] = $run['matin'];

    // ── B : T-10min seul ─────────────────────────────────────────────────────
    if ($ok_depart) {
        $stats['depart']['paris']++;
        $delta = $gagne ? round($cote_dir - 1.0, 2) : -1.0;
        $stats['depart']['net'] += $delta;
        if ($gagne) $stats['depart']['gagnes']++;
        $run['depart'] = round($run['depart'] + $delta, 2);
    } else {
        $stats['depart']['abstentions']++;
    }
    $stats['depart']['tl'][] = $run['depart'];

    // ── C : Double mise ──────────────────────────────────────────────────────
    // Toujours 1€ matin si ok_matin. +1€ si confirmé T-10min.
    if ($ok_matin) {
        $mise = $ok_depart ? 2.0 : 1.0; // 2€ si confirmé, 1€ sinon
        $stats['double']['paris'] += (int)round($mise);
        $delta = $gagne ? round($mise * ($cote_pay - 1.0), 2) : -$mise;
        $stats['double']['net'] += $delta;
        if ($gagne) $stats['double']['gagnes']++;
        $run['double'] = round($run['double'] + $delta, 2);
    } else {
        $stats['double']['abstentions']++;
    }
    $stats['double']['tl'][] = $run['double'];
}

// ── Résumé ────────────────────────────────────────────────────────────────────
$result = [];
foreach (['matin','depart','double'] as $k) {
    $s = $stats[$k];
    $result[$k] = [
        'label'           => ($k==='matin') ? 'Matin seul (1€)' : (($k==='depart') ? 'T-10min seul (1€)' : 'Double mise (1€+1€)'),
        'courses_jouees'  => $s['paris'],   // nb paris effectifs
        'abstentions'     => $s['abstentions'],
        'gagnes'          => $s['gagnes'],
        'taux_reussite'   => $s['paris'] > 0 ? round($s['gagnes']/$s['paris']*100, 1) : 0,
        'net_total'       => round($s['net'], 2),
        'roi_pct'         => $s['paris'] > 0 ? round($s['net']/$s['paris']*100, 1) : 0,
        'max_drawdown'    => maxDrawdown($s['tl']),
        'longest_losing'  => losingStreak($s['tl']),
    ];
}

// ── Comparaison ───────────────────────────────────────────────────────────────
$nm = $result['matin']['net_total'];
$nd = $result['depart']['net_total'];
$nc = $result['double']['net_total'];

$max_net = max($nm, $nd, $nc);
$best    = ($max_net === $nc) ? 'double' : (($max_net === $nm) ? 'matin' : 'depart');

$labels = ['matin'=>'Matin seul','depart'=>'T-10min seul','double'=>'Double mise'];
$verdict_parts = [];
$verdict_parts[] = $labels[$best].' est la meilleure stratégie nette sur avril (+'.round($max_net,2).'€).';
$verdict_parts[] = 'Double vs Matin : '.($nc>=$nm?'+':'').round($nc-$nm,2).'€.';
$verdict_parts[] = 'T-10min vs Matin : '.($nd>=$nm?'+':'').round($nd-$nm,2).'€.';
$verdict_parts[] = $diff['sorti_profil_T10'].' course(s) sur '.$diff['total_terminees'].' ont vu le cheval sortir du profil à T-10min (abstention départ).';

echo json_encode([
    'periode'          => 'Avril 2026',
    'courses_terminees'=> $diff['total_terminees'],
    'strategies'       => $result,
    'differences'      => $diff,
    'verdict'          => implode(' ', $verdict_parts),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
