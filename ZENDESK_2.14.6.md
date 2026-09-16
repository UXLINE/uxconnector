# UXConnector 2.14.6 - Catégories ITIL depuis le Sujet Zendesk

## Source fonctionnelle

Le champ Zendesk `Sujet` est un champ `tagger`. Ses valeurs techniques sont de la forme `topic__...` et ses libellés sont hiérarchiques avec le séparateur `::`, par exemple :

```text
Software::Software information::Question about functionality
```

UXConnector ne confond pas ce champ avec `ticket.subject`, qui reste le titre du ticket GLPI.

## Job 4

`zendesk.itilcategories.bootstrap` parcourt le même flux incrémental que les tickets, mais ne matérialise que les sujets portés par des tickets `new`, `open`, `pending`, `hold` ou `solved`. Les tickets `closed` ne créent donc pas de catégories hors périmètre.

Le champ Sujet est découvert automatiquement : UXConnector recherche le champ `tagger` dont les options techniques commencent par `topic__`. Le champ numérique Zendesk n'est donc pas codé en dur.

Chaque libellé est découpé par `::` et créé comme arborescence native GLPI :

```text
Software
└── Software information
    └── Question about functionality
```

La feuille reçoit un `ObjectLink` :

```text
zendesk_topic + topic__software__info__does_software_do
    -> ITILCategory <id>
```

Les niveaux intermédiaires utilisent des liens techniques `zendesk_topic_path` afin de rendre le bootstrap rejouable et idempotent.

## Job 5

Au traitement d'un ticket, UXConnector lit la valeur du même custom field `Sujet`, retrouve la catégorie par `ObjectLink` et renseigne `Ticket.itilcategories_id`.

Si un nouveau sujet apparaît après le bootstrap pendant un delta, l'arborescence est créée à la volée puis affectée au ticket.

Un ticket sans valeur Sujet reste importable sans catégorie ITIL ; une valeur `topic__...` inconnue du catalogue Zendesk provoque en revanche un échec contrôlé afin d'éviter une affectation arbitraire.

## Compatibilité

L'ancien job `zendesk.ticket-forms.bootstrap` est conservé mais masqué du catalogue. Les instances qui l'avaient déjà créé peuvent le mettre en pause ou le supprimer après validation du nouveau job 4.
