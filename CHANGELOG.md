# Changelog

## 0.3.0 - 2025-11-01
- Interface d’administration (gestion visuelle, import/export JSON) pour configurer les contrôles sans code.
- Ajout du type `preset` (bundles de classes) et support des classes multiples pour tous les contrôles.
- Normalisation améliorée (`class`/`classes`) et fusion des options enregistrées avec le filtre PHP.

## 0.2.0 - 2025-11-01
- Ajout du type `select` pour appliquer des classes exclusives par contrôle.
- Groupement des contrôles par `panel` (metabox) dans l’inspecteur Gutenberg.
- REST: normalisation étendue (type, panel, options) avec compatibilité ascendante.

## 0.1.0 - 2025-11-01
- Version initiale avec toggles configurables par bloc via filtre `up_block_switches` et API REST `GET /up/v1/switches`.
