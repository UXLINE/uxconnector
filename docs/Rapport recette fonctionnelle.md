# Rapport de recette fonctionnelle UXConnector 2.18.37

## Identification

| Élément | Référence |
| --- | --- |
| Date du rapport | 9 novembre 2026 |
| Archive contrôlée | `uxconnector-2.18.37.zip` |
| SHA-256 | `d8e4dcfd991f2b90868f0ed82f6d822bcd748af8184a915ac1dc283ba5cffaf3` |
| Environnement de recette | GLPI 11.0.11 local sous Docker, base MariaDB |
| Conteneur des logs contrôlés | `glpi-data-glpi-1` |
| Rapport automatisé | `resultats.json` du kit R2, empreinte identique à celle de l’archive finale |
| Statut | Recette fonctionnelle déclarée réussie par le testeur. Audit automatisé final sans échec. |

**Lecture des résultats.** « OK confirmé » reprend la confirmation globale du testeur pour la recette. « OK observé » correspond à une sortie de commande ou à un résultat d’audit communiqué. Les identifiants d’exécution, captures et relevés par connecteur ne figurent pas dans les éléments transmis. Les critères du tableau documentent les scénarios, sans constituer des traces individuelles d’exécution.

## Installation, exécution et reprise

| ID | Scénario | Critère de validation | Résultat |
| --- | --- | --- | --- |
| F01 | Installation et mise à jour | Version 2.18.37 visible, menus accessibles et conservation des connexions, jobs, historiques et liaisons lors de la mise à jour. | OK confirmé |
| F02 | Asynchrone, concurrence et planification | Deux jobs distincts progressent selon la capacité configurée. Le même job ne traite pas le lot deux fois en parallèle. Le job planifié se déclenche selon sa configuration. | OK confirmé |
| F03 | Arrêt et reprise sur un lot paginé | Arrêt après l’élément en cours. La reprise utilise le checkpoint et conserve les identifiants déjà créés. | OK confirmé |
| F04 | `Replay from start` | Action visible dans l’historique. Relecture du lot sans doublon ni écrasement des enrichissements GLPI hors mapping. | OK confirmé |
| F05 | `Retry failed items` et CSV | Après correction à la source, l’élément en erreur est repris. Les éléments déjà traités ne sont pas recréés. L’export permet de qualifier l’erreur. | OK confirmé |

## Flux et intégrité des données

| ID | Scénario | Critère de validation | Résultat |
| --- | --- | --- | --- |
| F06 | Zendesk vers GLPI | Import des catégories et des tickets. Rattachement du demandeur, diagnostic exploitable pour une catégorie sans correspondance et mise à jour de l’email sans changer l’identité GLPI. | OK confirmé |
| F07 | Pièces jointes Zendesk OFF puis ON | Aucune pièce jointe importée en OFF. PDF et image importés selon les droits en ON. Suivi privé invisible pour le demandeur et absence de doublon après rejeu. | OK confirmé |
| F08 | GLPI 10 vers GLPI 11, Télécoms, Réseau et Licences Microsoft | Résolution des lieux, liaison Ligne ↔ SIM, Fields et affectations conformes. La Date de remise et le Tarif équipement sont dans les informations financières du matériel Télécom associé. | OK confirmé |
| F09 | Contrôle préalable Fields | En cas de champs requis absents, liste exhaustive par type d’objet cible et arrêt avant toute écriture. Le lot passe après correction de la configuration. | OK confirmé |
| F10 | Modèles externes Jira, JSM, Matrix42 et EasyVista | Sur les modèles actifs du périmètre de recette, authentification, mapping et pagination conformes. Pour EasyVista, contrôle de `records`, `RFC_NUMBER` et `@next` sur deux pages. Un modèle sortant désactivé n’émet aucun appel. | OK confirmé globalement. Détail par tenant non consigné. |

## Sécurité fonctionnelle

| ID | Scénario | Critère de validation | Résultat |
| --- | --- | --- | --- |
| S02 | Droits et entités | Tentative d’écriture hors entité refusée sans modification. | OK confirmé |
| S04/S05 | TLS et redirections | Certificat non approuvé ou destination hors périmètre refusés. Jeton absent de la destination non autorisée. | OK confirmé |
| S06/R02 | Webhooks et flux bidirectionnels, lorsqu’ils sont utilisés | Appel sans secret refusé. Une mise à jour aller-retour ne déclenche pas de boucle. | OK confirmé globalement. Applicabilité par flux non consignée. |
| S10-BDD | Recherche du marqueur fictif, de formats connus et de clés webhook renseignées dans les colonnes UXConnector ciblées | Aucune ligne retournée. | OK observé, `Empty set (0.103 sec)` |
| S10-LOG | Recherche de `UXC_QA_CANARY_9F27C1` dans les fichiers de logs GLPI | Aucun fichier correspondant dans `/var/www/glpi/files/_log` ni dans `/var/glpi/logs`. | OK observé, `grep -rlF` sans sortie, code retour `1` |
| S10-UI/CSV | Recherche du même marqueur dans le détail de l’erreur et l’export CSV UXConnector | Marqueur absent des surfaces contrôlées. | OK confirmé |
| S10-DOCKER | Recherche du même marqueur dans la sortie `docker logs` | Marqueur absent de la sortie du conteneur contrôlé. | OK confirmé globalement. Sortie distincte non transmise. |

La recherche de secrets porte sur le marqueur de recette et les formats recherchés dans les surfaces indiquées. Le résultat SQL ne démontre pas l’absence de tout secret dans l’ensemble de GLPI.

## Audit automatisé et conclusion

Le rapport R2 du ZIP final confirme les contrôles du package, de Composer en QA, de la syntaxe PHP, de l’architecture, de PHPUnit, de PHPStan, de Twig, du style et des régressions. L’audit Composer de production est non applicable, car aucun paquet de production n’est verrouillé. Le champ `recette_glpi` du JSON est `MANUELLE`. Le présent rapport consigne la confirmation du testeur pour ce volet.

**Bilan.** Aucun échec n’a été signalé sur le périmètre déclaré testé. 
