<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');
$cols = $pdo->query("PRAGMA table_info(courses)")->fetchAll(PDO::FETCH_ASSOC);
$sample = $pdo->query("SELECT * FROM courses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode(['columns'=>array_column($cols,'name'), 'sample'=>$sample], JSON_PRETTY_PRINT);
