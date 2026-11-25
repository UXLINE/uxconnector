# UXConnector pour GLPI

**Version stable 2.18.39** · HELPLINE by EVERIENCE · Licence GPL-3.0-or-later

[Télécharger l’archive installable](https://github.com/UXLINE/uxconnector/releases/download/v2.18.39/uxconnector-2.18.39.tar.gz) · [Notes de version](docs/releases/RELEASE_NOTES_2.18.39.md) · [XML de production](https://raw.githubusercontent.com/UXLINE/uxconnector/refs/heads/production/uxconnector.xml)

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

**Complément du 24 novembre 2026, révision `audit-20261124.1` : numéro maintenu à 2.18.39.** Si 2.18.39 est déjà installée, relancer explicitement `plugin:install --force` après remplacement des fichiers. Ne pas désinstaller. La [procédure et la réponse complète aux remarques TECLIB](docs/SECURITY_2.18.39_FOLLOWUP.md) détaillent la migration, le rapport des jobs actifs et la fermeture de l’API CRUD des objets internes.

**Anciens webhooks :** la migration de clés historiques génère de nouveaux secrets de signature. Le bearer/jeton seul est refusé. Le droit **Webhook credentials** n’est attribué automatiquement à aucun profil : déléguer Reveal/Rotate et reconfigurer les émetteurs concernés avant leur remise en service. Une installation déjà migrée ne subit pas de nouvelle rotation automatique.

1. Sauvegarder la base GLPI et le dossier du plugin avant une mise à jour.
2. Extraire le fichier `.tar.gz` sous `<GLPI_ROOT>/plugins/`. Le fichier `setup.php` doit se trouver dans `plugins/uxconnector/`.
3. Dans **Configuration > Plugins**, installer ou mettre à jour UXConnector, puis l’activer. Pour une mise à jour, remplacer intégralement le dossier du plugin après sauvegarde.
4. Créer une connexion avec un compte technique aux droits adaptés, tester son accès et instancier le job en pause. Configurer l’entité cible et les mappings avant le premier lancement.

La version 2.18.39 déclenche une mise à niveau depuis les RC2 à RC13, qui déclaraient toutes 2.18.38. Arrêter les workers de synchronisation et les émetteurs webhook pendant le remplacement des fichiers et la migration. Sauvegarder aussi la clé GLPI `config/glpicrypt.key` avec la base. Ne pas désinstaller le plugin.

La migration lie les anciens secrets à l’origine (protocole, hôte et port) et à la méthode d’authentification actuellement enregistrées, puis les rechiffre avec GLPIKey. Vérifier ces destinations avant la mise à niveau. Un secret déjà lié n’est jamais rattaché à une autre origine. Une erreur de déchiffrement, de vérification ou d’écriture annule cette migration et bloque la mise à niveau. Après correction de la cause, la migration peut être rejouée.

La migration des secrets conserve les statuts des connexions. Les anciens retraits de stockages spécifiques restent applicables lors d’une mise à niveau depuis une version antérieure à la RC13. Un retour arrière nécessite la restauration cohérente du code, de la base et de la clé sauvegardés.

Les modifications, tests et changements de statut d’une connexion exigent désormais l’accès à toutes les entités ciblées par ses interactions, même en pause. Les écritures directes sur `AuthConfigItem` via l’API générique GLPI sont refusées ; utiliser les écrans et services du plugin.

Dans l’onglet UXConnector du profil GLPI, la ligne **Webhook credentials** donne accès à deux droits distincts, **Reveal** et **Rotate**, désactivés par défaut. L’opérateur doit également pouvoir consulter la connexion et modifier le job dans les entités concernées. Les actions passent par un formulaire protégé et sont journalisées dans `uxconnector-webhook-audit.log`, avec l’action, le job et l’utilisateur.

La rotation du secret de signature invalide immédiatement l’ancien secret. Reporter le nouveau secret dans l’émetteur du webhook pour rétablir ses appels signés.

Les listes de jobs, les fiches et les données d’exécution tiennent compte du périmètre d’entités actif du profil GLPI. La lecture d’un job et de son historique exige l’accès à toutes ses entités cibles. Un job sans périmètre fixe est accessible aux profils qui ont accès à toutes les entités.

## Documentation

- [Correctifs de sécurité et mise à niveau 2.18.39](docs/SECURITY_2.18.39.md)

- [Correction de la modification des jobs asset_identity](docs/releases/RELEASE_NOTES_2.18.38_RC13.md)
- [Correctifs et précautions de la RC12](docs/SECURITY_RC12.md)

### Contrôles hérités de la RC11

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

La branche `production` distribue la version stable 2.18.39. Le descripteur référence le tag `v2.18.39` et l’archive installable `uxconnector-2.18.39.tar.gz`. L’autorisation de distribution sur le Marketplace privé reste administrée par TECLIB.
