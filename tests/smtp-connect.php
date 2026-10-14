<?php
// Optional, explicit connectivity check. Authenticates and quits; never submits a message.
declare(strict_types=1);
$config=require __DIR__.'/../config/mail.php';
$ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$config['host'],'allow_self_signed'=>false,'cafile'=>$config['cafile']]]);
$socket=@stream_socket_client('ssl://'.$config['host'].':'.$config['port'],$errno,$errstr,15,STREAM_CLIENT_CONNECT,$ctx);
if (!$socket) { fwrite(STDERR,"Échec de connexion SMTP TLS vérifiée.\n"); exit(1); }
stream_set_timeout($socket,15);
function receive($socket, int $code): void {
    $response='';
    do { $line=fgets($socket,4096); if ($line===false) throw new RuntimeException('Réponse SMTP absente.'); $response.=$line; } while (isset($line[3]) && $line[3]==='-');
    if ((int)substr($response,0,3)!==$code) throw new RuntimeException('Le serveur SMTP a refusé une étape (code '.(int)substr($response,0,3).').');
}
try {
    receive($socket,220); fwrite($socket,"EHLO ominis.local\r\n"); receive($socket,250);
    fwrite($socket,"AUTH LOGIN\r\n"); receive($socket,334);
    fwrite($socket,base64_encode($config['username'])."\r\n"); receive($socket,334);
    fwrite($socket,base64_encode($config['password'])."\r\n"); receive($socket,235);
    fwrite($socket,"QUIT\r\n");
    echo "OK : certificat SMTP vérifié et authentification acceptée. Aucun email envoyé.\n";
} catch (Throwable $ex) { fwrite(STDERR,$ex->getMessage().PHP_EOL); exit(1); }
finally { fclose($socket); }
