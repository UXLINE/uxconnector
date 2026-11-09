# UXConnector 2.18.35 candidate

Les avertissements Zendesk précisent désormais la première correspondance manquante rencontrée pour un ticket.

- REQUESTER_LINK_UNRESOLVED avec requester_id numérique.
- ASSIGNEE_LINK_UNRESOLVED avec assignee_id numérique.
- GROUP_LINK_UNRESOLVED avec group_id numérique.
- COMMENT_AUTHOR_UNRESOLVED avec author_id numérique.
- TOPIC_CATEGORY_UNRESOLVED lorsque le Sujet ne peut pas devenir une catégorie.
- Codes distincts lorsque GLPI refuse une association ou une écriture de catégorie.

Le diagnostic est limité aux identifiants numériques pour les acteurs. Les suivis incomplets restent rejouables. Le premier diagnostic détaillé est conservé même si plusieurs étapes rencontrent un problème.

Après installation par mise à jour habituelle, utiliser Retry failed items sur l'exécution #580. Les anciennes lignes d'historique gardent leurs messages enregistrés. Le rejeu enregistre maintenant ses nouveaux diagnostics dans la même exécution, en conservant les lignes historiques. Examiner les nouvelles lignes après le passage du worker. Cela permet d'identifier les correspondances à réparer.

Les correctifs de statut du delta et de dernière page Search Export sont inclus. Ce correctif enrichit le diagnostic. La réparation des ObjectLinks nécessite de connaître le compte ou groupe GLPI correspondant à l'identifiant Zendesk signalé.

## Complément du 4 novembre 2026

Le delta applique le statut Zendesk converti aux tickets déjà liés si la valeur diffère. Les nouveaux groupes support et techniciens résolus sont ajoutés aux tickets même si le rôle est déjà occupé. Le rejeu vérifie le couple ticket/acteur/rôle pour éviter les doublons. Les affectations existantes sont conservées, et une valeur source vide ne les supprime pas.

Les erreurs de catégorie affichent le chemin lisible du Sujet Zendesk lorsqu'il est présent dans le catalogue. Si Zendesk ne fournit plus le libellé, le message indique « Libellé indisponible » et conserve la valeur technique permettant de l'identifier. Les caractères de contrôle sont éliminés.

Après mise à jour, conserver le mapping status vers status du job #6. Les réglages des jobs existants sont pris en charge. Les sept avertissements peuvent être rejoués avec Retry failed items après la fin du job en cours. Les nouveaux diagnostics apparaissent dans de nouvelles lignes de l'exécution d'origine.

## Nettoyage du catalogue Indigo

Les modèles Zendesk Organisations vers Entités, Ticket Forms 2.14.5, Tickets actifs 2.13 et Tous les tickets sont retirés de la création de nouvelles interactions. Les cartes sans job configuré sont masquées. Si un ancien job utilise encore un de ces modèles, sa carte reste accessible pour le gérer et consulter son historique.

Le catalogue conserve Utilisateurs, Groupes, Sujets vers Catégories ITIL, Reprise initiale non close et Delta incrémentiel. Les trois profils REST GLPI 10 Indigo restent disponibles pour Télécoms, Réseau et Licences Microsoft.

Ce nettoyage porte sur les modèles proposés. Il conserve les jobs enregistrés, leurs historiques, ObjectLinks et checkpoints.
