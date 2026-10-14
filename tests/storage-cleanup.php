<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
$dir = sys_get_temp_dir() . '/ominis-cleanup-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700); putenv('OMINIS_DATA_DIR=' . $dir);
function ensure(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
}
try {
    $id = str_repeat('a',64);
    $item = parse_item(__DIR__ . '/../qcm/154-infections-broncho-pulmonaires.txt');
    $q = $item['questions'][0];
    $answers = array_map(fn($choice) => $choice['correct'], $q['choices']);
    $run = ['id'=>'test-run','revision'=>2,'questions'=>[$q],'answers'=>[$q['id']=>$answers],'mode'=>'practice','retry'=>false,'started'=>time()-20];
    update_profile($id, function($p) use ($run) {$p['active']=$run; return $p;});
    ensure(is_file(profile_path($id)), 'Profil actif sauvegardé');
    $stale = $run; $stale['revision']=1;
    try {complete_run($id,$stale,true); throw new LogicException('Révision périmée acceptée');}
    catch (RuntimeException $ex) {ensure(is_file(profile_path($id)), 'Échec de validation : profil conservé');}
    ensure(complete_run($id,$run,true)==='test-run', 'Fin de série');
    clearstatcache(); ensure(!is_file(profile_path($id)), 'Fichier supprimé à la fin');
    ensure($_SESSION['completed_attempt']['score']===1.0, 'Bilan temporaire consultable');
    ensure(count($_SESSION['completed_attempt']['results'])===1, 'Correction temporaire disponible');
    ensure(read_profile($id)['history']===[], 'Aucun historique persistant');
    $date = new DateTimeImmutable('2026-10-10 03:00:00', new DateTimeZone('Europe/Paris'));
    $cutoff = $date->getTimestamp();
    ensure(storage_cutoff($cutoff)===$cutoff, 'Seuil exact à 03:00');
    ensure(storage_cutoff($cutoff-1)===$date->modify('-1 day')->getTimestamp(), 'Avant 03:00 : seuil précédent');
    foreach (['2026-03-29','2026-10-25'] as $day) {
        $boundary = new DateTimeImmutable($day.' 03:00:00',new DateTimeZone('Europe/Paris'));
        ensure(storage_cutoff($boundary->getTimestamp())===$boundary->getTimestamp(), 'Changement heure été/hiver');
    }
    $old = str_repeat('b',64); $fresh = str_repeat('c',64);
    update_profile($old, fn($p)=>$p); touch(profile_path($old),$cutoff-1);
    update_profile($fresh, fn($p)=>$p); touch(profile_path($fresh),$cutoff);
    file_put_contents($dir.'/.htaccess','Require all denied');
    file_put_contents($dir.'/keep.php','protection'); touch($dir.'/keep.php',$cutoff-1);
    ensure(cleanup_storage($cutoff)===1, 'Nettoyage des anciens profils seulement');
    clearstatcache(); ensure(!is_file(profile_path($old)), 'Ancien profil effacé');
    ensure(is_file(profile_path($fresh)), 'Profil commencé après le seuil conservé');
    ensure(is_file($dir.'/.htaccess') && is_file($dir.'/keep.php'), 'Fichiers hors profils conservés');
    ensure(cleanup_storage($cutoff)===0, 'Nettoyage idempotent');
    echo "OK : suppression en fin de série, bilan temporaire, révision périmée, seuil 03:00 Paris, été/hiver et protection des fichiers.\n";
} finally {
    foreach (scandir($dir) as $name) if ($name!=='.' && $name!=='..') unlink($dir.'/'.$name);
    rmdir($dir); putenv('OMINIS_DATA_DIR');
}
