# UXConnector 2.18.24 - Candidate de recette

## Fonctionnalité : interruption coopérative et reprise

- Nouveau bouton **Stop execution** dans l'historique d'un job, visible uniquement lorsqu'une exécution est `running` et pour les profils disposant du droit `plugin_uxconnector_syncjob: UPDATE`.
- Confirmation avant émission d'une demande d'arrêt. La requête POST passe par le contrôle CSRF natif de GLPI 11.
- La demande est stockée indépendamment des mises à jour du worker (`stop_requested`) pour éviter qu'un heartbeat écrase le signal.
- Le worker vérifie la demande à chaque frontière de ticket pour le pipeline inbound standard, ainsi qu'entre les pages. Il ne force pas l'arrêt d'un téléchargement ou d'une écriture en cours.
- Le job termine en statut `interrupted` avec l'option de reprise.
- Le champ `resume_path` conserve le chemin de la prochaine page confirmée, ou de la première page partiellement traitée. La reprise repart de cette page plutôt que de la première page de l'export Zendesk.
- Une page partielle peut être **rejouée** après l'arrêt : les liens d'identité externes existants protègent contre la duplication d'objets. Les compteurs des tickets traités sur une page partielle ne deviennent définitifs qu'une fois cette page confirmée.
- Le heartbeat est actualisé à chaque record inbound standard, afin de ne pas déclarer une page longue comme abandonnée.
- Les traitements spécialisés en lot (catégories, Télécoms, Réseau, Licences Microsoft) contrôlent l'arrêt **entre lots/pages**, pas entre les éléments internes du processeur spécialisé.
- Les exécutions existantes, les jobs, les checkpoints, les liens d'idempotence et les identifiants Zendesk ne sont pas effacés.

## Migration de schéma

Deux champs additionnels idempotents dans `glpi_plugin_uxconnector_syncexecutions` :

- `stop_requested` : booléen, valeur par défaut 0, écrit seulement par la commande de contrôle.
- `resume_path` : texte nullable, écrit par le worker après des frontières de pages cohérentes.

La migration s'effectue lors de la mise à jour du plugin dans l'interface GLPI. Avant d'utiliser le bouton, contrôler que la migration de schéma est terminée. Ne pas appliquer le ZIP en plein milieu d'une exécution existante, car le worker PHP déjà chargé avec la version antérieure ne connaît pas la nouvelle demande d'arrêt.

## Validation livrée

- `sh tools/preflight-2.18.24.sh` : tests préalables 2.18.17 → 2.18.24 et architecture.
- `php tools/selftest-2.18.24-stop.php` : droits UI, contrôle du signal, mapping des champs et interruption à une frontière de record.
- `php tools/selftest-2.18.24-runtime.php` : import synthétique à 3 pages, arrêt dans la deuxième, reprise à partir de cette deuxième page et absence de doublons.
- PHP CLI syntax : 419 fichiers contrôlés, aucune erreur.

**Non encore validé :** exécution sur un véritable GLPI Docker + MariaDB/GLPI UI, suite PHPUnit complète, test de concurrence avec watchdog/HTTP réseau réel, et interruptions des processeurs spécialisés en lot.

## Recette manuelle requise sur le GLPI Docker de test

1. Sauvegarder DB et archive du plugin 2.18.23.
2. Mettre le plugin à jour en 2.18.24, lancer la mise à jour GLPI et contrôler les deux champs de schéma.
3. Exécuter le préflight puis ouvrir **Outils → UXConnector → Historique** d'un job de test non critique.
4. Démarrer une synchronisation de données fictives; confirmer la présence du bouton **Stop execution** sur la ligne `En cours`.
5. Cliquer sur **Stop execution** et confirmer. Vérifier le message `Stop requested`, puis le statut `Interrompu / reprenable`.
6. Reprendre l'exécution. Vérifier les compteurs, la progression depuis la première page non confirmée et l'absence de doublon.
7. Vérifier qu'aucun arrêt n'est possible pour un utilisateur sans droit UPDATE et qu'il n'existe pas de bouton sur une exécution terminée.

Ne pas solliciter INDIGO pour installer cette candidate avant validation locale.
