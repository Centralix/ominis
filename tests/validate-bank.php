<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
[$items,$questions,$errors]=bank();
foreach ($errors as $message) fwrite(STDERR,$message.PHP_EOL);
foreach ($items as $item) {
    $a=count(array_filter($item['questions'],fn($q)=>$q['rank']==='A'));
    echo 'Item '.$item['id'].' : '.count($item['questions']).' questions, '.$a.' A / '.(count($item['questions'])-$a)." B\n";
    if (count($item['questions'])%10!==0 || $a!== (int)(count($item['questions'])*.7)) { $errors[]='Ratio 70/30 non respecté pour l’item '.$item['id']; fwrite(STDERR,end($errors).PHP_EOL); }
}
if (!$items) { fwrite(STDERR,"Banque vide.\n"); exit(1); }
exit($errors?1:0);
