<?php
/**
 * ticket_prochain_test.php
 * Simule une course qualifiée dans 8 minutes pour tester le bloc alerte du dashboard.
 * Retourne exactement la même structure que ticket_prochain.php.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$tz  = new DateTimeZone('Europe/Paris');
$now = new DateTime('now', $tz);

// Course fictive dans 8 minutes
$depart = clone $now;
$depart->modify('+8 minutes');

$heure_ms    = $depart->getTimestamp() * 1000;
$heure_label = $depart->format('H:i');
$restant_sec = 8 * 60; // 480s

// Date du jour format DDMMYYYY
$date_str = $now->format('d') . $now->format('m') . $now->format('Y');

$prochaine = [
    'reunion'      => 3,
    'course'       => 2,
    'libelle'      => 'PRIX DU TEST — 1800m Plat',
    'discipline'   => 'PLAT',
    'heure_depart' => $heure_label,
    'heure_ms'     => $heure_ms,
    'restant_sec'  => $restant_sec,
    'num_cheval'   => 7,
    'nom_cheval'   => 'SILVER ARROW',
    'profil'       => 2,
    'jt_score'     => 3.8421,
    'cote_matin'   => 4.2,
    'cote_finale'  => 3.8,
    'mise'         => 8,
    'alerte_10min' => true,
    'ordre_arrive' => null,
];

$autres = [
    [
        'reunion'      => 3,
        'course'       => 4,
        'libelle'      => 'PRIX ESSAI 2 — 2100m Plat',
        'discipline'   => 'PLAT',
        'heure_depart' => $now->modify('+35 minutes')->format('H:i'),
        'heure_ms'     => ($now->getTimestamp()) * 1000,
        'restant_sec'  => 35 * 60,
        'num_cheval'   => 3,
        'nom_cheval'   => 'GOLDEN FLASH',
        'profil'       => 1,
        'jt_score'     => 4.125,
        'cote_matin'   => 6.1,
        'cote_finale'  => 5.8,
        'mise'         => 6,
        'alerte_10min' => false,
        'ordre_arrive' => null,
    ],
    [
        'reunion'      => 5,
        'course'       => 1,
        'libelle'      => 'PRIX ESSAI 3 — 1600m Plat',
        'discipline'   => 'PLAT',
        'heure_depart' => $now->modify('+62 minutes')->format('H:i'),
        'heure_ms'     => ($now->getTimestamp()) * 1000,
        'restant_sec'  => 62 * 60,
        'num_cheval'   => 11,
        'nom_cheval'   => 'QUICK STORM',
        'profil'       => 3,
        'jt_score'     => 2.9,
        'cote_matin'   => 2.5,
        'cote_finale'  => 2.3,
        'mise'         => 4,
        'alerte_10min' => false,
        'ordre_arrive' => null,
    ],
];

$toutes = array_merge([$prochaine], $autres);

echo json_encode([
    'date'           => $date_str,
    'heure_actuelle' => $now->format('H:i:s'),
    'capital'        => 100,
    'prochaine'      => $prochaine,
    'toutes_courses' => $toutes,
    'nb_courses'     => count($toutes),
    '_test'          => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
