# UXConnector 2.18.27 : pièces jointes et captures Zendesk

Version construite à partir de la 2.18.25 fournie. La correction OAuth de la candidate 2.18.26 n'est pas incluse : le test de connexion fonctionne avec le secret corrigé.

## Défauts corrigés et portée
- GLPI addFiles peut réutiliser un document de même contenu et de même entité en conservant son ancien tag. UXConnector recherchait seulement le tag demandé et échouait alors à enregistrer la réconciliation. Après l'upload natif, le document peut désormais être retrouvé par son empreinte et son entité, en excluant les documents blacklistés.
- Le repli authentifié ne gérait qu'une redirection : il suivait ensuite anonymement même les redirections du même serveur. La connexion reste désormais authentifiée sur la seule origine configurée ; les téléchargements CDN restent anonymes et soumis aux contrôles HTTPS et de destination.
- Les captures présentes uniquement dans html_body avec une URL sans extension reçoivent un nom PNG exploitable par GLPI.
- Un téléchargement en échec ne fait plus abandonner les autres fichiers téléchargés du même commentaire. L'échec reste signalé pour permettre une relance.
- Une page HTML de connexion reçue en réponse au téléchargement authentifié d'une image n'est plus enregistrée comme fichier image.
- Les documents des suivis prennent l'entité du ticket parent, y compris lors d'une exécution Cron sans entité active adaptée.
- Les redirections explicites vers HTTP restent HTTP pour être refusées, au lieu d'être interprétées comme un chemin relatif HTTPS.

Les catégories Zendesk, le contrôle bloquant Fields et les mappages INDIGO sont conservés.

## Validation
- 45 contrôles de pièces jointes : nouveau PNG, PDF, image intégrée, réutilisation native d'un document, relance sans doublon, redirections authentifiées, CDN anonyme, échec partiel, rejet HTML, entité du ticket, capture HTML avec URL opaque.
- Le test utilise la méthode addFiles de GLPI 11.0.11 inchangée, extraite dans une fixture GPL ; stockage, base et réseau sont simulés. Le convertisseur d'images est simulé.
- Le test reproduit l'échec de réutilisation de document avec le post-processeur 2.18.25 d'origine et passe avec le correctif.
- Tous les contrôles du preflight passent : catalogue Zendesk, Fields, INDIGO, architecture, arrêt/reprise et anciennes vérifications des PJ.
- PHP 8.3.6 : syntaxe des fichiers PHP modifiés et ajoutés vérifiée.
- La suite PHPUnit n'a pas été exécutée : dépendances vendor absentes.
- Aucun accès authentifié à l'instance Zendesk ni test visuel sur GLPI réel. Ces défauts sont démontrés localement ; la cause exacte du ticket 9837 nécessite la recette client.

## Installation et test ciblé
1. Attendre la fin des traitements et suspendre l'action automatique UXConnector.
2. Sauvegarder la base et le dossier du plugin.
3. Remplacer plugins/uxconnector par le dossier uxconnector de cette archive. Ne pas désinstaller le plugin et ne pas supprimer ses tables ou ses liens de réconciliation.
4. Vérifier la version 2.18.27. Appliquer la mise à jour proposée par GLPI si nécessaire. Ce correctif ne change pas le schéma.
5. Relancer un ticket Zendesk contenant une capture et un PDF. Une reprise au dernier checkpoint peut ignorer les pages déjà parcourues : utiliser Retry failed items si le ticket y figure, ou une relecture ciblée incluant ce ticket. Conserver les liens de réconciliation pour éviter les doublons.
6. Vérifier le fichier téléchargeable, l'image dans le ticket ou le suivi, puis relancer le même ticket pour vérifier l'absence de doublons.
7. Pour le ticket GLPI 9837, vérifier qu'il est effectivement reparcouru. Ne pas supprimer le ticket pour forcer sa recréation.
8. Après validation, réactiver le traitement automatique.

Si le défaut persiste, relever le code et le message d'erreur détaillés associés au ticket dans l'exécution UXConnector, l'ID Zendesk source et la version GLPI. Un simple message de remplacement dans un suivi confirme la détection de la PJ, pas le succès de son téléchargement.

Limite existante : les téléchargements anonymes acceptent 50 MiB, mais le repli via le moteur API authentifié reste limité à 10 MiB. Cette version ne relève pas cette limite.

Retour arrière : restaurer les fichiers 2.18.25 sauvegardés. Aucune migration de base introduite.
