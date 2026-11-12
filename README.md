# UXConnector pour GLPI

**Version de recette 2.18.38 RC11** · HELPLINE by EVERIENCE · Licence GPL-3.0-or-later

UXConnector connecte GLPI à des services externes. Il configure les connexions HTTP, les mappings et les jobs de synchronisation. Selon le modèle, un job peut être exécuté manuellement, planifié, déclenché par un webhook ou par un événement GLPI.

| Prérequis | Valeur |
| --- | --- |
| GLPI | 11.x |
| PHP | 8.2 minimum |
| Répertoire du plugin | `uxconnector` |

## Catalogue

Le package contient **13 connecteurs et 40 définitions de jobs, dont 31 instanciables**. Certains modèles sont désactivés ou conservés pour les jobs historiques.

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
| REST générique | Connexions configurables selon l’usage, sans job préconfiguré. |

## Installation et mise à jour

1. Sauvegarder la base GLPI et le dossier du plugin avant une mise à jour.
2. Extraire le fichier `.tar.gz` sous `<GLPI_ROOT>/plugins/`. Le fichier `setup.php` doit se trouver dans `plugins/uxconnector/`.
3. Dans **Configuration > Plugins**, installer ou mettre à jour UXConnector, puis l’activer. Pour une mise à jour, remplacer intégralement le dossier du plugin après sauvegarde.
4. Créer une connexion avec un compte technique aux droits adaptés, tester son accès et instancier le job en pause. Configurer l’entité cible et les mappings avant le premier lancement.

Les candidates RC2 à RC11 déclarent la même version GLPI. Après remplacement du dossier, exécuter ces commandes depuis la racine GLPI pour appliquer les migrations et enregistrer les nouveaux droits.

```bash
php bin/console glpi:plugin:install --force uxconnector
php bin/console cache:clear
```

Cette migration retire les anciens stockages dédiés et révoque les identifiants des connexions héritées concernées. Les connexions concernées sont désactivées et converties en fiches génériques. Leurs jobs et historiques restent consultables.

Les nouveaux secrets et les secrets remplacés sont liés à l’origine de la connexion et à la méthode d’authentification avant chiffrement. Pour revenir à une version antérieure après une modification de credentials, restaurer la sauvegarde de base prise avant mise à jour.

Dans l’onglet UXConnector du profil GLPI, la ligne **Webhook credentials** donne accès à deux droits distincts, **Reveal** et **Rotate**, désactivés par défaut. L’opérateur doit également pouvoir consulter la connexion et modifier le job dans les entités concernées. Les actions passent par un formulaire protégé et sont journalisées dans `uxconnector-webhook-audit.log`, avec l’action, le job et l’utilisateur.

La rotation du secret de signature invalide immédiatement l’ancien secret. Reporter le nouveau secret dans l’émetteur du webhook pour rétablir ses appels signés.

Les listes de jobs, les fiches et les données d’exécution tiennent compte du périmètre d’entités actif du profil GLPI. La lecture d’un job et de son historique exige l’accès à toutes ses entités cibles. Un job sans périmètre fixe est accessible aux profils qui ont accès à toutes les entités.

## Documentation

### Contrôles de la RC11

- Le webhook entrant est l’unique route machine sans session GLPI. Il accepte POST et exige une signature HMAC liée au job, à l’horodatage et au corps brut. Le traitement s’applique uniquement au job entrant actif associé et à ses cibles configurées. Les droits GLPI et le périmètre d’entités s’appliquent à l’administration des jobs. Le jeton seul est refusé, y compris avec `UXCONNECTOR_REQUIRE_WEBHOOK_HMAC=0`. Une signature déjà reçue est refusée avec HTTP 409.
- [Revue détaillée des huit signaux et recette RC11](docs/SECURITY_RC11.md)
- Les secrets récupérables sont chiffrés avant stockage. La recherche par jeton webhook utilise une empreinte. Les paramètres OAuth2 sont construits en mémoire pour la requête d’authentification.
- Les schémas fixes sont chargés depuis les fichiers SQL livrés via le chargeur GLPI. Le verrouillage `FOR UPDATE` d’un utilisateur emploie une requête préparée et un paramètre entier.
- L’export CSV neutralise les préfixes de formule, y compris leurs variantes pleine chasse, sur les cellules dynamiques. La lecture des réponses de session REST v1 applique une limite de 1 Mio et respecte le délai restant.

- [Notes de version 2.18.38](docs/releases/RELEASE_NOTES_2.18.38.md)
- [Historique des notes de version](docs/releases/)
- [Guide EasyVista](docs/EasyVista_Service_Manager.md)
- [Guide Matrix42](docs/README_Matrix42_UXConnector.md)
- [Historique des changements](CHANGELOG.md)
- [Descripteur de publication](uxconnector.xml)
- [Licence](LICENSE)

Le descripteur cible le tag `v2.18.38-rc.11` et l’archive `uxconnector-2.18.38-rc.11.tar.gz`. Après publication, vérifier le téléchargement avant de transmettre le XML à TECLIB.
