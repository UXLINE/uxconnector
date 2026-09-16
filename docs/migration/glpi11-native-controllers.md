# Migration vers les contrôleurs natifs GLPI 11

## Statut 2.5.1

**Migration native différée, dette explicite — aucun contrôleur expérimental non
câblé n'est livré dans le runtime.**

GLPI 11 continue d'accepter les scripts publics situés dans `front/` et
`ajax/`, mais l'initialisation du framework est maintenant centralisée par
`public/index.php`. Les quatre points d'entrée web UXConnector restent donc
sous `front/` pour cette version, sans `include('../../../inc/includes.php')`.

## Pourquoi les anciens POC ont été retirés

La 2.5.0 contenait deux classes expérimentales :

- `Presentation/Engine/Controller/Native/AuthConfigNativeController.php` ;
- `Presentation/Sync/Controller/Native/SyncJobNativeController.php`.

Elles n'étaient pas découvertes par le routeur et leur emplacement reposait
sur une hypothèse non validée. Les conserver donnait une impression trompeuse
de migration effective et augmentait la surface de maintenance sans fournir
de route utilisable. La 2.5.1 les retire.

## Architecture actuelle

Les scripts `front/*.php` restent de très minces adaptateurs :

1. contrôle du droit GLPI ;
2. construction du contrôleur de présentation via les Service Providers ;
3. appel d'une méthode de contrôleur ;
4. `Html::header()` / `Html::footer()` pour les écrans GET.

Les règles métier restent dans `Application` / `Domain`, et les vues sont déjà
en Twig. Une future migration vers des routes natives ne devra donc remplacer
que l'adaptation HTTP, pas réécrire le moteur.

## Critères avant migration native

La migration ne devra être relancée qu'avec une instance GLPI 11 de test et
les critères suivants :

- route réellement auto-découverte selon la convention GLPI courante ;
- mêmes droits que les points d'entrée legacy ;
- CSRF validé sur toutes les mutations ;
- mêmes templates Twig et mêmes Services applicatifs ;
- tests fonctionnels sur création, test/activation d'une connexion, création,
  activation, pause et exécution d'un SyncJob ;
- aucune coexistence ambiguë de deux URL faisant la même mutation.

Tant que ces critères ne sont pas prouvés, conserver `front/` est préférable à
un contrôleur natif théorique non testé.
