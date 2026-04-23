<?php
header('Content-Type: application/json; charset=utf-8');
$dbPath = __DIR__ . '/data/pmu.sqlite';
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mois = $_GET['mois'] ?? '032026';
    $table = in_array($_GET['table'] ?? '', ['courses', 'participants', 'moulinette_inputs', 'market_odds']) ? $_GET['table'] : 'moulinette_inputs';
    $stmt = $pdo->prepare("
        SELECT DISTINCT date_course
        FROM $table
        WHERE date_course LIKE :pattern
        ORDER BY SUBSTR(date_course,5,4)||SUBSTR(date_course,3,2)||SUBSTR(date_course,1,2)
    ");
    $stmt->execute([':pattern' => '%' . $mois]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'mois' => $mois, 'nb' => count($dates), 'dates' => $dates], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
