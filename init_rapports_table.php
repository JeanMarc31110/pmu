<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("DROP TABLE IF EXISTS rapports");

    $pdo->exec("
        CREATE TABLE rapports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            endpoint_tested TEXT,
            type_pari TEXT,
            type_rapport TEXT,
            rapport TEXT,
            indicateur_tendance TEXT,
            nombre_indicateur_tendance TEXT,
            date_rapport_ts TEXT,
            permutation TEXT,
            favoris INTEGER,
            num_pmu_1 TEXT,
            num_pmu_2 TEXT,
            num_pmu_3 TEXT,
            num_pmu_4 TEXT,
            num_pmu_5 TEXT,
            grosse_prise INTEGER,
            raw_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_rapports_unique
        ON rapports (
            date_course, reunion, course, type_pari, type_rapport,
            permutation, num_pmu_1, num_pmu_2, num_pmu_3, num_pmu_4, num_pmu_5
        );
    ");

    echo json_encode([
        'success' => true,
        'message' => 'Table rapports créée avec succès',
        'db_path' => $dbPath
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}