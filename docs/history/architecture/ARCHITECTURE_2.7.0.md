# UXConnector 2.7.0 — architecture Catalogue / Templates

## Décision

UXConnector conserve un moteur d'intégration générique mais rétablit la couche
produit prévue dans l'expression de besoin : un **catalogue de connecteurs**
préconfigurés et des **jobs standards activables individuellement**.

La frontière fondamentale est la suivante :

> Un `JobTemplate` peut connaître un éditeur. Un `SyncJob` peut contenir des
> valeurs résolues issues de ce template. `ExecuteSyncJobService` ne connaît
> jamais l'éditeur ni les placeholders du template.

## Flux d'activation

```text
resources/connectors/*/connector.json
                 │
                 ▼
        ConnectorTemplateRepository
                 │
                 ▼
            JobTemplate
                 │
       + paramètres administrateur
       + AuthConfig
       + entité GLPI
                 │
                 ▼
      InstantiateJobTemplateService
                 │
      résolution unique des {{paramètres}}
                 │
                 ▼
              SyncJob
                 │
                 ▼
       ExecuteSyncJobService
```

Une exécution planifiée ou manuelle ne relit donc pas le manifest du catalogue.
La suppression ou la mise à jour d'un manifest ne modifie pas silencieusement
un job déjà instancié.

## Phase A — capacités moteur

### CollectionPath

Le `SyncJob` porte le chemin de la collection dans la réponse JSON :

- `$` pour une collection à la racine ;
- `value`, `values`, `issues`, `result`, etc. uniquement lorsque le template
  ou l'administrateur le déclare ;
- les noms de ces clés ne sont pas codés dans le moteur.

### JsonPathValueReader

Même lecteur pour :

- les champs de mapping : `fields.summary` ;
- les métadonnées de pagination : `nextPageToken` ;
- les clés spéciales : `$['@odata.nextLink']` ;
- les tableaux : `attachments[0].filename`.

### PaginationStrategy

`PaginationConfig` sélectionne une stratégie :

- `none` ;
- `offset` ;
- `cursor` ;
- `next_link`.

Les chemins de réponse et noms de paramètres HTTP sont fournis par la
configuration. Une stratégie ne connaît donc aucune API particulière.

Les liens absolus `next_link` sont normalisés avant le prochain appel. Le
plugin refuse un changement d'origine ou une sortie du chemin de base de
l'endpoint d'authentification afin de ne pas propager des credentials vers une
URL étrangère.

## Templates

### ConnectorTemplate

Décrit l'entrée de catalogue : identifiant, version, nom, icône, indications
non secrètes de connexion, méthodes d'authentification supportées et liste de
`JobTemplate`.

### JobTemplate

Décrit une recette déclarative :

- nom/version ;
- direction ;
- resource path ;
- collection path ;
- pagination ;
- cible GLPI ;
- champ de rapprochement ;
- identifiant externe ;
- mappings ;
- paramètres administrateur.

### Paramètres

La syntaxe `{{parameter}}` est uniquement une syntaxe de manifest. Les filtres
supportés à l'instanciation sont `upper`, `lower` et `urlencode`.

Après instanciation il ne reste aucun placeholder dans le `SyncJob`.

## Provenance et mises à jour

Le `SyncJob` conserve `template_id` et `template_version` comme métadonnées.
Elles permettent de distinguer un job catalogue d'un job personnalisé et
préparent une future gestion de versions de templates. Elles ne sont pas une
dépendance d'exécution : un job existant reste autonome.

## Transformations spécifiques

Une transformation spécifique à un format fournisseur ne doit pas être ajoutée
au `FieldValueTransformer` générique sous forme de branche conditionnelle.
Elle implémente `FieldTransformInterface` et est fournie par le bounded context
Template/Infrastructure au registre de transformations.

## Roadmap

- **Phase A (2.7.0)** : catalogue, instanciation, JSON paths, collection path,
  pagination déclarative, jobs standards minimaux.
- **Phase B** : value mappings et transformations métier enrichies (statuts,
  priorités, formats riches, valeurs par défaut/constantes plus structurées).
- **Phase C** : résolution inter-jobs via l'`ObjectLink` existant pour établir
  les relations entre objets synchronisés (par exemple ticket ↔ projet).
- **Phase D** : outbound/bidirectionnel, conflits, checkpoints et prévention de
  boucles.
