# Revue ciblée UXConnector 2.18.38 RC11

Cette candidate renforce les contrôles associés aux huit signaux relevés dans la RC10. Un signal du scanner est une demande de revue, pas la preuve d’une vulnérabilité. Le scanner générique est conservé sans exception ajoutée et peut donc afficher ces huit signaux après correction. Cette revue ne vaut ni homologation TECLIB, ni audit exhaustif de l’application.

## Qualification des huit signaux

| Signal et fichier | Traitement et justification | Validation ciblée |
| --- | --- | --- |
| SEC-05 · `setup.php` | L’exception de session est nécessaire au webhook machine et explicitement prévue par GLPI. Le chemin est limité à `front/webhook.php`. Signature HMAC obligatoire, POST, limite de corps 2 Mio, horodatage et anti-rejeu persistant. | Résolution native GLPI des 18 routes : seule la route webhook est sans session. Tests HMAC, ancien jeton refusé, signature altérée, expiration, répétition, stockage indisponible. |
| SEC-05 · `src/Infrastructure/HookManager.php` | Déclaration firewall du même endpoint. Les 17 autres routes conservent les stratégies GLPI. La signature ne fournit l’accès qu’au job entrant actif identifié. L’administration des clés reste protégée par droits, entités et CSRF GLPI. | Résolution native du firewall ; tests de périmètre d’entités, rotation et modification concurrente du périmètre avec doubles de stockage. |
| SEC-08 · `src/Presentation/Sync/Controller/SyncExecutionController.php` | `fputcsv` est conservé pour l’encodage correct. Les cellules dynamiques passent par la neutralisation des préfixes de formule. Les erreurs d’écriture sont maintenant contrôlées et les réponses portent `no-store` et `nosniff`. Le contrôle d’entité précède l’export dans la route. | Formules ASCII et pleine chasse, caractères initiaux invisibles, UTF-8 invalide, séparateurs, guillemets et cellules multilignes. |
| SEC-11 · `sql/install/migrate-webhook-keys.php` | La clé ancienne est transformée en empreinte de recherche et secrets chiffrés par GLPIKey. Vérification du déchiffrement avant suppression de la valeur ancienne ; refus de supprimer la colonne si des anciennes valeurs subsistent. | Migration rejouable, échec d’écriture préservant la source, suppression de colonne, absence de clé brute dans le mapping de persistance. |
| SEC-11 · `src/Infrastructure/Engine/Http/Authentication/OAuth2TokenClient.php` | `client_secret` et `password` sont des noms de champs OAuth2 requis. Leur présence en mémoire pour l’échange HTTPS n’est pas un stockage en clair. Paramètres sensibles masqués dans les traces PHP, TLS vérifié, pas de redirection, réponse limitée à 1 Mio et lecture avec délai restant de 30 s. Jeton Bearer validé avant utilisation en en-tête. Cache limité au jeton et à son expiration, sans affichage de son contenu dans `var_dump`. | Serveur HTTPS local avec secrets fictifs : succès, cache, TLS non fiable, redirection, 401, réponse trop grande, jeton vide ou contenant CR/LF et dépassement du délai de lecture. |
| SEC-11 · `src/Infrastructure/Sync/SyncServiceProvider.php` | Le secret de signature est déchiffré côté serveur pour vérifier le HMAC. Il n’est transmis ni à l’émetteur ni au résultat du webhook. Le fournisseur sélectionne uniquement les jobs entrants actifs et injecte le stockage anti-rejeu. | HMAC séparé du jeton, rotation et refus de l’ancienne signature ; inspection du branchement au stockage anti-rejeu. |
| SEC-11 · `src/Presentation/Engine/Controller/OAuth2ClientCredentialsSecretComposer.php` | Assemblage transitoire en mémoire, puis chiffrement par le service de création/modification. Validation des valeurs scalaires et conservation exacte des octets du secret. Le paramètre de formulaire est masqué dans les traces PHP. | Formulaire valide, tableau malformé refusé, espaces du secret conservés, secret fictif absent de la trace de rejet. |
| SEC-11 · `src/Presentation/Engine/Controller/OAuth2PasswordSecretComposer.php` | Même protection pour le flux GLPI OAuth2 Password. Le mot de passe doit être récupérable pour l’appel HTTPS ; il est chiffré au repos, pas remplacé par une empreinte inutilisable pour ce protocole. | Formulaire valide et malformed refusé ; test natif GLPIKey avec clé jetable, chiffrement aléatoire et mauvaise clé refusée. |

Les trois colonnes chiffrées du plugin restent déclarées dans le hook GLPI `secured_fields`. Les champs webhook et `protected_secret` restent exclus des représentations API via `undisclosedFields`. Le droit natif `Webhook credentials` est conservé pour la consultation explicite et la rotation.

## Changement du webhook et migration

La formule de signature reste `HMAC-SHA256(secret, job_id + "." + timestamp + "." + corps_brut)` avec les en-têtes `X-UXConnector-Webhook-ID`, `X-UXConnector-Timestamp` et `X-UXConnector-Signature`. Le secret ne doit pas être envoyé dans la requête. Utiliser HTTPS en déploiement.

- Le jeton seul est désormais refusé. L’ancienne variable `UXCONNECTOR_REQUIRE_WEBHOOK_HMAC=0` n’assouplit plus le contrôle.
- Une nouvelle table `glpi_plugin_uxconnector_webhookrequests` conserve uniquement l’empreinte SHA-256 d’une livraison authentifiée et sa date d’expiration en secondes Unix. Sa clé primaire unique arbitre les demandes concurrentes. Les écritures utilisent le query builder GLPI. Le schéma fixe est chargé avec `DBmysql::runFile`.
- Le même triplet job/horodatage/signature n’est accepté qu’une fois pendant sa validité. Le premier appel réserve la livraison avant traitement. Un appel répété reçoit HTTP 409, même si le premier traitement est encore en cours ou s’est interrompu. Une indisponibilité du stockage interdit le traitement.
- Cette protection empêche le rejeu exact. Elle ne garantit pas une exécution métier exactement une fois : le même événement re-signé avec un autre horodatage constitue une nouvelle livraison. Garder un identifiant source stable pour le rapprochement des objets.
- Après un résultat incertain, vérifier l’historique avant de réessayer. Pour une nouvelle tentative volontaire, produire un nouvel horodatage et une nouvelle signature. Deux événements distincts ayant un corps identique dans la même seconde sont considérés comme le même envoi ; donner un identifiant distinct au payload si nécessaire.
- Les empreintes expirées sont nettoyées lors des demandes authentifiées suivantes. Si aucun appel ne survient, elles restent en base jusqu’au prochain appel ou à la désinstallation. Elles ne contiennent ni payload ni secret.

Sur l’instance de test, sauvegarder base et fichiers puis remplacer intégralement le dossier du plugin. Toutes les candidates déclarent 2.18.38 ; exécuter la migration même si GLPI ne propose pas de mise à jour automatique.

```powershell
docker exec glpi-data-glpi-1 php /var/www/glpi/bin/console glpi:plugin:install --force uxconnector
if ($LASTEXITCODE -ne 0) { throw "Migration en echec." }
docker exec glpi-data-glpi-1 php /var/www/glpi/bin/console glpi:plugin:activate uxconnector
if ($LASTEXITCODE -ne 0) { throw "Activation en echec." }
docker exec glpi-data-glpi-1 php /var/www/glpi/bin/console cache:clear
```

Ne pas désinstaller le plugin pour effectuer la mise à jour : la désinstallation supprime les données.

## Vérifications sur GLPI après installation

1. Rejouer votre envoi signé fictif avec un nouvel horodatage : HTTP 200 et historique cohérent. Renvoyer exactement les mêmes octets et en-têtes : HTTP 409 sans nouvelle écriture métier. Le test du corps modifié doit encore renvoyer HTTP 400.
2. Vérifier les appels sans signature, avec clé seule, signature expirée, ancien secret après rotation : refus. Une nouvelle signature avec le nouveau secret doit fonctionner.
3. Avec le profil COAXIS limité à l’entité 10, confirmer les blocages sur les jobs de l’entité racine, y compris accès direct à l’historique, CSV et formulaire de credentials.
4. Télécharger un CSV de recette comportant guillemets, points-virgules, retours à la ligne et libellés commençant par `=`, `+`, `-` ou `@`. Les cellules doivent rester du texte lors de l’ouverture dans votre tableur. CSV n’impose pas de type de cellule et les réenregistrements peuvent modifier les protections ; ne pas considérer une réouverture après édition comme garantie par le plugin.
5. Tester la connexion Zendesk et les connexions GLPI existantes, puis une modification et une rotation de secret. Les valeurs déjà chiffrées restent compatibles.
6. Tester deux envois identiques simultanés sur la vraie base : un seul doit être admis. Les tests locaux utilisent des doubles pour la base et ne remplacent pas ce contrôle InnoDB/MariaDB.

## Périmètre et limites des preuves

Les rapports joints portent sur PHP 8.3, PHPCS PSR12 et les composants GLPI 11.0.11 disponibles localement. Les tests de migration, persistance, droits et concurrence métier utilisent des doubles contrôlés. Le chiffrement GLPIKey et la résolution des stratégies HTTP utilisent les vraies classes GLPI ; OAuth2 utilise un serveur HTTPS local, sans compte réel.

PHPUnit complet, PHPStan global, audit des dépendances, essai cloud et matrice entière GLPI/PHP ne sont pas certifiés par ces résultats. La compatibilité déclarée `>=11.0.0 <12.0.0` est conservée ; elle n’équivaut pas à des tests sur chaque version. Le kit générique continue à distinguer les signaux bruts de leur qualification. Les rubriques REV-01 à REV-12 nécessitent toujours une recette adaptée au contexte réel.

## Références

- [GLPI : routes sans session et authentification applicative](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/controllers.html#unauthenticated-access)
- [OWASP : injections CSV et limites selon les tableurs](https://community.owasp.org/attacks/CSV_Injection)
- [OWASP : sécurité des API REST](https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html)
- [OWASP : stockage chiffré](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
