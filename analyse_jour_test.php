<?php
/**
 * analyse_jour_test.php
 * Données fictives : sélections du matin ET analyse T-10min pour la journée complète.
 * Retourne les deux côte à côte pour afficher les différences.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$tz  = new DateTimeZone('Europe/Paris');
$now = new DateTime('now', $tz);

// Courses fictives du jour
// Statut : 'arrive' = terminé, 'en_cours' = prochaine (T-10min), 'a_venir' = futur
$courses = [
    [
        'heure'   => '11:05', 'reunion' => 2, 'course' => 1,
        'libelle' => 'PRIX DE SAINT-CLOUD', 'discipline' => 'PLAT', 'distance' => 1400,
        'statut'  => 'arrive',
        'matin'   => ['num'=>3, 'nom'=>'SILVER ARROW',   'profil'=>2, 'cote'=>4.2, 'mise'=>8],
        'depart'  => ['num'=>3, 'nom'=>'SILVER ARROW',   'profil'=>2, 'cote'=>3.8, 'mise'=>9],
        'ordre'   => 1,  // GAGNE
        'changed' => false,
    ],
    [
        'heure'   => '11:30', 'reunion' => 3, 'course' => 1,
        'libelle' => 'PRIX DU LOUVRE', 'discipline' => 'PLAT', 'distance' => 1600,
        'statut'  => 'arrive',
        'matin'   => ['num'=>7, 'nom'=>'GOLDEN FLASH',   'profil'=>1, 'cote'=>5.8, 'mise'=>5],
        'depart'  => ['num'=>4, 'nom'=>'QUICK STAR',     'profil'=>2, 'cote'=>3.5, 'mise'=>9],
        'ordre'   => 3,  // PERDU
        'changed' => true,
        'note'    => 'Cote de GOLDEN FLASH passe de 5.8 à 9.2 → hors profil P1. QUICK STAR entre en P2.',
    ],
    [
        'heure'   => '12:01', 'reunion' => 2, 'course' => 2,
        'libelle' => 'PRIX DE VERSAILLES', 'discipline' => 'PLAT', 'distance' => 2000,
        'statut'  => 'arrive',
        'matin'   => ['num'=>9, 'nom'=>'DARK RUNNER',    'profil'=>3, 'cote'=>2.4, 'mise'=>10],
        'depart'  => ['num'=>9, 'nom'=>'DARK RUNNER',    'profil'=>3, 'cote'=>2.2, 'mise'=>11],
        'ordre'   => 2,  // PERDU
        'changed' => false,
    ],
    [
        'heure'   => '12:35', 'reunion' => 4, 'course' => 1,
        'libelle' => 'PRIX DE LONGCHAMP', 'discipline' => 'PLAT', 'distance' => 1800,
        'statut'  => 'arrive',
        'matin'   => ['num'=>5, 'nom'=>'ROYAL DREAM',    'profil'=>2, 'cote'=>4.5, 'mise'=>7],
        'depart'  => null,   // Abstention départ : cote monte à 9.5 → hors profil
        'ordre'   => 1,  // GAGNE (mais on n'était plus dessus au départ !)
        'changed' => true,
        'note'    => 'Cote de ROYAL DREAM monte de 4.5 à 9.5 → hors profil. Abstention au départ. Course GAGNÉE sans nous.',
    ],
    [
        'heure'   => '13:10', 'reunion' => 3, 'course' => 2,
        'libelle' => 'PRIX DU TROCADERO', 'discipline' => 'PLAT', 'distance' => 1200,
        'statut'  => 'arrive',
        'matin'   => null,   // Abstention matin : pas de profil valide
        'depart'  => ['num'=>11,'nom'=>'NIGHT SPIRIT',   'profil'=>1, 'cote'=>6.1, 'mise'=>4],
        'ordre'   => 1,  // GAGNE (uniquement si misé au départ)
        'changed' => true,
        'note'    => 'Pas de sélection matin. NIGHT SPIRIT entre en P1 (6.1) au départ. Course GAGNÉE.',
    ],
    [
        'heure'   => '13:48', 'reunion' => 2, 'course' => 3,
        'libelle' => 'PRIX DE CHANTILLY', 'discipline' => 'PLAT', 'distance' => 1600,
        'statut'  => 'arrive',
        'matin'   => ['num'=>2, 'nom'=>'STORM CHASER',   'profil'=>1, 'cote'=>6.8, 'mise'=>4],
        'depart'  => ['num'=>2, 'nom'=>'STORM CHASER',   'profil'=>1, 'cote'=>7.1, 'mise'=>4],
        'ordre'   => 4,  // PERDU
        'changed' => false,
    ],
    [
        'heure'   => '14:25', 'reunion' => 4, 'course' => 2,
        'libelle' => 'PRIX DE DEAUVILLE', 'discipline' => 'PLAT', 'distance' => 2100,
        'statut'  => 'en_cours',  // ← PROCHAINE (T-10min)
        'matin'   => ['num'=>6, 'nom'=>'BLUE OCEAN',     'profil'=>2, 'cote'=>3.9, 'mise'=>8],
        'depart'  => ['num'=>8, 'nom'=>'FALCON RISE',    'profil'=>2, 'cote'=>4.1, 'mise'=>7],
        'ordre'   => null,
        'changed' => true,
        'note'    => 'BLUE OCEAN passe de 3.9 à 2.1 → profil P3 désormais prioritaire. FALCON RISE (4.1) devient P2 sélectionné.',
    ],
    [
        'heure'   => '15:10', 'reunion' => 3, 'course' => 3,
        'libelle' => 'PRIX DE VICHY', 'discipline' => 'PLAT', 'distance' => 1900,
        'statut'  => 'a_venir',
        'matin'   => ['num'=>1, 'nom'=>'IRON KNIGHT',    'profil'=>1, 'cote'=>5.5, 'mise'=>5],
        'depart'  => null,  // Pas encore mis à jour
        'ordre'   => null,
        'changed' => false,
    ],
];

// Calcul P&L matin vs départ
$pnl = ['matin'=>['paris'=>0,'gagnes'=>0,'net'=>0.0],'depart'=>['paris'=>0,'gagnes'=>0,'net'=>0.0]];
foreach ($courses as $c) {
    if ($c['statut'] === 'arrive' && $c['ordre'] !== null) {
        // Matin
        if ($c['matin']) {
            $pnl['matin']['paris']++;
            if ($c['ordre'] === 1) {
                $pnl['matin']['net'] += round($c['matin']['mise'] * $c['matin']['cote'] - $c['matin']['mise'], 2);
                $pnl['matin']['gagnes']++;
            } else {
                $pnl['matin']['net'] -= $c['matin']['mise'];
            }
        }
        // Départ
        if ($c['depart']) {
            $pnl['depart']['paris']++;
            if ($c['ordre'] === 1) {
                $pnl['depart']['net'] += round($c['depart']['mise'] * $c['depart']['cote'] - $c['depart']['mise'], 2);
                $pnl['depart']['gagnes']++;
            } else {
                $pnl['depart']['net'] -= $c['depart']['mise'];
            }
        }
    }
}

echo json_encode([
    'date'     => $now->format('d') . $now->format('m') . $now->format('Y'),
    'heure'    => $now->format('H:i:s'),
    '_test'    => true,
    'courses'  => $courses,
    'pnl_matin'  => $pnl['matin'],
    'pnl_depart' => $pnl['depart'],
    'nb_changements' => count(array_filter($courses, fn($c) => $c['changed'])),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
