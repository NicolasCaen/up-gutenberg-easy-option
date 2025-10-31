# UP Gutenberg Easy Option

Ajoute des switches configurables aux blocs Gutenberg pour ajouter/retirer des classes CSS depuis l’inspecteur de l’éditeur.

- Route REST: `GET /up/v1/switches`
- Filtre PHP: `up_block_switches`

## Installation
- Copiez le dossier `up-gutenberg-easy-option` dans `wp-content/plugins/`.
- Activez le plugin dans WP-Admin > Extensions.

## Usage rapide
Déclarez vos switches par bloc via le filtre `up_block_switches`.

Chaque switch doit définir:
- `id`: identifiant unique (par bloc)
- `label`: libellé affiché dans l’éditeur
- `class`: classe CSS ajoutée/supprimée dans `attributes.className`

### Exemple minimal (dans functions.php du thème ou mu-plugin)
```php
add_filter('up_block_switches', function($switches) {
  // Pour les paragraphes
  $switches['core/paragraph'][] = [
    'id'    => 'highlight',
    'label' => 'Surbrillance',
    'class' => 'is-highlight',
  ];

  // Pour le bloc Media & Texte
  $switches['core/media-text'][] = [
    'id'    => 'nopadding',
    'label' => 'Texte sans padding',
    'class' => 'is-nopadding',
  ];

  return $switches;
});
```

## Comment ça marche
- À l’ouverture de l’éditeur, le plugin récupère la configuration des switches via la route REST.
- Pour chaque bloc ciblé, un panneau “Options UP” apparaît dans l’InspectorControls avec des ToggleControl.
- Cocher/décocher un toggle ajoute/retire la classe dans `className` du bloc.

## Bonnes pratiques
- Préfixez vos classes (ex: `is-...`) pour éviter les collisions.
- Gardez des `id` stables (ils servent de clé d’UI par bloc).
- Ciblez précisément vos blocs: `core/paragraph`, `core/media-text`, `acf/hero`, etc.

## Dépannage
- Les toggles n’apparaissent pas: vérifiez que le bloc courant est listé dans la config retournée par le filtre.
- 401/nonce REST: assurez-vous d’être connecté à l’admin et que l’éditeur charge correctement `wp-api-fetch`.

## Licence
MIT
