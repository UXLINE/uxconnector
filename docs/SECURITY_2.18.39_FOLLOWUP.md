# UXConnector 2.18.39 : complément de correction de l’audit

24 novembre 2026. Révision de livraison **audit-20261124.1**. Le numéro du plugin reste **2.18.39**, sans suffixe RC.

Cette révision complète le premier lot du 23 novembre : celui-ci traitait les quatre constats de sécurité TECLIB, mais pas toutes les remarques de qualité. L’audit joint par l’utilisateur correspond bien à cette première archive, SHA-256 `4f6119c8f6ccb565f5bba19ac2b4e86447e1cdff4d60c9cfd85d6efff2de7dc8`. Le nouveau paquet possède une empreinte différente, fournie à côté de l’archive.

## Mise à niveau : action nécessaire même si GLPI affiche déjà 2.18.39

**Le simple remplacement des fichiers ne garantit pas l’exécution des migrations lorsque le numéro reste identique. Il faut relancer explicitement l’installation avec `plugin:install --force`. Ne pas désinstaller le plugin.**

1. Prévoir une fenêtre de maintenance. Suspendre les workers UXConnector et les émetteurs webhook. Sauvegarder ensemble la base, le dossier du plugin et la clé `config/glpicrypt.key`.
2. Vérifier les destinations des connexions existantes avant migration des secrets hérités. La migration prend leur origine et leur méthode actuelles comme référence ; elle ne peut pas détecter une modification antérieure à sa première exécution.
3. Remplacer intégralement le dossier **actif** `uxconnector` par celui du TAR.GZ, après sauvegarde. Respecter l’emplacement réellement utilisé par GLPI (`plugins` ou `marketplace`) ; ne pas conserver deux copies installées. En hébergement administré, transmettre le paquet et cette procédure à l’hébergeur.
4. Exécuter la commande native GLPI avec un compte GLPI administrateur valide et l’utilisateur système habituel du serveur web. Exemple pour le conteneur utilisé en recette, depuis PowerShell :

   ```powershell
   $compteGLPI = 'LOGIN_ADMIN_GLPI_A_REMPLACER'
   docker exec -u www-data glpi-data-glpi-1 php /var/www/glpi/bin/console plugin:install uxconnector --force --username $compteGLPI --no-interaction
   if ($LASTEXITCODE -ne 0) { throw 'Migration UXConnector interrompue : consulter les erreurs avant toute activation.' }
   ```

5. Après réussite de la migration, réactiver le plugin **avant** l’inventaire. GLPI ne découvre cette commande que lorsque UXConnector figure parmi les plugins activés. Les workers et émetteurs webhook doivent rester suspendus ; conserver la fenêtre de maintenance pour éviter les déclenchements par événements GLPI. Cette étape corrige l’ordre indiqué dans la première procédure livrée.

   ```powershell
   docker exec -u www-data glpi-data-glpi-1 php /var/www/glpi/bin/console plugin:activate uxconnector --no-interaction
   if ($LASTEXITCODE -ne 0) { throw 'Activation UXConnector échouée : consulter le message affiché.' }
   ```

6. Consulter les avertissements de migration et l’inventaire en lecture seule, sans lancer de synchronisation :

   ```powershell
   docker exec -u www-data glpi-data-glpi-1 php /var/www/glpi/bin/console plugins:uxconnector:review-active-job-scopes --no-interaction
   if ($LASTEXITCODE -ne 0) { throw 'Inventaire UXConnector échoué : garder les traitements suspendus.' }
   ```

   Les stratégies nulles, invalides ou visant plusieurs entités sont signalées avec les IDs des jobs et des connexions. La base historique ne stocke pas le créateur : ses anciens droits ne sont pas reconstituables. Un administrateur doit donc examiner le périmètre voulu de **tous** les jobs actifs, même ceux visant une seule entité. Ce rapport ne suspend ni ne modifie automatiquement les jobs. Il ne contient ni secrets, ni noms, ni valeurs métier des mappings.
7. Rafraîchir les caches GLPI/OPcache selon la procédure d’exploitation, si nécessaire. Les workers et émetteurs restent suspendus le temps des contrôles ci-dessous.
8. Vérifier une connexion, les écrans du plugin, un webhook signé et une synchronisation de contrôle. Contrôler les droits et entités avec un profil délégué avant de remettre les traitements en service.

La migration de `webhookrequests` crée d’abord l’index UNIQUE sur `fingerprint`, puis ajoute `id` numérique auto-incrémenté et remplace la clé primaire dans le même ALTER TABLE. Elle ne supprime aucune réservation ni date d’expiration. Un second passage ne réapplique pas ces modifications. Les migrations de secrets déjà effectuées ne sont pas répétées inutilement. Les migrations DDL ne sont pas une transaction globale : en cas d’échec, garder les traitements arrêtés, corriger la cause puis rejouer. Un retour arrière nécessite le jeu cohérent code/base/clé sauvegardé.

## Migration des anciens émetteurs webhook

**Une mise à niveau depuis une version conservant `webhook_key` en clair génère un nouveau secret de signature pour chaque ancienne clé à migrer. Les appels bearer ou jeton seul sont refusés. Les anciens émetteurs doivent être reconfigurés.**

- Le droit **Webhook credentials**, avec **Reveal** et **Rotate**, est désactivé par défaut ; aucun profil ne reçoit automatiquement ce nouveau droit. L’administrateur doit le déléguer explicitement, en complément des droits de consultation/modification et du périmètre d’entités requis.
- Révéler le secret depuis la fiche de connexion, configurer chaque émetteur pour signer avec HMAC, puis tester un appel et son rejeu. Une rotation ultérieure invalide immédiatement l’ancien secret.
- Si la migration des clés a déjà eu lieu en RC13 ou dans la première 2.18.39, cette révision ne régénère pas les secrets existants. Le bouton bearer et l’action associée ont été retirés ; les données internes nécessaires à la compatibilité de stockage sont conservées.

## Correspondance avec la revue TECLIB

« Corrigé » désigne ici du code livré et vérifié localement, pas une approbation de production par TECLIB.

| Référence | Traitement dans cette livraison | Vérification / limite |
| --- | --- | --- |
| Constat 1 : secrets et changement d’origine | Verrouillage API des écritures de connexion, refus des secrets non liés et migration de liaison origine/méthode conservés. | Tests de migration, chiffrement GLPIKey, rejet avant transport et droits combinés. |
| Constat 2 : connexion partagée entre entités | Contrôle de tous les jobs liés avant les actions de connexion conservé, y compris jobs en pause. | Tests de refus avant invocation du service, entité 0 et mappings. |
| Constat 3 : assignations de licences | Jointure avec les licences et filtre d’entité avant LIMIT conservés. | Query builder GLPI réel, données fictives SQLite. |
| Constat 4 : CSP | JavaScript externe et écouteurs délégués conservés. | Compilation Twig et navigateur sous CSP restrictive. |
| Quality 1 / UXQ-01 | `JobEntityAccess` est utilisé par les contrôleurs de connexion. | Tests des actions de mutation, test et activation. |
| Quality 2 / UXQ-02 | Suppression de `reveal_bearer`, du bouton et de `allow_webhook_bearer`. | Recherche dans le code livré ; HMAC reste obligatoire. |
| Quality 3 / UXQ-03 | Procédure webhook et impact sur les émetteurs explicités ci-dessus. | Reconfiguration d’un ancien émetteur à vérifier sur l’instance. |
| Quality 4 / UXQ-04 | Liaison des secrets hérités, rejouable et bloquante sur erreur, conservée. | Tests existants réexécutés. |
| Quality 5 / UXQ-05 | Rapport des jobs actifs pendant la migration et commande native d’inventaire. | Cas nuls, invalides, fixes, racine, mappings et défaut ; pas de créateur historique disponible. |
| Quality 6 / UXQ-06 | Fermeture de la lecture **et de l’écriture** génériques des `SyncJobItem`, `SyncExecutionItem` et `ObjectLinkItem`. | `canView()` bloque listes/recherches ; `can()` et `canViewItem()` bloquent accès direct. Vérification avec les vraies classes de base GLPI. |
| Quality 7 / UXQ-07 | Plusieurs adresses déjà approuvées essayées avec un budget de temps partagé, sans nouvelle résolution DNS. | Test TLS réel IPv6 inaccessible puis IPv4, OAuth2 et REST v1 ; Host/SNI, certificats, redirections et limites. |
| Quality 8 / UXQ-08 | `id` numérique en clé primaire, `fingerprint` UNIQUE, migration sans purge. | SQL produit par la vraie classe Migration de GLPI ; reprise, réexécution et échec d’indexation. Exécution MariaDB et contrôle de schéma GLPI encore requis. |
| Quality 9 / UXQ-09 | Commentaires français du code livré traduits et descriptions obsolètes actualisées. | Extraction des commentaires PHP/Twig et relecture ; textes UI et documentation utilisateur conservés en français. |
| Quality 10 / UXQ-10 | Suppression de `tools/create-copilot-client.php` dans le dépôt. | Diff Git ; cet outil était déjà absent du paquet installable. |

### Conséquences fonctionnelles explicites

Les objets internes de synchronisation ne constituent plus une API CRUD générique publique, même pour un profil administrateur. Les écrans et services du plugin continuent à fonctionner avec leurs contrôles de droits et d’entités. Aucun nouveau point d’accès REST public n’est ajouté. Une intégration externe qui lisait directement ces itemtypes doit être revue.

Le repli d’adresse concerne les lectures GET/HEAD/OPTIONS et les échanges d’authentification explicitement prévus pour cela. Un POST/PUT/PATCH/DELETE métier n’est pas réémis sur une autre adresse par ce nouveau mécanisme : un échec d’ouverture HTTP peut survenir après envoi. Les budgets de lecture et de connexion sont partagés par tentative ; les politiques de retry et les limites de redirection existantes restent applicables.

Le rapprochement des objets, les mappings, les règles Fields, le périmètre des entités, les statuts des jobs et la logique des checkpoints ne sont pas modifiés par ce complément.

## Résultats locaux et limites

- PHP **8.4.27**, sources GLPI **11.0.0**, fixtures isolées. Aucun accès à une instance client.
- **71 tests de sécurité, 265 assertions : succès.** Chiffrement, droits, périmètres, repli d’adresse, inventaire et migration de clé primaire.
- Anti-rejeu testé sur une base SQLite isolée avec `id` auto-incrémenté et `fingerprint` UNIQUE : HMAC, rejeu entre instances du service, altération, expiration, jeton seul et indisponibilité du stockage. Ce contrôle ne simule pas une concurrence InnoDB.
- Contrat d’héritage vérifié séparément avec le véritable `CommonDBTM` GLPI, sans base applicative.
- HTTPS réel en boucle locale, certificat fictif de confiance : IPv6 indisponible puis IPv4, OAuth2 et REST v1, Host/SNI corrects, rejet du mauvais nom TLS, refus des redirections automatiques et réponse trop volumineuse.
- Chromium **156** : onze confirmations, annulation/validation, clavier, formulaires dynamiques, rotation, sélection groupée et redirection encodée sous CSP à nonce. Aucune erreur de script/CSP.
- Régressions héritées RC13 : sorties, masquage, arrêt, migrations, retrait, réseau, écritures, pièces jointes, snapshots et identité des équipements. Deux fixtures ont été adaptées au nouveau contrat interne `streamUrls()` et au déplacement de l’ouverture stream ; leurs assertions de sécurité sont conservées.
- PHPStan niveau 5 ciblé sur 14 classes, avec sources GLPI et sans certitude imposée aux annotations PHPDoc : aucune erreur. Ce n’est pas une analyse de tout le plugin.
- Cinq contre-épreuves détectent les protections volontairement retirées dans des copies temporaires.
- Syntaxe des 333 fichiers PHP de production : aucune erreur.
- Les journaux, empreintes, contrôles de style et résultat du réaudit sont fournis dans le dossier de preuves de cette livraison.

**Non exécutés ici : installation/migration sur MariaDB dans un GLPI complet, requêtes REST authentifiées sur cette instance, contrôle de schéma GLPI et concurrence de deux webhooks sur la table migrée. PHP 8.2 et 8.5 n’ont pas été exécutés dans cet environnement.** La suite PHPUnit générale héritée a des doubles de test obsolètes ; elle ne doit pas être présentée comme entièrement verte. Les tests ciblés ne valent pas certification de production.

## Recette finale sur une copie de l’instance

1. Migrer une copie RC13 et une copie déjà en 2.18.39, puis relancer une seconde fois. Vérifier conservation des secrets, jobs, liens, fingerprints et expirations ; index primaire `id`, unicité `fingerprint` et contrôle de schéma GLPI.
2. Avec un profil restreint à une entité, refuser les accès génériques directs, listes et recherches sur les trois itemtypes internes ; vérifier aussi toutes les mutations de connexion partagée et les assignations de licences.
3. Vérifier que les écrans autorisés, la création/modification des jobs via leurs services, l’historique et les exports restent fonctionnels.
4. Contrôler l’inventaire des jobs actifs avant reprise des workers. Faire valider les périmètres historiques par l’administrateur.
5. Sur un job webhook fictif, valider HMAC, rejeu séquentiel, deux appels identiques simultanés (un seul accepté), rotation, droits insuffisants et absence de CSRF pour les actions d’administration.
6. Vérifier une authentification OAuth2/REST v1 sur la connexion réelle, puis une synchronisation et sa réexécution sans doublon.

Les huit signaux heuristiques génériques peuvent rester présents : une route machine, une sortie CSV ou un nom de champ sensible demandent une revue de contexte. Leur présence n’est pas, à elle seule, la preuve d’une faille ni d’un échec des correctifs.
