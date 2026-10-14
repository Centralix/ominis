<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/core.php';
try {
    echo cleanup_storage() . " profil(s) supprimé(s).\n";
} catch (Throwable $ex) {
    fwrite(STDERR, "Échec du nettoyage : " . $ex->getMessage() . "\n");
    exit(1);
}
