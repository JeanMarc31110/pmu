<?php
error_reporting(0);
$dir = __DIR__.'/';
$robot = 'C:/Users/rigai/OneDrive/Desktop/robot/';

// Fichiers PHP temporaires à supprimer de /pmu/
$php_tmp = ['deploy.php','hexwriter.php','reader.php','reader2.php','rd.php',
            'cp.php','lister.php','finder.php','check.php','deploy_robot.php',
            'tmp_turbo.py.txt'];

$deleted = [];
$failed  = [];

foreach($php_tmp as $f){
  $p = $dir.$f;
  if(file_exists($p)){
    unlink($p) ? $deleted[] = $f : $failed[] = $f;
  }
}

// Supprimer les fichiers partiels du dossier robot
$robot_tmp = ['pmu_turbo_maitre.py'];  // fichier corrompu
foreach($robot_tmp as $f){
  $p = $robot.$f;
  if(file_exists($p)){
    unlink($p) ? $deleted[] = 'robot/'.$f : $failed[] = 'robot/'.$f;
  }
}

header('Content-Type: application/json');
echo json_encode(['deleted'=>$deleted,'failed'=>$failed]);
