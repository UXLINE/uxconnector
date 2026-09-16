# UXConnector 2.8.2 — Correctif de chargement du catalogue

## Symptôme

Sur une Connection historique, le sélecteur **Connector type** ne proposait que `Custom REST` alors que les manifests Jira, Intune, Graph, MDM, ServiceNow et Zabbix étaient bien présents dans `resources/connectors/`.

## Cause

`TemplateServiceProvider::repository()` construisait le chemin avec :

```php
dirname(__DIR__, 2) . '/resources/connectors'
```

Depuis `src/Infrastructure/Template`, cela résout vers `<plugin>/src/resources/connectors`, répertoire inexistant. Le repository retournait donc une liste vide.

## Correction

Le chemin est désormais :

```php
dirname(__DIR__, 3) . '/resources/connectors'
```

qui résout vers `<plugin>/resources/connectors`.

Aucun changement de schéma SQL.
