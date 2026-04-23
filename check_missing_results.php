<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $mois = $_GET['mois'] ?? '042026'; // ex: 042026 pour avril 2026

    // Par date : total participants, avec ordreArrivee, avec incident, sans rien
    $stmt = $pdo->prepare("
        SELECT
            date_course,
            COUNT(*) as total,
            SUM(CASE WHEN json_extract(raw_json,'$.ordreArrivee') IS NOT NULL THEN 1 ELSE 0 END) as avec_arrivee,
            SUM(CASE WHEN json_extract(raw_json,'$.incident') IS NOT NULL THEN 1 ELSE 0 END) as avec_incident,
            SUM(CASE WHEN
                json_extract(raw_json,'$.ordreArrivee') IS NULL AND
                (json_extract(raw_json,'$.incident') IS NULL OR json_extract(raw_json,'$.incident') = '')
            THEN 1 ELSE 0 END) as manquants
        FROM participants
        WHERE date_course LIKE :pattern
        GROUP BY date_course
        ORDER BY SUBSTR(date_course,5,4)||SUBSTR(date_course,3,2)||SUBSTR(date_course,1,2)
    ");

    $stmt->execute([':pattern' => '%' . $mois]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totaux globaux
    $totalParticipants = 0;
    $totalAvecArrivee = 0;
    $totalAvecIncident = 0;
    $totalManquants = 0;

    foreach ($rows as &$r) {
        $r['total'] = (int)$r['total'];
        $r['avec_arrivee'] = (int)$r['avec_arrivee'];
        $r['avec_incident'] = (int)$r['avec_incident'];
        $r['manquants'] = (int)$r['manquants'];
        $totalParticipants += $r['total'];
        $totalAvecArrivee += $r['avec_arrivee'];
        $totalAvecIncident += $r['avec_incident'];
        $totalManquants += $r['manquants'];
    }

    // Aussi compter les paris sélectionnés par la moulinette sans résultat
    $stmtParis = $pdo->prepare("
        SELECT
            mi.date_course,
            COUNT(*) as paris_selectionnes,
            SUM(CASE WHEN
                (SELECT json_extract(p.raw_json,'$.ordreArrivee')
                 FROM participants p
                 WHERE p.date_course = mi.date_course AND p.reunion = mi.reunion AND p.course = mi.course AND p.num_pmu = mi.num_pmu
                 LIMIT 1) IS NULL AND
                (SELECT json_extract(p.raw_json,'$.incident')
                 FROM participants p
                 WHERE p.date_course = mi.date_course AND p.reunion = mi.reunion AND p.course = mi.course AND p.num_pmu = mi.num_pmu
                 LIMIT 1) IS NULL
            THEN 1 ELSE 0 END) as paris_sans_resultat
        FROM (
            SELECT date_course, reunion, course, num_pmu, qualifie_final,
                   ROW_NUMBER() OVER (PARTITION BY date_course, reunion, course ORDER BY profil ASC, jt_score DESC, valeur_handicap DESC) as rn
            FROM moulinette_inputs
            WHERE qualifie_final = 1 AND date_course LIKE :pattern
        ) mi
        WHERE mi.rn = 1
        GROUP BY mi.date_course
        ORDER BY SUBSTR(mi.date_course,5,4)||SUBSTR(mi.date_course,3,2)||SUBSTR(mi.date_course,1,2)
    ");
    $stmtParis->execute([':pattern' => '%' . $mois]);
    $parisByDate = [];
    foreach ($stmtParis->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parisByDate[$row['date_course']] = [
            'paris_selectionnes' => (int)$row['paris_selectionnes'],
            'paris_sans_resultat' => (int)$row['paris_sans_resultat']
        ];
    }

    // Merge
    foreach ($rows as &$r) {
        $d = $r['date_course'];
        $r['paris_selectionnes'] = $parisByDate[$d]['paris_selectionnes'] ?? 0;
        $r['paris_sans_resultat'] = $parisByDate[$d]['paris_sans_resultat'] ?? 0;
    }

    $totalParisSelectionnes = array_sum(array_column(array_values($parisByDate), 'paris_selectionnes'));
    $totalParisSansResultat = array_sum(array_column(array_values($parisByDate), 'paris_sans_resultat'));

    while (ob_get_level()) ob_end_clean();
    echo json_encode([
        'success' => true,
        'mois' => $mois,
        'nb_jours' => count($rows),
        'totaux' => [
            'participants' => $totalParticipants,
            'avec_arrivee' => $totalAvecArrivee,
            'avec_incident' => $totalAvecIncident,
            'manquants' => $totalManquants,
            'paris_selectionnes' => $totalParisSelectionnes,
            'paris_sans_resultat' => $totalParisSansResultat,
        ],
        'par_jour' => $rows
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
