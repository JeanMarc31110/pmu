<?php

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/data/pmu.sqlite';

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
    return preg_match('/^\d{8}$/', $date) ? $date : null;
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

function course_arrival_known(?string $rawJson): bool
{
    $raw = safe_json_decode($rawJson);
    if (!$raw) {
        return false;
    }
    $order = $raw['ordreArrivee'] ?? $raw['ordre_arrivee'] ?? null;
    return is_array($order) && !empty($order);
}

try {
    $date = normalize_date((string)($_GET['date'] ?? (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('dmY')));
    if (!$date) {
        throw new RuntimeException('Date invalide. Format attendu : JJMMAAAA');
    }
    $reunionFilter = isset($_GET['reunion']) && trim((string)$_GET['reunion']) !== ''
        ? strtoupper(trim((string)$_GET['reunion']))
        : null;
    $courseFilter = isset($_GET['course']) && trim((string)$_GET['course']) !== ''
        ? strtoupper(trim((string)$_GET['course']))
        : null;

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS d10_test_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            libelle TEXT,
            heure_depart TEXT,
            minutes_left INTEGER,
            selection_json TEXT,
            captured_at TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'test_live_d10_moulinette',
            UNIQUE(date_course, reunion, course)
        )
    ");

    $now = new DateTime('now', new DateTimeZone('Europe/Paris'));
    $stmt = $pdo->prepare("
        SELECT c.date_course, c.reunion, c.course, c.libelle, c.heure_depart, a.raw_json AS arrivee_raw
        FROM courses c
        LEFT JOIN arrivees a
          ON a.date_course = c.date_course
         AND a.reunion = c.reunion
         AND a.course = c.course
        WHERE c.date_course = :date
          AND c.heure_depart IS NOT NULL
          " . ($reunionFilter !== null ? "AND UPPER(c.reunion) = :reunion" : "") . "
          " . ($courseFilter !== null ? "AND UPPER(c.course) = :course" : "") . "
        ORDER BY CAST(c.heure_depart AS INTEGER), c.reunion, c.course
    ");
    $courseParams = [':date' => $date];
    if ($reunionFilter !== null) {
        $courseParams[':reunion'] = $reunionFilter;
    }
    if ($courseFilter !== null) {
        $courseParams[':course'] = $courseFilter;
    }
    $stmt->execute($courseParams);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtExists = $pdo->prepare("
        SELECT 1
        FROM d10_test_tickets
        WHERE date_course = :date
          AND reunion = :reunion
          AND course = :course
        LIMIT 1
    ");
    $stmtInsert = $pdo->prepare("
        INSERT INTO d10_test_tickets (
            date_course, reunion, course, libelle, heure_depart, minutes_left,
            selection_json, captured_at, source
        ) VALUES (
            :date_course, :reunion, :course, :libelle, :heure_depart, :minutes_left,
            :selection_json, :captured_at, 'test_live_d10_moulinette'
        )
    ");

    $dueCount = 0;
    $saved = 0;
    $alreadyPresent = 0;
    $alreadyArrived = 0;
    $withoutSelection = 0;
    $errors = [];

    foreach ($courses as $course) {
        if (course_arrival_known($course['arrivee_raw'] ?? null)) {
            $alreadyArrived++;
            continue;
        }
        $depart = (new DateTime('@' . intdiv((int)$course['heure_depart'], 1000)))->setTimezone(new DateTimeZone('Europe/Paris'));
        $minutesLeft = (int)floor(($depart->getTimestamp() - $now->getTimestamp()) / 60);
        if ($minutesLeft < -5 || $minutesLeft > 10) {
            continue;
        }
        $dueCount++;

        $stmtExists->execute([
            ':date' => $course['date_course'],
            ':reunion' => $course['reunion'],
            ':course' => $course['course'],
        ]);
        if ($stmtExists->fetchColumn()) {
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
                'message' => $summary['message'] ?? 'Résumé test D-10 indisponible',
            ];
            continue;
        }

        $row = $summary['data'][0] ?? null;
        if (!is_array($row) || empty($row['selection']) || !is_array($row['selection'])) {
            $withoutSelection++;
            continue;
        }

        $stmtInsert->execute([
            ':date_course' => $course['date_course'],
            ':reunion' => strtoupper((string)$course['reunion']),
            ':course' => strtoupper((string)$course['course']),
            ':libelle' => (string)($row['libelle'] ?? $course['libelle'] ?? ''),
            ':heure_depart' => (string)($row['heure_depart'] ?? $depart->format('H:i')),
            ':minutes_left' => $minutesLeft,
            ':selection_json' => json_encode($row['selection'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':captured_at' => $now->format(DateTimeInterface::ATOM),
        ]);
        $saved++;
    }

    json_response([
        'success' => true,
        'mode' => 'test_live_d10_no_snapshot',
        'date' => $date,
        'reunion' => $reunionFilter,
        'course' => $courseFilter,
        'due_count' => $dueCount,
        'saved' => $saved,
        'already_present' => $alreadyPresent,
        'already_arrived' => $alreadyArrived,
        'without_selection' => $withoutSelection,
        'errors_count' => count($errors),
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'mode' => 'test_live_d10_no_snapshot',
        'message' => $e->getMessage(),
    ]);
}
