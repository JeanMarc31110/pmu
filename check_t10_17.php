<?php
/**
 * check_t10_17.php
 * Inspecte les données du 17/04/2026 pour détecter des changements
 * de sélection entre le matin et T-10min.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$date = '17042026';

// 1. Schéma de moulinette_inputs
$cols = $pdo->query("PRAGMA table_info(moulinette_inputs)")->fetchAll(PDO::FETCH_ASSOC);
$col_names = array_column($cols, 'name');

// 2. Combien de lignes pour le 17 ?
$total = $pdo->query("SELECT COUNT(*) FROM moulinette_inputs WHERE date_course='$date'")->fetchColumn();
$qualifies = $pdo->query("SELECT COUNT(*) FROM moulinette_inputs WHERE date_course='$date' AND qualifie_final=1")->fetchColumn();

// 3. Y a-t-il plusieurs entrées pour la même course (indice d'un re-run) ?
$doublons = $pdo->query("
    SELECT reunion, course, COUNT(*) as nb, GROUP_CONCAT(num_pmu) as chevaux
    FROM moulinette_inputs
    WHERE date_course='$date' AND qualifie_final=1
    GROUP BY reunion, course
    HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Y a-t-il un champ created_at ou run_id ?
$has_created = in_array('created_at', $col_names);
$has_run_id  = in_array('run_id', $col_names);
$has_source  = in_array('source', $col_names);
$has_version = in_array('version', $col_names);

// 5. Si created_at existe, voir les runs distincts sur le 17
$runs = [];
if ($has_created) {
    $runs = $pdo->query("
        SELECT substr(created_at,1,16) as minute, COUNT(*) as nb
        FROM moulinette_inputs
        WHERE date_course='$date'
        GROUP BY substr(created_at,1,16)
        ORDER BY minute
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// 6. Tous les chevaux qualifiés du 17 avec leurs cotes
$qualif = $pdo->query("
    SELECT mi.reunion, mi.course, mi.num_pmu, mi.nom, mi.profil, mi.jt_score,
           mi.cote_probable,
           json_extract(p.raw_json,'$.dernierRapportReference.rapport') AS cote_ref,
           json_extract(p.raw_json,'$.dernierRapportDirect.rapport')    AS cote_dir,
           json_extract(p.raw_json,'$.ordreArrivee')                    AS ordre
    FROM moulinette_inputs mi
    JOIN participants p
      ON p.date_course=mi.date_course AND p.reunion=mi.reunion
     AND p.course=mi.course AND p.num_pmu=mi.num_pmu
    WHERE mi.date_course='$date' AND mi.qualifie_final=1
    ORDER BY mi.reunion, mi.course, mi.profil ASC, mi.jt_score DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 7. Comparer cote_ref vs cote_dir — ceux qui changent de profil
function profil_from_cote($c) {
    if ($c === null) return null;
    $c = (float)$c;
    if ($c >= 5.0 && $c <= 8.0) return 1;
    if ($c >= 3.0 && $c <  5.0) return 2;
    if ($c >= 2.0 && $c <  3.0) return 3;
    return null; // hors profil
}

$changes = [];
foreach ($qualif as $r) {
    $p_ref = profil_from_cote($r['cote_ref']);
    $p_dir = profil_from_cote($r['cote_dir']);
    $r['profil_ref'] = $p_ref;
    $r['profil_dir'] = $p_dir;
    $r['change'] = ($p_ref !== $p_dir);
    if ($r['change']) $changes[] = $r;
}

echo json_encode([
    'colonnes_moulinette_inputs' => $col_names,
    'has_created_at'  => $has_created,
    'has_run_id'      => $has_run_id,
    'has_source'      => $has_source,
    'total_lignes_17' => (int)$total,
    'qualifies_17'    => (int)$qualifies,
    'doublons_par_course' => $doublons,
    'runs_par_minute'     => $runs,
    'selections_qualifiees' => $qualif,
    'changements_profil' => $changes,
    'nb_changements'     => count($changes),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
