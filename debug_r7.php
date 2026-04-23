<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$date = $_GET['date'] ?? date('dmY');
$reunion = $_GET['reunion'] ?? 'R7';

// Tous les inputs moulinette pour cette réunion
$stmt = $pdo->prepare("
    SELECT course, num_pmu, nom, profil, jt_score,
           cote_probable, valeur_handicap,
           qualifie_q5, qualifie_final
    FROM moulinette_inputs
    WHERE date_course = :d AND reunion = :r
    ORDER BY course, num_pmu
");
$stmt->execute([':d' => $date, ':r' => $reunion]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Résumé par course
$par_course = [];
foreach ($rows as $r) {
    $c = $r['course'];
    if (!isset($par_course[$c])) {
        $par_course[$c] = ['nb_total'=>0,'q5'=>0,'final'=>0,'raisons'=>[],'chevaux'=>[]];
    }
    $par_course[$c]['nb_total']++;
    if ($r['qualifie_q5'])    $par_course[$c]['q5']++;
    if ($r['qualifie_final']) $par_course[$c]['final']++;
    $par_course[$c]['chevaux'][] = [
        'num'       => $r['num_pmu'],
        'nom'       => $r['nom'],
        'profil'    => $r['profil'],
        'jt_score'  => $r['jt_score'],
        'cote'      => $r['cote_probable'],
        'q5'        => (bool)$r['qualifie_q5'],
        'final'     => (bool)$r['qualifie_final'],
    ];
}

// Y a-t-il des données moulinette pour cette réunion ?
$nb_rows_total = count($rows);
$courses_sans_qualifie = [];
foreach ($par_course as $c => $d) {
    if ($d['final'] == 0) $courses_sans_qualifie[] = $c;
}

echo json_encode([
    'date'                  => $date,
    'reunion'               => $reunion,
    'nb_chevaux_moulinette' => $nb_rows_total,
    'courses_sans_selection'=> $courses_sans_qualifie,
    'detail_par_course'     => $par_course,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
