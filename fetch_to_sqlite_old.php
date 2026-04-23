<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/curl_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('toDbValue')) {
    function toDbValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

$date = cleanDate($_GET['date'] ?? date('dmY'));

if (!$date) {
    jsonResponse([
        'success' => false,
        'message' => 'Date invalide. Utilise le format JJMMAAAA, ex: 16042026'
    ], 400);
}

$dbPath = __DIR__ . '/data/pmu.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS programmes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            date_programme_ts INTEGER,
            source_url TEXT,
            raw_json TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            num_reunion INTEGER,
            num_course INTEGER,
            libelle TEXT,
            heure_depart TEXT,
            discipline TEXT,
            categorie_participants TEXT,
            distance INTEGER,
            specialite TEXT,
            statut TEXT,
            categorie_statut TEXT,
            condition_sexe TEXT,
            condition_age TEXT,
            parcours TEXT,
            num_course_pmu TEXT,
            url_participants TEXT,
            raw_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date_course, reunion, course)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_course TEXT NOT NULL,
            reunion TEXT NOT NULL,
            course TEXT NOT NULL,
            num_pmu INTEGER,
            num_partant INTEGER,
            nom TEXT,
            age TEXT,
            sexe TEXT,
            race TEXT,
            driver_jockey TEXT,
            entraineur TEXT,
            proprietaire TEXT,
            musique TEXT,
            corde TEXT,
            handicap_valeur TEXT,
            poids TEXT,
            gains TEXT,
            indicateur_inedit INTEGER,
            supplement INTEGER,
            non_partant INTEGER,
            raw_json TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date_course, reunion, course, num_pmu)
        );
    ");

    $programmeUrl = PMU_BASE_URL . '/' . $date;
    $programmeRes = httpGet($programmeUrl);

    if (!$programmeRes['ok'] || empty($programmeRes['json'])) {
        jsonResponse([
            'success' => false,
            'step' => 'programme',
            'url' => $programmeUrl,
            'http_code' => $programmeRes['http_code'] ?? null,
            'error' => $programmeRes['error'] ?? 'Réponse vide',
            'data' => $programmeRes['raw'] ?? null
        ], 502);
    }

    $programmeJson = $programmeRes['json'];
    $reunions = $programmeJson['programme']['reunions'] ?? [];

    $stmtProgramme = $pdo->prepare("
        INSERT INTO programmes (date_course, date_programme_ts, source_url, raw_json)
        VALUES (:date_course, :date_programme_ts, :source_url, :raw_json)
    ");

    $stmtProgramme->execute([
        ':date_course' => $date,
        ':date_programme_ts' => $programmeJson['programme']['date'] ?? null,
        ':source_url' => $programmeUrl,
        ':raw_json' => json_encode($programmeJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);

    $stmtCourse = $pdo->prepare("
        INSERT INTO courses (
            date_course, reunion, course, num_reunion, num_course, libelle, heure_depart,
            discipline, categorie_participants, distance, specialite, statut, categorie_statut,
            condition_sexe, condition_age, parcours, num_course_pmu, url_participants, raw_json
        ) VALUES (
            :date_course, :reunion, :course, :num_reunion, :num_course, :libelle, :heure_depart,
            :discipline, :categorie_participants, :distance, :specialite, :statut, :categorie_statut,
            :condition_sexe, :condition_age, :parcours, :num_course_pmu, :url_participants, :raw_json
        )
        ON CONFLICT(date_course, reunion, course) DO UPDATE SET
            libelle = excluded.libelle,
            heure_depart = excluded.heure_depart,
            discipline = excluded.discipline,
            categorie_participants = excluded.categorie_participants,
            distance = excluded.distance,
            specialite = excluded.specialite,
            statut = excluded.statut,
            categorie_statut = excluded.categorie_statut,
            condition_sexe = excluded.condition_sexe,
            condition_age = excluded.condition_age,
            parcours = excluded.parcours,
            num_course_pmu = excluded.num_course_pmu,
            url_participants = excluded.url_participants,
            raw_json = excluded.raw_json
    ");

    $stmtParticipant = $pdo->prepare("
        INSERT INTO participants (
            date_course, reunion, course, num_pmu, num_partant, nom, age, sexe, race,
            driver_jockey, entraineur, proprietaire, musique, corde, handicap_valeur,
            poids, gains, indicateur_inedit, supplement, non_partant, raw_json
        ) VALUES (
            :date_course, :reunion, :course, :num_pmu, :num_partant, :nom, :age, :sexe, :race,
            :driver_jockey, :entraineur, :proprietaire, :musique, :corde, :handicap_valeur,
            :poids, :gains, :indicateur_inedit, :supplement, :non_partant, :raw_json
        )
        ON CONFLICT(date_course, reunion, course, num_pmu) DO UPDATE SET
            num_partant = excluded.num_partant,
            nom = excluded.nom,
            age = excluded.age,
            sexe = excluded.sexe,
            race = excluded.race,
            driver_jockey = excluded.driver_jockey,
            entraineur = excluded.entraineur,
            proprietaire = excluded.proprietaire,
            musique = excluded.musique,
            corde = excluded.corde,
            handicap_valeur = excluded.handicap_valeur,
            poids = excluded.poids,
            gains = excluded.gains,
            indicateur_inedit = excluded.indicateur_inedit,
            supplement = excluded.supplement,
            non_partant = excluded.non_partant,
            raw_json = excluded.raw_json
    ");

    $summary = [];
    $totalCourses = 0;
    $totalParticipants = 0;
    $errors = [];

    foreach ($reunions as $reunion) {
        $numOfficiel = $reunion['numOfficiel'] ?? null;
        if ($numOfficiel === null) {
            continue;
        }

        $reunionCode = 'R' . $numOfficiel;
        $courses = $reunion['courses'] ?? [];

        foreach ($courses as $course) {
            $numOrdre = $course['numOrdre'] ?? null;
            if ($numOrdre === null) {
                continue;
            }

            $courseCode = 'C' . $numOrdre;
            $participantsUrl = PMU_BASE_URL . '/' . $date . '/' . $reunionCode . '/' . $courseCode . '/participants';

            $stmtCourse->execute([
                ':date_course' => $date,
                ':reunion' => $reunionCode,
                ':course' => $courseCode,
                ':num_reunion' => $numOfficiel,
                ':num_course' => $numOrdre,
                ':libelle' => toDbValue($course['libelle'] ?? null),
                ':heure_depart' => toDbValue($course['heureDepart'] ?? null),
                ':discipline' => toDbValue($course['discipline'] ?? null),
                ':categorie_participants' => toDbValue($course['categoriePartants'] ?? null),
                ':distance' => is_numeric($course['distance'] ?? null) ? (int)$course['distance'] : null,
                ':specialite' => toDbValue($course['specialite'] ?? null),
                ':statut' => toDbValue($course['statut'] ?? null),
                ':categorie_statut' => toDbValue($course['categorieStatut'] ?? null),
                ':condition_sexe' => toDbValue($course['conditionSexe'] ?? null),
                ':condition_age' => toDbValue($course['conditionAge'] ?? null),
                ':parcours' => toDbValue($course['parcours'] ?? null),
                ':num_course_pmu' => toDbValue($course['numCourseDedoublee'] ?? null),
                ':url_participants' => $participantsUrl,
                ':raw_json' => json_encode($course, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $participantsRes = httpGet($participantsUrl);
            $totalCourses++;

            if (!$participantsRes['ok'] || empty($participantsRes['json'])) {
                $errors[] = [
                    'reunion' => $reunionCode,
                    'course' => $courseCode,
                    'url' => $participantsUrl,
                    'http_code' => $participantsRes['http_code'] ?? null,
                    'error' => $participantsRes['error'] ?? 'Réponse vide'
                ];
                continue;
            }

            $participantsJson = $participantsRes['json'];
            $partants = $participantsJson['participants'] ?? [];

            $countThisRace = 0;

            foreach ($partants as $partant) {
                $nomDriver = null;
                if (!empty($partant['driver']['nom'])) {
                    $nomDriver = $partant['driver']['nom'];
                } elseif (!empty($partant['jockey']['nom'])) {
                    $nomDriver = $partant['jockey']['nom'];
                }

                $stmtParticipant->execute([
                    ':date_course' => $date,
                    ':reunion' => $reunionCode,
                    ':course' => $courseCode,
                    ':num_pmu' => $partant['numPmu'] ?? null,
                    ':num_partant' => $partant['numPartant'] ?? null,
                    ':nom' => toDbValue($partant['nom'] ?? null),
                    ':age' => toDbValue($partant['age'] ?? null),
                    ':sexe' => toDbValue($partant['sexe'] ?? null),
                    ':race' => toDbValue($partant['race'] ?? null),
                    ':driver_jockey' => toDbValue($nomDriver),
                    ':entraineur' => toDbValue($partant['entraineur']['nom'] ?? ($partant['entraineur'] ?? null)),
                    ':proprietaire' => toDbValue($partant['proprietaire']['nom'] ?? ($partant['proprietaire'] ?? null)),
                    ':musique' => toDbValue($partant['musique'] ?? null),
                    ':corde' => toDbValue($partant['placeCorde'] ?? null),
                    ':handicap_valeur' => toDbValue($partant['handicapValeur'] ?? null),
                    ':poids' => toDbValue($partant['poidsConditionMonte'] ?? null),
                    ':gains' => toDbValue($partant['gainsParticipant'] ?? null),
                    ':indicateur_inedit' => !empty($partant['indicateurInedit']) ? 1 : 0,
                    ':supplement' => !empty($partant['supplement']) ? 1 : 0,
                    ':non_partant' => !empty($partant['statut']) && $partant['statut'] === 'NP' ? 1 : 0,
                    ':raw_json' => json_encode($partant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);

                $countThisRace++;
                $totalParticipants++;
            }

            $summary[] = [
                'reunion' => $reunionCode,
                'course' => $courseCode,
                'libelle' => $course['libelle'] ?? null,
                'participants_count' => $countThisRace
            ];
        }
    }

    jsonResponse([
        'success' => true,
        'db_path' => $dbPath,
        'date' => $date,
        'total_courses_processed' => $totalCourses,
        'total_participants_inserted' => $totalParticipants,
        'errors_count' => count($errors),
        'errors' => $errors,
        'summary' => $summary
    ]);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}