# UXConnector 2.18.38 candidate

- Le diagnostic Fields source et la liste des affectations Microsoft utilisent Twig avec échappement HTML.
- Le signal d'arrêt reste conditionné atomiquement au statut de l'exécution. La lecture du signal et les deux mises à jour de données historiques utilisent l'API de base de données GLPI.
- L'installation conserve les dix créations de tables du plugin, suivant le mécanisme documenté par GLPI. Les workers utilisent `DBmysql::getLock()` et `releaseLock()`. Le SQL spécialisé reste utilisé pour vérifier la propriété du verrou du worker et pour verrouiller la ligne utilisateur lors des mises à jour d’e-mail. Le worker refuse le démarrage avant toute prise de verrou si le nom de la base dépasse 33 caractères sur MySQL ou 161 octets sur MariaDB. Le diagnostic indique la limite applicable.
- Le verrou transactionnel du job utilise une mise à jour native GLPI sur sa clé primaire, avec la même valeur. Les opérations de mise en file, reprise, rejeu complet et réinitialisation de checkpoint se sérialisent sur la même ligne InnoDB.
- L’historique permet d’annuler une exécution en attente, en cours ou interrompue. Le worker confirme les demandes en cours au prochain point d’arrêt sûr. Une exécution annulée reste visible et ne peut pas être reprise. Les données déjà importées et la planification du job sont conservées.
- Arrêter les workers avant de remplacer le code. Les anciens workers et les workers utilisant les noms de verrous natifs de GLPI doivent être exécutés séparément.
- La plage déclarée reste `>=11.0.0 <12.0.0`. Le descripteur contient l'URL cible de la future archive GitHub.
- Le kit QA est fourni séparément. Exécuter la recette sur GLPI 11 avant publication, en particulier l'installation neuve, la mise à niveau, les droits des deux écrans et la reprise des workers.
