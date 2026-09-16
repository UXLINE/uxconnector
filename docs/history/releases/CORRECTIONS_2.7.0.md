# UXConnector 2.7.0 — corrections et évolutions

## 1. Correction du cadrage produit

La 2.6.0 exposait des presets dans le formulaire de création de jobs. La 2.7.0
rétablit la cible du produit : les intégrations standards sont visibles dans un
**catalogue**, avec des modules/jobs déjà référencés et activables. Le formulaire
REST avancé reste disponible en parallèle.

## 2. Suppression des connaissances éditeur du runtime

`ExecuteSyncJobService` ne devine plus des wrappers tels que `values` ou
`issues` et ne contient plus de branche de pagination propre à une API.

Chaque `SyncJob` persiste explicitement :

- `collection_path` ;
- `pagination_config` ;
- éventuellement `template_id` et `template_version`.

Le moteur applique ensuite ces données de façon générique.

## 3. JSON imbriqué

`JsonPathValueReader` remplace les lookups plats de type `$item[$field]` et est
réutilisé par le mapping, l'extraction de collection et les stratégies de
pagination.

## 4. Pagination déclarative

Ajout des stratégies :

- aucune pagination ;
- offset ;
- cursor ;
- next-link.

Les chemins de métadonnées et paramètres HTTP sont fournis par
`PaginationConfig`.

## 5. Templates et activation

Ajout de :

- `ConnectorTemplate` ;
- `JobTemplate` ;
- `TemplateParameter` ;
- `JsonConnectorTemplateRepository` ;
- `InstantiateJobTemplateService` ;
- écrans Catalogue liste/détail.

La substitution `{{parameter}}` intervient uniquement lors de l'instanciation.
Le `SyncJob` persistant reçoit le `ResourcePath`, les mappings, le
`CollectionPath` et la pagination déjà résolus.

## 6. Catalogue embarqué

Entrées 2.7.0 :

- Jira Cloud ;
- Microsoft Intune ;
- Microsoft Graph ;
- MDM générique REST ;
- ServiceNow ;
- Zabbix ;
- REST générique.

ServiceNow et Zabbix sont volontairement référencés comme **planifiés** plutôt
que de présenter un job comme fonctionnel avant que le moteur requis et les
mappings n'aient été validés.

## 7. Sécurité pagination

Les `next_link` absolus ne sont suivis que s'ils restent sur la même origine et
sous le chemin de base de l'endpoint configuré. Cette vérification évite
qu'une réponse distante ne détourne la requête suivante, accompagnée de son
authentification, vers une autre destination.

## 8. Migration

La migration est additive. Elle ne supprime pas les anciens jobs et ajoute les
colonnes nécessaires aux nouvelles primitives. Les anciens jobs qui comptaient
sur l'autodétection 2.6.0 doivent cependant être revus/recréés depuis le
catalogue pour bénéficier du modèle déclaratif 2.7.0.
