# UXConnector 2.18.38 RC13

Correction de la modification des jobs utilisant le rapprochement `asset_identity`.

La RC12 acceptait ce mode à la création mais exigeait à tort, lors de la modification, un mapping vers un champ GLPI nommé `asset_identity`. Ce nom désigne une stratégie de rapprochement, pas une colonne à ajouter au mapping.

La RC13 partage désormais la même validation entre création et modification. La présence d'au moins un champ cible parmi `uuid`, `serial`, `otherserial` et `name` suffit pour cette stratégie. Une modification invalide est refusée avant toute mutation du job. Les champs de rapprochement ordinaires restent soumis à la présence d'un mapping correspondant.

Un seul fichier PHP de production est modifié : `src/Domain/Sync/Entity/SyncJob.php`. Aucun changement de schéma, de writer, de périmètre d'entité, de droits, de secret ou de correspondance ObjectLink. La version interne reste 2.18.38 ; la livraison est identifiée par le tag prévu v2.18.38-rc.13 et les empreintes des archives. Aucune publication GitHub n'est effectuée par la génération du paquet.

Le test de régression reproduit l'erreur sur la RC12 puis contrôle la RC13 avec les sept mappings signalés, chaque champ d'identité, les refus invalides, l'absence de mutation sur refus et le verrouillage des jobs actifs. Les simulations ne remplacent pas la modification du job 32 sur GLPI réel.
