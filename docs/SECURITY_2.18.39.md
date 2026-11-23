# UXConnector 2.18.39 : réponse aux quatre constats TECLIB

Date : 23 novembre 2026. Base : `v2.18.38-rc.13`, commit `33882b7bc7cc033500048f0c6c66f4a4a5f1db06`.

Les quatre corrections sont implémentées et testées localement. Ce document ne constitue pas une validation de mise en production par TECLIB. La version sans suffixe RC est préparée pour la validation sur une instance de test via le Marketplace privé.

## Corrections

| Constat de la revue | Correction | Preuves locales |
| --- | --- | --- |
| 1. Réutilisation d’un ancien secret après modification par l’API GLPI | `AuthConfigItem::can()` refuse CREATE, UPDATE, DELETE et PURGE, même dans une combinaison de droits. `OriginBoundCredential::unwrap()` refuse un secret non lié, ainsi qu’une origine ou une méthode différente. La mise à niveau lie et rechiffre les secrets hérités. | Refus des droits d’écriture avec un parent autorisant tout ; contrôle de la lecture ; absence d’appel au transport HTTP avec un secret hérité ou lié ailleurs ; migration rejouable et annulation sur erreur. |
| 2. Connexion partagée entre plusieurs entités | `JobEntityAccess::assertConnectionId()` examine tous les jobs référant la connexion, sans filtrage préalable par profil ou statut. Les contrôleurs actuel et historique l’appellent avant édition, test, activation, désactivation, suppression ou association à un connecteur. | Dix routes de mutation refusées avant appel à leur service ; cas partagés, pause, entité racine 0, mapping avec entité de repli et ancien job sans périmètre. |
| 3. Assignations de licences visibles hors périmètre | Jointure interne avec `glpi_softwarelicenses`, puis filtre sur les entités actives de la session, avant application de la limite. Aucune ligne si la session ne fournit pas de périmètre valide. | Requête construite par le query builder GLPI et exécutée sur une base SQLite fictive : autre entité et licence absente exclues, racine 0 conservée, filtrage avant limite de 100 lignes. |
| 4. Gestionnaires JavaScript incompatibles CSP | Les onze confirmations et la sélection du connecteur utilisent des attributs `data-*`. Les trois blocs de script des vues sont déplacés dans `js/uxconnector.js`, enregistré par le hook `ADD_JAVASCRIPT` de GLPI. | Compilation des vues Twig ; aucun gestionnaire `on*=`, URL `javascript:` ou balise script dans `templates/`, `src/`, `front/`. Test Chromium sous CSP à nonce, sans autorisation de scripts intégrés. |

Le JavaScript conserve la sélection multiple, les champs d’authentification conditionnels et le blocage des doubles soumissions. Les confirmations fonctionnent au clavier et pour les formulaires ajoutés après le chargement. Le nom et la valeur du bouton soumis sont conservés dans le POST.

## Migration des secrets

1. Sauvegarder ensemble la base GLPI, le plugin et `config/glpicrypt.key`.
2. Contrôler les origines et méthodes actuellement enregistrées. Un secret ancien n’a pas de preuve de sa destination historique : la migration prend la configuration courante comme référence, elle ne détecte pas une altération antérieure.
3. Arrêter les workers de synchronisation et les émetteurs webhook pendant le remplacement des fichiers et la mise à niveau. Ne pas désinstaller le plugin.
4. Installer la version 2.18.39 via le cycle de mise à jour GLPI. Le numéro change car les RC2 à RC13 déclaraient déjà 2.18.38.
5. La nouvelle migration s’exécute après les migrations existantes et la révocation des intégrations retirées. Elle conserve les statuts des connexions, vérifie le chiffrement puis la relecture du stockage, et utilise une transaction pour l’ensemble de ses modifications. En cas d’échec, cette transaction est annulée et la mise à niveau échoue ; les autres étapes de migration ne sont pas présentées comme transactionnelles dans leur ensemble.
6. Rejouer la migration après correction de la clé ou de la configuration si nécessaire. Un secret déjà lié à une autre destination n’est jamais automatiquement rattaché. Réactiver les traitements après un test de connexion et une synchronisation de contrôle.

Le moteur refuse les valeurs non liées même si la migration a été omise ou a échoué. Un retour arrière exige la restauration cohérente du code, de la base et de la clé sauvegardés.

## Vérifications exécutées

- PHP 8.4.27 : 37 tests de sécurité, 122 assertions, tous réussis. Ces tests incluent le chiffrement natif `GLPIKey` de GLPI 11.0.0 avec une clé jetable ; aucun secret client utilisé.
- Chromium 156 : onze confirmations traduites contenant guillemets et caractères HTML, annulation/acceptation, clavier, ajout dynamique, rotation seule, sélection groupée, double soumission, retour de page et redirection encodée, sous CSP restrictive. Aucun message d’erreur de script ou CSP.
- Douze suites du kit RC13 : champs sortants sensibles, masquage webhook, arrêt, migrations, retrait des anciennes intégrations, réseau, écritures, téléchargements, snapshots de reprise, `asset_identity`, HTTPS réel en boucle locale avec cURL et streams, et cinq contre-épreuves par mutation. Toutes réussies.
- Syntaxe des 327 fichiers PHP de `src/`, `front/`, `inc/`, `sql/`, `setup.php` et `hook.php` : aucune erreur. Composer valide ; formatage des fichiers PHP modifiés conforme aux règles PSR-12 du projet.
- PHPStan niveau 5 : aucune erreur sur `BindLegacyCredentialsService`, `JobEntityAccess` et `OriginBoundCredential`. Ce contrôle ciblé ne vaut pas analyse complète du plugin dans GLPI.

Les commandes et dépendances de la nouvelle suite sont décrites dans `tests/Security/README.md` dans les sources.

## Limites et recette sur l’instance TECLIB

La suite PHPUnit générale héritée de la RC13 bloque déjà au chargement : des doubles de test n’implémentent plus toutes les méthodes de `DestinationPolicyInterface` et des interfaces de repositories. Une exécution séparée des tests de domaine révèle également trois attentes anciennes dans `SyncExecutionTest`. Les classes et tests concernés par ces trois attentes n’ont pas été modifiés. Ces résultats sont consignés ; la suite générale n’est pas déclarée verte.

Les tests de contrôleurs emploient des adaptateurs GLPI simulés. Les transactions de migration sont testées par simulation et le chiffrement par le vrai `GLPIKey`. Le filtre des licences utilise le vrai query builder et une base SQLite fictive. Ces vérifications ne remplacent pas une mise à niveau sur MariaDB avec un noyau GLPI complet. PHP 8.2 et 8.5 n’ont pas été exécutés ici ; aucune syntaxe nécessitant plus que PHP 8.2 n’a été ajoutée.

Sur l’instance de test autorisée par François, vérifier :

- Mise à niveau depuis la RC13 et depuis un jeu de secrets antérieurs à leur liaison ; connexion fonctionnelle après migration et second passage sans modification des secrets déjà liés.
- Avec un profil délégué, refus des écritures REST v1 sur `AuthConfigItem` et absence de changement en base.
- Connexion utilisée par des jobs de deux entités : modification, test et activation refusés pour un profil limité à une seule, puis autorisés pour le profil couvrant les deux.
- Page des licences limitée à l’entité active, y compris après changement de périmètre.
- Chargement du fichier JavaScript par GLPI et confirmations sous la CSP réelle de l’hébergeur.

La publication du tag et de l’archive, l’autorisation du Marketplace privé et le déploiement en production sont des étapes distinctes. Les dix notes de qualité du rapport ne sont pas toutes annoncées comme traitées par ce lot de quatre corrections.
