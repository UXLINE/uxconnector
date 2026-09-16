## 2.17.1 - 2026-09-16

### GLPI High-Level asset reference performance hotfix

- Cache resolved GLPI references (entity, state, manufacturer, type, model and location) during a synchronization run to avoid repeated database lookups for thousands of assets.
- Skip the second GLPI asset update when all reference IDs are already correct, making idempotent replays much cheaper.
- Refresh PHP's normal execution-time budget at each pagination boundary so multi-page jobs are not killed solely because the whole run exceeds `max_execution_time`; each individual page remains bounded by the configured PHP limit.
- No schema change and no job recreation required.

## 2.17.0 - 2026-09-16

- GLPI High-Level assets: enrichissement automatique des six types d’actifs avec entité, statut, fabricant, lieu, type et modèle.
- Les identifiants numériques de référentiels du GLPI source ne sont jamais recopiés ; UXConnector résout ou crée les valeurs par nom dans la cible.
- Les entités et lieux hiérarchiques utilisent `completename` lorsqu’il est fourni par l’API.
- Refus des correspondances ambiguës lorsqu’un même référentiel existe plusieurs fois dans le même périmètre cible.
- Correction permanente des chemins de catalogue GLPI High-Level vers `/api.php/Assets/...`.
- Les assets déjà importés en 2.16.x peuvent être enrichis par simple relance des jobs existants grâce à ObjectLink.

## 2.16.0 - 2026-09-16

## 2.16.1

- Corrige la création des jobs GLPI High-Level Assets utilisant le MatchField synthétique `asset_identity`.
- `asset_identity` est maintenant validé lorsqu'au moins un mapping cible `uuid`, `serial`, `otherserial` ou `name` est présent.
- Ajoute des tests unitaires de non-régression pour cette stratégie de réconciliation.


- GLPI 11 High-Level API : ajout de 6 jobs de consolidation de parc (Computer, Monitor, Phone, Peripheral, Printer, NetworkEquipment).
- Ajout de la pagination générique `count_offset` (`start` + `limit`) pour les listes High-Level API renvoyées sous forme de tableau JSON.
- Ajout de la réconciliation `asset_identity` multi-source : UUID / série / inventaire, avec fallback nom uniquement pour Computer et NetworkEquipment.
- Les templates et actifs soft-deleted d'un GLPI source sont ignorés.
- Les IDs de référentiels distants (fabricant, modèle, type, statut, lieu) ne sont pas recopiés aveuglément ; leur réconciliation sera traitée séparément.

## 2.15.0 - 2026-09-15

- Stream inbound pagination page by page instead of accumulating all remote records in memory.
- Zendesk initial ticket migration now uses Search Export with `status<closed` and a recommended 100-record page size.
- Zendesk ITIL-topic discovery uses the same non-closed Search Export scope.
- Add a separate `zendesk.tickets.delta.inbound` cursor-based incremental job for post-initial-load deltas.
- Persist incremental checkpoints after every fully successful page; freeze the checkpoint before the first failing page so replay remains lossless and idempotent.
- Add generic HTTP 429 handling honoring `Retry-After` (up to 5 retries).
- Add `tools/zendesk-volume-test.php`, a no-write synthetic 10,000-record pagination/load test.

## 2.14.6 - 2026-09-15

- Replace Zendesk Ticket Form categorization with the real Zendesk `Sujet`/topic tagger field.
- Discover the topic field dynamically from `topic__*` tagger options, independent of tenant field id and UI language.
- Add `zendesk.itilcategories.bootstrap`: scan only non-closed tickets and create only topic categories actually used by the migration scope.
- Rebuild Zendesk `A::B::C` topic labels as native hierarchical GLPI ITIL categories.
- Link each technical Zendesk topic value to its GLPI leaf category with `ObjectLink` (`zendesk_topic`).
- Ticket import now fills `Ticket.itilcategories_id` from the Zendesk topic and creates a newly discovered category on demand during delta runs.
- Keep the former Ticket Forms category bootstrap hidden for compatibility with already-created 2.14.5 jobs.

## 2.14.5 - 2026-09-15

- Fix Zendesk inline attachment downloads: allow signed/tokenized query strings while retaining HTTPS and SSRF host validation.
- Keep attachment downloads unauthenticated across Zendesk -> zdusercontent redirects; OAuth remains an account-protected fallback only.
- Make bulk interaction controls visible for every profile allowed to manage jobs on a connection.
- Align VERSION, marketplace metadata and Zendesk connector template versions with plugin 2.14.5.

## 2.14.4 - 2026-09-15

- Zendesk inline-image fallback: when `include_inline_images=true` still leaves the screenshot only in `body`/`html_body`, UXConnector synthesizes a safe attachment descriptor from the tokenized `/attachments/...` URL.
- Attachment downloads now try the tokenized URL without OAuth credentials first, then fall back to the authenticated Zendesk endpoint when required.
- Bulk interaction activation/pause is now available on the **connection detail / Interactions** page where standard connector jobs are actually managed.
- Bulk actions verify that selected jobs belong to the current connection.

## 2.14.3 - 2026-09-15

- Zendesk inline screenshots now use GLPI 11 native `CommonDBTM::addFiles()` + document tags instead of hand-crafted image HTML.
- Existing Zendesk followups can be repaired idempotently using their existing Document/ObjectLink records.
- Bulk job actions are exposed in a dedicated visible toolbar with Select all, selected count, Activate and Pause.
- Bulk selection no longer relies on cross-form checkbox association.

# 2.14.2

## 2.14.2 - 2026-09-15

- Fix Zendesk inline screenshots: store them as GLPI Documents and rewrite Zendesk Markdown image URLs to native GLPI inline images.
- Replay-safe repair of existing 2.14.1 followups using ObjectLink without duplicate comments/documents.
- Keep regular attachments as linked GLPI Documents.
- Add bulk selection with Activate selection / Pause selection on the synchronization jobs page.


- Zendesk comments: preserve replies as GLPI followups.
- Zendesk screenshots/attachments: request inline images and import them as GLPI Documents.
- Initial comment identical to ticket description remains de-duplicated while its attachments are preserved.

# 2.13.0 - 2026-09-14

## Zendesk Support

- Ajout du provider catalogue `zendesk`.
- Deux templates d'import de tickets : tous les tickets et création initiale limitée aux tickets actifs.
- Export incrémentiel Zendesk cursor-based (`after_cursor` / `end_of_stream`).
- Persistance du curseur par SyncJob et reprise automatique au passage suivant.
- Idempotence conservée via `ObjectLink`; un ticket actif déjà importé continue d'être mis à jour lorsqu'il devient `solved` ou `closed`.
- Mapping natif Zendesk → GLPI pour identifiant externe, sujet, description, statut, priorité, type et date de création.
- Support des authentifications Zendesk par API token via Basic (`email/token` + token) ou OAuth Bearer.

> Cette version 2.13.0 couvre le flux de tickets. La reprise des commentaires/pièces jointes n'est pas encore exécutée par le moteur générique de templates et doit être ajoutée dans un lot spécialisé si elle est requise.

# 2.10.2 - Copilot Analytics Semantics

- Corrects TTR coverage: the denominator is now solved/closed tickets, not all opened tickets.
- Restricts TTR samples to tickets currently solved/closed so historical solved dates on reopened tickets cannot contaminate the KPI.
- Adds an explicit resolution rate separate from TTR data-quality coverage.
- Adds period observation metadata (requested days, observed days, observed-through date, partial-period flag).
- Adds comparison warnings when one period is still in progress or extends beyond the current date.
- Adds normalized per-observed-day rates for safer comparison of partial versus completed periods.
- Clarifies OpenAPI semantics for Copilot Studio.

# 2.10.1 - Copilot Analytics Data Quality

- Added KPI sample sizes and coverage.
- Corrected historical zero-duration handling.
- Corrected trend semantics (opened by creation date, resolved by solve/close date) and zero buckets.
- Added group overlap/unassigned counters.
- Added technician intervention metrics and optional user exclusions.
- Enriched SLA coverage, current/historical breaches and breach context.
- Added comparison warnings for weak samples and clarified backlog semantics.
- Improved local ticket-context duration and final-solution normalization.

# 2.10.0 - Copilot Analytics Lot 2

- Adds a visible **AI Agents / Copilot** entry point directly on the UXConnector Connections page.
- Adds read-only analytical tools for ticket summary/volume, trends, categories, SLA, groups, technicians and period comparison.
- Enriches `searchTickets` with requester, assigned groups/users and linked assets for returned rows.
- Enriches `getTicketContext` with named actors, assigned group list, human-readable TTO/TTR/task durations, canonical timeline and final solution.
- Extends the Copilot OpenAPI document with dedicated semantic operations for the Lot 2 tools.
- Keeps Lot 2 analytics local-GLPI-only for this release; remote GLPI HL analytics remains a future adapter.

# Changelog

## 2.9.1

- Ajoute l'écran **AI Agents / Copilot Gateway** dans le menu UXConnector.
- Ajoute le provider `local_glpi`, recommandé lorsque UXConnector est installé dans le GLPI interrogé : aucun appel HTTP vers soi-même et aucune seconde authentification OAuth ne sont nécessaires.
- Conserve `glpi_hl_api` pour les GLPI distants via une connexion UXConnector.
- Ajoute `provider_type` aux clients Copilot et permet de créer/révoquer/activer les clés depuis l'interface.
- Restreint explicitement la règle Firewall/stateless aux seuls endpoints machine Copilot afin que la page d'administration AI Agents reste protégée par la session GLPI.

## 2.9.0

- Added the read-only UXConnector Copilot Gateway Lot 1.
- Added canonical ITSM ticket model, GLPI HL provider, Copilot API clients and OpenAPI tools.

## 2.8.4 — 2026-08-19

- Fix standard interaction creation on an already active connection: the `Activate immediately` checkbox no longer reuses the connection-level `activate` POST action name.
- Dispatch `instantiate` before AuthConfig lifecycle actions as a defensive routing rule.
- No database schema change.

## 2.8.3 — 2026-08-19

- Ajoute des accès directs **Edit** et **Interactions** depuis la liste des connexions.
- Ajoute un raccourci **Interactions** dans l'en-tête de la fiche connexion.
- Ajoute une ancre `#interactions` afin d'ouvrir directement la zone des interactions standards.
- Expose le droit de lecture des jobs à la vue liste sans contourner le modèle de droits GLPI.

## 2.8.2 — 2026-08-19

### Correctif catalogue / types de connecteurs

- Corrige le chemin racine utilisé par `TemplateServiceProvider` pour charger `resources/connectors/*/connector.json`.
- En 2.8.1, `dirname(__DIR__, 2)` pointait vers `src/` et le dépôt cherchait à tort `src/resources/connectors`, ce qui renvoyait zéro connecteur standard.
- Le sélecteur de type de connexion affiche désormais Jira Cloud, Microsoft Intune, Microsoft Graph, MDM générique, ServiceNow, Zabbix et REST générique.
- Le catalogue et la fiche Connection partagent de nouveau la même source de templates embarqués.
- Aucun changement de schéma SQL.

## 2.8.1 — 2026-08-19

### Parcours Connection

- Ajout de l’édition d’une Connection existante : nom, endpoint, ressource de test, méthode d’authentification et remplacement optionnel du secret.
- Un secret laissé vide lors de l’édition est conservé ; un nouveau secret invalide le test précédent et impose un nouveau test avant activation.
- La fiche Connection affiche désormais explicitement le type, la méthode d’authentification et la ressource utilisée par le bouton Test.
- Le type de connecteur peut être associé/modifié depuis la fiche Connection, y compris pour les anciennes connexions migrées en `Custom REST`.
- Associer un connecteur standard applique automatiquement sa ressource de test recommandée. Ainsi une ancienne connexion Jira testée sur `/` n’échoue plus par construction sur le gateway Atlassian.
- Changer le type de connecteur ou la ressource de test remet volontairement la Connection en `Brouillon` afin d’exiger une nouvelle validation.
- Le bouton Test de la liste exécute le test puis revient à la liste au lieu de donner l’impression d’être un alias de Manage.
- Ajout des actions Activate / Disable / Delete directement dans la liste lorsque l’état et les droits le permettent.
- Le formulaire REST technique reste accessible uniquement comme interaction avancée ; les interactions standard apparaissent directement sur la fiche après association à un connecteur du catalogue.

### Sécurité / cohérence

- Le changement de méthode d’authentification exige un nouveau secret lorsque la nouvelle méthode utilise des credentials, pour éviter de réinterpréter un secret chiffré sous un autre format.
- Passer à une méthode sans credential purge le secret protégé devenu inutile.
- Les méthodes d’authentification proposées en édition restent filtrées par les capacités du connecteur standard associé.

## 2.8.0 — 2026-08-18

### Connections

- UXConnector est désormais centré sur des **Connections** : création manuelle ou à partir du catalogue, test, activation, désactivation et suppression.
- Une configuration historique `AuthConfig` reste l'agrégat interne pour préserver la compatibilité, mais l'interface n'expose plus ce vocabulaire technique comme concept principal.
- Ajout de `connector_id` afin d'associer une Connection réelle à une définition de connecteur sans dupliquer endpoint ni secret.
- Une ancienne Connection `custom` peut être associée à un connecteur du catalogue si sa méthode d'authentification est compatible.
- Désactiver une Connection suspend ses interactions actives sans les mettre en erreur.

### Interactions

- Les interactions standard sont créées/activées depuis la fiche de la Connection ; le formulaire REST complet devient un éditeur **Advanced custom REST interaction**.
- Ajout des triggers `polling`, `webhook` et `glpi_event`. Le bidirectionnel est représenté par deux interactions indépendantes.
- Ajout d'un webhook stateless générique par interaction, protégé par une clé aléatoire de 256 bits.
- Ajout du runtime générique GLPI → système externe déclenché par les hooks `item_add` / `item_update` pour Project, Ticket et les principaux assets/utilisateurs.
- Les interactions sortantes décrivent leurs requêtes create/update (POST/PUT/PATCH/DELETE) de manière déclarative ; le runtime reste indépendant de Jira/Intune/etc.
- Ajout d'un accès direct à l'historique depuis la fiche Connection.
- Les interactions standard sortantes exigent une entité GLPI fixe comme périmètre source ; l'entité active est proposée par défaut.

### Réconciliation et supervision

- `ObjectLink` est partagé à l'échelle de la Connection par `external_object_type` + `external_id` afin qu'une interaction inbound et une interaction outbound puissent réutiliser la même équivalence.
- Compatibilité paresseuse des anciens liens `external_object_type=record`, avec conservation correcte du type sémantique demandé lors de leur prochaine sauvegarde.
- Les ObjectLinks survivent à la suppression d'une interaction et sont supprimés avec la Connection.
- La suppression d'une interaction purge son historique `SyncExecution`.
- Les événements sortants produisent désormais eux aussi un `SyncExecution`.
- Ajout d'une garde anti-boucle locale pour empêcher une écriture inbound d'activer immédiatement un outbound dans la même requête.

### Jira

- Templates : Projects → GLPI Projects, Issues → GLPI Tickets, Issue webhook → GLPI Ticket, GLPI Projects → Jira Projects et GLPI Tickets → Jira Issues.
- Le spécifique Jira reste exclusivement dans `resources/connectors/jira/connector.json`.

### Robustesse / droits

- La fiche Connection ne charge/affiche les interactions que si le profil possède le droit de lecture correspondant.
- Les clés webhook ne sont révélées qu'aux profils disposant du droit de modification des interactions.
- Validation serveur de la compatibilité méthode d'authentification / connecteur lors d'une association.
- Fallback ASCII `strtoupper` / `strtolower` pour les paramètres de templates lorsque `mbstring` n'est pas disponible.

## 2.7.0 — 2026-08-18

### Catalogue et templates

- Ajout du bounded context **Template** avec `ConnectorTemplate`, `JobTemplate` et `TemplateParameter`.
- Ajout du menu **Connector catalog**, devenu la page d'entrée UXConnector pour les profils disposant du droit de lecture des jobs.
- Les paramètres d'un `JobTemplate` sont résolus **une seule fois à l'instanciation** ; le `SyncJob` persistant est ensuite autonome et conserve `template_id` / `template_version` uniquement comme provenance.
- Manifests embarqués pour Jira Cloud, Microsoft Intune, Microsoft Graph, MDM générique REST, ServiceNow, Zabbix et REST générique.
- Jobs activables fournis pour Jira Projects, Jira Issues, Intune Managed Devices, Microsoft Graph Users et MDM REST générique. ServiceNow et Zabbix restent visibles mais leurs jobs sont explicitement non activables.

### Moteur générique

- Ajout de `CollectionPath` : la collection source n'est plus devinée à partir d'une liste de clés propres aux éditeurs.
- Ajout de `JsonPathValueReader` pour les chemins imbriqués, les indices de tableaux et les clés entre crochets.
- Ajout de `PaginationConfig` et de stratégies déclaratives `none`, `offset`, `cursor` et `next_link`.
- `ExecuteSyncJobService` ne contient plus aucune connaissance de Jira, Intune, Graph, ServiceNow ou Zabbix ; il exécute uniquement la configuration persistée sur le `SyncJob`.
- Normalisation sécurisée des liens absolus de pagination : origine et chemin de base de l'`AuthConfig` obligatoires avant réutilisation des identifiants.
- Les transformations spécifiques à un template sont fournies par extension du registre de transformations ; le moteur de mapping générique ne référence pas l'éditeur.

### Interface et persistance

- Le formulaire de job avancé expose explicitement `collection_path` et la stratégie de pagination.
- La liste des jobs affiche leur provenance Catalogue/Custom et la version du template d'origine.
- Migration additive de `glpi_plugin_uxconnector_syncjobs` : `collection_path`, `pagination_config`, `template_id`, `template_version` et index `template_id`.
- Le catalogue préremplit la création d'une configuration d'authentification avec les informations non secrètes du connecteur.

### Compatibilité

- Les jobs existants sont conservés. Pour éviter toute connaissance implicite d'un éditeur dans le runtime, les jobs 2.6.0 qui dépendaient de l'autodétection `issues`/`values` ou d'une pagination spécifique doivent être revus ou recréés depuis le catalogue.
- Le périmètre d'écriture vers les systèmes tiers reste hors Phase A : les jobs fournis sont INBOUND.

## 2.6.0 — 2026-08-18

### Ajouté

- Cibles GLPI **Project** et **Ticket** pour les synchronisations INBOUND.
- Lecture de champs source par **chemin JSON pointé** (`fields.summary`, `fields.status.name`, `$.fields.project.key`) et indices simples (`attachments[0].filename`).
- Sources constantes via `@literal:<valeur>` pour alimenter des champs GLPI fixes (ex. `@literal:1` → type Incident).
- Extraction des collections `values` et `issues`, en plus de `value/data/items/results`.
- Pagination automatique **cursor** (`nextPageToken` + `isLast`) et **offset** (`startAt/maxResults/total`), avec détection de boucle et limite de sécurité à 1000 pages.
- Transformations `datetime_glpi`, `integer`, `boolean_int`, `jira_adf_to_text` et mappings configurables `map:key=value|key=value|*=fallback`.
- Deux **presets Jira Cloud** dans le formulaire de job : Projets Jira → Projets GLPI et Issues Jira → Tickets GLPI.
- 10 lignes de mapping configurables dans l'UI et champ transform libre avec datalist.

### Sécurité / limites

- Les nouvelles cibles restent bornées par `TargetItemtype` et `TargetFieldPolicy`; aucune colonne GLPI arbitraire n'est inscriptible.
- Cette version reste **INBOUND uniquement**. Aucun POST/PUT/PATCH vers Jira n'est exécuté.
- Les projets et tickets sont synchronisés comme objets indépendants. GLPI lie nativement un Ticket à une **ProjectTask**, pas directement à un Project; la matérialisation automatique de cette relation est donc volontairement reportée à une évolution dédiée.


## 2.5.2 — 2026-08-18

- Authentication connection test now targets a configurable relative **Test path** instead of always calling `/`.
- Only HTTP **2xx** responses are considered successful authentication tests; 401/403/404/5xx now move the configuration to `ERROR` and cannot be activated.
- Added additive migration `glpi_plugin_uxconnector_authconfigs.test_path` (default `/`).
- Jira Cloud recommendation: Endpoint `https://api.atlassian.com/ex/jira/{cloudId}` with Test path `/rest/api/3/project`.
- Includes prior GLPI 11 hotfixes for CSRF handling, `CommonDBTM::find()` arrays, and AuthConfig deletion.

## 2.5.1 — GLPI 11 hotfix (2026-08-18)

- Added a permanent **Delete** action for authentication configurations.
- Deletion requires the GLPI `PURGE` right and is protected by the native GLPI 11 CSRF layer.
- Protected credentials are purged transactionally with their AuthConfig.
- Deletion is refused while a synchronization job still references the configuration, preventing orphaned jobs.
- Includes the earlier GLPI 11 CSRF and `CommonDBTM::find()` compatibility fixes.


Toutes les modifications notables de ce projet sont documentées dans ce
fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Non publié]

### Corrigé

- **GLPI 11 / CSRF** : suppression de la seconde validation CSRF dans les
  contrôleurs AuthConfig et SyncJob. Le Kernel GLPI 11 valide déjà le token
  des POST avant le chargement des points d'entrée legacy ; la seconde
  validation consommait à nouveau le même token et bloquait les créations.
- **GLPI 11 / CommonDBTM** : les repositories AuthConfig, SyncJob et
  SyncExecution utilisent directement le tableau retourné par
  `CommonDBTM::find()` au lieu de l'envoyer à `iterator_to_array()`.

Voir [2.5.1](#251) pour les derniers travaux publiés.

## [2.5.1]

Lot de durcissement GLPI 11 / sécurité / fiabilité issu de la revue complète
de l'archive 2.5.0.

### Corrigé

- **PSR-4** : `composer.json` utilise désormais exactement
  `GlpiPlugin\\Uxconnector\\`, comme toutes les classes et comme le namespace
  enregistré par GLPI pour la clé technique `uxconnector`.
- **Mappings GLPI** : ajout de `TargetFieldPolicy`, liste blanche explicite
  par itemtype et validation à la création **et avant chaque exécution**. Les
  colonnes techniques/sensibles (`id`, `entities_id`, `is_deleted`, tokens,
  mots de passe, etc.) ne peuvent plus être alimentées par un mapping.
- **ObjectLink** : le rapprochement stable est désormais borné par le
  `SyncJob`, l'`AuthConfig`, l'itemtype et l'identifiant externe ; deux flux
  utilisant le même connecteur ne peuvent plus se croiser sur le même
  `external_id`. Migration additive et idempotente de l'index existant. Un
  lien dont l'objet GLPI a été purgé est également supprimé avant une nouvelle
  tentative de rapprochement, au lieu de faire échouer le job indéfiniment.
- **Affectation d'entité** : le parseur échoue désormais explicitement sur une
  stratégie, une règle ou une politique de repli mal formée au lieu de
  retomber silencieusement sur « aucune stratégie ».
- **SSRF IPv6** : blocage des adresses IPv4 mappées en IPv6 même lorsqu'elles
  sont écrites sous forme hexadécimale (`::ffff:7f00:1`,
  `::ffff:a9fe:a9fe`).
- **Transport des secrets** : une méthode d'authentification utilisant un
  secret ne peut plus envoyer celui-ci sur HTTP en clair ; HTTPS est requis,
  y compris pour le endpoint OAuth2 Client Credentials.
- **HTTP** : rejet des identifiants intégrés à une URL de base, des query
  strings/fragments dans l'Endpoint de base et des valeurs d'en-tête contenant
  CR/LF.
- **Lifecycle** : l'uninstall conserve les données métier selon l'ADR existant
  mais retire désormais les droits `ProfileRight` afin de ne pas laisser de
  métadonnées GLPI orphelines.
- **GLPI 11 web** : suppression des inclusions manuelles `inc/includes.php`
  dans les quatre points d'entrée `front/`.
- **Marketplace** : retrait du descripteur ancien/incomplet et du lien GitHub
  inexistant ; ajout de `uxconnector.xml.dist` avec URL de release publique à
  renseigner avant publication.
- **Contrôleurs natifs expérimentaux** : retrait des deux POC 2.5.0 non
  auto-découverts. La migration native reste documentée comme dette au lieu de
  livrer du code mort.

### Qualité

- ajout de `glpi-project/coding-standard` et d'une commande `composer lint:glpi` ;
- nouveaux tests sur la politique de champs, les collisions ObjectLink, le
  parsing d'entité, les variantes IPv4-mapped IPv6, les URLs et les en-têtes
  HTTP.

### Limites restantes

- le pinning DNS entre validation SSRF et connexion effective n'est pas encore
  implémenté ;
- deux exécutions réellement concurrentes d'un même SyncJob ne disposent pas
  encore d'un verrou distribué ;
- la rétention automatique des historiques `SyncExecution` reste à définir ;
- la migration complète `front/` → contrôleurs/routes natives doit être testée
  sur une vraie instance GLPI 11 avant activation.

## [2.5.0]

Trois chantiers demandés explicitement : authentification OAuth2 Client
Credentials, écran de consultation de l'historique des synchronisations, et
première phase de migration vers les contrôleurs natifs GLPI 11.

### Ajouté

- **OAuth2 Client Credentials** (RFC 6749 §4.4) : `OAuth2TokenClient`
  (échange client_id/client_secret contre un jeton, protégé SSRF via
  `DestinationPolicyInterface`), `OAuth2ClientCredentialsAuthenticationStrategy`.
  `AuthMethod::OAUTH2_CLIENT_CREDENTIALS` est désormais proposé dans le
  formulaire de création. `OAUTH2_AUTHORIZATION_CODE` reste délibérément non
  implémenté (flux interactif incompatible avec une tâche planifiée
  headless — incompatibilité de nature, pas un manque temporaire). Aucun
  cache de jeton : un nouveau jeton est obtenu à chaque exécution
  (limitation documentée, pas un défaut).
- **Écran d'historique des `SyncExecution`** : `ListSyncExecutionsService`,
  nouvel écran accessible depuis un lien « Historique » sur chaque ligne de
  la liste des synchronisations — statut, durée, éléments traités/échoués,
  message d'erreur. Les données étaient déjà persistées ; elles ne sont
  aujourd'hui plus consultables uniquement en base.
- **Migration GLPI 11 native — phase 1 (lecture seule)** :
  `AuthConfigNativeController`, `SyncJobNativeController`, délégant
  strictement aux mêmes Services applicatifs déjà utilisés par les
  `front/*.php` existants. Non câblée dans `HookManager` — voir
  `docs/migration/glpi11-native-controllers.md` pour le statut détaillé,
  les hypothèses non vérifiées, et la procédure de validation recommandée.

### Note de transparence

Contrairement au reste de ce projet, systématiquement validé par exécution
réelle (smoke tests), la migration vers les contrôleurs natifs GLPI 11 n'a
**pas pu être prouvée par exécution** dans cet environnement de
développement, faute de runtime GLPI/Symfony disponible. Le code est écrit
et syntaxiquement valide, mais son comportement réel reste à confirmer sur
une instance GLPI 11 — voir `docs/migration/glpi11-native-controllers.md`.

## [2.4.0]

Gestion des entités GLPI — le dernier des quatre chantiers structurants
identifiés par les deux relectures externes successives (après le cycle
d'activation, la sécurité SSRF/rotation de clé, et `ObjectLink`).

### Ajouté

- **`EntityAssignmentStrategy`** (nouveau Value Object, bounded context
  Sync) : détermine l'entité GLPI (`entities_id`) à assigner à chaque
  enregistrement synchronisé. Deux stratégies :
  - **Fixe** : toujours la même entité pour tous les enregistrements du
    `SyncJob` ;
  - **Déduite** : lit un champ de la réponse brute du système tiers (ex.
    `location`) et le résout via une table `valeur source → entities_id`,
    avec une politique explicite (`UnknownEntityPolicy`) en cas de valeur
    non répertoriée — échouer l'enregistrement, ou utiliser une entité par
    défaut.
- **`SyncJob::entityAssignmentStrategy`** : nouveau champ de configuration,
  optionnel (absent = comportement GLPI par défaut, adapté à une instance
  mono-entité).
- **`GlpiItemWriter`** applique désormais `entities_id` lors de la
  création/mise à jour, et **scope également la recherche de collision par
  entité** quand elle est résolue — corrige directement l'exemple donné par
  la revue de code externe (deux `Computer` de même `serial`, un par
  entité) : la collision devient une résolution non ambiguë dès lors que
  l'entité cible est déterminée.
- Section « Affectation d'entité » dans le formulaire de création d'une
  synchronisation (stratégie Fixe/Déduite, règles de correspondance,
  politique de repli), avec `EntityAssignmentStrategyRequestParser` dédié
  et testable indépendamment de GLPI.

### Note de conception

Contrairement aux trois précédentes extensions de `SyncJob`
(`TargetItemtype`, `MatchField`, puis `ExternalIdField`), ce champ a été
ajouté comme **paramètre optionnel en fin de signature** plutôt qu'en
position médiane — aucun appel `SyncJob::create()` existant dans la base de
tests n'a donc eu besoin d'être modifié pour cette version.

## [2.3.0]

Réconciliation par identifiant externe stable (`ObjectLink`) — le sujet
structurant classé en tête de priorité par les deux relectures externes
successives, juste après le cycle d'activation.

### Ajouté

- **`ObjectLink`** (nouvel agrégat, bounded context Sync) : mémorise le
  rapprochement entre un enregistrement externe (`ExternalId`, scopé par
  `AuthConfig`) et un objet GLPI (`itemtype` + `item_id`). Table dédiée
  `glpi_plugin_uxconnector_objectlinks`.
- **`SyncJob::externalIdField`** (`ExternalIdField`) : nouveau champ de
  configuration nommant, dans la réponse brute du système tiers, celui qui
  porte l'identifiant stable de l'enregistrement (ex. `id`, `sys_id`) —
  distinct de `MatchField`, qui ne sert désormais plus qu'à la toute première
  tentative de rapprochement d'un `ExternalId` encore inconnu.
- **`GlpiItemWriter` réécrit** : la stratégie de rapprochement n'est plus une
  simple recherche `WHERE <matchField> = <valeur> LIMIT 1`. Elle consulte
  d'abord `ObjectLink` ; à défaut de lien connu, elle applique une logique
  CREATE (aucune correspondance) / LINK (une correspondance, adoption d'un
  enregistrement existant) / **COLLISION** (plusieurs correspondances — refus
  d'écrire plutôt que de deviner lequel modifier).
- Doc-block de `GlpiItemWriter` corrigé : retire l'affirmation inexacte selon
  laquelle `CommonDBTM` protégerait automatiquement les droits utilisateur, et
  documente explicitement le CronTask comme un acteur système à part entière.

### Sécurité

- **Corrige le risque de collision signalé par la revue de code externe** :
  auparavant, deux enregistrements GLPI partageant la même valeur de
  `MatchField` (ex. deux `Computer` de même numéro de série dans deux entités
  différentes) pouvaient voir le mauvais silencieusement modifié. Ce cas est
  désormais détecté et le rapprochement est refusé.

## [2.2.0]

Cycle d'activation applicatif — le sujet classé comme le plus urgent
fonctionnellement par les deux relectures externes successives : le Domaine
exposait un cycle de vie complet (`AuthConfig` : `DRAFT → TESTING → ACTIVE` ;
`SyncJob` : `DRAFT → ACTIVE → PAUSED`) qu'aucun code applicatif n'orchestrait.
Une configuration ou une synchronisation créée depuis l'interface restait
bloquée en `DRAFT` pour toujours.

### Ajouté

- **`TestAuthConfigService`** : effectue un appel HTTP réel vers l'endpoint
  configuré, fait transitionner l'`AuthConfig` vers `TESTING` avant l'appel
  (visible même si l'appel reste en attente), et vers `ERROR` en cas d'échec
  de transport ou de réponse 5xx. Une réponse 4xx est traitée comme un succès
  de connectivité (elle prouve que la requête a atteint le serveur avec les
  en-têtes d'authentification).
- **`ActivateAuthConfigService`**, **`DisableAuthConfigService`** : pilotent
  les transitions `TESTING → ACTIVE` et `* → DISABLED`.
- **`ActivateSyncJobService`**, **`PauseSyncJobService`** : pilotent les
  transitions `DRAFT/PAUSED → ACTIVE` et `ACTIVE → PAUSED`.
- **Exécution manuelle immédiate** (« Exécuter maintenant ») d'un `SyncJob`
  actif, réutilisant directement `ExecuteSyncJobService` (même cas d'usage que
  le `CronTask`, seul l'appelant diffère).
- Boutons d'action correspondants dans les écrans de liste
  (`AuthConfig` : Tester / Activer / Désactiver ; `SyncJob` : Activer / Pause
  / Exécuter maintenant), chacun protégé par CSRF et par le droit `UPDATE`.

## [2.1.0]

Lot **sécurité**, consécutif à une seconde relecture externe du lot 2.0.1. Les
deux sujets traités ici étaient explicitement classés comme préalables à toute
mise en production.

### Sécurité

- **Protection SSRF (`DestinationPolicy`)** : toute utilisation réelle d'un
  `Endpoint` — à la création d'un `AuthConfig` (`CreateAuthConfigService`) et à
  chaque exécution HTTP (`GenericHttpEngine`) — vérifie désormais que l'hôte,
  une fois résolu par DNS, ne pointe pas vers une adresse de boucle locale, une
  adresse lien-local (qui inclut les métadonnées d'instance cloud sur
  `169.254.169.254`), ou une autre plage à usage spécial jamais légitime pour
  ce plugin. Les réseaux privés RFC1918 (`10.x`, `172.16.x`, `192.168.x`) sont
  délibérément **autorisés** par défaut : ils correspondent à l'usage principal
  du plugin (Zabbix, MDM, API internes). Limite documentée : protection
  effective contre un hôte malveillant ou une redirection DNS vers une cible
  interdite ; ne couvre pas le cas résiduel d'un changement de résolution DNS
  survenant entre la vérification et la connexion effective (« DNS
  rebinding »).
- **Rotation de `GLPI_INSTANCE_KEY` (`CredentialVaultRekeyService`)** :
  service applicatif re-chiffrant chaque secret `AuthConfig` stocké de
  l'ancienne vers la nouvelle clé, isolant les échecs individuels (un secret
  déjà migré ou corrompu n'interrompt pas la rotation des autres). Exposé par
  un script opérationnel (`tools/rekey-credential-vault.php`) destiné à être
  exécuté manuellement par un administrateur avant toute modification de
  `GLPI_INSTANCE_KEY` — auparavant, une rotation de cette constante aurait
  rendu tous les secrets déjà stockés définitivement indéchiffrables.

## [2.0.1]

Lot de durcissement **P0**, consécutif à une revue de code externe menée sur
la version 2.0.0. Chaque correctif ci-dessous a été vérifié par exécution
réelle (smoke tests dédiés), pas seulement par relecture — voir le document
de synthèse projet, section 6, pour le détail des scénarios de preuve.

### Sécurité

- **CSRF** : jeton injecté automatiquement dans tout rendu de formulaire
  (`AbstractController::render()`) et validé en tête de chaque traitement de
  soumission (`AbstractController::checkCsrf()`), sur les deux formulaires de
  création (`AuthConfig`, `SyncJob`).
- **Redirections HTTP** : `GenericHttpEngine` ne suit plus automatiquement les
  redirections (`follow_location => 0`) — élimine le risque de fuite des
  en-têtes d'authentification (`Authorization`, `X-Api-Key`) vers un hôte de
  destination non maîtrisé.
- **Taille de réponse HTTP bornée** : lecture du corps de réponse par blocs
  (10 Mio maximum) plutôt que chargement intégral en mémoire
  (`file_get_contents()` → `fopen()`/`fread()`).

### Corrigé

- **`AuthMethod::isImplemented()`** : les méthodes d'authentification OAuth2
  (non exécutables par `GenericHttpEngine`) ne sont plus proposées dans le
  formulaire de création d'`AuthConfig`, et sont désormais rejetées côté
  serveur (`AuthMethodNotSupportedException`) si soumises malgré tout.
- **`SyncDirection::isImplemented()`** : les directions `OUTBOUND` et
  `BIDIRECTIONAL`, jusqu'ici sélectionnables mais exécutées silencieusement
  comme `INBOUND` par `ExecuteSyncJobService`, sont désormais filtrées côté
  formulaire et rejetées à la fois à la création
  (`CreateSyncJobService`) et à l'exécution
  (`ExecuteSyncJobService`, défense en profondeur) via
  `SyncDirectionNotSupportedException`.
- **Isolation des erreurs par élément** : dans
  `ExecuteSyncJobService::applyFieldMappings()`, une exception levée par la
  transformation ou l'écriture d'un seul élément (ex. une date non parsable)
  n'interrompt plus le traitement du lot entier et ne fait plus perdre le
  décompte des éléments déjà écrits avec succès (auparavant : `FAILED` /
  `itemsProcessed = 0` malgré des écritures GLPI réellement effectuées).
- **Test d'intégration `SyncJobCronTaskTest`** : scindé en un scénario isolé
  (n'exécute que le `SyncJob` du test, via `ExecuteSyncJobService`
  directement) et un scénario couvrant le comportement réel de
  `SyncJobCronTask::cronSyncJob()` (qui exécute *tous* les `SyncJob` actifs de
  la base), ce dernier verrouillé derrière la variable d'environnement
  `UXCONNECTOR_ALLOW_DESTRUCTIVE_CRON_TEST=1` — ne doit jamais être activé sur
  une instance partagée ou de production.
- **Fixture `AuthConfig` du test cron** : ne portait pas la configuration
  d'authentification à l'état `ACTIVE` avant utilisation
  (`markTesting()`/`activate()` manquants), ce qui aurait fait échouer le
  test avec `SyncJobAuthConfigUnavailableException` au lieu de valider le
  scénario nominal.
- **Nettoyage incomplet du test cron** : les `SyncExecution` produites par le
  test n'étaient pas purgées en `tearDown()` (une base de CI persistante
  aurait vu cette table grossir indéfiniment). `SyncExecutionRepositoryInterface`
  gagne une méthode `delete()` à cet effet.

## [2.0.0]

Version initiale du moteur de connecteurs générique, suite au pivot d'ADR-002
(abandon des connecteurs codés en dur au profit d'un moteur HTTP générique et
configurable).

### Ajouté

- Squelette du plugin conforme à l'arborescence officielle UXConnector
  (RULE-J.001 à RULE-J.005) : `composer.json`, `setup.php`, `src/Setup.php`,
  structure `src/{Domain,Application,Infrastructure,Presentation}` découpée
  par bounded contexts `Engine` et `Sync`.
- **Bounded context Engine** : agrégat `AuthConfig` (cycle de vie
  `DRAFT → TESTING → ACTIVE/ERROR → DISABLED`), `CredentialVault`
  (AES-256-CBC + HMAC-SHA256, clés dérivées de `GLPI_INSTANCE_KEY`),
  `GenericHttpEngine` (client HTTP générique, stratégies d'authentification
  `None`/`Basic`/`ApiKey`/`BearerToken`).
- **Bounded context Sync** : agrégats `SyncJob` (configuration de
  synchronisation) et `SyncExecution` (historique d'exécution, agrégat
  séparé), `GlpiItemWriter` (upsert vers un itemtype GLPI parmi une liste
  fermée — `TargetItemtype`), `SyncJobCronTask` (exécution planifiée horaire
  de tous les `SyncJob` actifs, isolation des erreurs entre jobs).
- **Presentation** : écrans de liste et de création pour `AuthConfig` et
  `SyncJob`, menu de navigation GLPI (`inc/menu.class.php`), droits par
  profil (`inc/profile.class.php`).
- **Tests** : couverture unitaire complète du Domaine et de l'Application
  (aucune dépendance GLPI requise), tests d'intégration écrits pour la
  persistance, l'installation et le CronTask (nécessitent une instance GLPI
  réelle pour être exécutés).
- Documentation projet (`README.md`, `CHANGELOG.md`, `LICENSE`), métadonnées
  Marketplace initiales (`plugin.xml`, `marketplace/metadata.json`).

### Connu comme non couvert par cette version

Voir le document de synthèse projet, section 3, pour la liste exhaustive
(cycle d'activation non piloté par l'Application, réconciliation par
identifiant externe stable, gestion des entités GLPI, restriction des champs
cibles, OAuth2, etc.).
