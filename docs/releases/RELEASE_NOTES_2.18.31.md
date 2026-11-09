# UXConnector 2.18.31 candidate pour INDIGO

Date : 2 novembre 2026. Cible demandée : GLPI 11.0.11 ; sources GLPI 10.0.28 et Zendesk. PHP requis par le plugin : 8.2 ou supérieur. Contrôles hors ligne exécutés sous PHP 8.3.6. Les versions exactes de Fields et le fonctionnement dans les conteneurs INDIGO restent à valider sur une copie de la cible.

Base utilisée : `uxconnector-2.18.29.zip`, fourni dans cette conversation, SHA-256 `d18d6370dacfec4bb65b91b716b913caed1faba1455c476d8c0b6dcdce4b62cf`. Aucun export CSV ni capture client n'est embarqué. Aucun accès au serveur client n'a été utilisé.

## 1. Protection des données consolidées

La politique entrante est désormais conservatrice et s'applique immédiatement aux jobs existants, sans réécrire leurs mappings :

| Situation | Comportement |
| --- | --- |
| Objet nouveau | Création avec les données mappées, après contrôles |
| Objet existant | Compléter les champs réellement vides |
| Champ cible renseigné | Conserver la valeur cible, même si la source a changé |
| Source vide | Ne pas effacer la cible |
| Référence native à zéro | Peut être complétée, sauf `entities_id=0` qui désigne réellement la racine |
| Valeur numérique zéro ou booléen Non | Valeur renseignée, conservée, même si elle provenait d'un défaut |
| Rapprochement ambigu ou objet lié indisponible | Refuser l'écriture concernée ; ne pas deviner un autre objet |

Cette version ne sait pas distinguer rétrospectivement une modification humaine d'une ancienne valeur importée. Elle ne revendique pas de fusion à trois versions. Une évolution source ne remplace donc pas automatiquement une valeur cible remplie. Si un remplacement est nécessaire, il doit être décidé explicitement dans une opération distincte.

La protection concerne les écritures entrantes des profils retenus, pas les mises à jour LDAP, les autres plugins ou les jobs sortants. Elle ne restaure pas les données éventuellement écrasées par les anciennes versions. Les associations et documents existants ne sont pas purgés.

## 2. Équipements réseau

Les sept labels Inpark canoniques sont acceptés, avec les sept alias exacts `Code Inpark ...` présents dans le correctif de compatibilité. Aucune modification des libellés ou noms techniques Fields n'est exécutée. Si deux champs actifs représentent le même attribut canonique et son alias, le traitement refuse l'ambiguïté.

Un ObjectLink existant identifie le matériel à reprendre, même si son lieu a été corrigé manuellement. Sans lien fiable, le contrôle de numéro de série unique et de cohérence du site est conservé : `SERIAL_ALREADY_ATTACHED_TO_DIFFERENT_SITE` ne devient pas une autorisation de déplacer un matériel.

Les 16 champs du bloc observé `INDIGO Réseau` sont pris en charge : Entité Inpark, Code DA, Code DA Comptable, Code ETS, Code DB, Code NA, Code NB, Date de mise en service, Date ventilation, Déjà Amorti, Date retour Stock, Montant HT, Montant Mensuel, ID source GLPI 10, Source Last update, Source Creation date. Les métadonnées de dates source sont récupérées seulement si une option native non ambiguë est exposée par l'API. Les horodatages conservés dans Fields sont des informations d'origine ; un champ déjà rempli n'est pas réécrit à chaque passage.

Le bloc doit être actif, associé à NetworkEquipment et ses champs modifiables. Les deux champs Lieu déjà requis, IdBaseParcs et Début Exploitation, restent nécessaires pour les sites. Le montant Fields conserve la donnée source indépendamment de la valeur Infocom consolidée. Les champs de date de mise en service natifs et Fields ne sont pas assimilés automatiquement.

Un lien de matériel est enregistré avant son enrichissement Fields pour permettre une reprise après échec partiel. Les erreurs du profil indiquent une étape, un code contrôlé et, si connu, l'ID cible. Les messages SQL et les valeurs métier ne sont pas copiés dans ces diagnostics.

## 3. Microsoft : licences individuelles

Le modèle suit les lieux d'affectation présentés dans l'export source :

- Un logiciel commun par produit, recherché de façon non ambiguë à la racine. Un logiciel manquant est créé via l'API native.
- Une licence native distincte par affectation source. Son nom initial contient le produit, le bénéficiaire et l'ID source ; ce nom n'est pas sa clé de reprise.
- Une clé ObjectLink par connexion et ID source, sous le type `glpi_rest_v1_indigo_microsoft_license`. Les champs individuels ne sont pas empilés sur une licence partagée.
- `Nombre=1` représente l'unité affectée de cette ligne. Ce n'est pas une déduction du nombre de licences achetées ou d'un stock commercial disponible.
- Les lignes sans produit sont ignorées comme dossiers de classement. Un produit avec bénéficiaire vide est rejeté.
- Le champ texte Utilisateur est conservé ; aucun compte natif n'est deviné à partir du seul nom ou d'un matricule non unique. Une association utilisateur déjà consolidée sur la licence est conservée.

Les 21 champs du bloc actif `INDIGO Microsoft`, associé à SoftwareLicense, doivent être disponibles : Matricule, Fonction, Date d'activation, Prix annuel, Prix mensuel, Date de désactivation, Chemin hiérarchique source, ID lieu source GLPI 10, Lieu parent historique, Dernière modification source, Date création source, Code DA, Code DA Comptable, Code ETS, Code DB, Code NA, Code NB, Entité, Licences, Utilisateur, Statut. Les variantes de casse et d'apostrophe des deux libellés de dates sont acceptées sans renommer le schéma. Le parent et les horodatages sont facultatifs côté découverte source.

Dates françaises et montants avec virgule sont contrôlés et convertis. Entité Fields est résolue par libellé dans la liste cible ; un identifiant numérique d'option source n'est jamais pris pour un identifiant cible. Le libellé numérique `10595` reste un libellé et peut correspondre à un tout autre ID cible.

### Coexistence avec l'ancien modèle

Les anciennes affectations UXConnector et licences communes ne sont ni supprimées ni converties. Si une ligne source est déjà enregistrée dans l'ancien modèle de pools, le code `MICROSOFT_LEGACY_POOL_REVIEW_REQUIRED` bloque sa conversion automatique. Une conversion doit être préparée avant de relancer ces lignes. Cela évite un changement silencieux des quantités et des rattachements.

Contrôler aussi les pools préparés manuellement, même sans ObjectLink : additionner leurs quantités à celles des nouvelles licences individuelles pourrait compter deux fois le même périmètre. Aucun rapprochement automatique sur le seul nom d'une licence ne remplace cette vérification.

## 4. Zendesk

### Tickets et consolidation

Les nouveaux tickets utilisent leurs mappings et leur stratégie d'entité. Sur les tickets existants, les valeurs renseignées sont préservées : titre, description, statut, catégorie, entité et autres champs mappés. Les catégories vides peuvent être complétées depuis les ObjectLinks du champ Sujet. Une catégorie déjà remplie n'exige plus de résoudre une ancienne organisation source pour être conservée.

Un rôle déjà occupé (demandeur, technicien ou groupe affecté) est conservé. Un rôle vide peut être complété depuis un lien valide. Les suivis textuels continuent à être importés avec auteur mappé, date, visibilité publique ou privée et déduplication par ID du commentaire. Les suivis existants ne sont pas réécrits.

Les utilisateurs, groupes et catégories doivent être synchronisés avant les tickets. Les auteurs sans correspondance continuent à produire un avertissement ; aucun auteur fictif n'est créé. Pour INDIGO, le champ Sujet peut rester configuré par `pagination.options.zendesk_topic_field_id`, sans modifier cet ID lors du réglage de périodicité.

### Pièces jointes et captures

Le téléchargement est désactivé par défaut, y compris pour les jobs déjà enregistrés. Il n'y a alors ni requête de téléchargement ni création de document ; les fichiers existants restent en place. Les commentaires composés uniquement de fichiers reçoivent une mention indiquant que leur téléchargement est désactivé ou en attente.

La case du formulaire permet une réactivation explicite, stockée dans `pagination.options.zendesk_download_attachments=true`. Les contrôles TLS, SSRF, MIME, redirections et déduplication sont maintenus. Le contenu non vide d'un ticket ou suivi est préservé même lors de cette réactivation : les fichiers peuvent être liés comme documents sans reconstruire les images inline dans un corps déjà renseigné.

Le certificat d'autorité INDIGO doit rester approuvé dans les conteneurs et workers pour les téléchargements réactivés. Le plugin ne corrige pas une chaîne TLS non approuvée et ne désactive pas la validation du certificat. Les fichiers générés Fields manquants sont également à réparer dans Fields, pas par suppression/recréation automatique dans UXConnector.

### HTTP 422 et curseurs

Pour le profil de reprise initiale `zendesk.tickets.nonclosed.inbound`, un HTTP 422 sur Search Export avec `page[after]` entraîne au maximum un redémarrage sans ce curseur. Le filtre et la taille de page sont conservés. Les données déjà rencontrées repassent par la réconciliation habituelle.

Un second rejet de curseur arrête l'exécution. Un 422 sans curseur n'est jamais redémarré automatiquement. Le code ne prétend pas que chaque HTTP 422 provient d'une expiration ; une requête invalide doit rester visible. Les messages n'exposent plus le curseur ni la réponse brute du serveur.

La durée peut encore dépasser la validité d'un curseur Search Export. La suppression des téléchargements réduit le travail mais n'est pas une garantie de performance. Une acquisition durable découplée des enrichissements n'est pas ajoutée ici. Le flux delta incrémentiel existant reste distinct. Les compteurs peuvent inclure des tentatives rejouées lors du redémarrage, sans que cela signifie autant d'objets uniques créés.

Référence officielle : https://developer.zendesk.com/api-reference/ticketing/ticket-management/search/

## 5. Utilisateurs et email

Le mapping explicite `email -> email` remplit une adresse manquante via UserEmail et répare un enregistrement email natif vide. Une adresse existante, ses alias et son défaut sont conservés, y compris ceux consolidés par LDAP. Les valeurs invalides sont refusées avant modification du compte. Les logins, noms, prénoms et téléphones déjà remplis restent intacts.

Les jobs déjà enregistrés ne sont pas réécrits par le changement de catalogue. Ajouter le mapping email dans l'interface si nécessaire. Zendesk ne fournit pas le sAMAccountName dans les données observées ; cette version n'invente pas d'identifiant AD et ne revendique pas de rapprochement fiable sur le nom complet.

## 6. Périodicité et simultanéité

Suspendre le job, ouvrir Modifier, puis régler la section Planification du job :

- Passages automatiques activés ou non, indépendamment d'un lancement manuel.
- Intervalle de 5 à 43200 minutes, par défaut 60, mesuré après la fin du dernier passage, même manuel.
- Premier passage au plus tôt, facultatif, saisi explicitement en UTC. Le 2 novembre, 06:00 UTC correspond à 07:00 à Paris.
- Nouvelle tentative planifiée après échec ou interruption désactivée par défaut. Un lancement manuel reste possible.

Ces valeurs sont stockées dans les options de pagination existantes ; aucune nouvelle table n'est ajoutée. Le formulaire conserve les options métier et de pagination présentes. L'action SyncJob est enregistrée à une fréquence de 60 secondes ; le cron GLPI externe doit effectivement passer régulièrement pour que la cadence soit appliquée.

Les protections 2.18.29 sont conservées : file asynchrone, refus de deux exécutions ouvertes du même job, verrous de propriété par job et flux, capacité partagée et reprise du watchdog respectant un worker vivant. Les flux indépendants peuvent toujours travailler en parallèle.

Depuis la racine GLPI, sous le compte système habituel :

```sh
php bin/console plugins:uxconnector:worker --no-interaction
```

Chaque invocation consomme au plus un job et se termine. Prévoir une tâche récurrente ou un superviseur et plusieurs invocations indépendantes pour exploiter le parallélisme. Ne pas exécuter les transferts longs depuis un navigateur.

La limite de workers est contrôlée par la variable serveur `UXCONNECTOR_MAX_CONCURRENT_JOBS`, de 1 à 8, défaut 2. Pour empêcher tout chevauchement entre jobs différents pendant la recette, fixer 1 sur tous les workers. Décaler les dates et intervalles ne garantit pas, à lui seul, l'absence de chevauchement de jobs longs indépendants.

## 7. Installation et recette avant transmission pour production

1. Sauvegarder la base, les fichiers GLPI, les configurations nécessaires à sa restauration, le répertoire UXConnector actuel et les fichiers générés de Fields. Préparer une copie de recette représentative.
2. Suspendre les jobs et arrêter ou laisser terminer les workers. Ne pas remplacer le code sous un transfert actif.
3. Remplacer uniquement le répertoire `uxconnector` par celui du ZIP. Ne pas désinstaller le plugin. Éviter deux copies actives dans plugins et marketplace. Appliquer la mise à jour proposée par GLPI ; vérifier la version 2.18.31.
4. Conserver les configurations, ObjectLinks et checkpoints. Vérifier les blocs Fields actifs, leurs types associés et leurs classes générées. Si un contrôle Fields échoue, réparer son installation avant le job. Aucune suppression de champ n'est requise par cette livraison.
5. Régler les cadences des jobs, laisser les PJ Zendesk décochées, puis réactiver seulement le périmètre pilote. La nouvelle politique de mise à jour ne nécessite pas de refaire les connexions.
6. Sur un petit périmètre réseau : comparer fiche native, lieu, modèle, type, Infocom et Fields ; modifier manuellement un modèle, un lieu, un code et un montant, puis rejouer. Vérifier les mêmes IDs, les valeurs conservées et le complément des champs réellement vides.
7. Sur Microsoft : contrôler deux bénéficiaires du même produit, le classement sans produit, la quantité affectée, les 21 champs et les rapprochements. Rejouer, puis simuler un échec Fields et une reprise. Vérifier l'absence de licence individuelle en double. Arrêter et examiner les codes de coexistence avec les anciens pools.
8. Sur Zendesk : bootstrap utilisateurs/groupes/catégories ; contrôler demandeur, affectations, catégorie, texte et confidentialité des suivis. Rejouer et vérifier l'absence de doublons, les corrections conservées et aucun nouveau document lorsque les PJ sont désactivées.
9. Vérifier deux workers, une tentative de doublon du même job, une cadence quotidienne, un échec interrompant les relances automatiques et le fonctionnement normal de l'interface pendant un transfert.
10. Après validation sur cette copie, programmer la mise à niveau client. En cas de retour arrière, arrêter les workers et restaurer un état cohérent base/fichiers/plugin depuis la sauvegarde. Remettre seulement le code 2.18.29 réactiverait son ancienne politique d'écrasement et ne supprime pas les licences déjà créées.

## 8. Vérifications fournies et limites

Commande depuis le répertoire du plugin :

```sh
sh tools/preflight-2.18.31.sh
```

Le journal et le périmètre exact sont dans `VALIDATION_2.18.31.md` et `validation-2.18.31.txt`. Les contrôles combinent les régressions historiques, les simulations natives et les tests de nouveaux comportements. Le PHPStan et la suite PHPUnit complète ne sont pas revendiqués comme exécutés. Aucun test connecté à GLPI/Fields/MySQL/LDAP/Zendesk INDIGO, aucune recette navigateur GLPI et aucun audit externe ne sont revendiqués. Cette archive est une candidate de recette, pas une garantie d'absence totale de défaut.
