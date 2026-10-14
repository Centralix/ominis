<?php
declare(strict_types=1);

/** Minimal SMTP AUTH LOGIN over verified implicit TLS (465). No credentials reach the browser. */
function smtp_send(array $config, string $subject, string $body): void {
    foreach (['username','password','recipient','host'] as $key) if (empty($config[$key])) throw new RuntimeException('SMTP non configuré.');
    foreach (['username','recipient'] as $key) if (!filter_var($config[$key], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse SMTP invalide.');
    $tls = ['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$config['host'],'allow_self_signed'=>false];
    if (!empty($config['cafile'])) $tls['cafile'] = $config['cafile'];
    $context = stream_context_create(['ssl'=>$tls]);
    $socket = @stream_socket_client('ssl://' . $config['host'] . ':' . ($config['port'] ?? 465), $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Connexion SMTP impossible.');
    stream_set_timeout($socket, 15);
    $read = static function(array $expected) use ($socket): void {
        $response = ''; $line = false;
        for ($i = 0; $i < 100; $i++) {
            $line = fgets($socket, 4096);
            if ($line === false) throw new RuntimeException('Réponse SMTP absente.');
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($response, 0, 3);
        if ($code === 535) throw new RuntimeException('Authentification SMTP refusée.', 535);
        if ($line === false || !isset($line[3]) || $line[3] !== ' ' || !in_array($code, $expected, true)) throw new RuntimeException('Commande SMTP refusée.', $code);
    };
    $command = static function(string $line, array $expected) use ($socket, $read): void {
        if (fwrite($socket, $line . "\r\n") === false) throw new RuntimeException('Écriture SMTP impossible.'); $read($expected);
    };
    try {
        $read([220]); $command('EHLO ominis.local', [250]); $command('AUTH LOGIN', [334]);
        $command(base64_encode($config['username']), [334]); $command(base64_encode($config['password']), [235]);
        $command('MAIL FROM:<' . $config['username'] . '>', [250]); $command('RCPT TO:<' . $config['recipient'] . '>', [250,251]);
        $command('DATA', [354]);
        $headers = 'From: Ominis <' . $config['username'] . ">\r\nTo: <" . $config['recipient'] . ">\r\n";
        $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\nDate: " . date(DATE_RFC2822) . "\r\n";
        $headers .= 'Message-ID: <' . bin2hex(random_bytes(16)) . "@synapsplus.net>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $payload = $headers . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n";
        $offset = 0;
        while ($offset < strlen($payload)) { $written = fwrite($socket, substr($payload, $offset)); if (!$written) throw new RuntimeException('Écriture SMTP interrompue.'); $offset += $written; }
        $read([250]);
        // Delivery has been accepted: a subsequent QUIT failure must not trigger a duplicate.
        @fwrite($socket, "QUIT\r\n");
    } finally { fclose($socket); }
}
