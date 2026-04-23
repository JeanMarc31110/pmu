<?php
/**
 * analyse_cotes_16042026.php
 * Compare cote_probable (stockée) vs cote marché finale (raw_json)
 * pour voir si des changements auraient modifié la sélection.
 */
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$DATE = '16042026';

// Récupérer tous les chevaux de la journée avec cote stockée + cote rapport final
$stmt = $pdo->prepare("
    SELECT p.reunion, p.course,
           p.num_pmu, p.nom,
           mi.cote_probable      AS cote_stockee,
           mi.qualifie_final,
           mi.profil,
           mi.jt_score,
           json_extract(p.raw_json, '$.coteProbable')   AS cote_raw,
           json_extract(p.raw_json, '$.dernierRapport') AS rapport_final,
           json_extract(p.raw_json, '$.ordreArrivee')   AS ordre
    FROM participants p
    LEFT JOIN moulinette_inputs mi
           ON mi.date_course = p.date_course
          AND mi.reunion      = p.reunion
          AND mi.course       = p.course
          AND mi.num_pmu      = p.num_pmu
    WHERE p.date_course = :date
    ORDER BY p.reunion, p.course, p.num_pmu
");
$stmt->execute([':date' => $DATE]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sélection actuelle (méthode moulinette)
$stmt2 = $pdo->prepare("
    SELECT reunion, course, num_pmu, nom, cote_probable, jt_score, profil
    FROM moulinette_inputs
    WHERE date_course = :date AND qualifie_final = 1
    ORDER BY reunion, course, profil ASC, jt_score DESC
");
$stmt2->execute([':date' => $DATE]);
$qualifies = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Grouper par course → 1 cheval sélectionné par course (arbitrage)
$selectionnes = [];
$vus = [];
foreach ($qualifies as $q) {
    $key = $q['reunion'].'_'.$q['course'];
    if (!isset($vus[$key])) {
        $selectionnes[$key] = $q;
        $vus[$key] = true;
    }
}

// Analyser les changements de cote
$alertes = [];
$par_course = [];

foreach ($rows as $r) {
    $key = $r['reunion'].'_'.$r['course'];
    $par_course[$key][] = $r;

    $cote_stock = (float)($r['cote_stockee'] ?? 0);
    $cote_raw   = (float)($r['cote_raw']    ?? 0);

    if ($cote_stock <= 0 || $cote_raw <= 0) continue;

    $delta = $cote_raw - $cote_stock;
    $delta_pct = $cote_stock > 0 ? round($delta / $cote_stock * 100, 1) : 0;

    // Changement qui impacte les filtres
    $was_value  = $cote_stock >= 2.0;
    $now_value  = $cote_raw   >= 2.0;
    $was_profil = $cote_stock >= 2.0 && $cote_stock <= 8.0;
    $now_profil = $cote_raw   >= 2.0 && $cote_raw   <= 8.0;

    $impact = [];
    if ($was_value !== $now_value)   $impact[] = $was_value  ? 'perd filtre Value'   : 'gagne filtre Value';
    if ($was_profil !== $now_profil) $impact[] = $was_profil ? 'sort du Profil (>8)' : 'entre dans Profil';

    $est_selectionne = isset($selectionnes[$key]) && $selectionnes[$key]['num_pmu'] == $r['num_pmu'];

    if (abs($delta_pct) >= 15 || !empty($impact)) {
        $alertes[] = [
            'course'         => $r['reunion'].'/'.$r['course'],
            'num'            => $r['num_pmu'],
            'nom'            => $r['nom'],
            'cote_stockee'   => $cote_stock,
            'cote_marche'    => $cote_raw,
            'delta_pct'      => $delta_pct,
            'sens'           => $delta > 0 ? '↗ dérive' : '↘ baisse',
            'impact_filtre'  => $impact,
            'est_selectionne'=> $est_selectionne,
            'ordre_arrivee'  => $r['ordre'],
        ];
    }
}

// Mise Kelly avec cote stockée vs cote marché pour les sélectionnés
$kelly_diff = [];
foreach ($selectionnes as $key => $sel) {
    // Trouver la cote marché de ce cheval
    $cote_marche = null;
    foreach (($par_course[$key] ?? []) as $r) {
        if ($r['num_pmu'] == $sel['num_pmu']) {
            $cote_marche = (float)($r['cote_raw'] ?? 0);
            break;
        }
    }
    if (!$cote_marche) continue;

    $cote_stock = (float)$sel['cote_probable'];
    $roi = 0.4603;

    function kelly(float $cote, float $roi): float {
        if ($cote < 2.0) return 0;
        $b = $cote - 1;
        $p = (1 + $roi) / $cote;
        $fk = ($p * ($b + 1) - 1) / $b * 0.5;
        return max(0, round(min($fk, 0.10) * 100, 100) / 100);
    }

    $mise_stock  = kelly($cote_stock,  $roi);
    $mise_marche = kelly($cote_marche, $roi);

    $kelly_diff[] = [
        'course'       => $key,
        'nom'          => $sel['nom'],
        'cote_stockee' => $cote_stock,
        'cote_marche'  => $cote_marche,
        'kelly_stock'  => $mise_stock,
        'kelly_marche' => $mise_marche,
        'delta_kelly'  => round($mise_marche - $mise_stock, 4),
    ];
}

echo json_encode([
    'date'              => $DATE,
    'nb_chevaux'        => count($rows),
    'nb_selectionnes'   => count($selectionnes),
    'alertes_cote'      => $alertes,       // mouvements ≥15% ou impact filtre
    'impact_kelly'      => $kelly_diff,    // diff de mise sur les sélectionnés
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
