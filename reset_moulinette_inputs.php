<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("DROP TABLE IF EXISTS moulinette_inputs");

    $pdo->exec("
        CREATE TABLE moulinette_inputs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            num_pmu INTEGER NOT NULL,
            nom TEXT,
            driver_jockey TEXT,
            entraineur TEXT,
            alpha_score REAL,
            quintile TEXT,
            profil INTEGER,
            jt_score REAL,
            cote_probable REAL,
            valeur_handicap REAL,
            qualifie_q5 INTEGER DEFAULT 0,
            qualifie_value INTEGER DEFAULT 0,
            qualifie_profil INTEGER DEFAULT 0,
            qualifie_final INTEGER DEFAULT 0,
            source_mode TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date_course, reunion, course, num_pmu)
        );
    ");

    echo json_encode([
        'success' => true,
        'message' => 'Table moulinette_inputs recréée avec la nouvelle structure'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}