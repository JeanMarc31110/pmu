<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS market_odds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            num_pmu INTEGER NOT NULL,
            cote_probable REAL NOT NULL,
            captured_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $rows = [
        [
            'date_course' => '15042026',
            'reunion' => 'R1',
            'course' => 'C1',
            'num_pmu' => 1,
            'cote_probable' => 6.5,
            'captured_at' => '2026-04-15 18:10:00'
        ],
        [
            'date_course' => '15042026',
            'reunion' => 'R1',
            'course' => 'C1',
            'num_pmu' => 2,
            'cote_probable' => 4.4,
            'captured_at' => '2026-04-15 18:10:00'
        ],
        [
            'date_course' => '15042026',
            'reunion' => 'R1',
            'course' => 'C1',
            'num_pmu' => 3,
            'cote_probable' => 2.7,
            'captured_at' => '2026-04-15 18:10:00'
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO market_odds (
            date_course, reunion, course, num_pmu, cote_probable, captured_at
        ) VALUES (
            :date_course, :reunion, :course, :num_pmu, :cote_probable, :captured_at
        )
    ");

    $count = 0;

    foreach ($rows as $r) {
        $stmt->execute([
            ':date_course' => $r['date_course'],
            ':reunion' => $r['reunion'],
            ':course' => $r['course'],
            ':num_pmu' => (int)$r['num_pmu'],
            ':cote_probable' => (float)$r['cote_probable'],
            ':captured_at' => $r['captured_at']
        ]);
        $count++;
    }

    echo json_encode([
        'success' => true,
        'rows_inserted' => $count,
        'message' => 'Market odds importées'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
