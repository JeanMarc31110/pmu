<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

function calcMise(float $cote, float $bankroll): float {
    $roi = 0.4603; $kelly = 0.5; $seuil = 2.0; $maxExp = 0.10;
    if ($cote < $seuil) return 0.0;
    $b = $cote - 1.0;
    $p = (1.0 + $roi) / $cote;
    $f = ($p * ($b + 1.0) - 1.0) / $b;
    $mise = $bankroll * $f * $kelly;
    $limite = $bankroll * $maxExp;
    return (float) max(round(min($mise, $limite)), 1);
}

function getOrdre(?string $rawJson): ?int {
    if (!$rawJson) return null;
    $d = json_decode($rawJson, true);
    if (!$d) return null;
    $incident = strtoupper($d['incident'] ?? '');
    if (str_contains($incident, 'DISQUALIFIE') || str_contains($incident, 'DISTANCE') || str_contains($incident, 'TOMBE')) return 99;
    $o = $d['ordreArrivee'] ?? $d['ordre_arrivee'] ?? null;
    return is_numeric($o) ? (int)$o : null;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Toutes les dates disponibles triées chronologiquement
    $dates = $pdo->query("
        SELECT DISTINCT date_course FROM moulinette_inputs
        ORDER BY SUBSTR(date_course,5,4)||SUBSTR(date_course,3,2)||SUBSTR(date_course,1,2)
    ")->fetchAll(PDO::FETCH_COLUMN);

    $bankroll_depart = 181.54;
    $bankroll = $bankroll_depart;
    $historique = [];
    $peak = $bankroll_depart;
    $max_drawdown = 0.0;
    $max_dd_date = '';

    foreach ($dates as $date) {
        $courses = $pdo->prepare("SELECT DISTINCT reunion, course FROM moulinette_inputs WHERE date_course=? ORDER BY reunion, course");
        $courses->execute([$date]);

        $net_jour = 0.0; $nb = 0; $nb_gagnants = 0;

        foreach ($courses->fetchAll(PDO::FETCH_NUM) as [$reunion, $course]) {
            $rows = $pdo->prepare("SELECT num_pmu,nom,profil,jt_score,cote_probable,valeur_handicap,qualifie_final FROM moulinette_inputs WHERE date_course=? AND reunion=? AND course=?");
            $rows->execute([$date, $reunion, $course]);
            $qualifies = array_filter($rows->fetchAll(PDO::FETCH_ASSOC), fn($r) => $r['qualifie_final'] == 1);
            if (!$qualifies) continue;

            usort($qualifies, fn($a, $b) => [$a['profil'], -$b['jt_score'], -($b['valeur_handicap']??-9999)] <=> [$b['profil'], -$a['jt_score'], -($a['valeur_handicap']??-9999)]);
            $choix = array_values($qualifies)[0];
            $mise = calcMise((float)$choix['cote_probable'], $bankroll);

            $p = $pdo->prepare("SELECT raw_json FROM participants WHERE date_course=? AND reunion=? AND course=? AND num_pmu=? LIMIT 1");
            $p->execute([$date, $reunion, $course, $choix['num_pmu']]);
            $raw = $p->fetchColumn();
            $ordre = getOrdre($raw ?: null);

            if ($ordre === null) continue;
            $nb++;
            $net = ($ordre === 1) ? round($mise * ((float)$choix['cote_probable'] - 1), 2) : -$mise;
            if ($ordre === 1) $nb_gagnants++;
            $net_jour += $net;
        }

        $bankroll += $net_jour;

        // Drawdown
        if ($bankroll > $peak) $peak = $bankroll;
        $dd = $peak - $bankroll;
        if ($dd > $max_drawdown) { $max_drawdown = $dd; $max_dd_date = $date; }

        $historique[] = [
            'date' => $date,
            'paris' => $nb,
            'gagnants' => $nb_gagnants,
            'net_jour' => round($net_jour, 2),
            'bankroll' => round($bankroll, 2),
            'drawdown' => round($dd, 2)
        ];
    }

    while (ob_get_level()) ob_end_clean();
    echo json_encode([
        'success' => true,
        'bankroll_depart' => $bankroll_depart,
        'bankroll_finale' => round($bankroll, 2),
        'resultat_net' => round($bankroll - $bankroll_depart, 2),
        'max_drawdown' => round($max_drawdown, 2),
        'max_drawdown_date' => $max_dd_date,
        'nb_jours' => count($historique),
        'historique' => $historique
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
