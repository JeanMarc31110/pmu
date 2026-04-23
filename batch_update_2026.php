<?php
/**
 * batch_update_2026.php
 * Importe et traite toutes les dates de 2026 via le pipeline complet.
 * Paramètres GET optionnels :
 *   - start  : date début JJMMAAAA (défaut: 01012026)
 *   - end    : date fin   JJMMAAAA (défaut: aujourd'hui)
 *   - force  : 1 = réimporte même les dates déjà présentes (défaut: 0)
 *   - dry    : 1 = liste seulement les dates sans importer (défaut: 0)
 */

set_time_limit(0);
ini_set('display_errors', 0);
error_reporting(E_ALL);

ob_implicit_flush(true);
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');

$dbPath = __DIR__ . '/data/pmu.sqlite';
$base   = 'http://localhost/pmu';

// ── Paramètres ──────────────────────────────────────────────────────────────
$startStr = $_GET['start'] ?? '01012026';
$endStr   = $_GET['end']   ?? date('dmY');
$force    = ($_GET['force'] ?? '0') === '1';
$dry      = ($_GET['dry']   ?? '0') === '1';

function parseDate(string $s): ?DateTime {
    if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $s, $m)) {
        $d = DateTime::createFromFormat('Y-m-d', "{$m[3]}-{$m[2]}-{$m[1]}");
        return $d ?: null;
    }
    return null;
}

$start = parseDate($startStr);
$end   = parseDate($endStr);

if (!$start || !$end || $start > $end) {
    echo "Paramètres invalides. Utilise start=JJMMAAAA&end=JJMMAAAA\n";
    exit;
}

// ── Connexion DB ─────────────────────────────────────────────────────────────
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo "Erreur DB: " . $e->getMessage() . "\n";
    exit;
}

// Dates déjà présentes dans courses
$existing = [];
try {
    $rows = $pdo->query("SELECT DISTINCT date_course FROM courses")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) $existing[$r] = true;
} catch (Throwable $e) {}

// ── Génération des dates ──────────────────────────────────────────────────────
$dates = [];
$cur = clone $start;
while ($cur <= $end) {
    $dates[] = $cur->format('dmY'); // JJMMAAAA
    $cur->modify('+1 day');
}

$total   = count($dates);
$skipped = 0;
$done    = 0;
$errors  = 0;

echo "=== Batch PMU 2026 ===\n";
echo "Période : {$startStr} → {$endStr} ({$total} jours)\n";
echo "Force   : " . ($force ? 'OUI' : 'NON') . "\n";
echo "Mode sec: " . ($dry ? 'OUI (simulation)' : 'NON') . "\n\n";

// ── Appel HTTP interne via cURL ───────────────────────────────────────────────
function callStep(string $base, string $script, array $params): array {
    $url = $base . '/' . $script . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $err) {
        return ['success' => false, 'message' => "cURL error: $err (HTTP $code)"];
    }
    $json = json_decode($body, true);
    if ($json === null) {
        return ['success' => strlen(trim($body)) > 0, 'message' => substr(trim($body), 0, 120)];
    }
    return $json;
}

// ── Pipeline par date ─────────────────────────────────────────────────────────
foreach ($dates as $i => $date) {
    $num = $i + 1;

    // Skip si déjà présent et pas force
    if (!$force && isset($existing[$date])) {
        echo "[{$num}/{$total}] {$date} — déjà présent, ignoré\n";
        $skipped++;
        continue;
    }

    echo "[{$num}/{$total}] {$date} — traitement...\n";

    if ($dry) {
        echo "  (simulation - pas d'import réel)\n";
        $done++;
        continue;
    }

    $ok = true;

    // Étape 1 : Import courses + participants
    $r = callStep($base, 'fetch_to_sqlite.php', ['date' => $date]);
    if (!($r['success'] ?? false)) {
        echo "  ✗ fetch_to_sqlite : " . ($r['message'] ?? 'erreur') . "\n";
        // Si pas de courses ce jour-là (jour sans PMU), on skip
        $errors++;
        $ok = false;
    } else {
        echo "  ✓ fetch\n";
    }

    if (!$ok) continue;

    // Étape 2 : Réparation drivers/jockeys
    $r = callStep($base, 'repair_driver_jockey.php', ['date' => $date, 'date_filter' => $date]);
    echo "  " . (($r['success'] ?? false) ? '✓' : '✗') . " repair_driver_jockey\n";

    // Étape 3 : Build JT reference (global, rapide)
    $r = callStep($base, 'build_jt_reference_from_base.php', ['min_courses' => 1]);
    echo "  " . (($r['success'] ?? false) ? '✓' : '✗') . " build_jt_reference\n";

    // Étape 4 : Injection cotes
    $r = callStep($base, 'inject_market_odds_day_from_raw.php', ['date' => $date]);
    echo "  " . (($r['success'] ?? false) ? '✓' : '✗') . " inject_market_odds\n";

    // Étape 5 : Build moulinette inputs
    $r = callStep($base, 'build_moulinette_inputs.php', ['date' => $date]);
    echo "  " . (($r['success'] ?? false) ? '✓' : '✗') . " build_moulinette_inputs\n";

    $done++;
    echo "  → OK\n\n";
}

echo "\n=== Terminé ===\n";
echo "Traités  : {$done}\n";
echo "Ignorés  : {$skipped}\n";
echo "Erreurs  : {$errors}\n";
