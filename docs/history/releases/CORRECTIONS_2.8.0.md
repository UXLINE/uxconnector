# UXConnector 2.8.0 — Corrections et évolutions

## Parcours Connexion

- La page d'entrée UXConnector devient **Connections**.
- Création libre d'une Connection REST ou à partir d'une définition du catalogue.
- Cycle complet : **Test / Activate / Disable / Delete**.
- Une Connection standard n'affiche que les méthodes d'authentification compatibles avec son manifeste.
- Une Connection créée historiquement comme `custom` peut être associée a posteriori à une définition du catalogue sans ressaisir ses secrets.
- L'association est refusée si la méthode d'authentification existante est incompatible avec la définition choisie.

## Interactions rattachées à une Connection

- Les templates standard sont créés depuis la fiche Connection.
- L'administrateur ne saisit plus les chemins REST, collection paths, pagination ou mappings pour les interactions standard.
- Le formulaire technique est repositionné comme **Advanced custom REST interaction**.
- Trois modes avancés explicites :
  - external -> GLPI, polling REST ;
  - external -> GLPI, webhook ;
  - GLPI -> external, événement GLPI.
- Historique accessible directement depuis chaque interaction configurée.
- Les interactions standard sortantes exigent une entité GLPI fixe comme périmètre source ; le formulaire propose l'entité active courante par défaut.
- Respect du droit GLPI `plugin_uxconnector_syncjob:READ` sur la fiche Connection : les interactions ne sont pas exposées à un profil qui n'a que le droit de lire les Connections.

## Runtime entrant

- `InboundRecordProcessor` partagé entre polling et webhook.
- Webhook stateless `/front/webhook.php` avec clé aléatoire 256 bits par interaction.
- La clé webhook n'est révélée sur la fiche Connection qu'aux profils ayant le droit de modification des interactions.
- Mappings imbriqués, `CollectionPath`, pagination et réconciliation restent génériques.

## Runtime sortant

- Nouveau `InteractionTrigger::GLPI_EVENT`.
- Hooks GLPI sur Project, Ticket, Computer, Monitor, NetworkEquipment, Peripheral, Phone, Printer et User.
- `DispatchGlpiItemEventService` générique : POST/PUT/PATCH/DELETE défini par l'Interaction.
- Définitions de corps utilisant `@field:<champ>`, `@field_optional:<champ>` et `@literal:<valeur>`.
- Placeholders de chemin `{{external_id}}`, `{{item_id}}`, `{{field.xxx}}`.
- Chaque tentative sortante est historisée dans `SyncExecution`.
- Une Connection désactivée suspend silencieusement les interactions actives au lieu de les faire passer en erreur.

## Réconciliation

- `ObjectLink` devient fonctionnellement partagé par toutes les interactions d'une Connection pour une même famille d'objet externe.
- Ajout de `external_object_type`.
- Compatibilité ascendante des anciennes lignes `record` ; le type sémantique demandé est maintenant conservé lors de leur migration paresseuse.
- Les ObjectLinks survivent à la suppression d'une Interaction et sont purgés avec la Connection.
- La suppression d'une Interaction purge son historique d'exécution.

## Jira

Interactions livrées :

- Projects -> GLPI Projects ;
- Issues -> GLPI Tickets ;
- Issue webhook -> GLPI Ticket ;
- GLPI Projects -> Jira Projects ;
- GLPI Tickets -> Jira Issues (mapping standard minimal).

Le runtime ne contient aucune branche ou chaîne métier Jira ; tout le spécifique fournisseur reste dans le manifeste déclaratif.

## Robustesse

- Fallback `strtoupper/strtolower` lorsque l'extension PHP `mbstring` n'est pas disponible pour les filtres de paramètres de template.
- Validation serveur de la compatibilité méthode d'authentification / définition de connecteur.
