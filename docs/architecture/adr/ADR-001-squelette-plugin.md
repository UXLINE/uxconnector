# ADR-001 — Squelette initial du plugin UXConnector

## Statut

Acceptée

## Contexte

Le plugin UXConnector doit être publié sur la GLPI Marketplace officielle
et respecter le référentiel **GLPI AI Development Kit (GPKB)** comme
norme absolue : PHP 8.2+, GLPI 11, PSR-12, SOLID, DDD, Clean Architecture,
Repository Pattern, Domain Events, Value Objects et DTO immuables.

Le pivot d'architecture documenté en **ADR-002** (mémoire projet)
introduit un `ConnectorEngine` générique et configurable plutôt que des
connecteurs natifs codés en dur, organisé en trois bounded contexts :
**Engine**, **Sync** et **Application**.

## Décision

Le squelette initial applique :

1. L'arborescence officielle définie en **Annexe J** du GPKB
   (RULE-J.001 à RULE-J.005) : quatre couches `Domain`, `Application`,
   `Infrastructure`, `Presentation` sous `src/`.
2. À l'intérieur de chaque couche technique, un découpage par bounded
   context (`Engine/`, `Sync/`, `Shared/`) afin de matérialiser la
   séparation fonctionnelle introduite par ADR-002 tout en conservant
   l'arborescence unique imposée par RULE-J.001.
3. `composer.json` avec autoload PSR-4 `GlpiPlugin\Uxconnector\`
   (RULE-COMPOSER-001/002/005).
4. `setup.php` minimal et déterministe, déléguant à `src/Setup.php`
   (RULE-SETUP-001 à RULE-SETUP-004).
5. Aucune logique métier, aucune classe de Domaine, aucun Repository :
   ce commit ne fait que poser la structure et les fichiers de bootstrap.

## Conséquences

- Chaque nouvelle classe applicative des futurs commits (`AuthConfig`,
  `AuthMethod`, `GenericHttpEngine`, `CredentialVault` pour Engine ;
  `SyncJob`, `FieldMapping`, `SyncExecution` pour Sync) trouve un
  emplacement non ambigu dans cette arborescence.
- Le Domaine reste totalement indépendant de GLPI (RULE-J.003) :
  aucune dépendance vers `CommonDBTM`, `CommonGLPI` ou toute classe
  du cœur GLPI n'est autorisée dans `src/Domain/`.
- Le plugin est détectable par GLPI (présence de `setup.php`) mais ne
  déclare encore aucun hook, menu, droit ni CronTask : ces éléments
  seront ajoutés commit par commit, dans `src/Presentation/Hook/`.

## Références

- Annexe J — Arborescence de référence du plugin UXConnector (GPKB)
- RULE-COMPOSER-001 à 005 (GPKB, section 02.3)
- RULE-SETUP-001 à 004 (GPKB, section 02.2/02.4)
- ADR-002 — Pivot vers un `ConnectorEngine` générique (mémoire projet)
