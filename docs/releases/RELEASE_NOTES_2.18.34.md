# UXConnector 2.18.34 candidate

Le profil Zendesk Reprise initiale non close traite la dernière page de Search Export puis s'arrête si Zendesk indique `meta.has_more=false`, même si la réponse contient un lien suivant. Si Zendesk annonce d'autres résultats et renvoie un lien déjà parcouru, le contrôle de boucle signale toujours l'échec.

Installer via la procédure habituelle de mise à jour. Conserver `next_link` et `links.next` dans le job #5. Après installation, lancer une nouvelle exécution du #5 si la reprise initiale reste à terminer. Cette exécution repart de la première page et utilise les liens ObjectLink existants. Le correctif de statut du delta est inclus.

Les tests hors ligne reproduisent les deux cas de lien répété. L'exécution #579 n'a pas fourni la valeur de `meta.has_more`, donc la cause exacte de son arrêt reste à confirmer sur Indigo.
