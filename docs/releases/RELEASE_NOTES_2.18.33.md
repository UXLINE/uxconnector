# UXConnector 2.18.33

Le profil `zendesk.tickets.delta.inbound` applique le mapping de statut aux tickets GLPI déjà reliés par ObjectLink, lorsque le statut diffère. Les autres champs suivent la politique de préservation existante. Ce comportement s'applique à tous les jobs qui utilisent ce profil, dont le job #6 d'Indigo.

Conserver le mapping status vers status. Installer l'archive dans le répertoire du plugin existant via la procédure habituelle de mise à jour. Reprendre le delta avec son curseur enregistré. Pour un changement déjà parcouru, rejouer le ticket concerné ou reprendre depuis une date couvrant le changement.

Le test hors ligne vérifie la fermeture, la réouverture, le rejeu, la préservation du titre et le traitement du profil initial. La recette dans GLPI doit confirmer les transitions natives avec les règles et droits de l'instance.
