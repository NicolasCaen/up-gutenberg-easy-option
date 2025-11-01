# Changelog

## 0.3.1 - 2025-11-01
- Admin UI: vue “aperçu d’abord” groupée par panneau (badges types, chips de classes, bouton Éditer).
- Édition: champ Panneau en premier avec aide; libellés renommés (“Identifiant de l'input”, “Label de l'input”).
- Mise en page: champs principaux sur une ligne (Type/ID/Label), options `select/preset` sur une ligne (ID/Label/Classes).
- Config: prise en charge multi-blocs via noms de blocs séparés par virgules.
- Import: fusionne avec la configuration existante (pas de duplication, mêmes `id` mis à jour).

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
