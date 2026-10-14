# Ominis

Plateforme de révision médicale en PHP : QCM et QRU par item et collège, modes entraînement et examen, corrections expliquées et suivi des résultats.

## Installation

1. Installer PHP 8.2 ou supérieur avec les extensions utilisées par le site, notamment OpenSSL pour les signalements par mail.
2. Copier `config/mail.example.php` vers `config/mail.php` et configurer le serveur SMTP. Ce fichier privé est exclu de Git.
3. Autoriser PHP à écrire dans `storage/`.
4. Pour le développement, lancer `php -S 127.0.0.1:8000 router.php` depuis le dossier du projet.
5. En production, configurer le serveur pour protéger `app/`, `config/`, `qcm/` et `storage/`. Les fichiers `.htaccess` fournis nécessitent un serveur Apache qui les prend en charge.

Le nettoyage des données est décrit dans `config/nettoyage-storage.md`.

## Banque de questions

Les banques TXT se trouvent dans `qcm/`. Pour contrôler leur format :

```sh
php tests/validate-bank.php
```

Les rangs attribués aux questions sont pédagogiques. Les contrôles techniques ne constituent pas une certification médicale.

## Fichiers privés et locaux

Les identifiants SMTP, les données de session, les fichiers temporaires et les archives de travail ne sont pas publiés. Les sources du site, ses ressources, les banques de questions et les tests sont inclus.
