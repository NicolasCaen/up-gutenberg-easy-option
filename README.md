# UP Gutenberg Easy Option

Ajoute des options configurables (toggles et sélecteurs) aux blocs Gutenberg pour ajouter/retirer des classes CSS depuis l’inspecteur de l’éditeur. Les options peuvent être groupées par panneau (metabox) personnalisé dans l’inspecteur.

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

Champs communs:
- `id`: identifiant unique (par bloc)
- `label`: libellé affiché dans l’éditeur
- `panel` (optionnel): nom du panneau dans l’inspecteur pour regrouper les contrôles

Spécifique `toggle`:
- `class`: classe CSS à ajouter/retirer

Spécifique `select`:
- `options`: tableau d’options `{ id, label, class }` (classes exclusives)

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
      [ 'id' => 'sm', 'label' => 'Petite',  'class' => 'is-sm' ],
      [ 'id' => 'md', 'label' => 'Moyenne', 'class' => 'is-md' ],
      [ 'id' => 'lg', 'label' => 'Grande',  'class' => 'is-lg' ],
    ],
  ];

  return $switches;
});
```

## Comment ça marche
- À l’ouverture de l’éditeur, le plugin récupère la configuration via la route REST.
- Les contrôles sont groupés par `panel` dans l’InspectorControls (par défaut “Options UP”).
- `toggle`: coche/décoche et ajoute/retire la classe dans `attributes.className`.
- `select`: sélection exclusive d’une classe parmi les options (remplace les autres classes du même contrôle).

## Bonnes pratiques
- Préfixez vos classes (ex: `is-...`) pour éviter les collisions.
- Gardez des `id` stables (ils servent de clé d’UI par bloc).
- Ciblez précisément vos blocs: `core/paragraph`, `core/media-text`, `acf/hero`, etc.

## Dépannage
- Les toggles n’apparaissent pas: vérifiez que le bloc courant est listé dans la config retournée par le filtre.
- 401/nonce REST: assurez-vous d’être connecté à l’admin et que l’éditeur charge correctement `wp-api-fetch`.

## Changelog

### 0.2.0
- Ajout du type `select` avec gestion de classes exclusives.
- Groupement par `panel` (metabox) dans l’inspecteur.
- Compatibilité ascendante avec les définitions existantes.

### 0.1.0
- Version initiale: toggles par bloc via filtre et API REST.

## Licence
MIT
