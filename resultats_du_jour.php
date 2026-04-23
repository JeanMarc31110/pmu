<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $db_path = 'C:/Users/rigai/Desktop/Base pmu/PMU_MASTER_ANALYSE.backup_20260412_161235.db';

    if (!file_exists($db_path)) {
        throw new Exception("Base de données non trouvée");
    }

    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupère les 30 dernières courses terminées
    $stmt = $pdo->prepare("
        SELECT * FROM courses 
        WHERE statut LIKE '%ARRIVEE%' 
        ORDER BY date_course DESC, heure_depart DESC 
        LIMIT 30
    ");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Traitement des résultats
    foreach ($courses as &$c) {
        $ordre = $c['ordre_arrivee'] ?? '';
        $gagnant = $c['gagnant_num'] ?? '';

        if (!empty($gagnant)) {
            $c['resultat'] = "Gagnant : " . $gagnant;
            // Pour l'instant gain_reel = null car on n'a pas ta mise
            $c['gain_reel'] = null;
        } elseif (!empty($ordre)) {
            $c['resultat'] = "Arrivée : " . $ordre;
            $c['gain_reel'] = null;
        } else {
            $c['resultat'] = "En cours";
            $c['gain_reel'] = null;
        }
    }

    $response = [
        "success" => true,
        "date" => date('Y-m-d'),
        "nb_courses" => count($courses),
        "courses" => $courses
    ];

} catch (Exception $e) {
    $response = [
        "success" => false,
        "error" => $e->getMessage(),
        "courses" => []
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>