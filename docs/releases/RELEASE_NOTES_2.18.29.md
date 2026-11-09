# UXConnector 2.18.29 candidate

Date : 28 octobre 2026. Base : 2.18.28 candidate. Cible : GLPI 11, PHP 8.2 ou supérieur.

## Correctifs inclus

- Interconnexions réseau et licences Microsoft : conservation du correctif 2.18.28. La découverte Location utilise les métadonnées brutes et distingue le nom complet du lieu de la relation vers un lieu parent. Les numéros 1 et 13 ne sont pas des identifiants de lieux. Une réelle ambiguïté reste bloquante.
- Captures et pièces jointes Zendesk : conservation des correctifs 2.18.27, notamment réutilisation des documents dédupliqués par GLPI, redirections authentifiées sur la même origine, téléchargement anonyme sur le CDN, reprise et traitement des erreurs partielles.
- Jobs : les lancements manuels restent en file. Le planificateur des jobs actifs met désormais aussi en file, sans lancer les transferts dans sa propre action automatique. Une commande CLI permet de démarrer plusieurs workers indépendants.
- Utilisateurs : une cible de mapping `email` est maintenant autorisée. Elle utilise `UserEmail::add/update`, avec validation native GLPI, transaction courte et verrou de ligne utilisateur. L'adresse par défaut est modifiée ou créée ; les autres adresses sont conservées. Une adresse déjà présente est promue sans doublon. Une valeur vide ou absente ne supprime rien. Une valeur invalide est refusée avant modification des champs utilisateur. Les erreurs ne reproduisent pas l'adresse source.

## Mise à niveau

1. Sauvegarder la base GLPI, les documents et le répertoire actuel du plugin. Arrêter ou terminer les jobs et workers avant remplacement.
2. Remplacer le répertoire `uxconnector` par celui de l'archive, sans désinstaller le plugin. Appliquer la mise à jour proposée dans GLPI. Cette version n'ajoute aucune table ni colonne.
3. Garder les actions automatiques UXConnector en mode CLI/externe et le cron GLPI habituel. Ne pas lancer les transferts longs depuis l'action forcée dans le navigateur.
4. Vérifier le mapping des jobs utilisateurs déjà enregistrés, décrit ci-dessous. Les configurations existantes ne sont pas réécrites automatiquement.
5. Effectuer les essais de préproduction décrits en fin de document avant un passage complet.

## Exécution simultanée

Commande à lancer depuis le répertoire GLPI, avec le compte système qui exécute habituellement GLPI :

```sh
php bin/console plugins:uxconnector:worker --no-interaction
```

Une invocation traite au plus un job en attente puis quitte. Si le premier job est déjà détenu, le worker cherche un autre job parmi les 100 premières entrées en attente. Les tentatives de retry ciblé restent traitées uniquement par l'action GLPI `ExecutionQueue`.

Pour une exécution automatique en parallèle, configurer deux entrées dans le crontab du compte système GLPI. Exemple de chemins à adapter à l'installation :

```cron
* * * * * /usr/bin/flock -n /var/lib/glpi/_lock/uxconnector-worker-1.lock /usr/local/bin/php /var/www/glpi/bin/console plugins:uxconnector:worker --no-interaction
* * * * * /usr/bin/flock -n /var/lib/glpi/_lock/uxconnector-worker-2.lock /usr/local/bin/php /var/www/glpi/bin/console plugins:uxconnector:worker --no-interaction
```

Le répertoire de verrou doit exister, être privé à l'exploitation GLPI et être accessible en écriture au compte choisi. `flock` protège chaque entrée cron d'un empilement de processus. Les verrous MySQL protègent également des processus lancés sur d'autres machines partageant la base. Dans Docker, installer ces entrées dans le service d'exécution ou utiliser deux services workers supervisés avec la même configuration GLPI et les mêmes certificats. Le plugin ne crée aucun processus shell et aucun endpoint HTTP de lancement non authentifié.

La limite globale est de deux jobs actifs pour une même base, y compris le worker de l'action GLPI. La variable d'environnement `UXCONNECTOR_MAX_CONCURRENT_JOBS` accepte de 1 à 8, avec repli à 2 si la valeur est invalide. Tous les workers doivent partager la même valeur. Ne pas augmenter sans mesurer la capacité MySQL, PHP et les limites Zendesk.

Un verrou par job empêche sa double exécution. Un second verrou sérialise les jobs partageant la même connexion source et le même type cible GLPI, pour protéger les rapprochements et les documents des flux qui se recouvrent. Les verrous ne gardent aucune transaction ouverte pendant les appels HTTP et sont libérés à la déconnexion. La propriété des verrous est contrôlée aux frontières de page et d'enregistrement du pipeline générique ; après perte de propriété, le worker arrête ses écritures de progression. Le watchdog ne récupère pas une exécution toujours détenue par un worker, même si son heartbeat est ancien.

Exécuter d'abord les référentiels nécessaires aux tickets : utilisateurs, groupes et catégories. La concurrence ne constitue pas un moteur de dépendances et n'accélère pas les téléchargements d'un même job. Les jobs de sources différentes qui adoptent intentionnellement les mêmes objets GLPI doivent être planifiés séparément.

## Mapping des emails et préparation LDAP

Ajouter au job utilisateur existant :

| Source | Target | Transform | Required |
| --- | --- | --- | --- |
| `email` | `email` | `trim` | No |

Le nouveau modèle Zendesk contient aussi ce mapping et le mapping `phone` vers `phone`. Les jobs déjà créés doivent être modifiés explicitement dans l'interface. Le champ `email` n'est pas une clé de rapprochement et n'autorise aucun accès aux champs internes `_useremails` ou aux identifiants d'authentification.

Pour un enrichissement email seul d'utilisateurs déjà liés, conserver le job et ses ObjectLinks existants et retirer les mappings qui ne doivent plus être écrasés, notamment `email -> name` et `name -> realname`. Un utilisateur déjà lié est mis à jour par son ID GLPI. Un utilisateur encore inconnu nécessite toujours la valeur de rapprochement configurée ; un job d'enrichissement seul ne crée donc pas les utilisateurs absents s'il ne mappe plus cette valeur. Ne pas transformer ce job en import de comptes AD : Zendesk ne fournit pas le véritable sAMAccountName dans les données observées.

L'installation ne renomme aucun compte existant, ne scinde aucun nom et ne modifie pas les liens aux tickets. La liaison LDAP reste une opération distincte, à préparer avec les vrais identifiants AD.

## Certificats INDIGO

La validation TLS et la politique SSRF restent actives. Le certificat d'autorité INDIGO doit être approuvé dans chaque environnement qui télécharge les documents, y compris les workers CLI. Le correctif applicatif ne peut pas remplacer cette confiance système. Conserver l'installation du certificat dans l'image ou la procédure de démarrage du conteneur, et contrôler la chaîne complète de redirection jusqu'à `*.zdusercontent.com`. Ne pas utiliser `-k` ni désactiver `verify_peer`.

## Vérifications et limites

La commande `sh tools/preflight-2.18.29.sh` exécute les régressions précédentes, 45 contrôles de pièces jointes, 29 contrôles Location et les nouveaux tests hors ligne workers/emails. Les tests workers simulent plusieurs connexions à un serveur de verrous partagé. Les tests emails utilisent des doubles des API natives GLPI. Ces tests ne remplacent pas une intégration réelle MySQL/MariaDB, PHP-FPM, cron, LDAP et proxy INDIGO. Aucun audit externe, certification ou garantie d'absence totale de défaut n'est revendiqué.

Recette minimale sur préproduction :

1. Vérifier la découverte et importer quelques lignes réseau et licences.
2. Importer un ticket contenant une capture intégrée et un document PDF, puis rejouer pour vérifier la déduplication.
3. Mettre à jour l'email d'un compte de test déjà lié ; vérifier son ID, son login, ses tickets et ses adresses secondaires.
4. Lancer deux jobs indépendants avec deux processus CLI. Vérifier qu'ils avancent ensemble. Relancer un troisième worker sur un job déjà actif : aucune seconde exécution du même job ne doit démarrer.
5. Arrêter un worker de test, attendre le seuil de récupération configuré, puis reprendre et vérifier le checkpoint et l'absence de doublons.

Le tiret cadratin a été retiré des fichiers texte distribués, conformément à la demande.
