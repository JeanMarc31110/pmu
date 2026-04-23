<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$statePath = 'C:\\Users\\rigai\\OneDrive\\Documents\\New project\\agent_state\\ticket_pmu_10min_state.json';
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));

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

$lastCheckedAt = (string)($state['last_checked_at'] ?? $state['updated_at'] ?? '');
$lastLoopAt = (string)($state['last_loop_at'] ?? '');
$ageSeconds = null;
if ($lastCheckedAt !== '') {
    try {
        $checked = new DateTimeImmutable($lastCheckedAt);
        $ageSeconds = max(0, $now->getTimestamp() - $checked->getTimestamp());
    } catch (Throwable $e) {
        $ageSeconds = null;
    }
}

$status = strtolower(trim((string)($state['watcher_status'] ?? 'unknown')));
$isStale = $ageSeconds !== null ? $ageSeconds > 180 : true;

echo json_encode([
    'success' => true,
    'watcher_status' => $status,
    'last_checked_at' => $lastCheckedAt ?: null,
    'last_loop_at' => $lastLoopAt ?: null,
    'age_seconds' => $ageSeconds,
    'is_stale' => $isStale,
    'last_ticket' => $state['last_ticket'] ?? null,
    'last_course_id' => $state['last_course_id'] ?? null,
    'last_live_cote' => $state['last_live_cote'] ?? null,
    'live_cotes_by_course' => $state['live_cotes_by_course'] ?? [],
    'last_error' => $state['last_error'] ?? null,
    'sent_count' => is_array($state['sent_course_ids'] ?? null) ? count($state['sent_course_ids']) : 0,
    'push_provider' => $state['push']['provider'] ?? null,
    'ntfy_topic' => $state['push']['ntfy_topic'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
