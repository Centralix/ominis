Nettoyage de storage
===================

Les données du profil sont supprimées quand une série est terminée ou abandonnée.
Le dernier bilan reste consultable dans la session PHP jusqu'au début d'une nouvelle
série ou au prochain seuil de 03:00 (Europe/Paris). Les bilans et la progression
ne sont plus conservés dans storage après la fin de la série.

Dans le panneau d'hébergement, créer une tâche planifiée quotidienne à 03:00,
avec le fuseau Europe/Paris, qui exécute :

    /chemin/vers/php /chemin/absolu/du/site/app/cleanup-storage.php

Les chemins doivent être adaptés à l'hébergement. Si OMINIS_DATA_DIR est utilisé
par le site, définir la même variable pour la tâche planifiée.

Exemple crontab lorsque l'ordonnanceur accepte CRON_TZ :

    CRON_TZ=Europe/Paris
    0 3 * * * /usr/bin/php /chemin/absolu/du/site/app/cleanup-storage.php

Sans tâche serveur, le même nettoyage est exécuté à la première visite après
03:00 : il ne s'exécute alors pas à 03:00 en l'absence de visites.
Seuls les fichiers de profil au nom composé de 64 caractères hexadécimaux,
antérieurs au dernier seuil de 03:00, sont supprimés. Les séries ouvertes avant
le seuil sont donc effacées. Les fichiers de protection et le verrou restent.
Les liens symboliques sont ignorés. Les accès sont verrouillés pendant le nettoyage.
