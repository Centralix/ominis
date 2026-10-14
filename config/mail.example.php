<?php
declare(strict_types=1);

// Copier dans mail.php et renseigner les identifiants sur le serveur.
return [
    'host' => 'smtp.example.com',
    'port' => 465,
    'username' => 'notifications@example.com',
    'password' => '',
    'recipient' => 'contact@example.com',
    'cafile' => __DIR__ . '/cacert.pem',
];
