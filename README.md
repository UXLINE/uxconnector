# UXConnector

UXConnector est un plugin **GLPI 11** fournissant un moteur générique de connexions et de synchronisations entre GLPI et des systèmes tiers.

Le principe est de configurer une **Connection** une seule fois (endpoint, méthode d'authentification, secret protégé), puis d'y associer une ou plusieurs **Interactions** entrantes ou sortantes : polling, webhook ou événement GLPI.

Le runtime reste générique : les particularités d'un éditeur ou d'une API sont décrites principalement dans les manifests du catalogue `resources/connectors/*/connector.json` et dans des post-processors spécialisés lorsque le flux nécessite un traitement métier complémentaire.

## Version courante

**2.17.1 — 16 septembre 2026**

Principaux apports des versions récentes :

- migration Zendesk vers GLPI avec référentiels, tickets non clos, commentaires, pièces jointes, captures inline, catégories ITIL et delta incrémentiel ;
- consolidation de parc **GLPI 11 → GLPI 11** via la High-Level API pour six familles d'assets ;
- réconciliation multi-source et idempotence via `ObjectLink` ;
- enrichissement des assets GLPI avec entité, statut, fabricant, lieu, type et modèle ;
- pagination volumétrique page par page et gestion HTTP `429 / Retry-After` ;
- optimisations 2.17.1 pour les imports de plusieurs milliers d'assets ;
- Copilot Gateway en lecture pour exposer des données ITSM GLPI à des agents/clients autorisés.

## Compatibilité

| Composant | Version |
| --- | --- |
| GLPI | `>= 11.0.0` et `< 12.0.0` |
| PHP | `>= 8.2` |
| Clé plugin | `uxconnector` |
| Licence | GPL-3.0-or-later |

## Concepts

```text
ConnectorTemplate
        ↓
Connection
(AuthConfig en interne)
endpoint + auth + secret protégé + statut
        ↓
Interaction(s)
(SyncJob en interne)
polling / webhook / événement GLPI
        ↓
ObjectLink
équivalence objet externe ↔ objet GLPI
        ↓
SyncExecution
historique et résultat d'exécution
```

Une Connection peut être créée en **Custom REST** ou associée à un connecteur standard du catalogue.

## Fonctionnalités principales

### Moteur générique

UXConnector fournit notamment :

- authentifications `none`, Basic, API key, Bearer, OAuth2 Client Credentials et OAuth2 Password selon les connecteurs ;
- moteur HTTP générique avec contrôle des destinations ;
- mappings déclaratifs source → cible ;
- lecture de chemins JSON imbriqués ;
- transformations de valeurs ;
- stratégies de pagination `none`, `offset`, `count_offset`, `cursor` et `next_link` ;
- traitement des pages au fil de l'eau pour limiter la consommation mémoire ;
- checkpoints persistants pour les flux incrémentiels ;
- réconciliation et idempotence via `ObjectLink` ;
- historique des exécutions ;
- interactions entrantes et sortantes ;
- webhooks stateless protégés par clé opaque ;
- déclenchements sortants sur événements GLPI ;
- protections anti-boucle pour éviter qu'une écriture inbound reparte immédiatement en outbound.

## Catalogue de connecteurs

Le package 2.17.1 embarque les définitions suivantes :

| Connecteur | Authentification déclarée | Interactions présentes dans le catalogue |
| --- | --- | --- |
| **GLPI 11 High-Level API** | OAuth2 Password | Computers, Monitors, Phones, Peripherals, Printers, NetworkEquipment → GLPI |
| **Zendesk Support** | OAuth2 Client Credentials, Bearer, Basic | utilisateurs, groupes, organisations, catégories ITIL, tickets non clos, delta incrémentiel |
| **Jira Cloud** | Bearer | projets/issues inbound, webhook issue, projets/tickets outbound |
| **Microsoft Intune** | OAuth2 Client Credentials | Managed Devices → GLPI Computers |
| **Microsoft Graph** | OAuth2 Client Credentials | Users → GLPI Users |
| **MDM générique REST** | Bearer, API key, Basic, OAuth2 Client Credentials | Devices → GLPI Computers |
| **ServiceNow** | Basic, OAuth2 Client Credentials | Incidents → GLPI Tickets |
| **Zabbix** | Bearer | Hosts → GLPI Computers |
| **REST générique** | selon configuration | interaction REST avancée |
| **API OpenAI compatible** | Bearer | provider utilisable par les fonctions Copilot/Semantic |

La présence d'un template dans le catalogue signifie qu'UXConnector sait instancier cette interaction. La validation fonctionnelle et les mappings finaux restent à adapter au tenant/API réellement utilisé.

---

# Consolidation GLPI → GLPI

Le connecteur **GLPI 11 High-Level API** est destiné à consolider plusieurs parcs GLPI dans une instance cible.

## Assets pris en charge

Six jobs standards sont fournis :

1. GLPI source → Ordinateurs
2. GLPI source → Moniteurs
3. GLPI source → Téléphones
4. GLPI source → Périphériques
5. GLPI source → Imprimantes
6. GLPI source → Équipements réseau

Les ressources High-Level utilisées sont basées sur :

```text
/api.php/Assets/Computer
/api.php/Assets/Monitor
/api.php/Assets/Phone
/api.php/Assets/Peripheral
/api.php/Assets/Printer
/api.php/Assets/NetworkEquipment
```

## Réconciliation des assets

UXConnector conserve un lien stable entre la source et la cible avec `ObjectLink` et utilise une identité métier d'asset pour éviter les doublons lors des consolidations multi-sources.

La stratégie `asset_identity` privilégie les identifiants disponibles tels que :

```text
UUID
  ↓
numéro de série
  ↓
numéro d'inventaire
  ↓
nom lorsque ce fallback est autorisé pour le type d'asset
```

Les templates et assets supprimés logiquement dans la source sont ignorés.

## Référentiels GLPI

Depuis la 2.17.0, les assets importés sont enrichis avec :

- entité ;
- statut ;
- fabricant ;
- lieu ;
- type d'asset ;
- modèle d'asset.

Les IDs numériques de la source ne sont **jamais copiés directement** vers la cible. UXConnector résout ou crée le référentiel dans le GLPI cible à partir de son nom, puis utilise l'ID cible.

Les hiérarchies d'entités et de lieux utilisent `completename` lorsqu'il est fourni par l'API. Une correspondance ambiguë est refusée plutôt que résolue arbitrairement.

## Performance 2.17.1

La version 2.17.1 ajoute :

- un cache en mémoire des référentiels déjà résolus pendant le run ;
- la suppression des `update()` GLPI inutiles lorsque les références sont déjà correctes ;
- le rafraîchissement du budget d'exécution PHP à chaque frontière de page pour les traitements multi-pages.

Aucun changement de schéma ni recréation des jobs n'est nécessaire lors du passage de 2.17.0 à 2.17.1.

## Limites actuelles du flux GLPI High-Level

- utilisateurs et groupes ne sont pas automatiquement recréés dans le cadre de l'enrichissement des assets ;
- les relations physiques entre assets ne sont pas reconstruites par UXConnector dans cette version ;
- lorsque GLPI Inventory sera activé sur la cible finale, il pourra reprendre la maintenance des données techniques et reconstruire les relations observables par l'inventaire ;
- la qualité des clés `uuid`, `serial`, `otherserial` et `name` reste déterminante pour éviter les doublons lors de la reprise par l'inventaire natif.

---

# Migration Zendesk → GLPI

UXConnector 2.15.0 fournit un parcours dédié aux reprises Zendesk volumétriques.

## Référentiels et tickets

Le catalogue Zendesk contient notamment :

```text
1 - Zendesk Users → GLPI Users
2 - Zendesk Groups → GLPI Groups
3 - Zendesk Organizations → GLPI Entities
4 - Zendesk Sujets utilisés → GLPI Catégories ITIL
5 - Zendesk Reprise initiale non close → GLPI Tickets
6 - Zendesk Delta incrémentiel → GLPI Tickets
```

Des interactions de compatibilité avec les versions antérieures restent présentes dans le catalogue.

## Reprise initiale

La reprise initiale s'appuie sur Zendesk Search Export avec le périmètre :

```text
status<closed
```

Elle traite donc les tickets `new`, `open`, `pending`, `hold` et `solved` et évite de parcourir tout l'historique déjà clos.

La taille recommandée est de **100 tickets par page**.

## Catégories ITIL

Le champ tagger Zendesk **Sujet** est découvert dynamiquement. Les catégories réellement utilisées dans le périmètre migré sont reconstruites comme catégories ITIL hiérarchiques GLPI.

Exemple :

```text
Logiciel::Microsoft 365::Outlook
```

est reconstruit sous forme d'une arborescence native GLPI.

## Commentaires et pièces jointes

Le traitement Zendesk prend en charge :

- followups GLPI à partir des commentaires Zendesk ;
- déduplication du commentaire initial lorsqu'il correspond à la description du ticket ;
- documents GLPI pour les pièces jointes ;
- captures/images inline ;
- comportement idempotent lors des relances grâce aux `ObjectLink`.

## Delta incrémentiel

Le job de delta utilise l'API cursor-based Zendesk.

Le checkpoint est persisté après chaque page entièrement réussie. En cas d'échec sur une page, le checkpoint reste positionné avant cette page afin qu'un run suivant puisse rejouer la séquence sans perte.

Un ticket déjà repris puis fermé peut donc être mis à jour dans GLPI sans qu'il soit nécessaire de réimporter tout l'historique.

## Volumétrie et rate limiting

Le moteur :

- traite les pages au fil de l'eau ;
- respecte HTTP `429` et `Retry-After` ;
- effectue jusqu'à 5 nouvelles tentatives ;
- contient `tools/zendesk-volume-test.php` pour un test synthétique sans écriture.

Exemple :

```bash
cd /var/www/glpi/plugins/uxconnector
php tools/zendesk-volume-test.php 10000 100
```

---

# AI Agents / Copilot Gateway

UXConnector embarque également un **Copilot Gateway** orienté lecture.

Deux types de provider sont prévus :

- `local_glpi` lorsque UXConnector est installé dans le GLPI interrogé ;
- `glpi_hl_api` pour interroger un GLPI distant via une Connection UXConnector.

Les fonctions analytiques locales comprennent notamment :

- recherche de tickets ;
- contexte détaillé d'un ticket ;
- volumes et synthèses ;
- tendances ;
- catégories ;
- SLA ;
- groupes ;
- techniciens ;
- comparaison de périodes ;
- analyses d'incidentologie ;
- fonctions de classification sémantique lorsqu'un provider compatible est configuré.

Les clients Copilot utilisent leurs propres clés API et les endpoints machine sont enregistrés comme chemins stateless dédiés.

---

# Architecture

Le plugin suit une approche Clean Architecture / DDD.

```text
resources/connectors/*/connector.json
                  ↓
ConnectorTemplate → JobTemplate
                  ↓
              SyncJob
                  ↓
          moteur générique
        ├── GenericHttpEngine
        ├── JsonPathValueReader
        ├── CollectionExtractor
        ├── PaginationStrategy
        ├── InboundRecordProcessor
        ├── DispatchGlpiItemEventService
        ├── GlpiItemWriter
        └── ObjectLink
```

Principe structurant : le runtime générique ne doit pas dépendre directement d'un éditeur. Les particularités sont portées par les templates, transformations ou post-processors spécialisés.

# Webhooks

Une interaction webhook reçoit une clé opaque aléatoire de 256 bits.

Endpoint :

```text
/plugins/uxconnector/front/webhook.php
```

Méthode recommandée lorsque le système émetteur accepte les headers personnalisés :

```text
X-UXConnector-Webhook-Key: <clé de l'interaction>
```

Compatibilité URL :

```text
/plugins/uxconnector/front/webhook.php?key=<clé>
```

Le header est préférable afin d'éviter l'enregistrement potentiel de la clé dans des logs d'accès HTTP.

# Sécurité

Les mécanismes de sécurité incluent notamment :

- stockage chiffré des secrets ;
- interdiction d'envoyer des credentials sur HTTP non chiffré ;
- contrôle des destinations contre les scénarios SSRF ;
- blocage des credentials intégrés dans les URLs ;
- validation des headers et des endpoints ;
- droits GLPI sur les écrans et actions administratives ;
- protection CSRF native GLPI ;
- clé webhook 256 bits ;
- liste blanche des champs GLPI inscriptibles via `TargetFieldPolicy` ;
- pilotage de `entities_id` par la stratégie d'entité et non par un mapping libre ;
- protection contre les boucles inbound/outbound ;
- contrôle des périmètres d'entité pour les interactions sortantes ;
- nettoyage contrôlé des credentials et des métadonnées lors des suppressions.

# Installation

Le package de release doit contenir directement un répertoire `uxconnector/` installable dans :

```text
<GLPI_ROOT>/plugins/uxconnector
```

Procédure recommandée :

1. sauvegarder la base GLPI et le dossier actuel `plugins/uxconnector/` ;
2. remplacer le dossier par celui de la nouvelle release ;
3. ne pas désinstaller le plugin lors d'une mise à jour ;
4. lancer **Mettre à jour** depuis **Configuration > Plugins** ;
5. vider le cache GLPI si nécessaire ;
6. se reconnecter ou changer de profil si les droits/menu sont encore en cache.

Depuis le serveur GLPI :

```bash
php bin/console cache:clear
```

# Développement et contrôles

Le dépôt source contient `composer.lock` afin de figer les dépendances de développement.

Le dossier `vendor/` n'est pas versionné dans Git mais doit être présent dans le package de release installable lorsque les dépendances sont nécessaires à l'exécution ou aux contrôles de livraison.

Commandes disponibles :

```bash
composer test
composer analyse
composer fix
composer lint:glpi
```

# Documentation du dépôt

Les principaux documents techniques disponibles dans le dépôt sont :

- `CHANGELOG.md` — historique fonctionnel et technique ;
- `GLPI_HL_ASSETS_2.16.0.md` — consolidation des assets GLPI High-Level ;
- `GLPI_HL_ASSETS_2.16.1.md` — correctif `asset_identity` ;
- `GLPI_HL_REFERENCES_2.17.0.md` — enrichissement des référentiels ;
- `GLPI_HL_REFERENCES_2.17.1_HOTFIX.md` — correctif de performance ;
- `ZENDESK_MIGRATION_2.14.0.md` — architecture de migration Zendesk ;
- `ZENDESK_2.15.0.md` — stratégie de migration volumétrique et delta ;
- `MARKETPLACE_COMPLIANCE_2026-09-02.md` — contrôles de conformité ;
- `docs/` — architecture, ADR et documentation complémentaire.

# Distribution

Ce dépôt est destiné à une **distribution contrôlée** du plugin.

La publication dans un catalogue public n'est pas requise pour une mise à disposition privée sur des instances GLPI sélectionnées. Le package soumis pour validation doit correspondre exactement au tag Git et à la release testée.

Le descripteur `uxconnector.xml.dist` contient volontairement une URL de release générique/non renseignée tant qu'aucune publication publique n'est prévue.

# Licence

GPL-3.0-or-later — voir `LICENSE`.
