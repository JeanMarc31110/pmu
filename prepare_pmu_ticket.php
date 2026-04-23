<?php

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function detect_python_command(): string
{
    $candidates = [
        'C:\\Users\\rigai\\AppData\\Local\\Python\\bin\\python.exe',
        'C:\\Users\\rigai\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe',
        'python',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'python') {
            return $candidate;
        }
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return 'python';
}

try {
    $reunion = trim((string)($_GET['reunion'] ?? ''));
    $course = trim((string)($_GET['course'] ?? ''));
    $horse = trim((string)($_GET['horse'] ?? ''));
    $stake = trim((string)($_GET['stake'] ?? ''));
    $baseUrl = trim((string)($_GET['base_url'] ?? 'https://www.pmu.fr/turf'));

    if ($reunion === '' || $course === '' || $horse === '' || $stake === '') {
        throw new Exception("Paramètres requis : reunion, course, horse, stake.");
    }

    $script = 'C:\\Users\\rigai\\OneDrive\\Documents\\New project\\pmu_prepare_ticket.py';
    if (!file_exists($script)) {
        throw new Exception("Script de préparation introuvable.");
    }

    $python = detect_python_command();
    $cmd = escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' --reunion ' . escapeshellarg($reunion)
        . ' --course ' . escapeshellarg($course)
        . ' --horse ' . escapeshellarg($horse)
        . ' --stake ' . escapeshellarg($stake)
        . ' --base-url ' . escapeshellarg($baseUrl)
        . ' 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $text = trim(implode("\n", $output));
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        $decoded = [
            'success' => $code === 0,
            'message' => $text !== '' ? $text : 'Aucune réponse JSON du préparateur.',
        ];
    }
    if (!isset($decoded['success'])) {
        $decoded['success'] = $code === 0;
    }
    if (!$decoded['success']) {
        throw new Exception((string)($decoded['message'] ?? 'Echec de préparation du ticket.'));
    }
    json_response($decoded);
} catch (Throwable $exc) {
    json_response([
        'success' => false,
        'message' => $exc->getMessage(),
    ]);
}
