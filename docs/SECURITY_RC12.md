# UXConnector 2.18.38 RC12

Candidate de recette du 12 novembre 2026, préparée à partir de la RC11. Version interne GLPI : 2.18.38. La validation finale de l’éditeur reste requise avant déploiement Cloud.

## Corrections de sécurité nouvelles

- Revue TECLIB n°6 : les pièces jointes et images Zendesk sont limitées à l’hôte de la connexion et au domaine de pièces jointes zdusercontent.com. Chaque redirection est contrôlée. Les adresses non publiques sont refusées, y compris sur le repli authentifié. Les secrets de connexion ne sont pas transmis au CDN.
- Revue n°8 : les suivis génériques vérifient le type et l’entité du parent avant écriture. Un auteur nominatif doit provenir d’un ObjectLink utilisateur de la même connexion et être actif et habilité dans l’entité via Profile_User natif. L’auteur système 0 reste utilisable. Deux commentaires d’un même ticket restent distincts ; un rejeu conserve le suivi initial.
- Revue n°15 : les transports cURL et PHP streams se connectent à l’adresse IP validée, tout en conservant le nom d’origine pour Host, SNI et le certificat TLS. Les adresses IPv6 ULA, NAT64 et de transition concernées sont bloquées. Les écritures ambiguës d’adresses IPv4 sont refusées. Une seconde résolution DNS ne détermine plus l’adresse de connexion.

Les intégrations configurées vers les réseaux IPv4 privés RFC1918 restent utilisables. Cette autorisation ne s’applique pas aux pièces jointes Zendesk. Les proxys HTTP implicites de l’environnement ne sont pas utilisés par cURL : une infrastructure qui impose un proxy doit être vérifiée avant déploiement. UXCONNECTOR_ALLOWED_HOSTS reste pris en compte ; prévoir les hôtes CDN Zendesk nécessaires dans cette liste lorsqu’elle est définie.

## Corrections complémentaires issues de la revue qualité

- Imports spécifiques Télécoms, Réseau et licences Microsoft : recherches et créations limitées à l’entité configurée, sans imposer l’entité racine. Une entité explicite est obligatoire. Les contrôles préalables des champs Fields sont conservés. La date de remise et le tarif du matériel restent dans ses Infocom.
- Consolidation GLPI High-Level API : les noms d’entités de la source ne créent plus d’entités implicitement ; les lieux restent dans l’entité de l’équipement cible.
- Catégories Zendesk : création dans l’entité fixe du job, sans récursivité automatique. Un ObjectLink pointant hors de cette entité est refusé. La visibilité des catégories déjà présentes est conservée et doit être revue dans GLPI si elle était trop large.
- Retry failed items : un snapshot contenant [REDACTED] est refusé avant traitement, avec le statut snapshot_redacted. Recharger l’objet depuis la source ou relancer l’interaction ; ne pas injecter le marqueur dans GLPI.
- Édition JSON des jobs : vérification des identifiants d’entités, de la politique de repli et des transformations. Chemins de ressources relatifs validés.
- Formulaire Twig : affichage littéral de {{external_id}}, compatible avec strict_variables.
- Ancien outil de rotation partielle : n’accepte plus de clés sur la ligne de commande et n’écrit plus en base. Utiliser la rotation native GLPI security:change_key, qui prend en compte les champs sécurisés du plugin. Le refus HTTP intervient avant tout chargement de GLPI.
- User-Agent dérivé de la version du plugin. Exception SQL préparée FOR UPDATE explicitement documentée, conformément à la possibilité admise dans le constat n°17 de l’éditeur.

## Mise à jour depuis RC11

Sauvegarder le dossier du plugin, la base GLPI et config/glpicrypt.key. Mettre les jobs en pause et attendre la fin des exécutions avant le remplacement intégral du dossier uxconnector. Ne pas désinstaller le plugin.

La version interne étant conservée, exécuter depuis la racine GLPI :

```bash
php bin/console glpi:plugin:install --force uxconnector
php bin/console cache:clear
```

Réactiver UXConnector si GLPI le propose. Vérifier que les jobs spécifiques et les catégories Zendesk ont une entité cible explicite avant de les reprendre. Aucun identifiant API ne doit être partagé pour la recette.

## Limites de cette livraison

Le contrôle PSR12 ne vaut pas certification de tous les standards GLPI. Les essais locaux n’établissent pas une validation TECLIB ni une validation fonctionnelle de toutes les intégrations. Les sujets de traduction exhaustive, de découpage des classes longues et d’extraction des profils spécifiques dans un package séparé restent des travaux de qualité. Une correction ne répare pas automatiquement les données historiques déjà placées dans une mauvaise entité.

Les exceptions de session nécessaires au webhook signé restent visibles dans un scanner heuristique. Leur présence doit être qualifiée avec les contrôles de signature, rejeu et droits des écrans, et ne doit pas être masquée pour obtenir un score artificiellement meilleur.
