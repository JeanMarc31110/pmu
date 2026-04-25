<?php
declare(strict_types=1);

$dbPath = __DIR__ . '/../data/pmu.sqlite';
$date = $argv[1] ?? (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('dmY');
if (!preg_match('/^\d{8}$/', $date)) {
    fwrite(STDERR, "Date invalide. Format attendu : JJMMAAAA\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("
    SELECT reunion, course, heure_depart
    FROM courses
    WHERE date_course = :date
      AND heure_depart IS NOT NULL
    ORDER BY CAST(heure_depart AS INTEGER), reunion, course
");
$stmt->execute([':date' => $date]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$scheduled = 0;
$skippedPast = 0;
$errors = 0;

foreach ($courses as $course) {
    $reunion = strtoupper((string)$course['reunion']);
    $courseCode = strtoupper((string)$course['course']);
    $depart = (new DateTime('@' . intdiv((int)$course['heure_depart'], 1000)))->setTimezone(new DateTimeZone('Europe/Paris'));
    $runAt = (clone $depart)->modify('-10 minutes');

    if ($runAt <= $now) {
        $skippedPast++;
        continue;
    }

    $taskName = "PMU D10 {$date} {$reunion}{$courseCode}";
    $time = $runAt->format('H:i');
    $taskDate = $runAt->format('d/m/Y');
    $action = 'wscript.exe //B //Nologo "C:\xampp\htdocs\pmu\scripts\capture_d10_course_hidden.vbs" "' . $date . '" "' . $reunion . '" "' . $courseCode . '"';

    $cmd = [
        'schtasks',
        '/Create',
        '/F',
        '/SC', 'ONCE',
        '/TN', $taskName,
        '/TR', $action,
        '/ST', $time,
        '/SD', $taskDate,
    ];

    $escaped = array_map('escapeshellarg', $cmd);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $escaped) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        $errors++;
        fwrite(STDERR, "ERROR {$taskName}: " . implode(' ', $output) . "\n");
        continue;
    }
    $scheduled++;
}

echo json_encode([
    'success' => $errors === 0,
    'date' => $date,
    'courses' => count($courses),
    'scheduled' => $scheduled,
    'skipped_past' => $skippedPast,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($errors === 0 ? 0 : 1);
