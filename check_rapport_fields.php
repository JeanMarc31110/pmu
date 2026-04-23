<?php
header('Content-Type: application/json; charset=utf-8');
$pdo = new PDO('sqlite:' . __DIR__ . '/data/pmu.sqlite');

// Trouver un cheval gagnant en avril avec son raw_json complet
$row = $pdo->query("
    SELECT p.date_course, p.reunion, p.course, p.num_pmu, p.nom, p.raw_json
    FROM participants p
    WHERE p.date_course LIKE '%042026'
      AND json_extract(p.raw_json, '$.ordreArrivee') = 1
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$raw = json_decode($row['raw_json'], true);

// Lister tous les champs et leurs valeurs
$fields = [];
foreach ($raw as $k => $v) {
    if (!is_array($v)) {
        $fields[$k] = $v;
    }
}

// Chercher spécifiquement les champs liés aux cotes/rapports
$cote_fields = [];
foreach ($raw as $k => $v) {
    if (stripos($k,'cote')!==false || stripos($k,'rapport')!==false ||
        stripos($k,'odd')!==false || stripos($k,'gain')!==false ||
        stripos($k,'prix')!==false || stripos($k,'retour')!==false ||
        stripos($k,'divid')!==false || stripos($k,'pay')!==false) {
        $cote_fields[$k] = $v;
    }
}

echo json_encode([
    'cheval' => ['date'=>$row['date_course'],'reunion'=>$row['reunion'],
                 'course'=>$row['course'],'nom'=>$row['nom']],
    'tous_les_champs_scalaires' => $fields,
    'champs_cote_rapport' => $cote_fields,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
