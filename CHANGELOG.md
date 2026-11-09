## 2.18.37 candidate - 2026-11-06

- Add EasyVista Service Manager with five inbound pilots and one disabled outbound definition.
- See docs/EasyVista_Service_Manager.md for API scope and acceptance checks.

## 2.18.36 candidate - 2026-11-06

- Bundle Matrix42 with two inbound pilots and two disabled outbound definitions.
- Add two JSM Cloud inbound pilots scoped to a portal and request type.
- Retain the existing Jira Cloud catalogue and all other connector definitions from the generic base.
- Include the 32 Jira and JSM design specifications in the documentation.
- See docs/releases/RELEASE_NOTES_2.18.36.md for test scope and publication metadata.

## 2.18.31 candidate - 2026-11-02

- Base: the exact 2.18.29 archive supplied by INDIGO; retain its existing worker and Location fixes, plus exact target-label aliases.
- Protect consolidated target data: fill empty fields only across native writes, Fields, financial data and ticket relations; preserve root entity, nonzero references, numeric zero and false.
- Microsoft: one native SoftwareLicense per source assignment, with the observed 21-field block; stable connection/source identity, normalized dates/prices and safe recovery after a partial Fields failure. Refuse silent conversion of legacy pool assignments.
- Network: reuse trusted ObjectLinks, preserve manually changed sites/models/types and financial values, enrich the observed 16 Fields attributes and expose safe failure stages.
- Fields: preflight generated classes, tables and columns; resolve custom dropdowns by target labels and refuse ambiguity. Do not regenerate or delete Fields schemas.
- Per-job cadence and optional first start (UTC) in the job editor; scheduled queue admission rechecks due dates under its mutex. Failed/interrupted jobs do not automatically restart by default.
- Zendesk: downloads disabled by default, optional explicit reactivation; textual private/public followups retained; preserve categories, entity and populated actor roles. Bound Search Export HTTP 422 cursor restart to one per run without changing the filter.
- Email: explicit mapping fills missing email/default placeholders, preserving existing addresses and consolidated user identity.
- See docs/releases/RELEASE_NOTES_2.18.31.md and tools/preflight-2.18.31.sh. Offline simulations are not a live GLPI/Fields/MySQL integration certification.

## 2.18.30 - 2026-10-28

- Accept seven exact INDIGO Inpark target labels for NetworkEquipment, without renaming Fields metadata.
- Share label resolution between preflight and writes; preserve active/type/read-only gates and reject ambiguous or duplicate target addresses.
- Add 26 offline regression checks reproducing the 2.18.29 error and verifying native write/replay behavior.
- See docs/releases/RELEASE_NOTES_2.18.30.md.

## 2.18.29 - 2026-10-28

- Consolidate Location option and Zendesk attachment fixes from 2.18.27/28.
- Queue scheduled jobs and add an independent CLI worker, job/stream ownership and bounded shared capacity.
- Preserve live worker ownership during watchdog recovery and serialize targeted retries with active jobs.
- Add explicit native user email mapping with validation, alias preservation and short transactions.
- See docs/releases/RELEASE_NOTES_2.18.29.md for installation, worker scheduling and preproduction acceptance.

## 2.18.28 - 2026-10-27

- Fix own Location name versus parent Location search option ambiguity in Network and Microsoft profiles.
- Request raw search option metadata and use relation fields rather than fixed option IDs.
- Preserve true ambiguity rejection and exhaustive Fields fail-fast checks.
- Clarify that ambiguous option numbers are API search field identifiers.
- Add 29 offline regression checks.

## 2.18.27 - 2026-10-26
- Reconcile documents reused by native GLPI content deduplication.
- Keep authentication for same-origin attachment redirect chains; never send API credentials to CDN destinations.
- Preserve valid files when another download in the comment fails.
- Reject HTML login responses; give opaque inline image URLs a usable filename.
- Use parent ticket entity for followup uploads.
- Add 45 attachment checks using the unmodified GLPI 11.0.11 addFiles method with simulated persistence/network.

## 2.18.23 - Zendesk Sujet explicit field selector (candidate)

- Job-scoped `pagination.options.zendesk_topic_field_id` avoids a potentially ambiguous automatic "Sujet" field discovery.
- The selected Zendesk field is verified with `GET /api/v2/ticket_fields/{id}.json` and must be a tagger.
- Options are retrieved from `GET /api/v2/ticket_fields/{id}/options?page[size]=100`, including pagination, and do not require `topic__` tags when the field is explicitly selected.
- Invalid field selection, empty options, invalid labels, duplicate conflicting tags and pagination errors remain blocking.
- No schema migration; no change to GLPI-to-GLPI profiles, association logic, or ObjectLink identity rules.
- Added offline regression tests and `tools/preflight-2.18.23.sh`.

## 2.18.22 - GLPI 11 Datawise Fields option recognition (candidate)

- Fix block-prefixed Location Fields labels and dedicated dropdown-table options.
- Real read-only Datawise search metadata replay: 35/35 matched; false-positive/duplicate guards.
- Strict source and target Fields fail-fast retained; Zendesk/INDIGO profiles unchanged.
- Offline-candidate only; live import and TLS trust need qualification.

## 2.18.21 - Safe GLPI REST v1 Fields diagnosis (candidate)

- Read-only administrator diagnostic of authenticated Location search options, mappings and ambiguities.
- More robust schema-aware detection across GLPI 10 / GLPI 11 Fields metadata, without silent fallback on unrelated native fields.
- Strict preflight retained, security checks and Zendesk/INDIGO job profiles preserved.

## 2.18.20 - Independent GLPI 10 legacy migration profiles (candidate)

- Three independent GLPI REST v1 jobs: Telecom (unchanged), Network and Microsoft Licences.
- Shared isolated GLPI 10 Location Fields search-option discovery with technical names,
  ambiguity rejection and source preflight. Distinct credentials, checkpoints and ObjectLinks.
- Network pseudo-locations become NetworkEquipment; site data goes to Location,
  standard asset attributes to core fields, finance to equipment Infocom and residual
  métier values to the required target Fields blocks.
- Microsoft product Software/SoftwareLicense catalogue and individualized assignments
  preserved in plugin-owned migration storage (not Location). Acquired quantity is
  explicitly unknown and never inferred from individual assignment counts.
- Read-only privileged Microsoft assignment list in the UXConnector menu.
- Adds an idempotent plugin-owned SQL table during GLPI plugin upgrade.
- Maintains Zendesk workflows, Telecom connector and source/target Fields fail-fast.
- Candidate: real GLPI 10/11 integration, full PHPUnit/GLPI editor review pending.

## 2.18.19 - GLPI 10 Fields source option discovery (candidate)

- Resolve the historical GLPI 10 telecom fields by technical `xml_node` identifiers
  when those identifiers are exposed by `/listSearchOptions/Location`, with
  scoped exact-label fallback for options of the Fields plugin.
- Never copy YAML field IDs as GLPI search option IDs; the latter are discovered
  dynamically from the live API on each source connection.
- Reject ambiguous field matches before writing GLPI 11 data, rather than
  choosing the first identically named option. Keep the source and target
  fail-fast prerequisites and per-record diagnostics.
- Legacy GLPI 10 telecom label variants supported: Utilisateur, Equipements,
  N° de série, Tarif équipements, Abonnements, Date de Création, Code RDS,
  Date de Résiliation, etc. No source credentials/configuration exports included.
- No change to Zendesk category or ticket jobs, SIM processing, material
  associations, finance write path, or Microsoft license transformation.
- `Fields` dropdown source values must resolve to their DISPLAY LABELS, not
  foreign numeric option IDs; verify this in GLPI 10 integration before use.
- Unit regression tests and a standalone preflight included; actual GLPI 10
  API and GLPI 11 target reconciliation remain unverified in this package.

## 2.18.18 - INDIGO GLPI 10 source diagnostics (candidate)

- **New source-side fail-fast**: the INDIGO telecom job checks the GLPI 10
  `/listSearchOptions/Location` mapping before any GLPI 11 write and lists
  expected source columns which cannot be resolved. Keeps the existing
  target Fields fail-fast for Location and Line unchanged.
- **No silent API omissions**: missing keys in a GLPI 10 search response
  generate a per-record `SOURCE_OPTION_NOT_RETURNED` warning, with only
  allowlisted column labels and source ID; empty or null values explicitly
  present in the response are not treated as missing columns.
- Removed overbroad substring-based source label matching to reduce the
  risk of choosing an unrelated search option.
- Extended strict financial date parsing to common GLPI 10 datetime and
  European date formats. Rejects impossible dates and malformed monetary
  values rather than silently transforming them.
- Preserves Zendesk catalog and delta workflows, SIM creation, GLPI material
  associations, `Infocom` exclusively on the material, `Line.caller_name` as
  text (no user reconciliation), ObjectLinks and idempotence.
- No database migration, schema change or new dependency.
- This is a **diagnostic candidate**, not evidence that empty source data
  or all missing target relations are fixed. Test on GLPI 11 staging.

## 2.18.15 (2026-10-15)

## 2.18.17 - GLPI 10 Télécoms : SIM, rattachement matériel, gestion financière et appelant

- Corrige la préparation du référentiel SIM (DeviceSimcard non nul) et renforce l'association Item_DeviceSimcard/Line.
- Isole les sous-traitements équipement / association / Infocom / SIM ; incidents détaillés avec codes stables sans secrets.
- Préserve la correspondance texte vers Line.caller_name, sans rapprochement utilisateurs GLPI.
- Conserve strictement le contrôle préalable Fields et le comportement Zendesk 2.18.15.
- Tests unitaires simulés complémentaires, préflight et note de recette.

- Replace Zendesk category ticket-scan bootstrap with direct paginated Sujet field-option catalog import, including upgrades of persisted jobs.
- Preserve `topic__` identity via ObjectLink while translating Zendesk `::` labels to GLPI ITILCategory ancestry (`>` display).
- Keep initial non-closed ticket import and incremental delta behavior; add unique-known-tag fallback for ticket records missing custom_fields.
- Keep category hierarchy synchronized on renamed/reparented subjects.
- Add isolated unit and no-dependency preflight checks.

## 2.18.14 - Candidate (2026-10-14)
- Advanced REST inbound interactions can now target `Item_DeviceSimcard` with native SIM fields and explicit associations.
- Active Fields labels appear as `fields:<label>` in allowed targets, with validation and existing generated container writer reuse.
- `link:<external_object_type>` transform resolves foreign relations through scoped ObjectLink rather than copying source primary keys.
- SIM entity/reference checks; sensitive SIM payloads withheld from error snapshots. Additional unit and standalone preflight checks.
- Requires functional GLPI 11 / Fields / API regression testing before publisher submission.

## 2.18.12 - 2026-10-09

- Advanced Custom REST: expose explicit GLPI targets `Lieu`, `Ligne` and `Licence` in the curated allow-list.
- `Licence` maps to the native GLPI `SoftwareLicense` itemtype (`glpi_softwarelicenses`) and is handled by the generic CommonDBTM writer.
- Add conservative match fields (`serial`, `otherserial`, `name`) and writable-field allow-list for software licenses.
- Preserve the security model: no arbitrary table/class names and no raw-SQL target selection.
- Add a regression self-test for the three management targets.

## 2.18.11 - 2026-10-08

- Verrouille le périmètre du job INDIGO Lignes téléphoniques sur la matrice validée.
- Documente le mapping exact Location / Line / Fields / équipements / SIM / Infocom.
- Confirme que le champ source Utilisateur alimente `caller_name` uniquement et ne crée pas d’utilisateur GLPI.
- Ajoute un self-test de non-régression sur les 2 champs Fields Lieu, 14 champs Fields Ligne et les relations natives.

## 2.18.10 - Test build

- Ajoute le job `INDIGO - Lieux Télécom GLPI 10 → Lieux / Lignes GLPI 11` au connecteur GLPI REST v1.
- Découverte automatique des search options `Location` du GLPI 10, y compris les champs du plugin Fields.
- Pagination native GLPI REST v1 par `range=start-end`.
- Normalisation des pseudo-lignes stockées dans `Location` vers `Location` + `Line`.
- Alimentation des 2 champs supplémentaires Lieu et des 14 champs supplémentaires Ligne via les classes générées du plugin Fields, sans altérer les tables cœur.
- Réconciliation par code/nom de site, numéro appelant de ligne et ObjectLink source, avec réparation du lien si la cible a été supprimée/recréée.
- Normalise les équipements (`OTD*` → `NetworkEquipment`, autres libellés → `Phone`), les rattache aux lignes via `Item_Line`, alimente `Infocom` (date de remise / valeur) et les SIM (`serial`, `PUK`, ligne et équipement).
- Préflight Fields avant toute écriture : le job refuse de démarrer si les 2 champs Lieu ou les 14 champs Ligne attendus ne sont pas configurés.

## 2.18.9 - Test build

- Fixes **Retry failed items** for corrected Zendesk source data: retries now re-fetch the current object by immutable Zendesk ID before replaying it instead of processing the historical failure snapshot.
- Keeps `source_snapshot` immutable as audit evidence; a supported live refresh failure never silently falls back to stale data.
- Adds an application retry-source refresh port and a Zendesk infrastructure adapter with a closed allow-list of single-object API paths (users, groups, organizations, tickets, ticket forms).
- Preserves snapshot-based retry as backward-compatible fallback only for connector/object types without a dedicated live refresh strategy.
- Allows a retry to be queued when a source ID exists even if no snapshot is stored, enabling live-source recovery.
- Improves `REQUIRED_MAPPING_MISSING` diagnostics by naming the missing source field (for example `email`).
- Keeps full replay, idempotence/ObjectLinks, category counters, ExecutionQueue and ExecutionWatchdog behavior unchanged.

## 2.18.8 - Test build

- Fixes execution counters for the specialized Zendesk topics -> GLPI ITIL categories batch processor.
- Specialized batch processors now return the shared detailed `BatchProcessResult`, preserving Created / Updated / Skipped / Failed counters end-to-end.
- Category reconciliation now exposes a typed create/update/skip outcome while retaining the existing `ensureForTopic()` compatibility API used by ticket enrichment.
- Adds a non-regression unit test covering create, update, skip, failure, duplicate-topic de-duplication and closed-ticket exclusion.
- Keeps the 2.18.7 full-replay UI/cache-bypass and 2.18.5 watchdog/resume behavior unchanged.

## 2.18.7 - Test build

- Fixes execution-history action discoverability when GLPI production Twig cache still serves a previously compiled template.
- Uses a versioned execution-history template (`history_2_18_7.html.twig`) so the controller cannot resolve the stale compiled 2.18.5/2.18.6 template.
- Keeps the same CSRF, UPDATE-right, atomic full-replay and idempotence controls.

## 2.18.5

## 2.18.6 - Test build

- Exposes **Replay from start** directly in the execution-history header, where operators already monitor and resume executions.
- Keeps CSRF and UPDATE-right checks on the replay POST action.
- Reuses the existing atomic QueueFullReplayService: source checkpoint reset only, ObjectLinks preserved, background PENDING execution.
- Version bump forces GLPI to run the plugin update cycle and refresh cached presentation resources.


### Fixed
- Added independent `ExecutionWatchdog` CronTask to recover abandoned RUNNING executions after stale heartbeat detection.
- Releases a stale GLPI `ExecutionQueue` RUNNING lease only after an abandoned UXConnector execution has been positively recovered.
- `ExecutionQueue` now processes at most one queued execution per invocation.
- Heartbeat is persisted before each potentially slow remote page.
- Full replay is now an atomic Application use case and is exposed as **Replay from start** in the UI.
- Removed inward-layer dependency violations: Application/Presentation no longer import Infrastructure and controller factories moved to `Bootstrap`.

### Security / quality
- Preserves GLPI CSRF protection, TLS verification, credential vault isolation, secret redaction and short-lived DB mutexes.
- Added architecture dependency gate and unit tests for stale recovery, heartbeat and atomic replay.

## 2.18.4 - 2026-10-06

### 2.18.4 recipe corrections (test build 2)

- Fixed the Connection screen `Run now` path: it now queues work through `QueueSyncJobService` instead of executing the full synchronization inside the user HTTP request.
- Added an InnoDB row mutex around queue/resume critical sections so rapid double-clicks cannot enqueue concurrent executions for the same interaction.
- Added UI double-submit guards for manual run/full replay actions.
- Added explicit GLPI REST v1 App-Token and User-Token fields to the main Connection form and wired them to the existing protected credential composer.
- Added `Full replay`: resets only the persisted source checkpoint, preserves ObjectLinks/idempotence metadata, then queues a background execution.
- Reduced abandoned-execution recovery default from 30 to 10 minutes (configurable with `UXCONNECTOR_STALE_EXECUTION_MINUTES`, 2..1440).
- Hardened rollback paths so a lost MariaDB connection cannot mask the original worker error; stale `RUNNING` executions are reconciled after DB recovery.
- No database schema change is required by this recipe correction.


### Execution resilience / GLPI REST v1

- Manual `Run now` actions are now queued and return immediately; the `ExecutionQueue` CronTask processes manual work asynchronously without changing the cadence of scheduled jobs.
- Added execution progress/checkpoint metadata, resumable `INTERRUPTED` executions, duplicate-launch prevention, and detailed Created/Updated/Skipped/Warnings/Failed counters.
- Added per-item failure persistence, CSV export, targeted failed-item replay queue, error categories, retry metadata, and `SUCCESS_WITH_WARNINGS` for non-fatal post-processing failures such as attachments.
- Added connector-agnostic HTTP resilience: retry budget, exponential backoff with jitter, `Retry-After`, 408/429/500/502/503/504 handling, one authentication renewal, and HTTP/2 stream-error fallback to HTTP/1.1 when cURL is available.
- Added native `glpi_rest_v1` connector support for GLPI 9/10 using App-Token + User-Token, `/initSession`, memory-only Session-Token renewal, and `/killSession`.
- Added secret redaction before failed-record snapshots are persisted; TLS verification remains mandatory.
- Added domain-level RetryPolicy/ErrorClassification rules and unit regressions for retry classification, HTTP/2 stream fallback detection, resumable executions, warning status, and secret redaction.
- Extended the README architecture statement without claiming historical strict TDD.

## 2.18.3 - 2026-10-05

### Zendesk offline pre-release validation

- Added a deterministic `ZendeskOffline` PHPUnit suite that exercises the real `ZendeskTicketPostProcessor` without a live Zendesk tenant.
- Added representative Zendesk fixtures for ticket topic/category, requester, assignee, group, organization, public/private comments, ticket attachment and inline followup image.
- Validates category hierarchy creation and assignment to the ticket.
- Validates requester/assignee/group reconstruction.
- Validates comments as GLPI followups while avoiding duplication of the initial ticket description.
- Validates attachment/document reconciliation and conversion of inline Zendesk images to local GLPI document references.
- Validates replay idempotence: no duplicate categories, actors, followups, documents or reconciliation links.
- Added a targeted regression test proving that a missing Zendesk organization link no longer blocks category, comment or attachment import; the record remains retryable.
- Synchronized all plugin version metadata to 2.18.3.
- Keeps the 2.18.1/2.18.2 security hardening and Zendesk post-processing fixes unchanged.

## 2.18.1 - 2026-09-17

### Regression fixes / Zendesk migration hardening

- Fixed a PHP 8.5 null-safety defect in generic inbound jobs: jobs without a template id no longer fail in the GLPI asset skip/post-processing checks. This addresses the nine `ExecuteSyncJobServiceTest` failures observed in Docker.
- Decoupled Zendesk ticket enrichments: a missing organization/user/group reconciliation link no longer prevents category assignment, comments or attachments from being processed.
- Split Zendesk entity and ITIL-category updates so one failed relation cannot suppress the other. Failed relations still mark the record partial/failed, preserving replay/checkpoint safety and allowing a later run to heal the missing link idempotently.
- Improved PHPUnit integration bootstrap and skip guards so GLPI-dependent tests fail/skip cleanly when `GLPI_ROOT` is not provided.
- Added empty Functional/EndToEnd suite directories so the full `phpunit -c phpunit.xml.dist` command is structurally valid.
- Retains all 2.18.0 security hardening (webhook HMAC option, query-key disabled by default, host allowlist option, built-in Zendesk target locking, 50,000-record safety ceilings).

## 2.18.0 - 2026-09-17

### Security hardening

- Hardened inbound webhook authentication with optional HMAC-SHA256 signatures and timestamp freshness validation.
- Disabled webhook keys in query strings by default; temporary legacy opt-in remains available for migration.
- Added optional outbound host allowlisting on top of the existing SSRF protections.
- Locked built-in Zendesk template ids to their intended GLPI target types, preventing trusted template/post-processor reuse against another object type while preserving User/Group/Entity/ITILCategory/Ticket imports.
- Added configurable 50,000-record safety ceilings for scheduled inbound runs and webhook batches without reducing Zendesk 20,000-ticket migration capacity.
- Fixed the Zendesk synthetic volume harness so it carries the current Zendesk ticket template id.
- Preserved existing Zendesk population of users, groups, entities, ITIL categories, tickets, follow-ups/comments and documents/attachments.

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

## 2.8.4 - 2026-08-19

- Fix standard interaction creation on an already active connection: the `Activate immediately` checkbox no longer reuses the connection-level `activate` POST action name.
- Dispatch `instantiate` before AuthConfig lifecycle actions as a defensive routing rule.
- No database schema change.

## 2.8.3 - 2026-08-19

- Ajoute des accès directs **Edit** et **Interactions** depuis la liste des connexions.
- Ajoute un raccourci **Interactions** dans l'en-tête de la fiche connexion.
- Ajoute une ancre `#interactions` afin d'ouvrir directement la zone des interactions standards.
- Expose le droit de lecture des jobs à la vue liste sans contourner le modèle de droits GLPI.

## 2.8.2 - 2026-08-19

### Correctif catalogue / types de connecteurs

- Corrige le chemin racine utilisé par `TemplateServiceProvider` pour charger `resources/connectors/*/connector.json`.
- En 2.8.1, `dirname(__DIR__, 2)` pointait vers `src/` et le dépôt cherchait à tort `src/resources/connectors`, ce qui renvoyait zéro connecteur standard.
- Le sélecteur de type de connexion affiche désormais Jira Cloud, Microsoft Intune, Microsoft Graph, MDM générique, ServiceNow, Zabbix et REST générique.
- Le catalogue et la fiche Connection partagent de nouveau la même source de templates embarqués.
- Aucun changement de schéma SQL.

## 2.8.1 - 2026-08-19

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

## 2.8.0 - 2026-08-18

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

## 2.7.0 - 2026-08-18

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

## 2.6.0 - 2026-08-18

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


## 2.5.2 - 2026-08-18

- Authentication connection test now targets a configurable relative **Test path** instead of always calling `/`.
- Only HTTP **2xx** responses are considered successful authentication tests; 401/403/404/5xx now move the configuration to `ERROR` and cannot be activated.
- Added additive migration `glpi_plugin_uxconnector_authconfigs.test_path` (default `/`).
- Jira Cloud recommendation: Endpoint `https://api.atlassian.com/ex/jira/{cloudId}` with Test path `/rest/api/3/project`.
- Includes prior GLPI 11 hotfixes for CSRF handling, `CommonDBTM::find()` arrays, and AuthConfig deletion.

## 2.5.1 - GLPI 11 hotfix (2026-08-18)

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
  headless - incompatibilité de nature, pas un manque temporaire). Aucun
  cache de jeton : un nouveau jeton est obtenu à chaque exécution
  (limitation documentée, pas un défaut).
- **Écran d'historique des `SyncExecution`** : `ListSyncExecutionsService`,
  nouvel écran accessible depuis un lien « Historique » sur chaque ligne de
  la liste des synchronisations - statut, durée, éléments traités/échoués,
  message d'erreur. Les données étaient déjà persistées ; elles ne sont
  aujourd'hui plus consultables uniquement en base.
- **Migration GLPI 11 native - phase 1 (lecture seule)** :
  `AuthConfigNativeController`, `SyncJobNativeController`, délégant
  strictement aux mêmes Services applicatifs déjà utilisés par les
  `front/*.php` existants. Non câblée dans `HookManager` - voir
  `docs/migration/glpi11-native-controllers.md` pour le statut détaillé,
  les hypothèses non vérifiées, et la procédure de validation recommandée.

### Note de transparence

Contrairement au reste de ce projet, systématiquement validé par exécution
réelle (smoke tests), la migration vers les contrôleurs natifs GLPI 11 n'a
**pas pu être prouvée par exécution** dans cet environnement de
développement, faute de runtime GLPI/Symfony disponible. Le code est écrit
et syntaxiquement valide, mais son comportement réel reste à confirmer sur
une instance GLPI 11 - voir `docs/migration/glpi11-native-controllers.md`.

## [2.4.0]

Gestion des entités GLPI - le dernier des quatre chantiers structurants
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
    non répertoriée - échouer l'enregistrement, ou utiliser une entité par
    défaut.
- **`SyncJob::entityAssignmentStrategy`** : nouveau champ de configuration,
  optionnel (absent = comportement GLPI par défaut, adapté à une instance
  mono-entité).
- **`GlpiItemWriter`** applique désormais `entities_id` lors de la
  création/mise à jour, et **scope également la recherche de collision par
  entité** quand elle est résolue - corrige directement l'exemple donné par
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
position médiane - aucun appel `SyncJob::create()` existant dans la base de
tests n'a donc eu besoin d'être modifié pour cette version.

## [2.3.0]

Réconciliation par identifiant externe stable (`ObjectLink`) - le sujet
structurant classé en tête de priorité par les deux relectures externes
successives, juste après le cycle d'activation.

### Ajouté

- **`ObjectLink`** (nouvel agrégat, bounded context Sync) : mémorise le
  rapprochement entre un enregistrement externe (`ExternalId`, scopé par
  `AuthConfig`) et un objet GLPI (`itemtype` + `item_id`). Table dédiée
  `glpi_plugin_uxconnector_objectlinks`.
- **`SyncJob::externalIdField`** (`ExternalIdField`) : nouveau champ de
  configuration nommant, dans la réponse brute du système tiers, celui qui
  porte l'identifiant stable de l'enregistrement (ex. `id`, `sys_id`) -
  distinct de `MatchField`, qui ne sert désormais plus qu'à la toute première
  tentative de rapprochement d'un `ExternalId` encore inconnu.
- **`GlpiItemWriter` réécrit** : la stratégie de rapprochement n'est plus une
  simple recherche `WHERE <matchField> = <valeur> LIMIT 1`. Elle consulte
  d'abord `ObjectLink` ; à défaut de lien connu, elle applique une logique
  CREATE (aucune correspondance) / LINK (une correspondance, adoption d'un
  enregistrement existant) / **COLLISION** (plusieurs correspondances - refus
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

Cycle d'activation applicatif - le sujet classé comme le plus urgent
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
  `Endpoint` - à la création d'un `AuthConfig` (`CreateAuthConfigService`) et à
  chaque exécution HTTP (`GenericHttpEngine`) - vérifie désormais que l'hôte,
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
  `GLPI_INSTANCE_KEY` - auparavant, une rotation de cette constante aurait
  rendu tous les secrets déjà stockés définitivement indéchiffrables.

## [2.0.1]

Lot de durcissement **P0**, consécutif à une revue de code externe menée sur
la version 2.0.0. Chaque correctif ci-dessous a été vérifié par exécution
réelle (smoke tests dédiés), pas seulement par relecture - voir le document
de synthèse projet, section 6, pour le détail des scénarios de preuve.

### Sécurité

- **CSRF** : jeton injecté automatiquement dans tout rendu de formulaire
  (`AbstractController::render()`) et validé en tête de chaque traitement de
  soumission (`AbstractController::checkCsrf()`), sur les deux formulaires de
  création (`AuthConfig`, `SyncJob`).
- **Redirections HTTP** : `GenericHttpEngine` ne suit plus automatiquement les
  redirections (`follow_location => 0`) - élimine le risque de fuite des
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
  `UXCONNECTOR_ALLOW_DESTRUCTIVE_CRON_TEST=1` - ne doit jamais être activé sur
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
  fermée - `TargetItemtype`), `SyncJobCronTask` (exécution planifiée horaire
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
