<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$statePath = 'C:\\Users\\rigai\\OneDrive\\Documents\\New project\\agent_state\\ticket_pmu_10min_state.json';
$enabledRaw = $_REQUEST['enabled'] ?? null;

if ($enabledRaw === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Paramètre requis: enabled=1|0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$enabled = in_array((string)$enabledRaw, ['1', 'true', 'on', 'yes'], true);
$stateDir = dirname($statePath);
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0777, true);
}

$state = [];
if (is_file($statePath)) {
    $raw = @file_get_contents($statePath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }
}

$push = $state['push'] ?? [];
if (!is_array($push)) {
    $push = [];
}

if ($enabled) {
    $provider = strtolower(trim((string)($push['provider_before_disable'] ?? $push['provider'] ?? '')));
    if ($provider === '' || $provider === 'none') {
        $provider = 'ntfy';
    }
    $push['provider'] = $provider;
    $push['enabled'] = true;
} else {
    $currentProvider = strtolower(trim((string)($push['provider'] ?? '')));
    if ($currentProvider !== '' && $currentProvider !== 'none') {
        $push['provider_before_disable'] = $currentProvider;
    }
    $push['provider'] = 'none';
    $push['enabled'] = false;
}

$state['push'] = $push;
$state['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM);

$ok = @file_put_contents(
    $statePath,
    json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($ok === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Impossible d\'écrire l\'état push',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$provider = strtolower(trim((string)($push['provider'] ?? 'none')));
echo json_encode([
    'success' => true,
    'push_enabled' => $provider !== 'none',
    'push_provider' => $provider,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

