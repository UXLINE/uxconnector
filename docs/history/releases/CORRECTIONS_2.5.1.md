# UXConnector 2.5.1 — audit et corrections GLPI 11

## Verdict

La base 2.5.0 auditée est nettement plus mature que SLA Manager et UXKnowDash :
architecture DDD/Clean réellement structurée, Twig, CommonDBTM/Query Builder, droits,
CSRF, chiffrement via GLPIKey, tests unitaires/intégration et protections réseau déjà
présentes.

La revue a cependant identifié plusieurs défauts de fiabilité/sécurité suffisamment
importants pour justifier une 2.5.1 avant validation production.

## Corrections appliquées

### 1. PSR-4 cohérent avec GLPI

`composer.json` déclarait `GlpiPlugin\\UxConnector\\` alors que toutes les classes
utilisent `GlpiPlugin\\Uxconnector\\`. Le préfixe Composer est désormais aligné sur
le namespace réellement utilisé par le plugin.

### 2. Liste blanche des champs GLPI synchronisables

Un mapping ne peut plus cibler une colonne arbitraire de la table GLPI. Une politique
`TargetFieldPolicy` définit les champs de rapprochement et d'écriture autorisés pour :

- Computer ;
- Monitor ;
- NetworkEquipment ;
- Peripheral ;
- Phone ;
- Printer ;
- User.

Les colonnes techniques/sensibles (`id`, `entities_id`, `is_deleted`, `is_template`,
champs d'authentification/tokens, dates système, etc.) ne peuvent pas être écrites par
un mapping externe. La configuration est validée à la création du SyncJob **et à
chaque exécution**, afin de protéger aussi les jobs créés avec une ancienne version ou
modifiés directement en base.

`GlpiItemWriter` conserve une seconde barrière défensive et retire les champs réservés
avant tout `CommonDBTM::add()`/`update()`.

### 3. ObjectLink isolé par SyncJob

La clé historique `AuthConfig + external_id` pouvait faire se croiser deux flux
utilisant le même connecteur et le même identifiant externe. Le lien est désormais
borné par :

`SyncJob + AuthConfig + itemtype + external_id`.

Le schéma et le Repository ont été modifiés en conséquence. La migration est additive :
les anciens liens restent présents avec `plugin_uxconnector_syncjobs_id = 0` et ne sont
plus utilisés par les nouveaux jobs. Le premier passage de chaque job recrée alors un
lien correctement scopé après rapprochement métier.

Si l'objet GLPI lié a été purgé, le lien devenu obsolète est supprimé et le plugin
rejoue la réconciliation initiale au lieu d'échouer indéfiniment.

### 4. Affectation d'entité en échec fermé

Le parseur ne transforme plus silencieusement une configuration multi-entité invalide
en « aucune stratégie ». Sont maintenant refusés explicitement :

- stratégie inconnue ;
- identifiant d'entité invalide ;
- ligne de mapping mal formée ;
- valeur source dupliquée ;
- politique de fallback inconnue ;
- `USE_DEFAULT` sans entité par défaut.

`GlpiItemWriter` vérifie également que l'entité résolue existe avant l'écriture.

### 5. Durcissement SSRF IPv6

Les IPv4 mappées en IPv6 sont maintenant analysées sous leur forme binaire. Cela bloque
notamment les variantes hexadécimales de loopback et de metadata endpoint telles que :

- `::ffff:7f00:1` ;
- `::ffff:a9fe:a9fe`.

### 6. Secrets uniquement sur HTTPS

Une AuthConfig qui utilise des credentials réutilisables ne peut plus être créée sur un
endpoint HTTP. La même vérification est rejouée au moment de l'exécution pour protéger
les anciennes lignes en base.

Le endpoint OAuth2 Client Credentials doit lui aussi être HTTPS avant l'envoi du
`client_id/client_secret`.

### 7. Durcissement HTTP

- rejet des credentials intégrés dans une URL (`user:pass@host`) ;
- rejet des query strings/fragments dans l'Endpoint de base ;
- rejet des caractères de contrôle dans le chemin ;
- validation syntaxique du nom des headers ;
- rejet CR/LF dans les valeurs de headers ;
- redirections automatiques toujours désactivées ;
- limite de taille de réponse conservée.

### 8. Parsing des mappings

Les lignes entièrement vides sont ignorées ; une ligne partiellement remplie est
refusée. Seules les transformations réellement supportées peuvent être configurées.

### 9. Lifecycle GLPI 11

Les quatre wrappers `front/*.php` ne chargent plus manuellement `inc/includes.php`.
Ils restent volontairement de fins adaptateurs legacy, avec contrôles de droits et
CSRF dans les contrôleurs métier. Les deux POC de contrôleurs natifs non prouvés en
runtime ont été retirés plutôt que laissés comme code mort.

L'uninstall conserve les données métier conformément à l'ADR du projet, mais retire
maintenant les droits `ProfileRight` afin de ne pas laisser de métadonnées orphelines.

### 10. Compatibilité et Marketplace

- version portée à 2.5.1 ;
- compatibilité déclarée `GLPI >= 11.0.0` et `< 12.0.0` tant que GLPI 12 n'a pas été
  validé ;
- ancien `plugin.xml` retiré ;
- ajout de `uxconnector.xml.dist` au format catalogue actuel avec placeholder de
  téléchargement public ;
- liens repository/support non vérifiables retirés au lieu d'être publiés comme s'ils
  existaient ;
- ajout du GLPI Coding Standard dans les dépendances de développement.

### 11. Tests anti-régression

Ajouts/corrections de tests sur :

- politique de champs ;
- scope ObjectLink ;
- parsing des entités ;
- parsing des mappings ;
- IPv4-mapped IPv6 ;
- URL/headers HTTP ;
- transport HTTPS des credentials/OAuth2 ;
- schéma ObjectLink ;
- architecture : PSR-4, absence de bootstrap legacy, absence de SQL brut runtime,
  conventions SQL GLPI 11.

## Ce qui n'a volontairement pas été « corrigé » artificiellement

### Concurrence d'un même SyncJob

Deux exécutions réellement simultanées du même job (cron + manuel ou plusieurs nœuds)
peuvent encore se rencontrer pendant la première réconciliation avant création de
l'ObjectLink. Une correction robuste nécessite un verrou distribué/par job ; ce point
reste à traiter avant un déploiement multi-nœuds fortement concurrent.

### DNS rebinding

La destination est résolue et contrôlée avant l'ouverture de la connexion, mais l'IP
n'est pas « pinnée » jusqu'au socket effectif. Un DNS malveillant changeant de réponse
entre les deux étapes reste une limite connue.

### Rétention SyncExecution

L'historique n'a pas encore de politique automatique de rétention/purge.

### Contrôleurs GLPI 11 natifs

Les wrappers `front/` restent supportés et ont été conservés. Une migration complète
vers les Controllers/routes natifs doit être faite seulement avec un runtime GLPI 11
réel permettant de valider le routage, Twig et les redirections.

## Validation requise sur instance GLPI réelle

Avant qualification production :

1. mise à jour 2.5.0 → 2.5.1 avec données existantes ;
2. vérification de la migration ObjectLink ;
3. création/test/activation de chaque méthode d'authentification réellement utilisée ;
4. création d'un SyncJob avec entité fixe puis mappée ;
5. exécution initiale, seconde exécution (UPDATE via ObjectLink), purge d'un objet puis
   réexécution ;
6. cron + exécution manuelle ;
7. contrôle des droits de profils ;
8. désinstallation/réinstallation selon la politique de rétention.
