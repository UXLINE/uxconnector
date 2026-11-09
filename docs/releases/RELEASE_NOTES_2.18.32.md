# UXConnector 2.18.32 candidate

Date : 3 novembre 2026. Base : version 2.18.31 candidate, issue de l'archive 2.18.29 fournie. Cible de validation : GLPI 11.0.11 ; API sources GLPI 10.0.28 et Zendesk. Aucun accès au serveur Indigo n'a été utilisé.

## Corrections de qualité et de compatibilité

- Remplacement des constructions dynamiques concernées par une fabrique qui délègue à la résolution native GLPI, avec contrôle du résultat CommonDBTM.
- Exceptions HTTP Symfony pour les requêtes incorrectes et les méthodes non autorisées. Suppression des appels exit des parcours web concernés pour laisser GLPI terminer ses traitements.
- Respect des signatures void de DBmysql::beginTransaction() et commit(). Les exceptions natives se propagent ; une erreur pendant l'écriture ou le commit déclenche le rollback.
- Nettoyage des conditions redondantes, constantes et ancien parcours d'import Zendesk inaccessibles. Le parcours natif addFiles réellement utilisé reste couvert par les tests de PJ et de reprise.
- Suppression de la dépendance de développement abandonnée glpi-project/coding-standard. Mise à jour effective de composer.lock ; utiliser composer install, et non composer update, pour reproduire les versions testées.
- Configuration PHP-CS-Fixer fournie ; commande lint:glpi en lecture seule. Formatage des fichiers PHP selon PER-CS2.0 et les règles du kit.
- Découverte des classes historiques du dossier inc dans PHPStan. Configuration de base fournie ; le kit génère celle adaptée aux chemins du conteneur et accepte UXC_GLPI_CONSTANTS.
- Remplacement de l'annotation PHPUnit dataProvider par l'attribut natif.

## Comportements conservés

Les règles fonctionnelles de la version 2.18.31 restent applicables, détaillées dans RELEASE_NOTES_2.18.31.md :

- Objets existants : compléter uniquement les champs réellement vides. Une valeur cible renseignée reste prioritaire, même si la source change. Le zéro numérique et Non sont conservés ; l'entité racine 0 est une entité réelle.
- Identités et ObjectLinks conservés ; aucune suppression des objets, rattachements ou données consolidées. Les relations LDAP et les écritures d'autres outils restent hors de cette protection.
- Réseau : résolution Location sans identifiants de recherche fixes, alias Fields contrôlés et enrichissement conservateur.
- Microsoft : licences individuelles par ligne source. Une ancienne affectation en pool exige une revue explicite avant conversion ; pas de conversion automatique.
- Zendesk : PJ et captures désactivées par défaut, suivis textuels conservés. La réactivation est explicite dans les paramètres du job. Les documents déjà présents restent conservés.
- Emails : compléter un email manquant, préserver une adresse déjà renseignée et les alias. Cette version ne remplace pas automatiquement un email existant erroné et ne déduit pas un samAccountName.
- Planification par job et workers indépendants conservés ; un même job ne doit pas être lancé deux fois simultanément.

## Vérifications réellement exécutées

Environnement des vérifications : PHP 8.3.6, sources officielles GLPI 11.0.11 et Fields 1.24.5. Les dépendances du coeur ont été installées pour l'analyse des symboles, sans scripts ni exécution d'une instance GLPI ou de sa base ; ce n'est pas une validation des prérequis d'exploitation du coeur.

| Contrôle | Résultat |
| --- | --- |
| PHPStan 2.3.4 + phpstan-glpi 1.4.0, niveau 5 | Aucune erreur ; src, inc, front, setup.php, hook.php et sql ; pas de baseline ajoutée ni d'erreur ignorée |
| PHPUnit 11.5.57 | 41 tests, 154 assertions, aucune dépréciation |
| PHP-CS-Fixer 3.95.27 | Aucun fichier restant à corriger |
| Composer validate --strict | Conforme |
| Composer audit | Aucune alerte connue ni dépendance abandonnée dans le lock testé |
| Suites hors ligne du preflight 2.18.32 | Réussies, dont 6 contrôles supplémentaires de transactions et de résolution native |
| Twig | Trois modèles analysés ; valeurs de planification et échappement contrôlés |
| Syntaxe PHP | Vérifiée sur tous les fichiers PHP du plugin, hors vendor |

Les simulations utilisent des doubles d'API et de base. Il reste à rejouer le kit sur le conteneur PHP 8.5.12 et à effectuer la recette fonctionnelle sur une copie de données Indigo, notamment les profils Fields réels et les jobs concurrents. Ces résultats ne constituent ni un audit d'intrusion, ni une certification de sécurité, ni une approbation TECLIB.

Le fichier uxconnector.xml.dist garde l'état dev et une URL de publication à renseigner. Ne pas soumettre ce modèle de catalogue sans fournir l'URL réelle de l'archive retenue et terminer la recette.

## Installation et retour arrière

Mettre à jour le dossier existant après arrêt des workers et sauvegarde des fichiers ainsi que de la base. Ne pas désinstaller le plugin ni supprimer ses tables. Ne pas garder deux copies actives entre plugins et marketplace. Appliquer la mise à jour par le mécanisme natif GLPI si elle est proposée, puis reprendre les contrôles sur la copie locale.

Le package source n'embarque pas vendor ni les données client. Pour les tests, installer les dépendances verrouillées avec composer install dans le conteneur. Un retour arrière après exécution de jobs nécessite les fichiers et la base correspondant à la sauvegarde ; remplacer uniquement le code ne retire pas les objets créés depuis.
