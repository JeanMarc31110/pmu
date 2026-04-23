<?php

header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__ . '/data';

function json_response(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parse_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function normalize_date(string $date): ?string
{
    $date = trim($date);
    if ($date === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    if (preg_match('/^\d{8}$/', $date)) {
        $dt = DateTime::createFromFormat('dmY', $date, new DateTimeZone('Europe/Paris'));
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function normalize_course_key(array $row, string $date): ?string
{
    $reunion = strtoupper(trim((string)($row['reunion'] ?? '')));
    $course = strtoupper(trim((string)($row['course'] ?? '')));
    if ($reunion === '' || $course === '') {
        return null;
    }
    return $date . '_' . $reunion . '_' . $course;
}

try {
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }

    $payload = parse_input();
    $date = normalize_date((string)($payload['date'] ?? ''));
    $rows = $payload['rows'] ?? [];

    if (!$date) {
        throw new RuntimeException('Paramètre requis : date');
    }
    if (!is_array($rows)) {
        throw new RuntimeException('Paramètre requis : rows');
    }

    $filePath = $baseDir . '/d10_analysis_snapshot_' . $date . '.json';
    $existing = [
        'date' => $date,
        'analysis_mode' => 'd10',
        'updated_at' => null,
        'courses' => [],
    ];

    if (is_file($filePath)) {
        $current = json_decode((string)file_get_contents($filePath), true);
        if (is_array($current)) {
            $existing = array_merge($existing, array_intersect_key($current, $existing));
            if (isset($current['courses']) && is_array($current['courses'])) {
                $existing['courses'] = $current['courses'];
            }
        }
    }

    $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
    $saved = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (empty($row['selection']) || !is_array($row['selection'])) {
            continue;
        }

        $courseKey = normalize_course_key($row, $date);
        if ($courseKey === null) {
            continue;
        }
        if (isset($existing['courses'][$courseKey])) {
            continue;
        }

        $existing['courses'][$courseKey] = [
            'course_key' => $courseKey,
            'reunion' => strtoupper(trim((string)($row['reunion'] ?? ''))),
            'course' => strtoupper(trim((string)($row['course'] ?? ''))),
            'libelle' => (string)($row['libelle'] ?? ''),
            'heure_depart' => (string)($row['heure_depart'] ?? ''),
            'minutes_left' => isset($row['minutes_left']) ? (int)$row['minutes_left'] : null,
            'selection' => $row['selection'],
            'captured_at' => $now->format(DateTimeInterface::ATOM),
            'source' => 'd10_analysis',
        ];
        $saved++;
    }

    $existing['updated_at'] = $now->format(DateTimeInterface::ATOM);
    $existing['date'] = $date;
    $existing['analysis_mode'] = 'd10';

    file_put_contents(
        $filePath,
        json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    json_response([
        'success' => true,
        'date' => $date,
        'saved' => $saved,
        'file' => $filePath,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
