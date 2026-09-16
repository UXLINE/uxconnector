# UXConnector 2.8.0 — Connexions et interactions

## 1. Contrat fonctionnel

UXConnector distingue désormais explicitement :

1. **ConnectorDefinition / ConnectorTemplate** : définition livrée avec le plugin (Jira, Intune, Graph, MDM, ...).
2. **Connection** : instance réellement configurée chez le client (`JIRA COAXIS`, `INTUNE CLIENT A`, API REST personnalisée). L'agrégat interne historique reste `AuthConfig` afin de conserver la compatibilité des données.
3. **Interaction** : comportement rattaché à une Connection. L'agrégat interne historique reste `SyncJob`.
4. **ObjectLink** : équivalence durable entre un objet externe et un objet GLPI, à l'échelle de la Connection.
5. **SyncExecution** : historique d'une exécution d'Interaction.

Une Connection possède son propre cycle de vie :

```text
Créer -> Tester -> Activer -> Désactiver -> Réactiver
                      \
                       -> Supprimer (si aucune interaction ne la référence)
```

Désactiver une Connection **suspend** ses interactions actives sans modifier leur propre statut. Elles reprennent automatiquement lorsque la Connection redevient active.

## 2. Architecture cible

```text
resources/connectors/*/connector.json
             |
             v
      ConnectorTemplate
             |
       JobTemplate(s)
             |
   paramètres administrateur
             |
             v
 InstantiateJobTemplateService
             |
             v
    Interaction / SyncJob concret
             |
      +------+---------------------+
      |                            |
      v                            v
INBOUND                       OUTBOUND
polling/webhook               GLPI event
      |                            |
      v                            v
Generic inbound engine        Generic outbound engine
      |                            |
      +------------+---------------+
                   v
                ObjectLink
                   |
                   v
             objets GLPI
```

### Règle d'architecture

> Un `JobTemplate` peut connaître Jira, Intune ou un autre éditeur. Les services runtime (`ExecuteSyncJobService`, `InboundRecordProcessor`, `DispatchGlpiItemEventService`) ne doivent jamais connaître le nom d'un éditeur.

Les paramètres de template sont substitués **une seule fois lors de l'instanciation**. Le runtime ne reçoit ensuite qu'un `SyncJob` totalement résolu.

## 3. Types d'interactions

### 3.1 Polling entrant

```text
Système externe --GET périodique--> UXConnector --> GLPI
```

Le moteur prend en charge de manière déclarative :

- `CollectionPath` configurable ;
- chemins JSON imbriqués ;
- pagination `none`, `offset`, `cursor`, `next_link` ;
- mappings et transformations ;
- affectation d'entité ;
- réconciliation par `ObjectLink`.

### 3.2 Événement entrant / webhook

```text
Système externe --POST webhook--> /plugins/uxconnector/front/webhook.php
                                    |
                                    v
                                Interaction
                                    |
                                    v
                                  GLPI
```

Chaque Interaction webhook reçoit une clé opaque aléatoire de 256 bits. Le header `X-UXConnector-Webhook-Key` est le mode recommandé. Le paramètre `?key=` est conservé pour les solutions ne permettant pas d'ajouter un header personnalisé.

### 3.3 Événement GLPI sortant

```text
GLPI CommonDBTM add/update
          |
          v
plugin_uxconnector_item_changed
          |
          v
DispatchGlpiItemEventService
          |
          v
API externe (POST/PUT/PATCH/DELETE)
```

Les définitions HTTP de création/mise à jour sont génériques et stockées sur le `SyncJob`; elles proviennent d'un template ou de l'éditeur REST avancé. Une interaction standard sortante exige en outre un périmètre d'entité GLPI fixe : elle n'émet jamais implicitement les objets de toutes les entités.

## 4. Bidirectionnel

Le bidirectionnel n'est jamais un job magique unique. Il est composé de deux interactions indépendantes partageant le même `ObjectLink` :

```text
Jira Project 10000 <---- ObjectLink ----> GLPI Project 42
       ^                                  |
       |                                  v
 Jira -> GLPI                        GLPI -> Jira
 Interaction A                       Interaction B
```

Cela permet d'activer, suspendre, auditer et faire évoluer chaque direction indépendamment.

## 5. ObjectLink à l'échelle de la Connection

Depuis 2.8.0, la recherche fonctionnelle d'un lien repose sur :

```text
Connection/AuthConfig
+ external_object_type
+ external_id
```

ou, côté GLPI :

```text
Connection/AuthConfig
+ GLPI itemtype
+ GLPI item id
+ external_object_type
```

`plugin_uxconnector_syncjobs_id` reste stocké comme provenance historique mais n'est plus la frontière de réconciliation. Ainsi un import Jira -> GLPI et une action GLPI -> Jira peuvent réutiliser le même lien.

Les lignes héritées avec `external_object_type = record` sont reconnues et migrées paresseusement lors de leur prochaine utilisation.

## 6. Garde anti-boucle

Les écritures entrantes GLPI sont exécutées dans `SyncRuntimeGuard`. Lorsque GLPI déclenche ensuite son hook `item_add/item_update` dans la même requête, le dispatcher outbound s'abstient.

Cela couvre notamment :

```text
GLPI -> Jira -> webhook Jira -> GLPI
```

Le webhook écrit dans GLPI sous garde runtime ; cette écriture ne repart donc pas immédiatement vers Jira.

La résolution de conflits de modifications simultanées entre deux systèmes reste un chantier ultérieur (hash/version/checkpoint).

## 7. Interface

Le parcours normal est centré sur **Connections** :

```text
UXConnector
├── Connections
│   ├── New connection
│   └── Connection detail
│       ├── Test / Activate / Disable / Delete
│       ├── standard interactions
│       └── advanced custom REST interaction
├── Connector catalog (découverte)
├── Interactions (supervision globale)
└── Advanced REST interaction
```

Le formulaire technique (resource path, collection path, pagination, JSON paths, corps REST) est réservé au mode avancé. Les intégrations standard sont créées depuis une Connection et ne demandent que les paramètres métier/environnement nécessaires.

## 8. Jira livré en 2.8.0

Le manifeste Jira contient :

- Jira Projects -> GLPI Projects (polling) ;
- Jira Issues -> GLPI Tickets (polling) ;
- Jira Issue event -> GLPI Ticket (webhook générique) ;
- GLPI Projects -> Jira Projects (événement GLPI) ;
- GLPI Tickets -> Jira Issues (événement GLPI, mapping minimal du titre).

Les endpoints et détails Jira restent exclusivement dans `resources/connectors/jira/connector.json`.

## 9. Limitations connues

- Pas encore de signature native spécifique à chaque fournisseur de webhook ; la sécurité générique repose sur la clé d'Interaction.
- Le paramètre de clé dans l'URL peut apparaître dans les logs d'un reverse proxy : le header est préféré lorsque le produit émetteur le permet.
- Pas encore de liaison automatique Jira Issue -> Jira Project -> `glpi_itils_projects` ; les primitives `ObjectLink` nécessaires sont en place.
- Pas encore de résolution sophistiquée des conflits concurrents entre deux systèmes.
- Les interactions sortantes sont événementielles ; il n'existe pas encore de « backfill » manuel de tous les objets GLPI existants.
- Le mapping Jira Issue sortant standard est volontairement minimal pour éviter d'imposer des champs Jira qui dépendent des écrans/types configurés chez le client.
