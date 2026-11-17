# Recette UXConnector 2.18.38 RC13

**Consolidation : 17 novembre 2026.**  
**Période des essais rapportés : 12 au 17 novembre 2026.**  
**Conclusion : validation partielle du périmètre exécuté, avec réserves ouvertes et scénarios non testés.**

Ce document consolide les résultats de la recette guidée sur le GLPI Docker local. Il remplace, pour le suivi des résultats, le journal intermédiaire `RECETTE_EN_COURS.md` du kit RC13, qui précédait les essais réels. Les archives livrées ne sont pas modifiées.

Les contrôles réussis ci-dessous sont validés dans leur périmètre précis. Ils ne constituent ni une validation globale de toutes les fonctions du plugin, ni une approbation de TECLIB pour une installation Cloud. Les résultats RC12 restent identifiés comme tels.

## 1. Environnement et méthode

| Élément | Valeur constatée ou déclarée |
|---|---|
| Candidate | UXConnector 2.18.38 RC13 ; version interne 2.18.38 |
| Instance de recette | GLPI Docker local, `http://localhost:8081` |
| Conteneur | `glpi-data-glpi-1` |
| Version GLPI | 11.0.12, relevée par la CLI |
| Version PHP de l’instance | 8.5.12 CLI, relevée par `php -v` |
| Profil restreint testé | Supervisor, profil 7, entité COAXIS 10 |
| Entité racine | HELPLINE, ID 0 |
| Zendesk de recette | `uxlinesupport.zendesk.com`, connexion 8 |
| GLPI Cloud source du lot final | `helpline-datawise.with32.glpi-network.cloud`, connexion 12 |
| Kit HTTP | SEC05 révision 4 ; Python 3.13.5 selon la sortie utilisateur |
| Origine des preuves réelles | Captures, sorties CLI/JSON et confirmations transmises par l’utilisateur |
| Vérifications complémentaires | Lecture des sources RC13, journaux du kit, empreintes des archives et analyse des deux CSV transmis |

Les commandes sur le poste Windows et les actions dans GLPI ont été réalisées par l’utilisateur. L’assistant n’a pas accédé directement à cette instance. Les heures affichées sont conservées comme preuves d’origine, sans supposer que tous les écrans et rapports utilisent le même fuseau.

**Lecture des statuts :**

- **VALIDÉ** : le résultat attendu est observé pour le scénario et le lot indiqués.
- **ÉCHEC** : un comportement attendu n’est pas satisfait ; une éventuelle correction est indiquée séparément.
- **NON TESTÉ** : la preuve réelle demandée manque, même si des simulations ou un retour de production existent.
- **INCONCLUANT** : l’essai n’a pas atteint le contrôle recherché. Un nouvel essai peut ensuite le remplacer.

## 2. Bilan des contrôles

| Réf. | Contrôle | Résultat | Preuve et portée |
|---|---|---|---|
| INS-01 | Migration forcée, cache, activation et conservation des connexions/jobs | VALIDÉ sur RC12 | Codes de sortie 0 et contrôle visuel utilisateur. Pas de conversion de cette preuve en migration RC13. |
| INS-02 | Installation RC13 et édition du job 32 avec `asset_identity` | VALIDÉ | Fichiers remplacés, cache vidé, configuration enregistrée sans l’erreur RC12. |
| CON-01 | Tests des connexions Zendesk et GLPI Cloud | VALIDÉ dans les essais rapportés | HTTP 200 et 206. La réussite finale du job 31 complète la preuve de communication Cloud après résolution TLS. |
| CON-02 | Connexion GLPI interne et connexion Jira expirée | NON TESTÉ | Sources indisponibles pour cette recette. |
| ACL-01 | Création/lancement autorisés dans COAXIS sous Supervisor | VALIDÉ dans les essais RC12 | Création et relance rapportées ; les droits d’exécution ne suppriment pas les contrôles d’entité du writer. |
| ACL-02 | Protection d’une cible liée située dans une autre entité | VALIDÉ sur RC12 | Job 30, exécution 1226 : 10 refus, message `Linked target is outside the interaction entity.` |
| ACL-03 | Accès et lancement directs hors périmètre | VALIDÉ | Job 32 actif : lancement refusé sous Supervisor/COAXIS et absence de nouvelle exécution vérifiée. |
| EXE-01 | Interruption puis reprise de la même exécution | VALIDÉ | Exécution 1233 : interrompue/reprenable puis succès, 20 ignorés, aucun échec. |
| EXE-02 | Annulation définitive d’une exécution | VALIDÉ | 1234 annulée ; reprise forcée refusée ; 1235 constitue un nouveau lancement distinct. |
| EXE-03 | Reprise après incident réseau/TLS | VALIDÉ | 1256 finalement en succès, 20 ignorés, 0 création, 0 mise à jour, 0 avertissement, 0 échec. |
| SEC-01 | Webhook signé, signatures invalides, expiration, rejeu et concurrence | VALIDÉ | T01 à T09 réussis sur le job fictif 28 ; détail en section 4. |
| SEC-02 | Accès anonyme aux écrans sensibles | VALIDÉ dans le scénario du kit | T10 et T11 réussis, code global 0. |
| SEC-03 | Consultation du secret interdite au profil restreint | VALIDÉ | Boutons absents et requête directe de consultation refusée côté serveur. |
| SEC-04 | Rotation du secret sous profil restreint | NON TESTÉ côté serveur | Absence du bouton observée ; aucune tentative directe de rotation exécutée. |
| SEC-05 | Action de session sans jeton CSRF | VALIDÉ | Action de consultation refusée sous Super-Admin sans le jeton attendu. |
| ZD-01 | Création du ticket, récupération et lecture de la pièce jointe | VALIDÉ après résolution de la collision décrite en section 8 | 1241 : ticket GLPI 9939 créé, fichier lisible. |
| ZD-02 | Récupération du texte d’un suivi avec image | VALIDÉ avec les prérequis utilisateurs | Texte et image récupérés après import de l’auteur Zendesk manquant. |
| ZD-03 | Absence de doublon de document ou de suivi lors d’une répétition | VALIDÉ sur les objets testés | Un fichier avant/après le premier rejeu ; puis aucun suivi/document supplémentaire lors des répétitions. |
| ZD-04 | Résolution du groupe Zendesk | VALIDÉ | Après synchronisation des groupes, 1249 en succès, 1 ignoré et 0 avertissement. |
| ZD-05 | Refus réel d’une image hébergée sur un domaine non autorisé | NON TESTÉ | Aucun domaine public de test maîtrisé disponible. |
| GEN-01 | Six scénarios de suivis génériques `itil_followup` | NON TESTÉ | Aucun job générique de suivis disponible. Le chemin spécialisé Zendesk ne remplace pas cette preuve. |
| ENT-01 | Webhook avec entité fixe COAXIS 10 | VALIDÉ | Les deux nouveaux ordinateurs QA du second essai sont dans COAXIS. |
| ENT-02 | Catégorie Zendesk dans l’entité fixe, sans récursivité automatique | VALIDÉ | Job 13, exécution 1253 : catégorie 9287 créée dans COAXIS ; sous-entités non cochées. |
| ENT-03 | Conservation de l’entité des ordinateurs High-Level API | VALIDÉ sur le lot de 20 | Après 1256, mêmes IDs 2151 à 2170 dans l’entité 10 ; liens et cibles présents. |
| ENT-04 | Absence de nouvelle entité pendant ce lot | VALIDÉ sur ce lot | Liste des IDs d’entités identique avant/après. Le cas volontaire d’un nom source inconnu reste non testé. |
| ENT-05 | Télécoms/Réseau/Licences : cibles, lieux, Fields bloquants et Infocom | NON TESTÉ sur la RC13 locale | Jobs/sources absents. Fonctionnement en production INDIGO rapporté par l’utilisateur, sans preuve équivalente dans cette recette. |
| RET-01 | Reprise ciblée après correction d’un prérequis source | VALIDÉ | Avertissement de 1247 : `not_retried` puis `succeeded` après import des groupes. |
| RET-02 | Refus de reprise d’un snapshot masqué | VALIDÉ dans le scénario CLI contrôlé | 1252 : `queued` puis `snapshot_redacted` ; 0 ordinateur portant le nom QA avant/après. |
| CSV-01 | Structure, encodage et contenu d’un export réel | VALIDÉ sur le fichier | CSV 1247 : UTF-8 avec BOM, séparateur point-virgule, 17 colonnes, 1 ligne de données cohérente. |
| CSV-02 | Neutralisation du libellé fictif `=1+1` | VALIDÉ sur le fichier | CSV 1251 : valeur exportée `'=1+1`, précédée d’une apostrophe. |
| CSV-03 | Ouverture et comportement dans un tableur réel | NON TESTÉ | Pas de preuve fournie d’une ouverture dans Excel ou équivalent. |

## 3. Dernier contrôle : job 31, exécution 1256

### Configuration du petit lot

- Connexion : **12**.
- Template : `glpi-hl.assets.computers.inbound`.
- Ressource : `/api.php/Assets/Computer?start=0&limit=1&filter=id%3Dle%3D20`.
- Pagination : `count_offset`, taille de page `1`.
- Cible : `{"type":"fixed","entity_id":10}`.
- Planification automatique : `false`.
- État final déclaré : **job en pause**.

La taille de page de 1 ne limite pas à elle seule le nombre total d’objets. Le filtre sur les IDs source borne ici le lot de recette.

### Résultat avant/après

| Élément | Avant, dernière exécution 1235 | Après, dernière exécution 1256 |
|---|---|---|
| Ordinateurs liés dans l’entité 10 | IDs 2151 à 2170, soit 20 | Mêmes 20 IDs |
| Sources sans lien | Aucune | Aucune |
| Sources dont la cible est indisponible | Aucune | Aucune |
| IDs des entités GLPI | 0, 10, 11, 12, 13, 14, 15, 16, 17, 18 | Liste identique |
| Planification | Désactivée | Désactivée |

**1256 : Succès ; 0 créé, 0 mis à jour, 20 ignorés, 0 avertissement, 0 échec.**

Le résultat valide la conservation de l’entité des cibles et l’absence de création d’entité sur ce lot. Aucun enregistrement contenant volontairement un nouveau nom d’entité source n’a été injecté : la preuve ne couvre pas exhaustivement ce contre-exemple.

La durée affichée, **320 892 secondes**, accompagne une interruption de plusieurs jours entre le 13 et le 17 novembre. Elle ne constitue pas une mesure exploitable du seul temps de traitement après reprise.

### Incident TLS et résolution observée

L’essai initial 1256 s’était arrêté avant traitement avec `Unable to contact the OAuth2 token endpoint`. Le diagnostic du conteneur montrait :

- erreur cURL 60 et HTTP 0 ;
- chaîne présentée par les autorités INDIGO, dont `ROOT-CA-INDIGO` ;
- erreur OpenSSL 19 : certificat auto-signé dans la chaîne.

L’utilisateur a identifié le VPN INDIGO actif. Le 17 novembre, le même contrôle présente une chaîne publique Let's Encrypt YR2 / Root YR / ISRG Root X1 et **`Verify return code: 0 (ok)`**. La reprise réussie de 1256 établit ensuite le rétablissement du traitement réel.

Aucune désactivation de la vérification TLS, modification des secrets ou correction du code du plugin n’est nécessairement déduite de cette résolution ; aucune de ces opérations n’a été réalisée par l’assistant dans cet échange.

## 4. Webhook et droits sur les secrets

### SEC05, job fictif 28

Le premier essai, rapport `rapport-20261113-085918-cdbf20`, est **INCONCLUANT** : le mauvais job avait été activé, T01 répondait HTTP 400 avec `interaction_failed`. Il est exclu des preuves positives.

Après activation du bon job, un essai sans stratégie d’entité explicite réussit et crée deux ordinateurs dans HELPLINE 0. Cela ne prouve pas que l’entité active du navigateur pilote un appel webhook sans session.

Après enregistrement de l’entité fixe 10, la suite complète réussit :

| Test | Résultat HTTP observé |
|---|---|
| T01 : appel signé sans session GLPI | 200 |
| T02 : absence de signature | 400 |
| T03 : signature incorrecte | 400 |
| T04 : corps modifié, signature inchangée | 400 |
| T05 : signature expirée de deux heures | 400 |
| T06 : jeton fictif seul | 400 |
| T07 : répétition exacte de T01 | 409 |
| T08 : méthode GET | 405 |
| T09 : deux envois identiques simultanés | 200 et 409 |
| T10 : accès anonyme à `connections.php` | 302 / 200 / 302 / 200, contrôles du kit réussis |
| T11 : accès anonyme à `webhook.credentials.php` | 302 / 200 / 302 / 200, contrôles du kit réussis |

Rapport de référence annoncé : **`rapport-20261113-094149-b8157a`**, code de sortie **0**. La sortie du kit et les captures ont été communiquées ; le dossier complet du rapport n’a pas été relu intégralement pour cette consolidation.

Les deux ordinateurs créés sont :

- `QA-WEBHOOK-RC13-20261113-084201-e6e9de90`
- `QA-WEBHOOK-RC13-20261113-084201-e6e9de90-DOUBLE`

Ils sont dans COAXIS 10, sans copie supplémentaire ni renommage signalé à la suite des requêtes négatives. Les deux anciens objets de l’essai sans entité fixe restent distincts dans HELPLINE.

### Droits et CSRF

Sous Supervisor/COAXIS, le job 28 est visible, les boutons Reveal/Rotate sont absents et une requête directe de consultation du secret est refusée avec le message de droits insuffisants. Les premiers essais JavaScript sur la mauvaise page n’avaient envoyé aucune requête et ne comptent pas comme preuves serveur.

Sous Super-Admin, une action sans jeton CSRF est refusée avec « L’action que vous avez réalisée n’est pas autorisée. »

La rotation forcée côté serveur n’a pas été testée. Aucun secret réel n’est reproduit dans ce document.

## 5. Zendesk : tickets, fichiers, suivis et catégories

### Ticket 3 et pièce jointe

Job tickets **10**, connexion **8**, cible HELPLINE 0. Le test est borné à `/api/v2/tickets/show_many.json?ids=3`, collection `tickets`, rapprochement `externalid`, identifiant source `id`, import des pièces jointes activé et planification désactivée.

| Exécution | Résultat significatif |
|---|---|
| 1240 | Succès, 1 ignoré ; un ancien ticket d’une autre instance Zendesk portait déjà l’ID externe 3. Voir réserve R1. |
| 1241 | 1 créé, 1 avertissement, 0 échec. Ticket GLPI **9939**, titre « test pièce jointe », entité HELPLINE, ID externe 3. |
| 1242 | 1 ignoré, 1 avertissement ; fichier lisible, **1 avant / 1 après**, sans copie supplémentaire. |
| 1246 et 1247 | 1 ignoré, 1 avertissement ; aucun suivi ni document supplémentaire lors des répétitions. |
| 1249 | Succès, 1 ignoré, 0 avertissement, 0 échec après synchronisation des groupes. |

L’avertissement de 1241 concerne **l’assigné**, `ASSIGNEE_LINK_UNRESOLVED`, ID Zendesk **40561364917533**, et non le demandeur. Le nouveau commentaire **40635759626269**, portant le texte « test capture dans le ticket » et une image, utilise cet auteur. Avant son import, l’image arrive mais le texte du suivi manque. Après synchronisation de l’utilisateur depuis la même connexion, le suivi est récupéré avec la capture.

L’avertissement restant de 1247 concerne le groupe **40561359527709**, `GROUP_LINK_UNRESOLVED`. La synchronisation des groupes permet ensuite le succès sans avertissement de 1249.

La recette confirme l’importance des prérequis utilisateurs et groupes et la possibilité de compléter les éléments associés à un ticket déjà compté comme ignoré.

### Catégorie Zendesk dans COAXIS

Le job **13**, template `zendesk.itilcategories.bootstrap` version 2.14.6, utilise la connexion 8. Pour éviter de rejouer tout le catalogue existant vers une autre entité, un champ Zendesk de test à une seule option a été utilisé :

- Champ : **40642667032221**, « QA RC13 - Entité catégorie ».
- Option : **QA-RC13-CATEGORIE-COAXIS-20261113**.
- Tag : `topic__qa_rc13_coaxis_20261113`.
- Entité temporaire du job : **10**, planification désactivée.

Exécution **1253** : succès, **1 création**, 0 mise à jour, 0 ignoré, 0 avertissement, 0 échec. Catégorie GLPI **9287** créée dans **HELPLINE > Coaxis**, avec **Sous-entités non coché**.

L’utilisateur a confirmé la restauration de la configuration d’origine : champ sujet **360000175518**, pagination curseur avec persistance, entité fixe **0** et job en pause. La planification reste désactivée dans la configuration de restauration proposée. La suppression du champ et de la catégorie QA n’a pas été réalisée dans cet échange.

## 6. Reprises ciblées et CSV

### Reprise depuis la source

L’avertissement du ticket 3 dans l’exécution **1247** passe de `not_retried` à **`succeeded`** après correction du prérequis groupe et action Retry failed items.

L’utilisateur a auparavant relancé par erreur les trois éléments de **1186**, qui sont également passés par `queued` puis `succeeded`. Cette observation n’est pas retenue comme preuve de réparation de l’ancien téléchargement TLS : la source courante et les identifiants numériques peuvent différer de ceux du test historique.

### Snapshot masqué

Les dix snapshots de l’exécution 1226, échecs 2045 à 2054, étaient valides mais non masqués. Ils n’ont pas été utilisés pour simuler un refus de snapshot masqué.

Un scénario CLI contrôlé a ensuite utilisé les services du plugin installé, avec le job fictif 28 en pause. Il a fourni un objet sans identifiant externe, contenant une valeur de secret **fictive** destinée à être masquée.

| Élément | Résultat |
|---|---|
| Exécution | **1252** |
| Nom QA | `QA-SNAPSHOT-RC13-80686555de89` |
| Erreur initiale | `MISSING_EXTERNAL_ID` |
| Snapshot masqué | `true` |
| Reprise | `not_retried` → `queued` → **`snapshot_redacted`** |
| Ordinateurs portant ce nom | **0 avant et 0 après** |

Le refus de reprise du snapshot est validé dans ce scénario. Ce test utilise les services applicatifs en CLI : il ne valide pas l’enregistrement d’un échec par le chemin HTTP webhook complet. Il ne constitue pas non plus une vérification exhaustive de tous les champs cibles susceptibles de recevoir une valeur masquée.

### CSV réels

| Fichier | Taille | Structure et contenu contrôlés |
|---|---:|---|
| `uxconnector-execution-1247-failures.csv` | 430 octets | UTF-8 avec BOM, séparateur `;`, 17 colonnes, 1 ligne ; groupe non résolu et reprise `succeeded`. |
| `uxconnector-execution-1251-failures.csv` | 393 octets | Même structure ; libellé source **`'=1+1`**, neutralisé par apostrophe. |

Pour le second test, le ticket Zendesk fictif **4** avait pour titre `=1+1`. Une configuration temporaire exigeait le champ inexistant `qa_csv_required_absent_rc13`, provoquant l’erreur attendue `REQUIRED_MAPPING_MISSING` dans **1251**. L’utilisateur a ensuite confirmé la restauration de la configuration normale du job 10.

La neutralisation est confirmée dans les octets du CSV pour ce cas précis. Aucun résultat de calcul ou d’ouverture dans Excel n’est revendiqué.

## 7. Cycle d’exécution et droits : repères

- **1233** : interruption avec checkpoint reprenable, puis reprise de la même exécution ; succès, 20 ignorés.
- **1234** : annulation ; tentative de reprise refusée avec `Only an interrupted resumable execution can be queued again.`
- **1235** : nouveau lancement explicite ; succès, 20 ignorés. 1234 reste annulée.
- **1256** : interruption sur transport OAuth2, puis reprise réussie après rétablissement TLS ; lot et entités conservés.
- **Job 32** : une première demande alors que le job était en pause ne testait que le contrôle de statut. L’essai probant a été effectué ensuite sur le job actif : refus `The interaction targets entities outside your active GLPI scope.`, sans création d’exécution.
- **1226 / job 30** : les 10 refus sur cibles liées hors entité sont un résultat attendu du contrôle d’isolation, pas une preuve de bon import métier.

## 8. Réserves ouvertes et incidents de recette

### R1. Collision d’ID externe entre deux instances Zendesk

**ÉCHEC observé de la distinction de provenance ; anomalie ouverte dans cette recette.**

Un ticket GLPI issu d’une autre instance Zendesk portait déjà `externalid=3`. L’import du ticket 3 de l’instance de recette a été compté comme ignoré sans créer le ticket attendu. Après suppression de l’ancien ticket par l’utilisateur, la création attendue a fonctionné.

La lecture de `src/Infrastructure/Sync/Writer/GlpiItemWriter.php` confirme que les ObjectLinks sont recherchés par connexion, mais que la réconciliation initiale peut rechercher un objet par champ de rapprochement et entité, sans critère de provenance de l’instance source. Ce mécanisme explique le risque observé.

**La suppression de l’ancien ticket est un contournement de recette, pas une correction du code.** Aucun correctif de cette collision n’a été livré dans cet échange. La suite doit définir une règle de provenance ou de refus d’adoption ambiguë, puis tester deux instances ayant le même ID numérique et la répétition sans doublon. La suppression de tickets existants n’est pas une procédure à généraliser.

### R2. Persistance des échecs élémentaires du webhook

**Limitation constatée à la lecture des sources RC13 ; à corriger ou qualifier.**

Dans `src/Application/Sync/Service/ProcessInboundWebhookService.php`, l’appel au processeur ne lui transmet pas l’identifiant de l’exécution. `InboundRecordProcessor::recordFailure()` n’enregistre pas d’échec élémentaire lorsque cet identifiant est absent.

Cela limite la traçabilité et la reprise d’échecs individuels issus du webhook HTTP. Le succès des contrôles HMAC et du scénario CLI 1252 ne clôture pas ce point. Aucun correctif n’a été apporté dans cette recette. Le test à ajouter porte sur un véritable appel HTTP signé contenant un objet invalide, avec vérification de la ligne d’échec enregistrée et de son traitement ultérieur.

### R3. Premier import de recette trop large

L’exécution **1227** a créé **2 030 ordinateurs** dans COAXIS, avec 3 ignorés, avant bornage du lot. Les exécutions 1228 et 1230 ont ensuite ignoré 2 033 objets sans nouvelle création rapportée.

Le périmètre initial proposé était trop large. La recette a ensuite été limitée aux IDs source 1 à 20. **Aucun nettoyage de ces copies n’a été effectué par l’assistant.** Toute suppression éventuelle nécessite d’identifier précisément les objets de test ; aucune suppression globale n’est prescrite ici.

### R4. Erreur d’édition RC12 corrigée dans RC13

Le refus injustifié d’éditer un job utilisant `asset_identity` a été reproduit puis corrigé dans `src/Domain/Sync/Entity/SyncJob.php`. La création et la modification utilisent désormais la même validation. L’enregistrement réel du job 32 sur RC13 est confirmé : **réserve levée pour ce scénario**.

La correction n’a pas modifié le schéma, les ObjectLinks ou les droits. Elle ne corrige pas automatiquement R1 ou R2.

## 9. Scénarios non exécutés et limites

Restent **NON TESTÉS en conditions réelles dans cette recette** :

1. Connexion GLPI interne indisponible et connexion Jira expirée.
2. Refus d’un téléchargement d’image depuis un domaine public non autorisé maîtrisé par le testeur.
3. Suivis génériques `itil_followup` : auteur système 0 ; deux sources distinctes sur un même parent ; rejeu sans doublon ; refus du parent hors entité sans notification ; refus d’un auteur non lié/inactif/supprimé/non habilité ; acceptation d’un auteur lié et habilité.
4. Scénarios Télécoms/Réseau/Licences, création des lieux/objets dans l’entité cible, diagnostic Fields bloquant et exhaustif, maintien des données financières dans les Infocom du matériel.
5. Tentative directe de rotation du secret avec un profil sans droit.
6. Injection volontaire d’un nom d’entité source inconnu dans le lot High-Level API.
7. Ouverture réelle du CSV dans un tableur et variantes de formules autres que le cas `=1+1`.
8. Enregistrement et reprise d’un échec individuel via le chemin HTTP webhook complet.

Le fonctionnement en production chez INDIGO, rapporté par l’utilisateur pour certains flux, est une information de contexte. Il ne remplace pas un essai identifié de cette candidate et de ces configurations.

## 10. État de sortie déclaré

| Job / donnée | Dernier état connu dans cet échange |
|---|---|
| Job 31 | **En pause**, planification désactivée, lot source 1 à 20, entité fixe 10 ; dernière exécution 1256 en succès. |
| Job 28 | Dernier état utilisé : en pause, entité fixe 10 ; objets QA et preuve 1252 conservés. |
| Job 13 | Configuration restaurée après 1253 : champ 360000175518, cible fixe 0, job en pause. |
| Job 10 | Configuration restaurée après le test CSV : mappings normaux, source bornée au ticket 3, cible fixe 0, planification désactivée ; dernier état demandé/rapporté en pause. |
| Job 32 et autres jobs | Aucun état final récent vérifié ; ne pas déduire une remise en pause de tous les jobs. |
| Données QA | Ordinateurs webhook, ticket 9939, catégorie 9287 et champ Zendesk QA non nettoyés dans cet échange. |
| Anciennes copies COAXIS | Aucun nettoyage confirmé des objets issus du premier lot trop large. |

Cet inventaire est déclaratif et n’est pas une lecture en temps réel de l’instance.

## 11. Preuves locales et identification des livrables

### Contrôles du kit RC13

Les journaux de `Validation_UXConnector_RC13/controles-executes/` indiquent **24 contrôles locaux/simulés réussis**, dont la régression `asset_identity`, **326 fichiers PHP** vérifiés en syntaxe et PHPCS PSR12 réussi. Le kit rapporte **405 fichiers identiques** entre les contenus ZIP et TAR.GZ.

Environnement de ces contrôles : PHP 8.3.6, cURL 8.14.1, PHPCS 3.13.6 et dépendances GLPI 11.0.11. Il est distinct de l’instance utilisateur GLPI 11.0.12 / PHP 8.5.12.

Les journaux existants ont été relus pour cette consolidation ; la suite complète n’a pas été rejouée le 17 novembre. **PHPUnit complet, PHPStan, audit des dépendances et matrice de compatibilité GLPI/PHP ne sont pas couverts.**

### Empreintes SHA-256 vérifiées le 17 novembre 2026

| Fichier | SHA-256 |
|---|---|
| `uxconnector-2.18.38-rc.13.zip` | `d4f10853e8660ecb9cedda4d80debf31f96e409f6e231c557714770ebedb9a9b` |
| `uxconnector-2.18.38-rc.13.tar.gz` | `15b30e3ef5953d983070b0c5ce1008312b447794c79596eb2e28b21c167d5233` |
| `Validation_UXConnector_RC13.zip` | `9d177471ef0284d99f31d3e239b5db1ac4606126c60a15f495e9783d9ec47d58` |
| `uxconnector-execution-1247-failures.csv` | `eec211712eebe6da3276824ce086f139783a535f5a55a554e0d3bef820bbc562` |
| `uxconnector-execution-1251-failures.csv` | `3973159d0a0ebd6171b847c46fa4abf1ff422b3f08f7dd9c939b72909c17d475` |

Rapport HTTP de référence à conserver sur le poste de recette :

`C:\Users\athebagm\Downloads\Validation_UXConnector_RC13\preuve-instance\rapport-20261113-094149-b8157a`

Conserver également les captures de 1233/1234/1235, du refus du job 32, des droits/CSRF du job 28, du ticket 9939, des reprises 1247/1252, de la catégorie 9287/1253, ainsi que le JSON final de 1256.

## 12. Suites proposées

1. Traiter la collision de provenance Zendesk R1 et qualifier/corriger la persistance des échecs webhook R2, puis exécuter les régressions ciblées.
2. Compléter les scénarios non testés qui appartiennent au périmètre de déploiement envisagé, sur des données fictives et des sources accessibles.
3. Conserver les archives exactes, leurs empreintes, les rapports HTTP et les preuves réelles avec ce bilan pour la revue TECLIB.

**Décision technique documentée : les scénarios marqués VALIDÉ ont leur preuve bornée ; aucune validation globale de la RC13 ou autorisation de déploiement en production n’est prononcée par ce document.**

