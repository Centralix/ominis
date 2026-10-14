<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
$checks=0;
function check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException('ÉCHEC : '.$message); }
function throws(callable $fn): bool { try { $fn(); return false; } catch (Throwable $ex) { return true; } }
[$items,$bank,$errors]=bank();
check(!$errors,'Banque TXT valide');
check(count($bank)===40,'40 questions');
check(count(array_filter($bank,fn($q)=>$q['rank']==='A'))===28,'28 rang A');
check(count(array_filter($bank,fn($q)=>$q['rank']==='B'))===12,'12 rang B');
check(count(array_filter($bank,fn($q)=>$q['format']==='CAS'))===37,'37 cas');
check(count(array_filter($bank,fn($q)=>$q['type']==='QRU'))===3,'3 QRU');
$q=$bank['154-001']; $correct=array_map(fn($c)=>$c['correct'],$q['choices']);
for ($mask=0;$mask<32;$mask++) {
    $answers=$correct; $errorsCount=0;
    foreach (array_keys($correct) as $bit=>$letter) if ($mask & (1<<$bit)) { $answers[$letter]=!$answers[$letter]; $errorsCount++; }
    $r=grade($q,$answers); check(abs($r['score']-([1,.7,.2][$errorsCount]??0))<.00001,'Barème masque '.$mask);
    check(count($r['wrong'])===$errorsCount,'Nombre de fautes masque '.$mask);
    check(count($r['falsePositive'])+count($r['falseNegative'])===$errorsCount,'Types de fautes masque '.$mask);
}
$qru=$bank['154-005'];
foreach (array_keys($qru['choices']) as $letter) {
    $answers=array_fill_keys(['A','B','C','D','E'],false); $answers[$letter]=true;
    check(grade($qru,$answers)['score']===($letter==='C'?1.0:0.0),'QRU choix '.$letter);
}
check(throws(fn()=>grade($q,[])),'Réponse incomplète refusée');
check(throws(fn()=>grade($qru,array_fill_keys(['A','B','C','D','E'],true))),'QRU multiple refusé');
foreach ([10,20,30,40] as $n) {
    $series=build_series($bank,$n); check(count($series)===$n,'Taille série '.$n);
    check(count(array_filter($series,fn($q)=>$q['rank']==='A'))===(int)round($n*.7),'Ratio A '.$n);
    check(count(array_unique(array_column($series,'id')))===$n,'Pas de doublon '.$n);
}
check(throws(fn()=>build_series(array_filter($bank,fn($q)=>$q['rank']==='A'),10)),'Ratio impossible refusé');
$invalid=__DIR__.'/../tmp/invalid-bank-'.bin2hex(random_bytes(4)).'.txt';
try {
    $original=file_get_contents(__DIR__.'/../qcm/154-infections-broncho-pulmonaires.txt');
    file_put_contents($invalid,preg_replace('/^EXPLICATION_A:.*\R/mu','',$original,1));
    check(throws(fn()=>parse_item($invalid)),'Justification de proposition obligatoire');
    file_put_contents($invalid,preg_replace('/^JUSTIFICATION:.*\R/mu','',$original,1));
    check(throws(fn()=>parse_item($invalid)),'Justification générale obligatoire');
    file_put_contents($invalid,str_replace('TYPE: QCM','TYPE: QRU',$original));
    check(throws(fn()=>parse_item($invalid)),'QRU avec plusieurs réponses vraies refusé');
} finally { if (is_file($invalid)) unlink($invalid); }
$dir=__DIR__.'/../tmp/test-data-'.bin2hex(random_bytes(6)); mkdir($dir,0700,true); putenv('OMINIS_DATA_DIR='.$dir);
try {
    $id=str_repeat('a',64); $series=array_slice(array_values($bank),0,3); $answers=[];
    foreach ($series as $question) $answers[$question['id']]=array_map(fn($c)=>$c['correct'],$question['choices']);
    $answers[$series[0]['id']]['A']=!$answers[$series[0]['id']]['A'];
    $run=['id'=>'test1','revision'=>1,'questions'=>$series,'answers'=>$answers,'mode'=>'exam','retry'=>false,'started'=>time()-60];
    update_profile($id,fn($p)=>$p+['active'=>$run]);
    check(throws(fn()=>complete_run($id,array_replace($run,['revision'=>0]),true)),'Onglet périmé refusé');
    complete_run($id,$run,true); $p=read_profile($id);
    check(count($p['history'])===1,'Historique enregistré');
    check(!isset($p['active']),'Série active effacée atomiquement');
    check(count(mistakes($p,$bank))===1,'Une erreur en attente');
    check(abs($p['history'][0]['score']-2.7)<.00001,'Total sans note négative');
    complete_run($id,$run); check(count(read_profile($id)['history'])===1,'Fin de série idempotente');
    $run['id']='test2'; $run['retry']=true;
    $run['answers'][$series[0]['id']]=array_map(fn($c)=>$c['correct'],$series[0]['choices']); complete_run($id,$run);
    check(!mistakes(read_profile($id),$bank),'Réussite retire une erreur');
    $p=read_profile($id); $p['latest'][$series[0]['id']]['score']=0;
    $changed=$bank; $changed[$series[0]['id']]['hash']='new'; check(!mistakes($p,$changed),'Question modifiée invalidée');
    check(str_starts_with(file_get_contents(profile_path($id)),"<?php exit; ?>\n"),'Stockage protégé par enveloppe PHP');
    check(throws(fn()=>profile_path('../config/mail')),'Traversée de chemin refusée');
} finally { foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); putenv('OMINIS_DATA_DIR'); }
echo "OK : $checks vérifications (barème exhaustif, QRU, banque, ratios, reprise et persistance).\n";
