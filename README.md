# UXConnector

UXConnector est un plugin **GLPI 11** fournissant un moteur gÃ©nÃ©rique de connexions et de synchronisations entre GLPI et des systÃ¨mes tiers.

Le principe est de configurer une **Connection** une seule fois (endpoint, mÃ©thode d'authentification, secret protÃ©gÃ©), puis d'y associer une ou plusieurs **Interactions** entrantes ou sortantes : polling, webhook ou Ã©vÃ©nement GLPI.

Le runtime reste gÃ©nÃ©rique : les particularitÃ©s d'un Ã©diteur ou d'une API sont dÃ©crites principalement dans les manifests du catalogue `resources/connectors/*/connector.json` et dans des post-processors spÃ©cialisÃ©s lorsque le flux nÃ©cessite un traitement mÃ©tier complÃ©mentaire.

## Version courante

**2.17.1 â€” 16 septembre 2026**

Principaux apports des versions rÃ©centes :

- migration Zendesk vers GLPI avec rÃ©fÃ©rentiels, tickets non clos, commentaires, piÃ¨ces jointes, captures inline, catÃ©gories ITIL et delta incrÃ©mentiel ;
- consolidation de parc **GLPI 11 â†’ GLPI 11** via la High-Level API pour six familles d'assets ;
- rÃ©conciliation multi-source et idempotence via `ObjectLink` ;
- enrichissement des assets GLPI avec entitÃ©, statut, fabricant, lieu, type et modÃ¨le ;
- pagination volumÃ©trique page par page et gestion HTTP `429 / Retry-After` ;
- optimisations 2.17.1 pour les imports de plusieurs milliers d'assets ;
- Copilot Gateway en lecture pour exposer des donnÃ©es ITSM GLPI Ã  des agents/clients autorisÃ©s.

## CompatibilitÃ©

| Composant | Version |
| --- | --- |
| GLPI | `>= 11.0.0` et `< 12.0.0` |
| PHP | `>= 8.2` |
| ClÃ© plugin | `uxconnector` |
| Licence | GPL-3.0-or-later |

## Concepts

```text
ConnectorTemplate
        â†“
Connection
(AuthConfig en interne)
endpoint + auth + secret protÃ©gÃ© + statut
        â†“
Interaction(s)
(SyncJob en interne)
polling / webhook / Ã©vÃ©nement GLPI
        â†“
ObjectLink
Ã©quivalence objet externe â†” objet GLPI
        â†“
SyncExecution
historique et rÃ©sultat d'exÃ©cution
```

Une Connection peut Ãªtre crÃ©Ã©e en **Custom REST** ou associÃ©e Ã  un connecteur standard du catalogue.

## FonctionnalitÃ©s principales

### Moteur gÃ©nÃ©rique

UXConnector fournit notamment :

- authentifications `none`, Basic, API key, Bearer, OAuth2 Client Credentials et OAuth2 Password selon les connecteurs ;
- moteur HTTP gÃ©nÃ©rique avec contrÃ´le des destinations ;
- mappings dÃ©claratifs source â†’ cible ;
- lecture de chemins JSON imbriquÃ©s ;
- transformations de valeurs ;
- stratÃ©gies de pagination `none`, `offset`, `count_offset`, `cursor` et `next_link` ;
- traitement des pages au fil de l'eau pour limiter la consommation mÃ©moire ;
- checkpoints persistants pour les flux incrÃ©mentiels ;
- rÃ©conciliation et idempotence via `ObjectLink` ;
- historique des exÃ©cutions ;
- interactions entrantes et sortantes ;
- webhooks stateless protÃ©gÃ©s par clÃ© opaque ;
- dÃ©clenchements sortants sur Ã©vÃ©nements GLPI ;
- protections anti-boucle pour Ã©viter qu'une Ã©criture inbound reparte immÃ©diatement en outbound.

## Catalogue de connecteurs

Le package 2.17.1 embarque les dÃ©finitions suivantes :

| Connecteur | Authentification dÃ©clarÃ©e | Interactions prÃ©sentes dans le catalogue |
| --- | --- | --- |
| **GLPI 11 High-Level API** | OAuth2 Password | Computers, Monitors, Phones, Peripherals, Printers, NetworkEquipment â†’ GLPI |
| **Zendesk Support** | OAuth2 Client Credentials, Bearer, Basic | utilisateurs, groupes, organisations, catÃ©gories ITIL, tickets non clos, delta incrÃ©mentiel |
| **Jira Cloud** | Bearer | projets/issues inbound, webhook issue, projets/tickets outbound |
| **Microsoft Intune** | OAuth2 Client Credentials | Managed Devices â†’ GLPI Computers |
| **Microsoft Graph** | OAuth2 Client Credentials | Users â†’ GLPI Users |
| **MDM gÃ©nÃ©rique REST** | Bearer, API key, Basic, OAuth2 Client Credentials | Devices â†’ GLPI Computers |
| **ServiceNow** | Basic, OAuth2 Client Credentials | Incidents â†’ GLPI Tickets |
| **Zabbix** | Bearer | Hosts â†’ GLPI Computers |
| **REST gÃ©nÃ©rique** | selon configuration | interaction REST avancÃ©e |
| **API OpenAI compatible** | Bearer | provider utilisable par les fonctions Copilot/Semantic |

La prÃ©sence d'un template dans le catalogue signifie qu'UXConnector sait instancier cette interaction. La validation fonctionnelle et les mappings finaux restent Ã  adapter au tenant/API rÃ©ellement utilisÃ©.

---

# Consolidation GLPI â†’ GLPI

Le connecteur **GLPI 11 High-Level API** est destinÃ© Ã  consolider plusieurs parcs GLPI dans une instance cible.

## Assets pris en charge

Six jobs standards sont fournis :

1. GLPI source â†’ Ordinateurs
2. GLPI source â†’ Moniteurs
3. GLPI source â†’ TÃ©lÃ©phones
4. GLPI source â†’ PÃ©riphÃ©riques
5. GLPI source â†’ Imprimantes
6. GLPI source â†’ Ã‰quipements rÃ©seau

Les ressources High-Level utilisÃ©es sont basÃ©es sur :

```text
/api.php/Assets/Computer
/api.php/Assets/Monitor
/api.php/Assets/Phone
/api.php/Assets/Peripheral
/api.php/Assets/Printer
/api.php/Assets/NetworkEquipment
```

## RÃ©conciliation des assets

UXConnector conserve un lien stable entre la source et la cible avec `ObjectLink` et utilise une identitÃ© mÃ©tier d'asset pour Ã©viter les doublons lors des consolidations multi-sources.

La stratÃ©gie `asset_identity` privilÃ©gie les identifiants disponibles tels que :

```text
UUID
  â†“
numÃ©ro de sÃ©rie
  â†“
numÃ©ro d'inventaire
  â†“
nom lorsque ce fallback est autorisÃ© pour le type d'asset
```

Les templates et assets supprimÃ©s logiquement dans la source sont ignorÃ©s.

## RÃ©fÃ©rentiels GLPI

Depuis la 2.17.0, les assets importÃ©s sont enrichis avec :

- entitÃ© ;
- statut ;
- fabricant ;
- lieu ;
- type d'asset ;
- modÃ¨le d'asset.

Les IDs numÃ©riques de la source ne sont **jamais copiÃ©s directement** vers la cible. UXConnector rÃ©sout ou crÃ©e le rÃ©fÃ©rentiel dans le GLPI cible Ã  partir de son nom, puis utilise l'ID cible.

Les hiÃ©rarchies d'entitÃ©s et de lieux utilisent `completename` lorsqu'il est fourni par l'API. Une correspondance ambiguÃ« est refusÃ©e plutÃ´t que rÃ©solue arbitrairement.

## Performance 2.17.1

La version 2.17.1 ajoute :

- un cache en mÃ©moire des rÃ©fÃ©rentiels dÃ©jÃ  rÃ©solus pendant le run ;
- la suppression des `update()` GLPI inutiles lorsque les rÃ©fÃ©rences sont dÃ©jÃ  correctes ;
- le rafraÃ®chissement du budget d'exÃ©cution PHP Ã  chaque frontiÃ¨re de page pour les traitements multi-pages.

Aucun changement de schÃ©ma ni recrÃ©ation des jobs n'est nÃ©cessaire lors du passage de 2.17.0 Ã  2.17.1.

## Limites actuelles du flux GLPI High-Level

- utilisateurs et groupes ne sont pas automatiquement recrÃ©Ã©s dans le cadre de l'enrichissement des assets ;
- les relations physiques entre assets ne sont pas reconstruites par UXConnector dans cette version ;
- lorsque GLPI Inventory sera activÃ© sur la cible finale, il pourra reprendre la maintenance des donnÃ©es techniques et reconstruire les relations observables par l'inventaire ;
- la qualitÃ© des clÃ©s `uuid`, `serial`, `otherserial` et `name` reste dÃ©terminante pour Ã©viter les doublons lors de la reprise par l'inventaire natif.

---

# Migration Zendesk â†’ GLPI

UXConnector 2.15.0 fournit un parcours dÃ©diÃ© aux reprises Zendesk volumÃ©triques.

## RÃ©fÃ©rentiels et tickets

Le catalogue Zendesk contient notamment :

```text
1 - Zendesk Users â†’ GLPI Users
2 - Zendesk Groups â†’ GLPI Groups
3 - Zendesk Organizations â†’ GLPI Entities
4 - Zendesk Sujets utilisÃ©s â†’ GLPI CatÃ©gories ITIL
5 - Zendesk Reprise initiale non close â†’ GLPI Tickets
6 - Zendesk Delta incrÃ©mentiel â†’ GLPI Tickets
```

Des interactions de compatibilitÃ© avec les versions antÃ©rieures restent prÃ©sentes dans le catalogue.

## Reprise initiale

La reprise initiale s'appuie sur Zendesk Search Export avec le pÃ©rimÃ¨tre :

```text
status<closed
```

Elle traite donc les tickets `new`, `open`, `pending`, `hold` et `solved` et Ã©vite de parcourir tout l'historique dÃ©jÃ  clos.

La taille recommandÃ©e est de **100 tickets par page**.

## CatÃ©gories ITIL

Le champ tagger Zendesk **Sujet** est dÃ©couvert dynamiquement. Les catÃ©gories rÃ©ellement utilisÃ©es dans le pÃ©rimÃ¨tre migrÃ© sont reconstruites comme catÃ©gories ITIL hiÃ©rarchiques GLPI.

Exemple :

```text
Logiciel::Microsoft 365::Outlook
```

est reconstruit sous forme d'une arborescence native GLPI.

## Commentaires et piÃ¨ces jointes

Le traitement Zendesk prend en charge :

- followups GLPI Ã  partir des commentaires Zendesk ;
- dÃ©duplication du commentaire initial lorsqu'il correspond Ã  la description du ticket ;
- documents GLPI pour les piÃ¨ces jointes ;
- captures/images inline ;
- comportement idempotent lors des relances grÃ¢ce aux `ObjectLink`.

## Delta incrÃ©mentiel

Le job de delta utilise l'API cursor-based Zendesk.

Le checkpoint est persistÃ© aprÃ¨s chaque page entiÃ¨rement rÃ©ussie. En cas d'Ã©chec sur une page, le checkpoint reste positionnÃ© avant cette page afin qu'un run suivant puisse rejouer la sÃ©quence sans perte.

Un ticket dÃ©jÃ  repris puis fermÃ© peut donc Ãªtre mis Ã  jour dans GLPI sans qu'il soit nÃ©cessaire de rÃ©importer tout l'historique.

## VolumÃ©trie et rate limiting

Le moteur :

- traite les pages au fil de l'eau ;
- respecte HTTP `429` et `Retry-After` ;
- effectue jusqu'Ã  5 nouvelles tentatives ;
- contient `tools/zendesk-volume-test.php` pour un test synthÃ©tique sans Ã©criture.

Exemple :

```bash
cd /var/www/glpi/plugins/uxconnector
php tools/zendesk-volume-test.php 10000 100
```

---

# AI Agents / Copilot Gateway

UXConnector embarque Ã©galement un **Copilot Gateway** orientÃ© lecture.

Deux types de provider sont prÃ©vus :

- `local_glpi` lorsque UXConnector est installÃ© dans le GLPI interrogÃ© ;
- `glpi_hl_api` pour interroger un GLPI distant via une Connection UXConnector.

Les fonctions analytiques locales comprennent notamment :

- recherche de tickets ;
- contexte dÃ©taillÃ© d'un ticket ;
- volumes et synthÃ¨ses ;
- tendances ;
- catÃ©gories ;
- SLA ;
- groupes ;
- techniciens ;
- comparaison de pÃ©riodes ;
- analyses d'incidentologie ;
- fonctions de classification sÃ©mantique lorsqu'un provider compatible est configurÃ©.

Les clients Copilot utilisent leurs propres clÃ©s API et les endpoints machine sont enregistrÃ©s comme chemins stateless dÃ©diÃ©s.

---

# Architecture

Le plugin suit une approche Clean Architecture / DDD.

```text
resources/connectors/*/connector.json
                  â†“
ConnectorTemplate â†’ JobTemplate
                  â†“
              SyncJob
                  â†“
          moteur gÃ©nÃ©rique
        â”œâ”€â”€ GenericHttpEngine
        â”œâ”€â”€ JsonPathValueReader
        â”œâ”€â”€ CollectionExtractor
        â”œâ”€â”€ PaginationStrategy
        â”œâ”€â”€ InboundRecordProcessor
        â”œâ”€â”€ DispatchGlpiItemEventService
        â”œâ”€â”€ GlpiItemWriter
        â””â”€â”€ ObjectLink
```

Principe structurant : le runtime gÃ©nÃ©rique ne doit pas dÃ©pendre directement d'un Ã©diteur. Les particularitÃ©s sont portÃ©es par les templates, transformations ou post-processors spÃ©cialisÃ©s.

# Webhooks

Une interaction webhook reÃ§oit une clÃ© opaque alÃ©atoire de 256 bits.

Endpoint :

```text
/plugins/uxconnector/front/webhook.php
```

MÃ©thode recommandÃ©e lorsque le systÃ¨me Ã©metteur accepte les headers personnalisÃ©s :

```text
X-UXConnector-Webhook-Key: <clÃ© de l'interaction>
```

CompatibilitÃ© URL :

```text
/plugins/uxconnector/front/webhook.php?key=<clÃ©>
```

Le header est prÃ©fÃ©rable afin d'Ã©viter l'enregistrement potentiel de la clÃ© dans des logs d'accÃ¨s HTTP.

# SÃ©curitÃ©

Les mÃ©canismes de sÃ©curitÃ© incluent notamment :

- stockage chiffrÃ© des secrets ;
- interdiction d'envoyer des credentials sur HTTP non chiffrÃ© ;
- contrÃ´le des destinations contre les scÃ©narios SSRF ;
- blocage des credentials intÃ©grÃ©s dans les URLs ;
- validation des headers et des endpoints ;
- droits GLPI sur les Ã©crans et actions administratives ;
- protection CSRF native GLPI ;
- clÃ© webhook 256 bits ;
- liste blanche des champs GLPI inscriptibles via `TargetFieldPolicy` ;
- pilotage de `entities_id` par la stratÃ©gie d'entitÃ© et non par un mapping libre ;
- protection contre les boucles inbound/outbound ;
- contrÃ´le des pÃ©rimÃ¨tres d'entitÃ© pour les interactions sortantes ;
- nettoyage contrÃ´lÃ© des credentials et des mÃ©tadonnÃ©es lors des suppressions.

# Installation

Le package de release doit contenir directement un rÃ©pertoire `uxconnector/` installable dans :

```text
<GLPI_ROOT>/plugins/uxconnector
```

ProcÃ©dure recommandÃ©e :

1. sauvegarder la base GLPI et le dossier actuel `plugins/uxconnector/` ;
2. remplacer le dossier par celui de la nouvelle release ;
3. ne pas dÃ©sinstaller le plugin lors d'une mise Ã  jour ;
4. lancer **Mettre Ã  jour** depuis **Configuration > Plugins** ;
5. vider le cache GLPI si nÃ©cessaire ;
6. se reconnecter ou changer de profil si les droits/menu sont encore en cache.

Depuis le serveur GLPI :

```bash
php bin/console cache:clear
```

# DÃ©veloppement et contrÃ´les

Le dÃ©pÃ´t source contient `composer.lock` afin de figer les dÃ©pendances de dÃ©veloppement.

Le dossier `vendor/` n'est pas versionnÃ© dans Git mais doit Ãªtre prÃ©sent dans le package de release installable lorsque les dÃ©pendances sont nÃ©cessaires Ã  l'exÃ©cution ou aux contrÃ´les de livraison.

Commandes disponibles :

```bash
composer test
composer analyse
composer fix
composer lint:glpi
```

# Documentation du dÃ©pÃ´t

Les principaux documents techniques disponibles dans le dÃ©pÃ´t sont :

- `CHANGELOG.md` â€” historique fonctionnel et technique ;
- `docs/history/releases/GLPI_HL_ASSETS_2.16.0.md` â€” consolidation des assets GLPI High-Level ;
- `docs/history/releases/GLPI_HL_ASSETS_2.16.1.md` â€” correctif `asset_identity` ;
- `docs/history/releases/GLPI_HL_REFERENCES_2.17.0.md` â€” enrichissement des rÃ©fÃ©rentiels ;
- `docs/history/releases/GLPI_HL_REFERENCES_2.17.1_HOTFIX.md` â€” correctif de performance ;
- `docs/history/releases/ZENDESK_MIGRATION_2.14.0.md` â€” architecture de migration Zendesk ;
- `docs/history/releases/ZENDESK_2.15.0.md` â€” stratÃ©gie de migration volumÃ©trique et delta ;
- `docs/marketplace/MARKETPLACE_COMPLIANCE_2026-09-02.md` â€” contrÃ´les de conformitÃ© ;
- `docs/` â€” architecture, ADR et documentation complÃ©mentaire.

# Distribution

Ce dÃ©pÃ´t est destinÃ© Ã  une **distribution contrÃ´lÃ©e** du plugin.

La publication dans un catalogue public n'est pas requise pour une mise Ã  disposition privÃ©e sur des instances GLPI sÃ©lectionnÃ©es. Le package soumis pour validation doit correspondre exactement au tag Git et Ã  la release testÃ©e.

Le descripteur `uxconnector.xml.dist` contient volontairement une URL de release gÃ©nÃ©rique/non renseignÃ©e tant qu'aucune publication publique n'est prÃ©vue.

# Licence

GPL-3.0-or-later â€” voir `LICENSE`.
