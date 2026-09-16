# UXConnector

Plugin GLPI 11 permettant de **connecter un système tiers une seule fois**, puis
d'activer les interactions nécessaires entre ce système et les objets GLPI.

UXConnector conserve un moteur HTTP générique : les particularités Jira,
Intune, Graph, MDM, ServiceNow, etc. sont décrites par des manifests du
catalogue et ne sont jamais codées en dur dans le runtime.

## Statut

**Version 2.8.1 — Parcours Connection corrigé + édition/test/activation depuis la fiche.**

Le parcours produit est désormais :

```text
Créer une Connection
      ↓
Tester
      ↓
Activer
      ↓
Ouvrir la Connection
      ↓
Créer/activer ses Interactions
      ├── polling externe → GLPI
      ├── webhook externe → GLPI
      └── événement GLPI → système externe
```

Le catalogue reste disponible pour fournir les définitions standard, tandis
que le formulaire REST très technique est réservé au mode **Advanced**.

Voir `ARCHITECTURE_2.8.0.md`, `CORRECTIONS_2.8.0.md` et
`VALIDATION_2.8.0.txt`.

## Prérequis

| Composant | Version minimale |
| --- | --- |
| GLPI | 11.0.0 |
| PHP | 8.2 |

## Concepts

```text
ConnectorTemplate                définition fournie avec UXConnector
        ↓
Connection                       instance configurée chez le client
(AuthConfig en interne)          endpoint + auth + secret protégé + statut
        ↓
Interaction(s)                   comportements activables indépendamment
(SyncJob en interne)             polling / webhook / événement GLPI
        ↓
ObjectLink                       équivalence objet externe ↔ objet GLPI
        ↓
SyncExecution                    historique d'exécution
```

Une Connection peut être créée manuellement en **Custom REST** ou à partir
d'un connecteur standard. Une Connection `custom` existante peut être associée
ultérieurement à Jira/Intune/etc. sans recréer ses identifiants.

## Menu

```text
UXConnector
├── Connections                 page d'entrée
│   ├── New connection
│   └── Manage
│       ├── Test / Activate / Disable / Delete
│       ├── standard interactions
│       └── advanced custom REST interaction
├── Connector catalog          découverte des définitions intégrées
├── Interactions               supervision globale
└── Advanced REST interaction  création totalement personnalisée
```

## Catalogue 2.8.0

| Connecteur | Interactions fournies |
| --- | --- |
| Jira Cloud | Projects → GLPI Projects ; Issues → GLPI Tickets ; Issue webhook → GLPI Ticket ; GLPI Projects → Jira Projects ; GLPI Tickets → Jira Issues |
| Microsoft Intune | Managed devices → GLPI Computers |
| Microsoft Graph | Users → GLPI Users |
| MDM générique REST | Devices → GLPI Computers, paramétrable |
| ServiceNow | Référencé ; interaction standard encore planifiée |
| Zabbix | Référencé ; JSON-RPC standard encore planifié |
| REST générique | Mode avancé libre |

### Jira : principe bidirectionnel

Le bidirectionnel est volontairement composé de **deux interactions** qui
partagent le même `ObjectLink` :

```text
Jira Project 10000  ←→  ObjectLink  ←→  GLPI Project 42
        ↑                                  ↓
   Jira → GLPI                         GLPI → Jira
```

La désactivation d'une Connection suspend ses interactions actives sans les
supprimer ni modifier leur statut.

## Architecture

Le plugin applique une Clean Architecture / DDD. Les définitions éditeur sont
séparées du runtime :

```text
resources/connectors/*/connector.json
                  ↓
ConnectorTemplate → JobTemplate
                  ↓ instanciation unique des paramètres
             SyncJob concret
                  ↓
        moteur générique runtime
        ├── GenericHttpEngine
        ├── JsonPathValueReader
        ├── CollectionExtractor
        ├── PaginationStrategy
        ├── InboundRecordProcessor
        ├── DispatchGlpiItemEventService
        ├── GlpiItemWriter
        └── ObjectLink
```

**Règle structurante : un JobTemplate peut connaître Jira ;
`ExecuteSyncJobService` et `DispatchGlpiItemEventService` ne doivent jamais
connaître Jira.**

## Webhooks

Une Interaction webhook reçoit une clé opaque aléatoire de 256 bits.

Endpoint :

```text
/plugins/uxconnector/front/webhook.php
```

Méthode recommandée lorsque l'émetteur accepte des headers personnalisés :

```text
X-UXConnector-Webhook-Key: <clé de l'interaction>
```

Compatibilité pour les produits ne permettant pas de header personnalisé :

```text
/plugins/uxconnector/front/webhook.php?key=<clé>
```

Le header est préférable, car un paramètre d'URL peut être enregistré par un
reverse proxy dans ses journaux d'accès.

## Installation / mise à jour

1. Sauvegarder la base GLPI et l'ancien dossier `plugins/uxconnector/`.
2. Remplacer le dossier par le répertoire `uxconnector` de l'archive 2.8.0.
3. **Ne pas désinstaller** le plugin : lancer **Mettre à jour** dans
   **Configuration > Plugins** afin d'exécuter les migrations additives.
4. Vider le cache :

   ```bash
   php bin/console cache:clear
   ```

5. Se déconnecter/reconnecter ou changer de profil si les droits/menu sont
   encore présents en session.
6. Ouvrir **UXConnector > Connections**.

Les anciennes configurations d'authentification sont conservées comme
Connections `Custom REST`. Il suffit d'ouvrir une connexion existante et de
l'associer au type de connecteur voulu lorsqu'il est compatible avec sa méthode
d'authentification.

## Sécurité

- secrets protégés avec le mécanisme de chiffrement GLPI / `glpicrypt.key` ;
- test de Connection réussi uniquement sur HTTP 2xx ;
- destinations HTTP contrôlées contre les scénarios SSRF ;
- interactions administratives protégées par droits GLPI + CSRF natif ;
- webhook stateless protégé par une clé d'interaction 256 bits ;
- champs GLPI cibles bornés par `TargetFieldPolicy` ;
- `entities_id` piloté par la stratégie d'entité, jamais par un mapping libre ;
- garde runtime empêchant qu'une écriture inbound GLPI reparte immédiatement
  vers une interaction outbound dans la même requête ;
- les interactions d'une Connection ne sont pas révélées à un profil qui n'a
  pas le droit `READ` sur les interactions ;
- la clé d'authentification d'un webhook n'est affichée qu'aux profils disposant
  du droit de modification des interactions ;
- une interaction standard sortante exige une entité GLPI fixe comme périmètre
  source afin d'éviter une émission involontaire depuis toutes les entités.

## Limites connues 2.8.0

- pas encore de vérification de signature native propre à chaque fournisseur de
  webhook ;
- pas encore de rattachement automatique Jira Issue → GLPI Ticket au projet
  GLPI correspondant (`glpi_itils_projects`) ;
- pas encore de résolution avancée des conflits concurrents ;
- pas encore de backfill sortant de tous les objets GLPI existants : la sortie
  fonctionne sur événements GLPI add/update ;
- le mapping Jira Ticket → Issue sortant est volontairement minimal, car les
  champs disponibles dépendent du projet, du type d'issue et des écrans Jira.

## Documentation

- `ARCHITECTURE_2.8.0.md`
- `CORRECTIONS_2.8.0.md`
- `VALIDATION_2.8.0.txt`
- historique : `ARCHITECTURE_2.7.0.md`, `CORRECTIONS_2.7.0.md`
- ADR et documentation projet : `docs/`

## Licence

GPL-3.0-or-later — voir `LICENSE`.
