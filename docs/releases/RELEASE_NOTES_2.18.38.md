## Candidate RC11

Voir [la revue et la recette RC11](../SECURITY_RC11.md). Migration obligatoire de la table anti-rejeu.
Le mode webhook avec jeton seul est retiré. Une requête signée identique reçoit HTTP 409 après sa première admission.
La version interne reste 2.18.38 et la compatibilité déclarée reste >=11.0.0 <12.0.0.

# UXConnector 2.18.38 candidate

- Le diagnostic Fields source et la liste des affectations Microsoft utilisent Twig avec échappement HTML.
- Le signal d'arrêt reste conditionné atomiquement au statut de l'exécution. La lecture du signal et les deux mises à jour de données historiques utilisent l'API de base de données GLPI.
- L'installation crée les tables nécessaires au moteur de synchronisation, suivant le mécanisme documenté par GLPI. Les workers utilisent `DBmysql::getLock()` et `releaseLock()`. Le SQL spécialisé reste utilisé pour vérifier la propriété du verrou du worker et pour verrouiller la ligne utilisateur lors des mises à jour d’e-mail. Le worker refuse le démarrage avant toute prise de verrou si le nom de la base dépasse 33 caractères sur MySQL ou 161 octets sur MariaDB. Le diagnostic indique la limite applicable.
- Le verrou transactionnel du job utilise une mise à jour native GLPI sur sa clé primaire, avec la même valeur. Les opérations de mise en file, reprise, rejeu complet et réinitialisation de checkpoint se sérialisent sur la même ligne InnoDB.
- L’historique permet d’annuler une exécution en attente, en cours ou interrompue. Le worker confirme les demandes en cours au prochain point d’arrêt sûr. Une exécution annulée reste visible et ne peut pas être reprise. Les données déjà importées et la planification du job sont conservées.
- Arrêter les workers avant de remplacer le code. Les anciens workers et les workers utilisant les noms de verrous natifs de GLPI doivent être exécutés séparément.
- La plage déclarée reste `>=11.0.0 <12.0.0`. Le descripteur contient l'URL cible de la future archive GitHub.
- Le kit QA est fourni séparément. Exécuter la recette sur GLPI 11 avant publication, en particulier l'installation neuve, la mise à niveau, les droits des deux écrans et la reprise des workers.

## Candidate RC2

- L’onglet des droits de profil utilise un formulaire Twig et la matrice native de GLPI. La consultation exige le droit de lecture du profil. La modification reste soumise au droit de mise à jour et au jeton CSRF de GLPI.
- Le formulaire de connexion contrôle le droit correspondant à l’action reçue. La reprise d’une exécution exige une requête POST, comme l’arrêt et l’annulation.
- L’export des échecs neutralise les préfixes pouvant déclencher l’interprétation d’une cellule comme formule.
- Les méthodes du composant de mise à jour de l’adresse e-mail et les méthodes modifiées sont documentées.

## Candidate RC3

- Le périmètre de distribution est limité aux connexions et jobs du moteur de synchronisation.
- Le catalogue contient 13 connecteurs. Les connexions GLPI High-Level et les webhooks de synchronisation restent disponibles.
- En mise à niveau, le script de retrait supprime les stockages dédiés obsolètes et révoque les identifiants des connexions héritées concernées. Il conserve les jobs et historiques.
- La version déclarée reste `2.18.38`. Depuis la RC2, remplacer entièrement le dossier et relancer l’installation avec `--force` pour appliquer le retrait. Voir le README.

## Candidate RC4

- Revue TECLIB, constat 3. Un changement de protocole, d’hôte ou de port impose un nouveau secret si le mode utilise un credential. Les nouveaux secrets chiffrés sont liés à cette origine et à la méthode d’authentification.
- Constat 5. Les champs d’authentification sont refusés dans les chemins et corps des jobs sortants à l’enregistrement et à l’exécution. Le hook exclut aussi les champs masqués par GLPI.
- Constat 7. La clé webhook est exclue des représentations REST de GLPI.
- Constats 10 et 18. Les erreurs dynamiques sont échappées avant affichage dans les notifications GLPI.
- Constats 14, 16 et 17. Les huit DDL interpolent des noms de tables constants. Les deux requêtes natives de verrouillage sont documentées.
- La migration du stockage et de la signature webhook, le contrôle par entité et le durcissement des transports restent à traiter dans des lots dédiés.

## Candidate RC5

- Constat 9. Les actions utilisateur vérifient la portée active GLPI du job. La vérification couvre les cibles fixes, toutes les cibles d’un mapping et la cible par défaut.
- Un job sans stratégie d’entité exige une portée couvrant toutes les entités pour les opérations utilisateur. La planification existante fonctionne sans session utilisateur.
- La création, l’édition et l’instanciation vérifient le périmètre. L’édition vérifie aussi le périmètre enregistré. L’accès générique GLPI aux jobs et aux exécutions reste disponible en lecture. Les mutations passent par les services UXConnector.
- En recette, lancer le même job racine avec un profil limité à COAXIS puis avec un profil autorisé à la racine. Refaire le test avec un job fixé à COAXIS.
- Le contrôle du ticket parent et de l’auteur des suivis ITIL relève du constat 8 et reste à traiter.

## Candidate RC6

- Constat 4. La migration calcule l’empreinte SHA-256 de chaque jeton existant, chiffre les valeurs récupérables avec le coffre GLPI et retire la colonne historique `webhook_key`. Les jetons existants restent utilisables dans l’en-tête Bearer spécifique au webhook. L’API générique GLPI masque les trois champs de stockage.
- Constat 11. Le mode signé utilise un secret distinct, un identifiant public de job, un horodatage Unix et une signature. Signer la chaîne `identifiant.horodatage.corps_brut` avec HMAC-SHA256. Envoyer `X-UXConnector-Webhook-Id`, `X-UXConnector-Timestamp` et `X-UXConnector-Signature` sans jeton. La fenêtre de fraîcheur est contrôlée. Une requête peut être rejouée dans cette fenêtre.
- Le mode avec jeton accepte `X-UXConnector-Webhook-Key`. Les appels avec `?key=` et l’ancien format HMAC doivent être adaptés. Activer `UXCONNECTOR_REQUIRE_WEBHOOK_HMAC=1` une fois les émetteurs configurés pour signer.
- L’identifiant et les valeurs à distribuer sont disponibles dans la fiche de connexion pour un profil pouvant modifier le job dans son périmètre d’entités. La clé du coffre GLPI doit être sauvegardée avec la base pour permettre la restauration.
- La version GLPI reste `2.18.38`. Après sauvegarde et remplacement complet du dossier, lancer `php bin/console glpi:plugin:install --force uxconnector` et vider le cache. Un retour à la RC5 nécessite la sauvegarde antérieure à la migration.
