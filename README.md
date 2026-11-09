# UXConnector pour GLPI

**Version publiée [2.18.37](https://github.com/UXLINE/uxconnector/releases/tag/v2.18.37)** · HELPLINE by EVERIENCE · Licence GPL-3.0-or-later

UXConnector connecte GLPI à des services externes. Il configure les connexions HTTP, les mappings et les jobs de synchronisation. Selon le modèle, un job peut être exécuté manuellement, planifié, déclenché par un webhook ou par un événement GLPI.

| Prérequis | Valeur |
| --- | --- |
| GLPI | 11.x |
| PHP | 8.2 minimum |
| Répertoire du plugin | `uxconnector` |

## Catalogue

Le package contient **14 connecteurs et 40 définitions de jobs, dont 31 instanciables**. Certains modèles sont désactivés ou conservés pour les jobs historiques.

| Connecteur | Périmètre |
| --- | --- |
| EasyVista Service Manager | 5 imports pilotes. Le modèle de création sortante est désactivé. |
| Jira Cloud | Projets et issues, échanges entrants et sortants, webhook pour les issues. |
| Jira Service Management Cloud | 2 imports pilotes pour incidents et demandes. |
| Matrix42 | 2 imports pilotes. Les 2 modèles sortants sont désactivés. |
| Zendesk Support | Imports de référentiels et de tickets, avec modèles de reprise et de delta selon le job. |
| GLPI REST v1 et GLPI High-Level API | Modèles de migration ou d’import d’objets GLPI. |
| Microsoft Intune, Microsoft Graph et MDM REST | Modèles pour les postes gérés et les utilisateurs. |
| ServiceNow et Zabbix | Définitions désactivées dans cette version. |
| REST générique et API compatible OpenAI | Connexions configurables selon l’usage, sans job préconfiguré. |

## Installation et mise à jour

1. Sauvegarder la base GLPI et le dossier du plugin avant une mise à jour.
2. Extraire le ZIP sous `<GLPI_ROOT>/plugins/`. Le fichier `setup.php` doit se trouver dans `plugins/uxconnector/`.
3. Dans **Configuration > Plugins**, installer ou mettre à jour UXConnector, puis l’activer. Pour une mise à jour, conserver l’installation existante et remplacer ses fichiers.
4. Créer une connexion avec un compte technique aux droits adaptés, tester son accès et instancier le job en pause. Configurer l’entité cible et les mappings avant le premier lancement.

## Documentation

- [Notes de version 2.18.37](docs/releases/RELEASE_NOTES_2.18.37.md)
- [Historique des notes de version](docs/releases/)
- [Guide EasyVista](docs/EasyVista_Service_Manager.md)
- [Guide Matrix42](docs/README_Matrix42_UXConnector.md)
- [Historique des changements](CHANGELOG.md)
- [Descripteur de publication](uxconnector.xml)
- [Licence](LICENSE)

Le descripteur cible le dépôt GitHub `UXLINE/UXConnector`, le tag `v2.18.37` et l’actif `uxconnector-2.18.37.zip`. Après publication, vérifier que cette URL donne accès au ZIP prévu avant de transmettre le XML à Teclib.

# Architecture et principes de développement

UXConnector repose sur une **Clean Architecture inspirée des principes de l’architecture hexagonale et du Domain-Driven Design (DDD)**.

L’objectif est d’isoler au maximum la logique métier des dépendances techniques telles que GLPI, les API externes, OAuth, HTTP ou les mécanismes de persistance.

L’architecture suit notamment les principes suivants :

- séparation claire entre `Domain`, `Application`, `Infrastructure` et couches de présentation ;
- dépendances orientées vers le domaine métier ;
- application des principes **SOLID** et **Dependency Inversion** ;
- isolation des connecteurs et intégrations externes derrière des composants dédiés ;
- limitation du couplage direct entre la logique métier et GLPI ;
- règles métier et politiques de sécurité placées dans les couches adaptées ;
- architecture pensée pour rester testable, maintenable et extensible.

Le projet applique une approche **DDD principalement tactique** : les règles métier, policies, services et objets du domaine sont séparés des implémentations techniques. UXConnector ne revendique toutefois pas l’application exhaustive de l’ensemble des patterns du DDD stratégique.

## Stratégie de tests

UXConnector ne se définit pas comme un projet développé intégralement selon un **TDD strict** historique.

La stratégie actuelle repose sur une combinaison de :

- tests unitaires ;
- tests de sécurité et de non-régression ;
- tests hors ligne des connecteurs à partir de fixtures ;
- tests d’idempotence et de replay ;
- tests de pagination et de volumétrie ;
- tests d’intégration dans un environnement GLPI dédié lorsque celui-ci est disponible ;
- validation live des connecteurs externes lorsque nécessaire ;
- préflight de release reproductible avant publication.

Pour les nouvelles fonctionnalités et les corrections critiques, l’approche tend vers un développement **test-first**, complété par une politique systématique de tests de non-régression.

## DevSecOps et qualité

Le cycle de développement intègre progressivement une démarche **DevSecOps** comprenant notamment :

- contrôles SSRF et validation des destinations HTTP ;
- authentification des webhooks ;
- protection des credentials ;
- allowlists sur les types d’objets et champs GLPI inscriptibles ;
- recherche de secrets avant publication ;
- contrôles Git et cohérence des versions ;
- Quality Gates ;
- préflight de release ;
- génération reproductible des artefacts ;
- traçabilité entre commit, tag, archive et release.

L’objectif est de garantir qu’une version publiée soit à la fois :

- fonctionnellement validée ;
- sécurisée ;
- testée ;
- reproductible ;
- traçable ;
- directement exploitable dans un environnement GLPI supporté.
