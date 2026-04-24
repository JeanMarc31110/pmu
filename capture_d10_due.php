<?php

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';
$snapshotDir = __DIR__ . '/data';

function json_response(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_date(string $date): ?string
{
    $date = trim($date);
    if (preg_match('/^\d{8}$/', $date)) {
        return $date;
    }
    return null;
}

function normalize_date_for_snapshot(string $date): string
{
    $dt = DateTime::createFromFormat('dmY', $date, new DateTimeZone('Europe/Paris'));
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }
    return $date;
}

function safe_json_decode(?string $value): ?array
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function fetch_json_url(string $url): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }
    $raw = preg_replace('/^[\x{FEFF}\s]+/u', '', $raw);
    return safe_json_decode($raw);
}

function load_snapshot(string $filePath, string $snapshotDate): array
{
    $existing = [
        'date' => $snapshotDate,
        'analysis_mode' => 'd10',
        'updated_at' => null,
        'courses' => [],
    ];

    if (!is_file($filePath)) {
        return $existing;
    }

    $current = safe_json_decode((string)file_get_contents($filePath));
    if (!$current) {
        return $existing;
    }

    foreach (['date', 'analysis_mode', 'updated_at'] as $key) {
        if (array_key_exists($key, $current)) {
            $existing[$key] = $current[$key];
        }
    }
    if (isset($current['courses']) && is_array($current['courses'])) {
        $existing['courses'] = $current['courses'];
    }

    return $existing;
}

try {
    $date = normalize_date((string)($_GET['date'] ?? (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('dmY')));
    if (!$date) {
        throw new RuntimeException('Date invalide. Format attendu : JJMMAAAA');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
    $stmt = $pdo->prepare("
        SELECT date_course, reunion, course, libelle, heure_depart
        FROM courses
        WHERE date_course = :date
          AND heure_depart IS NOT NULL
        ORDER BY CAST(heure_depart AS INTEGER), reunion, course
    ");
    $stmt->execute([':date' => $date]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dueCourses = [];
    foreach ($courses as $course) {
        $depart = (new DateTime('@' . intdiv((int)$course['heure_depart'], 1000)))->setTimezone(new DateTimeZone('Europe/Paris'));
        $minutesLeft = (int)floor(($depart->getTimestamp() - $now->getTimestamp()) / 60);
        if ($minutesLeft >= 0 && $minutesLeft <= 10) {
            $course['minutes_left'] = $minutesLeft;
            $course['heure_depart_label'] = $depart->format('H:i');
            $dueCourses[] = $course;
        }
    }

    if (!is_dir($snapshotDir)) {
        mkdir($snapshotDir, 0777, true);
    }

    $snapshotDate = normalize_date_for_snapshot($date);
    $snapshotPath = $snapshotDir . '/d10_analysis_snapshot_' . $snapshotDate . '.json';
    $snapshot = load_snapshot($snapshotPath, $snapshotDate);

    $saved = 0;
    $alreadyPresent = 0;
    $withoutSelection = 0;
    $errors = [];

    foreach ($dueCourses as $course) {
        $courseKey = $snapshotDate . '_' . strtoupper((string)$course['reunion']) . '_' . strtoupper((string)$course['course']);
        if (isset($snapshot['courses'][$courseKey])) {
            $alreadyPresent++;
            continue;
        }

        $url = sprintf(
            'http://localhost/pmu/query_day_moulinette_summary.php?date=%s&capital=100&live=1&reunion=%s&course=%s',
            rawurlencode($date),
            rawurlencode((string)$course['reunion']),
            rawurlencode((string)$course['course'])
        );
        $summary = fetch_json_url($url);
        if (!$summary || empty($summary['success']) || empty($summary['data']) || !is_array($summary['data'])) {
            $errors[] = [
                'reunion' => $course['reunion'],
                'course' => $course['course'],
                'message' => $summary['message'] ?? 'Résumé D-10 indisponible',
            ];
            continue;
        }

        $row = $summary['data'][0] ?? null;
        if (!is_array($row) || empty($row['selection']) || !is_array($row['selection'])) {
            $withoutSelection++;
            continue;
        }

        $snapshot['courses'][$courseKey] = [
            'course_key' => $courseKey,
            'reunion' => strtoupper((string)$course['reunion']),
            'course' => strtoupper((string)$course['course']),
            'libelle' => (string)($row['libelle'] ?? $course['libelle'] ?? ''),
            'heure_depart' => (string)($row['heure_depart'] ?? $course['heure_depart_label'] ?? ''),
            'minutes_left' => $course['minutes_left'],
            'selection' => $row['selection'],
            'captured_at' => $now->format(DateTimeInterface::ATOM),
            'source' => 'd10_analysis',
        ];
        $saved++;
    }

    $snapshot['updated_at'] = $now->format(DateTimeInterface::ATOM);
    $snapshot['date'] = $snapshotDate;
    $snapshot['analysis_mode'] = 'd10';

    file_put_contents(
        $snapshotPath,
        json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    json_response([
        'success' => true,
        'date' => $date,
        'snapshot_file' => $snapshotPath,
        'due_count' => count($dueCourses),
        'saved' => $saved,
        'already_present' => $alreadyPresent,
        'without_selection' => $withoutSelection,
        'errors_count' => count($errors),
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
