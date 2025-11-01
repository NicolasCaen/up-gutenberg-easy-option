# UP Gutenberg Easy Option

Ajoute des options configurables (toggles, sélecteurs et presets) aux blocs Gutenberg pour ajouter/retirer des classes CSS depuis l’inspecteur de l’éditeur. Les options peuvent être groupées par panneau (metabox) personnalisé dans l’inspecteur et être gérées depuis une interface d’administration (import/export inclus).

- Route REST: `GET /up/v1/switches`
- Filtre PHP: `up_block_switches`

## Installation
- Copiez le dossier `up-gutenberg-easy-option` dans `wp-content/plugins/`.
- Activez le plugin dans WP-Admin > Extensions.

## Usage rapide
Déclarez vos options par bloc via le filtre `up_block_switches`.

Types disponibles:
- `toggle` (par défaut): ajoute/retire une classe unique.
- `select`: propose plusieurs classes exclusives (une seule active à la fois).
- `preset`: applique un ensemble de classes (bundle) exclusif.

Champs communs:
- `id`: identifiant unique (par bloc)
- `label`: libellé affiché dans l’éditeur
- `panel` (optionnel): nom du panneau dans l’inspecteur pour regrouper les contrôles

Spécifique `toggle`:
- `class`: classe CSS à ajouter/retirer

Spécifique `select`:
- `options`: tableau d’options `{ id, label, classes[] }` (classes exclusives)

Spécifique `preset`:
- `options`: tableau d’options `{ id, label, classes[] }` (chaque option représente un bundle de classes)

### Exemple (dans functions.php du thème ou mu-plugin)
```php
add_filter('up_block_switches', function($switches) {
  // Pour les paragraphes
  $switches['core/paragraph'][] = [
    'id'    => 'highlight',
    'label' => 'Surbrillance',
    'class' => 'is-highlight',
    'panel' => 'Apparence',
  ];

  // Select exclusif sur Media & Texte
  $switches['core/media-text'][] = [
    'type'  => 'select',
    'id'    => 'taille',
    'label' => 'Taille',
    'panel' => 'Apparence',
    'options' => [
      [ 'id' => 'sm', 'label' => 'Petite',  'classes' => ['is-sm'] ],
      [ 'id' => 'md', 'label' => 'Moyenne', 'classes' => ['is-md'] ],
      [ 'id' => 'lg', 'label' => 'Grande',  'classes' => ['is-lg'] ],
    ],
  ];

  // Preset (bundle) appliquant plusieurs classes
  $switches['core/media-text'][] = [
    'type'  => 'preset',
    'id'    => 'hero-style',
    'label' => 'Style Hero',
    'panel' => 'Apparence',
    'options' => [
      [ 'id' => 'minimal', 'label' => 'Minimal', 'classes' => ['is-hero', 'is-hero--minimal'] ],
      [ 'id' => 'image-left', 'label' => 'Image gauche', 'classes' => ['is-hero', 'is-hero--image-left'] ],
    ],
  ];

  return $switches;
});
```

## Comment ça marche
- À l’ouverture de l’éditeur, le plugin récupère la configuration via la route REST.
- Les contrôles sont groupés par `panel` dans l’InspectorControls (par défaut “Options UP”).
- `toggle`: coche/décoche et ajoute/retire une ou plusieurs classes dans `attributes.className`.
- `select`: sélection exclusive d’une classe parmi les options (remplace les autres classes du même contrôle).
- `preset`: sélection exclusive d’un bundle (une ou plusieurs classes) et remplacement d’autres bundles définis dans le contrôle.

## Interface d’administration
- Accédez à **Réglages > UP Gutenberg Options**.
- Ajoutez vos blocs, contrôles (toggle/select/preset) et classes via l’interface.
- Possibilité de dupliquer/supprimer des contrôles, d’ajouter des options multiples et de réordonner manuellement.
- La configuration est sauvegardée dans les options WordPress et fusionnée avec le filtre `up_block_switches`.

### Import / Export
- Export: génère un fichier JSON de la configuration actuelle.
- Import: téléversez un JSON exporté; les blocs/contrôles sont fusionnés avec la configuration existante (mêmes `id` remplacés, nouveaux ajoutés) sans duplication.

## Bonnes pratiques
- Préfixez vos classes (ex: `is-...`) pour éviter les collisions.
- Gardez des `id` stables (ils servent de clé d’UI par bloc).
- Ciblez précisément vos blocs: `core/paragraph`, `core/media-text`, `acf/hero`, etc.

## Dépannage
- Les toggles n’apparaissent pas: vérifiez que le bloc courant est listé dans la config retournée par le filtre.
- 401/nonce REST: assurez-vous d’être connecté à l’admin et que l’éditeur charge correctement `wp-api-fetch`.

## Changelog

### 0.3.1 (2025-11-01)
- Interface d’administration: aperçu groupé par panneau (badges de type, chips de classes, bouton Éditer).
- Édition: champ `Panneau` en premier avec aide; libellés renommés (« Identifiant de l'input », « Label de l'input »).
- Mise en page: champs principaux alignés (Type / ID / Label) et options `select/preset` sur une seule ligne (ID / Label / Classes).
- Configuration: support des blocs multiples via noms séparés par des virgules.
- Import: fusion avec la configuration existante sans duplication (mêmes `id` mis à jour).

### 0.3.0 (2025-11-01)
- Interface d’administration (gestion graphique, import/export JSON).
- Ajout des presets/bundles (type `preset`) et support de classes multiples.
- Compatibilité améliorée pour les définitions `class` / `classes` côté filtre.

### 0.2.0
- Ajout du type `select` avec gestion de classes exclusives.
- Groupement par `panel` (metabox) dans l’inspecteur.
- Compatibilité ascendante avec les définitions existantes.

### 0.1.0
- Version initiale: toggles par bloc via filtre et API REST.

## Licence
MIT
