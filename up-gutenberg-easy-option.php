<?php
/**
 * Plugin Name: UP gutenberg easy option
 * Description: Ajoute des switches configurables aux blocs Gutenberg pour ajouter/retirer des classes via l’inspecteur. Configurable via un filtre et exposé via l’API REST.
 * Version: 0.6.2
 * Author: GEHIN Nicolas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Up_Block_Switches {
    const REST_NAMESPACE = 'up/v1';
    const OPTION_KEY = 'up_ge_switches_config';
    const CONTROL_SOURCES_OPTION_KEY = 'up_ge_control_sources';
    const SOURCE_MODE_OPTION_KEY = 'up_ge_source_mode';
    const ADMIN_PAGE_SLUG = 'up-ge-config';
    const ADMIN_MENU_SLUG = 'up-gutenberg';
    const PRESET_PAGE_SLUG = 'up-ge-preconfigs';
    const FILTER_PAGE_SLUG = 'up-ge-filter-configs';
    const THEME_GENERATE_PAGE_SLUG = 'up-ge-theme-generate';
    const PRESET_DIR = 'prefconfig';

    protected $admin_page_hook = null;
    protected $preset_page_hook = null;
    protected $config_page_hook = null;
    protected $filter_page_hook = null;
    protected $theme_generate_page_hook = null;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_up_ge_save_config', [$this, 'handle_save_config']);
        add_action('admin_post_up_ge_import_config', [$this, 'handle_import_config']);
        add_action('admin_post_up_ge_export_config', [$this, 'handle_export_config']);
        add_action('admin_post_up_ge_import_preset', [$this, 'handle_import_preset']);
        add_action('admin_post_up_ge_generate_theme_root', [$this, 'handle_generate_theme_root']);
        add_action('wp_ajax_up_ge_save_panel_preset', [$this, 'handle_save_panel_preset']);
        add_action('wp_ajax_up_ge_save_filter_as_preset', [$this, 'handle_save_filter_as_preset']);
        add_action('wp_ajax_up_ge_save_all_filters_as_presets', [$this, 'handle_save_all_filters_as_presets']);
        add_action('wp_ajax_up_ge_save_single_control_as_preset', [$this, 'handle_save_single_control_as_preset']);
        add_action('admin_post_up_ge_save_filter_as_preset', [$this, 'handle_save_filter_as_preset_post']);
        add_action('admin_post_up_ge_save_all_filters_as_presets', [$this, 'handle_save_all_filters_as_presets_post']);
        add_action('admin_post_up_ge_save_single_control_as_preset', [$this, 'handle_save_single_control_as_preset_post']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
    }

    protected function get_theme_block_option_base_dir() {
        return trailingslashit(get_stylesheet_directory()) . 'functions/gutenberg-block-option/';
    }

    protected function get_theme_block_option_inc_dir() {
        return $this->get_theme_block_option_base_dir() . 'inc/';
    }

    protected function get_theme_block_option_assets_dir() {
        return $this->get_theme_block_option_base_dir() . 'assets/';
    }

    protected function ensure_theme_block_option_directories() {
        $base = $this->get_theme_block_option_base_dir();
        $inc = $this->get_theme_block_option_inc_dir();
        $assets = $this->get_theme_block_option_assets_dir();

        if (!file_exists($base)) {
            wp_mkdir_p($base);
        }
        if (!file_exists($inc)) {
            wp_mkdir_p($inc);
        }
        if (!file_exists($assets)) {
            wp_mkdir_p($assets);
        }
    }

    protected function block_to_slug($block_name) {
        return str_replace('/', '-', sanitize_key($block_name));
    }

    protected function theme_control_json_filename($block_name, $control_id) {
        $block_slug = $this->block_to_slug($block_name);
        $control_slug = sanitize_key($control_id);
        return sanitize_file_name($block_slug . '-' . $control_slug . '.json');
    }

    protected function theme_control_json_path($block_name, $control_id) {
        return $this->get_theme_block_option_inc_dir() . $this->theme_control_json_filename($block_name, $control_id);
    }

    protected function load_theme_controls() {
        $dir = $this->get_theme_block_option_inc_dir();
        if (!is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $files = glob($dir . '*.json');
        if (!is_array($files) || empty($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $raw = file_get_contents($file);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $block = isset($decoded['block']) ? (string) $decoded['block'] : '';
            $control = isset($decoded['control']) && is_array($decoded['control']) ? $decoded['control'] : null;
            if ($block === '' || !$control) {
                continue;
            }
            $normalized = $this->normalize_control($control);
            if (!$normalized || empty($normalized['id'])) {
                continue;
            }
            if (!isset($out[$block])) {
                $out[$block] = [];
            }
            $out[$block][] = $normalized;
        }

        return $this->normalize_switches($out);
    }

    protected function select_sources(array $stored, array $filtered, array $theme) {
        $sources = get_option(self::CONTROL_SOURCES_OPTION_KEY, []);
        if (!is_array($sources)) {
            $sources = [];
        }

        $inStored = [];
        foreach ($stored as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $inStored[$b . '|' . $c['id']] = true;
            }
        }

        $inFiltered = [];
        foreach ($filtered as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $inFiltered[$b . '|' . $c['id']] = true;
            }
        }

        $inTheme = [];
        foreach ($theme as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $inTheme[$b . '|' . $c['id']] = true;
            }
        }

        $getSource = function($key) use ($sources, $inStored, $inFiltered, $inTheme) {
            if (isset($sources[$key]) && is_string($sources[$key]) && $sources[$key] !== '') {
                return $sources[$key];
            }
            if (isset($inTheme[$key])) {
                return 'theme';
            }
            if (isset($inFiltered[$key])) {
                return 'filter';
            }
            if (isset($inStored[$key])) {
                return 'plugin';
            }
            return 'plugin';
        };

        $storedOut = [];
        foreach ($stored as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $key = $b . '|' . $c['id'];
                if ($getSource($key) !== 'plugin') { continue; }
                if (!isset($storedOut[$b])) { $storedOut[$b] = []; }
                $storedOut[$b][] = $c;
            }
        }

        $filteredOut = [];
        foreach ($filtered as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $key = $b . '|' . $c['id'];
                if ($getSource($key) !== 'filter') { continue; }
                if (!isset($filteredOut[$b])) { $filteredOut[$b] = []; }
                $filteredOut[$b][] = $c;
            }
        }

        $themeOut = [];
        foreach ($theme as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $key = $b . '|' . $c['id'];
                if ($getSource($key) !== 'theme') { continue; }
                if (!isset($themeOut[$b])) { $themeOut[$b] = []; }
                $themeOut[$b][] = $c;
            }
        }

        return [$storedOut, $filteredOut, $themeOut];
    }

    protected function get_source_mode() {
        $mode = get_option(self::SOURCE_MODE_OPTION_KEY, 'merge');
        if (!is_string($mode)) {
            $mode = 'merge';
        }
        $mode = sanitize_key($mode);
        if (!in_array($mode, ['plugin', 'filter', 'theme', 'merge'], true)) {
            $mode = 'merge';
        }
        return $mode;
    }

    public function enqueue_editor_assets() {
        $handle = 'up-block-switches-editor';
        $src = plugins_url('assets/editor.js', __FILE__);
        $deps = [
            'wp-blocks',
            'wp-i18n',
            'wp-element',
            'wp-components',
            'wp-compose',
            'wp-data',
            'wp-edit-post',
            'wp-plugins',
            'wp-api-fetch',
            'wp-hooks',
            'wp-block-editor',
        ];
        wp_enqueue_script($handle, $src, $deps, filemtime(__DIR__ . '/assets/editor.js'), true);
        wp_add_inline_script('wp-api-fetch', 'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( "' . wp_create_nonce('wp_rest') . '" ) );');
    }

    protected function get_option_blocks() {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            return [];
        }
        return $this->normalize_switches($saved);
    }

    protected function normalize_switches($switches) {
        $normalized = [];
        if (!is_array($switches)) {
            return $normalized;
        }

        foreach ($switches as $blockName => $controls) {
            if (!is_string($blockName)) {
                continue;
            }
            $blockKey = trim($blockName);
            if ($blockKey === '') {
                continue;
            }
            if (!is_array($controls)) {
                continue;
            }

            $clean = [];
            foreach ($controls as $control) {
                if (!is_array($control)) {
                    continue;
                }
                $normalizedControl = $this->normalize_control($control);
                if ($normalizedControl) {
                    $clean[] = $normalizedControl;
                }
            }

            if ($clean) {
                // Autoriser la liste de blocs séparés par virgules
                $targets = preg_split('/\s*,\s*/', $blockKey);
                if (!is_array($targets) || !$targets) {
                    $targets = [$blockKey];
                }
                foreach ($targets as $target) {
                    if ($target === '') { continue; }
                    if (!isset($normalized[$target])) {
                        $normalized[$target] = [];
                    }
                    // concaténer en préservant l'ordre
                    $normalized[$target] = array_merge($normalized[$target], $clean);
                }
            }
        }

        return $normalized;
    }

    protected function normalize_control(array $control) {
        $id = isset($control['id']) ? sanitize_key($control['id']) : '';
        $label = isset($control['label']) ? sanitize_text_field($control['label']) : '';
        $panel = isset($control['panel']) ? sanitize_text_field($control['panel']) : '';
        $type = isset($control['type']) ? sanitize_key($control['type']) : 'toggle';

        if (!$id || !$label) {
            return null;
        }

        if ($type === 'select' || $type === 'preset') {
            $options = [];
            if (isset($control['options']) && is_array($control['options'])) {
                foreach ($control['options'] as $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $optId = isset($opt['id']) ? sanitize_key($opt['id']) : '';
                    $optLabel = isset($opt['label']) ? sanitize_text_field($opt['label']) : '';
                    $optClasses = $this->normalize_classes($opt['classes'] ?? null, $opt['class'] ?? null);
                    if ($optId && $optLabel && $optClasses) {
                        $options[] = [
                            'id' => $optId,
                            'label' => $optLabel,
                            'classes' => $optClasses,
                            'class' => $optClasses[0],
                        ];
                    }
                }
            }
            if (!$options) {
                return null;
            }

            $normalized = [
                'type' => $type,
                'id' => $id,
                'label' => $label,
                'options' => $options,
            ];
        } elseif ($type === 'palette') {
            $classPrefix = isset($control['class']) ? sanitize_html_class($control['class']) : '';
            $rawSource = isset($control['source']) ? trim((string)$control['source']) : '';
            $srcLower = strtolower($rawSource);
            // Map to canonical keys
            if ($srcLower === 'colors' || $srcLower === 'color') {
                $source = 'colors';
            } elseif ($srcLower === 'fontsizes' || $srcLower === 'font-sizes' || $srcLower === 'font_size' || $srcLower === 'font') {
                $source = 'fontSizes';
            } elseif ($srcLower === 'spacing' || $srcLower === 'sizes' || $srcLower === 'sizing') {
                $source = 'spacing';
            } else {
                $source = '';
            }
            if (!$classPrefix || !$source) {
                return null;
            }

            $normalized = [
                'type' => 'palette',
                'id' => $id,
                'label' => $label,
                'class' => $classPrefix,
                'source' => $source,
            ];
        } elseif ($type === 'number') {
            $classPrefix = isset($control['class']) ? sanitize_html_class($control['class']) : '';
            if (!$classPrefix) {
                return null;
            }

            $min = $this->sanitize_number_field($control['min'] ?? null);
            $max = $this->sanitize_number_field($control['max'] ?? null);
            if ($min !== null && $max !== null && $min > $max) {
                [$min, $max] = [$max, $min];
            }

            $step = $this->sanitize_number_field($control['step'] ?? null);
            if ($step !== null && $step <= 0) {
                $step = null;
            }

            $default = $this->sanitize_number_field($control['default'] ?? null);
            if ($default !== null) {
                if ($min !== null && $default < $min) {
                    $default = $min;
                }
                if ($max !== null && $default > $max) {
                    $default = $max;
                }
            }

            $normalized = [
                'type' => 'number',
                'id' => $id,
                'label' => $label,
                'class' => $classPrefix,
            ];

            if ($min !== null) {
                $normalized['min'] = $min;
            }
            if ($max !== null) {
                $normalized['max'] = $max;
            }
            if ($step !== null) {
                $normalized['step'] = $step;
            }
            if ($default !== null) {
                $normalized['default'] = $default;
            }
        } else {
            $classes = $this->normalize_classes($control['classes'] ?? null, $control['class'] ?? null);
            if (!$classes) {
                return null;
            }

            $normalized = [
                'type' => 'toggle',
                'id' => $id,
                'label' => $label,
                'classes' => $classes,
                'class' => $classes[0],
            ];
        }

        // Extra static classes applied unconditionally
        $extraRaw = $control['extra'] ?? ($control['extraClasses'] ?? null);
        if ($extraRaw !== null) {
            $extra = $this->normalize_classes($extraRaw);
            if ($extra) {
                $normalized['extra'] = $extra;
            }
        }

        if ($panel) {
            $normalized['panel'] = $panel;
        }

        if (!empty($control['description'])) {
            $normalized['description'] = sanitize_text_field($control['description']);
        }

        return $normalized;
    }

    protected function normalize_classes($classes, $fallback = null) {
        $list = [];

        if (is_array($classes)) {
            foreach ($classes as $class) {
                if (is_string($class)) {
                    $sanitized = sanitize_html_class($class);
                    if ($sanitized) {
                        $list[] = $sanitized;
                    }
                }
            }
        } elseif (is_string($classes)) {
            $classes = str_replace(',', ' ', $classes);
            $parts = preg_split('/\s+/', trim($classes));
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $sanitized = sanitize_html_class($part);
                    if ($sanitized) {
                        $list[] = $sanitized;
                    }
                }
            }
        }

        if (!$list && is_string($fallback)) {
            $fallback = str_replace(',', ' ', $fallback);
            $parts = preg_split('/\s+/', trim($fallback));
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $sanitized = sanitize_html_class($part);
                    if ($sanitized) {
                        $list[] = $sanitized;
                    }
                }
            }
        }

        return array_values(array_unique($list));
    }

    protected function sanitize_number_field($value) {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (!is_numeric($value)) {
            return null;
        }

        return 0 + $value;
    }

    protected function merge_configs(array ...$configs) {
        $merged = [];

        foreach ($configs as $config) {
            foreach ($config as $block => $controls) {
                if (!isset($merged[$block])) {
                    $merged[$block] = [];
                }

                foreach ($controls as $control) {
                    if (empty($control['id'])) {
                        continue;
                    }

                    $existingIndex = null;
                    foreach ($merged[$block] as $index => $existing) {
                        if (!empty($existing['id']) && $existing['id'] === $control['id']) {
                            $existingIndex = $index;
                            break;
                        }
                    }

                    if ($existingIndex !== null) {
                        $merged[$block][$existingIndex] = array_merge($merged[$block][$existingIndex], $control);
                    } else {
                        $merged[$block][] = $control;
                    }
                }
            }
        }

        return $merged;
    }

    public function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, '/switches', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_switches'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);
    }

    public function register_admin_page() {
        // Menu principal du plugin
        $this->admin_page_hook = add_menu_page(
            __('UP Gutenberg Options', 'up'),
            __('Up Options', 'up'),
            'manage_options',
            self::ADMIN_MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-welcome-widgets-menus',
            59
        );

        // Sous-menu: Configuration (pointe vers la page principale)
        $this->config_page_hook = add_submenu_page(
            self::ADMIN_MENU_SLUG,
            __('Configuration', 'up'),
            __('Configuration', 'up'),
            'manage_options',
            self::ADMIN_PAGE_SLUG,
            [$this, 'render_admin_page']
        );

        // Sous-menu: Préconfigurations
        $this->preset_page_hook = add_submenu_page(
            self::ADMIN_MENU_SLUG,
            __('Préconfigurations', 'up'),
            __('Préconfigurations', 'up'),
            'manage_options',
            self::PRESET_PAGE_SLUG,
            [$this, 'render_presets_page_cb']
        );

        // Sous-menu: Configurations du filtre
        $this->filter_page_hook = add_submenu_page(
            self::ADMIN_MENU_SLUG,
            __('Configurations du filtre', 'up'),
            __('Configurations du filtre', 'up'),
            'manage_options',
            self::FILTER_PAGE_SLUG,
            [$this, 'render_filter_configs_page']
        );

        // Sous-menu: Générer dans le thème
        $this->theme_generate_page_hook = add_submenu_page(
            self::ADMIN_MENU_SLUG,
            __('Générer dans le thème', 'up'),
            __('Générer dans le thème', 'up'),
            'manage_options',
            self::THEME_GENERATE_PAGE_SLUG,
            [$this, 'render_theme_generate_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        if (($this->admin_page_hook && $hook === $this->admin_page_hook) || ($this->config_page_hook && $hook === $this->config_page_hook)) {
            $handle = 'up-ge-admin';
            $src = plugins_url('assets/admin.js', __FILE__);
            $deps = ['wp-element', 'wp-components', 'wp-i18n'];
            wp_enqueue_script($handle, $src, $deps, file_exists(__DIR__ . '/assets/admin.js') ? filemtime(__DIR__ . '/assets/admin.js') : false, true);

            wp_localize_script($handle, 'UPGEAdminData', [
                'config' => $this->get_option_blocks(),
                'ajax' => [
                    'url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('up_ge_admin'),
                ],
                'strings' => [
                    'title' => __('Configuration des options Gutenberg', 'up'),
                    'blocks' => __('Blocs', 'up'),
                    'addBlock' => __('Ajouter un bloc', 'up'),
                    'blockNamePlaceholder' => __('core/paragraph, core/heading', 'up'),
                    'addControl' => __('Ajouter un contrôle', 'up'),
                    'label' => __('Libellé', 'up'),
                    'id' => __('Identifiant', 'up'),
                    'panel' => __('Panneau', 'up'),
                    'type' => __('Type', 'up'),
                    'class' => __('Classe', 'up'),
                    'classes' => __('Classes', 'up'),
                    'description' => __('Description', 'up'),
                    'optionLabel' => __('Libellé option', 'up'),
                    'optionId' => __('Identifiant option', 'up'),
                    'optionClasses' => __('Classes option', 'up'),
                    'remove' => __('Supprimer', 'up'),
                    'preset' => __('Preset', 'up'),
                    'toggle' => __('Toggle', 'up'),
                    'select' => __('Select', 'up'),
                    'number' => __('Nombre', 'up'),
                    'presetTitle' => __('Presets / Bundles', 'up'),
                    'savePanel' => __('Enregistrer le panneau comme préconfig', 'up'),
                    'savingPanel' => __('Enregistrement…', 'up'),
                    'panelSaved' => __('Préconfiguration enregistrée.', 'up'),
                    'panelSaveError' => __('Impossible d’enregistrer la préconfiguration.', 'up'),
                    'classPrefix' => __('Préfixe de classe', 'up'),
                    'minValue' => __('Valeur min', 'up'),
                    'maxValue' => __('Valeur max', 'up'),
                    'stepValue' => __('Pas', 'up'),
                    'defaultValue' => __('Valeur par défaut', 'up'),
                    'extraClasses' => __('Classes supplémentaires', 'up'),
                    'palette' => __('Palette', 'up'),
                    'paletteSource' => __('Source', 'up'),
                    'paletteColors' => __('Couleurs', 'up'),
                    'paletteFontSizes' => __('Tailles de police', 'up'),
                ],
            ]);
        } elseif ($this->preset_page_hook && $hook === $this->preset_page_hook) {
            $handle = 'up-ge-presets-admin';
            $src = plugins_url('assets/presets-admin.js', __FILE__);
            $deps = [];
            wp_enqueue_script($handle, $src, $deps, file_exists(__DIR__ . '/assets/presets-admin.js') ? filemtime(__DIR__ . '/assets/presets-admin.js') : false, true);

            $presets = array_map(function($preset) {
                $blocks = isset($preset['data']['blocks']) && is_array($preset['data']['blocks']) ? implode(', ', $preset['data']['blocks']) : __('Inconnu', 'up');
                $panel = isset($preset['data']['panel']) ? $preset['data']['panel'] : __('Inconnu', 'up');
                $count = isset($preset['data']['controls']) && is_array($preset['data']['controls']) ? count($preset['data']['controls']) : 0;
                $meta = sprintf(
                    __('Modifié le %s – %s octets', 'up'),
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $preset['modified']),
                    number_format_i18n($preset['size'])
                );

                return [
                    'file' => $preset['file'],
                    'blocks' => $blocks,
                    'panel' => $panel,
                    'count' => $count,
                    'meta' => $meta,
                    'raw' => $preset['raw'],
                ];
            }, $this->list_presets());

            wp_localize_script($handle, 'UPEGEPresetsData', [
                'presets' => $presets,
                'importUrl' => admin_url('admin-post.php'),
                'nonce' => wp_create_nonce('up_ge_import_preset'),
                'strings' => [
                    'noPreset' => __('Aucune préconfiguration disponible pour le moment.', 'up'),
                    'file' => __('Fichier', 'up'),
                    'blocks' => __('Bloc(s)', 'up'),
                    'panel' => __('Panneau', 'up'),
                    'controls' => __('Contrôles', 'up'),
                    'import' => __('Importer', 'up'),
                    'showJson' => __('Afficher le JSON', 'up'),
                ],
            ]);
        }
    }

    protected function get_save_url($notice = '') {
        $url = add_query_arg(['page' => self::ADMIN_PAGE_SLUG], admin_url('admin.php'));
        if ($notice) {
            $url = add_query_arg('up_ge_notice', $notice, $url);
        }
        return $url;
    }

    public function render_admin_notices() {
        if (!isset($_GET['page']) || !in_array($_GET['page'], [self::ADMIN_MENU_SLUG, self::ADMIN_PAGE_SLUG, self::PRESET_PAGE_SLUG], true)) {
            return;
        }

        $notice = isset($_GET['up_ge_notice']) ? sanitize_key($_GET['up_ge_notice']) : '';
        if (!$notice) {
            return;
        }

        $messages = [
            'saved' => __('Configuration enregistrée.', 'up'),
            'imported' => __('Configuration importée.', 'up'),
            'exported' => __('Export généré.', 'up'),
            'error' => __('Une erreur est survenue, veuillez réessayer.', 'up'),
            'preset_saved' => __('Préconfiguration enregistrée.', 'up'),
            'preset_imported' => __('Préconfiguration importée.', 'up'),
            'preset_error' => __('Une erreur est survenue lors de la gestion des préconfigurations.', 'up'),
        ];

        if (isset($messages[$notice])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$notice]));
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas les permissions nécessaires.', 'up'));
        }

        // Page top-level: dashboard explicatif
        if (isset($_GET['page']) && $_GET['page'] === self::ADMIN_MENU_SLUG) {
            $config = $this->get_option_blocks();
            $presets = $this->list_presets();
            $controlsCount = 0;
            foreach ($config as $blockControls) { $controlsCount += is_array($blockControls) ? count($blockControls) : 0; }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('UP Gutenberg – Tableau de bord', 'up'); ?></h1>
                <p><?php esc_html_e('Gérez vos options Gutenberg, enregistrez des panneaux comme préconfigurations et importez des presets JSON.', 'up'); ?></p>

                <div class="card" style="max-width:840px;">
                    <h2><?php esc_html_e('Raccourcis', 'up'); ?></h2>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::ADMIN_PAGE_SLUG)); ?>"><?php esc_html_e('Ouvrir la Configuration', 'up'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PRESET_PAGE_SLUG)); ?>"><?php esc_html_e('Voir les Préconfigurations', 'up'); ?></a>
                    </p>
                    <ul>
                        <li><?php printf(esc_html__('Blocs configurés: %d', 'up'), count($config)); ?></li>
                        <li><?php printf(esc_html__('Contrôles totaux: %d', 'up'), intval($controlsCount)); ?></li>
                        <li><?php printf(esc_html__('Préconfigurations: %d', 'up'), is_array($presets) ? count($presets) : 0); ?></li>
                    </ul>
                </div>

                <div class="card" style="max-width:840px;">
                    <h2><?php esc_html_e('Astuces & Bonnes pratiques', 'up'); ?></h2>
                    <ul>
                        <li><?php esc_html_e('Pour cibler plusieurs blocs à la fois, écrivez plusieurs types de blocs séparés par des virgules dans le champ Bloc(s) (ex: core/paragraph, core/heading). La configuration sera dupliquée vers chaque bloc.', 'up'); ?></li>
                        <li><?php esc_html_e('Dans un même bloc, chaque contrôle doit avoir un identifiant (id) unique. Deux contrôles avec le même id seront fusionnés.', 'up'); ?></li>
                        <li><?php esc_html_e('Import JSON: la configuration est fusionnée avec l’existant (pas de doublons; mêmes id mis à jour).', 'up'); ?></li>
                        <li><?php esc_html_e('Sélectionnez le même nom de "Panneau" pour regrouper plusieurs contrôles ensemble dans l’éditeur.', 'up'); ?></li>
                        <li><?php esc_html_e('Les presets (préconfigurations) sont des panneaux sauvegardés dans des fichiers JSON (noms en minuscules, sans espaces) que vous pouvez réimporter plus tard.', 'up'); ?></li>
                    </ul>
                </div>
            </div>
            <?php
            return;
        }

        // Sous-page Configuration: interface d’édition
        $config = $this->get_option_blocks();
        $config_json = wp_json_encode($config);
        if (!is_string($config_json)) {
            $config_json = '{}';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('UP Gutenberg Options', 'up'); ?></h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('up_ge_save_config'); ?>
                <input type="hidden" name="action" value="up_ge_save_config">
                <input type="hidden" id="up-ge-config-input" name="config" value="<?php echo esc_attr($config_json); ?>">
                <div id="up-ge-admin-app" data-initial="<?php echo esc_attr($config_json); ?>"></div>
                <?php submit_button(__('Enregistrer', 'up')); ?>
            </form>

            <hr>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-bottom:2rem;">
                <?php wp_nonce_field('up_ge_import_config'); ?>
                <input type="hidden" name="action" value="up_ge_import_config">
                <p><?php esc_html_e('Importer un fichier JSON exporté précédemment.', 'up'); ?></p>
                <input type="file" name="config_file" accept="application/json">
                <?php submit_button(__('Importer', 'up'), 'secondary'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('up_ge_export_config'); ?>
                <input type="hidden" name="action" value="up_ge_export_config">
                <?php submit_button(__('Exporter la configuration', 'up'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public function render_theme_generate_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }

        $stored = $this->normalize_switches($this->get_option_blocks());
        $filtered = $this->normalize_switches(apply_filters('up_block_switches', []));
        $theme = $this->load_theme_controls();
        $all = $this->merge_configs($stored, $filtered, $theme);

        $mode = $this->get_source_mode();

        $storedKeys = [];
        foreach ($stored as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $storedKeys[$b . '|' . $c['id']] = true;
            }
        }

        $filteredKeys = [];
        foreach ($filtered as $b => $controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as $c) {
                if (!is_array($c) || empty($c['id'])) { continue; }
                $filteredKeys[$b . '|' . $c['id']] = true;
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Générer dans le thème', 'up'); ?></h1>
            <p><?php esc_html_e('Générez des fichiers dans le thème pour conserver les options sans le plugin.', 'up'); ?></p>

            <div class="notice notice-info"><p><code><?php echo esc_html($this->get_theme_block_option_base_dir()); ?></code></p></div>

            <?php if (empty($all)): ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Aucune option disponible.', 'up'); ?></p></div>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('up_ge_generate_theme', 'up_ge_generate_theme_nonce'); ?>
                    <input type="hidden" name="action" value="up_ge_generate_theme_root" />

                    <p>
                        <label for="up-ge-source-mode"><strong><?php esc_html_e('Mode de source global', 'up'); ?></strong></label>
                        <select id="up-ge-source-mode" name="source_mode">
                            <option value="merge" <?php selected($mode, 'merge'); ?>><?php esc_html_e('Merge (plugin + filtre + thème)', 'up'); ?></option>
                            <option value="plugin" <?php selected($mode, 'plugin'); ?>><?php esc_html_e('Plugin (config enregistrée)', 'up'); ?></option>
                            <option value="filter" <?php selected($mode, 'filter'); ?>><?php esc_html_e('Filtre (up_block_switches)', 'up'); ?></option>
                            <option value="theme" <?php selected($mode, 'theme'); ?>><?php esc_html_e('Thème (JSON exportés)', 'up'); ?></option>
                        </select>
                    </p>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="check-column"><input type="checkbox" id="up-ge-select-all-controls" /></th>
                                <th scope="col"><?php esc_html_e('Bloc', 'up'); ?></th>
                                <th scope="col"><?php esc_html_e('ID', 'up'); ?></th>
                                <th scope="col"><?php esc_html_e('Label', 'up'); ?></th>
                                <th scope="col"><?php esc_html_e('Type', 'up'); ?></th>
                                <th scope="col"><?php esc_html_e('Plugin', 'up'); ?></th>
                                <th scope="col"><?php esc_html_e('Filtre', 'up'); ?></th>
                                <th scope="col"><?php esc_html_e('Thème', 'up'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all as $block_name => $controls): ?>
                                <?php if (!is_array($controls)) { continue; } ?>
                                <?php foreach ($controls as $control): ?>
                                    <?php
                                    if (!is_array($control) || empty($control['id'])) { continue; }
                                    $cid = (string) $control['id'];
                                    $key = $block_name . '|' . $cid;
                                    $file_path = $this->theme_control_json_path($block_name, $cid);
                                    $exists = file_exists($file_path);
                                    $inStored = isset($storedKeys[$key]);
                                    $inFiltered = isset($filteredKeys[$key]);
                                    ?>
                                    <tr>
                                        <th scope="row" class="check-column">
                                            <input type="checkbox" class="up-ge-control-checkbox" name="selected_controls[]" value="<?php echo esc_attr($key); ?>" />
                                        </th>
                                        <td><code><?php echo esc_html($block_name); ?></code></td>
                                        <td><code><?php echo esc_html($cid); ?></code></td>
                                        <td><?php echo esc_html(isset($control['label']) ? $control['label'] : $cid); ?></td>
                                        <td><?php echo esc_html(isset($control['type']) ? $control['type'] : 'toggle'); ?></td>
                                        <td>
                                            <?php if ($inStored): ?>
                                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                            <?php else: ?>
                                                <span class="dashicons dashicons-minus" style="color: orange;"></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($inFiltered): ?>
                                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                            <?php else: ?>
                                                <span class="dashicons dashicons-minus" style="color: orange;"></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($exists): ?>
                                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                            <?php else: ?>
                                                <span class="dashicons dashicons-minus" style="color: orange;"></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p style="margin-top: 12px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Générer dans le thème (JSON + root.php + class + assets)', 'up'); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            $('#up-ge-select-all-controls').on('change', function(){
                $('.up-ge-control-checkbox').prop('checked', $(this).prop('checked'));
            });
        });
        </script>
        <?php
    }

    protected function write_theme_loader_files() {
        $this->ensure_theme_block_option_directories();

        $root = $this->get_theme_block_option_base_dir() . 'root.php';
        $class = $this->get_theme_block_option_base_dir() . 'class-up-gutenberg-block-option.php';
        $assetEditor = $this->get_theme_block_option_assets_dir() . 'editor.js';

        $pluginEditor = __DIR__ . '/assets/editor.js';
        if (file_exists($pluginEditor)) {
            file_put_contents($assetEditor, file_get_contents($pluginEditor));
        }

        $classCode = "<?php\n";
        $classCode .= "if (!defined('ABSPATH')) { exit; }\n";
        $classCode .= "class UP_Gutenberg_Block_Option {\n";
        $classCode .= "    private static \$instance = null;\n";
        $classCode .= "    public static function get_instance() {\n";
        $classCode .= "        if (null === self::\$instance) { self::\$instance = new self(); }\n";
        $classCode .= "        return self::\$instance;\n";
        $classCode .= "    }\n";
        $classCode .= "    private function __construct() {\n";
        $classCode .= "        add_action('rest_api_init', array(\$this, 'register_rest'));\n";
        $classCode .= "        add_action('enqueue_block_editor_assets', array(\$this, 'enqueue_editor'));\n";
        $classCode .= "    }\n";
        $classCode .= "    public function enqueue_editor() {\n";
        $classCode .= "        if (wp_script_is('up-block-switches-editor', 'registered') || wp_script_is('up-block-switches-editor', 'enqueued')) { return; }\n";
        $classCode .= "        \$src = get_stylesheet_directory_uri() . '/functions/gutenberg-block-option/assets/editor.js';\n";
        $classCode .= "        \$deps = array('wp-blocks','wp-i18n','wp-element','wp-components','wp-compose','wp-data','wp-edit-post','wp-plugins','wp-api-fetch','wp-hooks','wp-block-editor');\n";
        $classCode .= "        wp_enqueue_script('up-block-switches-editor', \$src, \$deps, filemtime(__DIR__ . '/assets/editor.js'), true);\n";
        $classCode .= "        \$nonce = wp_create_nonce('wp_rest');\n";
        $classCode .= "        wp_add_inline_script('wp-api-fetch', 'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( \"' . \$nonce . '\" ) );');\n";
        $classCode .= "    }\n";
        $classCode .= "    public function register_rest() {\n";
        $classCode .= "        register_rest_route('up/v1', '/switches', array(\n";
        $classCode .= "            'methods' => 'GET',\n";
        $classCode .= "            'callback' => array(\$this, 'get_switches'),\n";
        $classCode .= "            'permission_callback' => function(){ return current_user_can('edit_posts'); },\n";
        $classCode .= "        ));\n";
        $classCode .= "    }\n";
        $classCode .= "    public function get_switches(\WP_REST_Request \$request) {\n";
        $classCode .= "        \$mode = get_option('up_ge_source_mode', 'merge');\n";
        $classCode .= "        if (!is_string(\$mode)) { \$mode = 'merge'; }\n";
        $classCode .= "        \$mode = sanitize_key(\$mode);\n";
        $classCode .= "        if (\$mode !== 'plugin' && \$mode !== 'filter' && \$mode !== 'theme' && \$mode !== 'merge') { \$mode = 'merge'; }\n";
        $classCode .= "\n";
        $classCode .= "        // 1) Controls du thème (JSON)\n";
        $classCode .= "        \$themeOut = array();\n";
        $classCode .= "        \$dir = __DIR__ . '/inc/';\n";
        $classCode .= "        \$files = glob(\$dir . '*.json');\n";
        $classCode .= "        if (is_array(\$files)) {\n";
        $classCode .= "            foreach (\$files as \$file) {\n";
        $classCode .= "                if (!is_file(\$file) || !is_readable(\$file)) { continue; }\n";
        $classCode .= "                \$raw = file_get_contents(\$file);\n";
        $classCode .= "                \$decoded = json_decode(\$raw, true);\n";
        $classCode .= "                if (!is_array(\$decoded) || empty(\$decoded['block']) || empty(\$decoded['control']) || !is_array(\$decoded['control'])) { continue; }\n";
        $classCode .= "                \$block = (string) \$decoded['block'];\n";
        $classCode .= "                \$control = \$decoded['control'];\n";
        $classCode .= "                if (empty(\$control['id'])) { continue; }\n";
        $classCode .= "                if (!isset(\$themeOut[\$block])) { \$themeOut[\$block] = array(); }\n";
        $classCode .= "                \$themeOut[\$block][] = \$control;\n";
        $classCode .= "            }\n";
        $classCode .= "        }\n";
        $classCode .= "\n";
        $classCode .= "        // 2) Controls du filtre\n";
        $classCode .= "        \$filterOut = array();\n";
        $classCode .= "        \$filtered = apply_filters('up_block_switches', array());\n";
        $classCode .= "        if (is_array(\$filtered)) {\n";
        $classCode .= "            foreach (\$filtered as \$b => \$controls) {\n";
        $classCode .= "                if (!is_array(\$controls)) { continue; }\n";
        $classCode .= "                \$filterOut[(string)\$b] = \$controls;\n";
        $classCode .= "            }\n";
        $classCode .= "        }\n";
        $classCode .= "\n";
        $classCode .= "        // 3) Controls du plugin (option WP, même si le plugin est désactivé)\n";
        $classCode .= "        \$pluginOut = get_option('up_ge_switches_config', array());\n";
        $classCode .= "        if (!is_array(\$pluginOut)) { \$pluginOut = array(); }\n";
        $classCode .= "\n";
        $classCode .= "        if (\$mode === 'plugin') { return new \\WP_REST_Response(\$pluginOut, 200); }\n";
        $classCode .= "        if (\$mode === 'filter') { return new \\WP_REST_Response(\$filterOut, 200); }\n";
        $classCode .= "        if (\$mode === 'theme') { return new \\WP_REST_Response(\$themeOut, 200); }\n";
        $classCode .= "\n";
        $classCode .= "        // merge: plugin + filtre + thème (thème override filtre override plugin)\n";
        $classCode .= "        \$merged = array();\n";
        $classCode .= "        foreach (array(\$pluginOut, \$filterOut, \$themeOut) as \$conf) {\n";
        $classCode .= "            if (!is_array(\$conf)) { continue; }\n";
        $classCode .= "            foreach (\$conf as \$block => \$controls) {\n";
        $classCode .= "                if (!is_array(\$controls)) { continue; }\n";
        $classCode .= "                if (!isset(\$merged[\$block])) { \$merged[\$block] = array(); }\n";
        $classCode .= "                foreach (\$controls as \$control) {\n";
        $classCode .= "                    if (!is_array(\$control) || empty(\$control['id'])) { continue; }\n";
        $classCode .= "                    \$found = null;\n";
        $classCode .= "                    foreach (\$merged[\$block] as \$i => \$existing) {\n";
        $classCode .= "                        if (is_array(\$existing) && isset(\$existing['id']) && \$existing['id'] === \$control['id']) { \$found = \$i; break; }\n";
        $classCode .= "                    }\n";
        $classCode .= "                    if (null !== \$found) {\n";
        $classCode .= "                        \$merged[\$block][\$found] = array_merge(\$merged[\$block][\$found], \$control);\n";
        $classCode .= "                    } else {\n";
        $classCode .= "                        \$merged[\$block][] = \$control;\n";
        $classCode .= "                    }\n";
        $classCode .= "                }\n";
        $classCode .= "            }\n";
        $classCode .= "        }\n";
        $classCode .= "\n";
        $classCode .= "        return new \\WP_REST_Response(\$merged, 200);\n";
        $classCode .= "    }\n";
        $classCode .= "}\n";
        $classCode .= "\n";
        $classCode .= "if (!class_exists('Up_Block_Switches')) {\n";
            $classCode .= "    UP_Gutenberg_Block_Option::get_instance();\n";
        $classCode .= "}\n";
        file_put_contents($class, $classCode);

        $rootCode = "<?php\n";
        $rootCode .= "if (!defined('ABSPATH')) { exit; }\n";
        $rootCode .= "if (file_exists(__DIR__ . '/class-up-gutenberg-block-option.php')) { include_once __DIR__ . '/class-up-gutenberg-block-option.php'; }\n";
        file_put_contents($root, $rootCode);
    }

    protected function write_theme_control_json($block, array $control) {
        if (empty($control['id'])) {
            return false;
        }
        $this->ensure_theme_block_option_directories();
        $path = $this->theme_control_json_path($block, $control['id']);
        $payload = ['block' => $block, 'control' => $control];
        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return false;
        }
        return file_put_contents($path, $encoded) !== false;
    }

    public function handle_generate_theme_root() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        if (!isset($_POST['up_ge_generate_theme_nonce']) || !wp_verify_nonce($_POST['up_ge_generate_theme_nonce'], 'up_ge_generate_theme')) {
            wp_die(__('Nonce invalide.', 'up'));
        }

        $mode = isset($_POST['source_mode']) ? sanitize_key(wp_unslash($_POST['source_mode'])) : 'merge';
        if (!in_array($mode, ['plugin', 'filter', 'theme', 'merge'], true)) {
            $mode = 'merge';
        }
        update_option(self::SOURCE_MODE_OPTION_KEY, $mode);

        $selected = isset($_POST['selected_controls']) && is_array($_POST['selected_controls']) ? array_map('sanitize_text_field', wp_unslash($_POST['selected_controls'])) : [];
        if (!empty($selected)) {
            $stored = $this->normalize_switches($this->get_option_blocks());
            $filtered = $this->normalize_switches(apply_filters('up_block_switches', []));
            $theme = $this->load_theme_controls();
            $all = $this->merge_configs($stored, $filtered, $theme);

            foreach ($selected as $key) {
                $parts = explode('|', $key, 2);
                if (count($parts) !== 2) { continue; }
                $block = sanitize_text_field($parts[0]);
                $cid = sanitize_key($parts[1]);
                if (!$block || !$cid) { continue; }
                if (!isset($all[$block]) || !is_array($all[$block])) { continue; }
                foreach ($all[$block] as $c) {
                    if (!is_array($c) || empty($c['id']) || $c['id'] !== $cid) { continue; }
                    $this->write_theme_control_json($block, $c);
                    break;
                }
            }

            $this->write_theme_loader_files();
        }

        wp_safe_redirect(add_query_arg(['page' => self::THEME_GENERATE_PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    public function handle_save_config() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        check_admin_referer('up_ge_save_config');

        $config_json = isset($_POST['config']) ? wp_unslash($_POST['config']) : '';
        $decoded = json_decode($config_json, true);

        if (!is_array($decoded)) {
            wp_safe_redirect($this->get_save_url('error'));
            exit;
        }

        $normalized = $this->normalize_switches($decoded);
        update_option(self::OPTION_KEY, $normalized);

        wp_safe_redirect($this->get_save_url('saved'));
        exit;
    }

    public function handle_import_config() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        check_admin_referer('up_ge_import_config');

        if (!isset($_FILES['config_file']) || empty($_FILES['config_file']['tmp_name'])) {
            wp_safe_redirect($this->get_save_url('error'));
            exit;
        }

        $file = $_FILES['config_file'];
        $contents = file_get_contents($file['tmp_name']);
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            wp_safe_redirect($this->get_save_url('error'));
            exit;
        }

        $normalized = $this->normalize_switches($decoded);
        $existing = $this->get_option_blocks();
        $merged = $this->merge_configs($existing, $normalized);

        update_option(self::OPTION_KEY, $merged);

        wp_safe_redirect($this->get_save_url('imported'));
        exit;
    }

    public function handle_export_config() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        check_admin_referer('up_ge_export_config');

        $config = $this->get_option_blocks();
        $json = wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        nocache_headers();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="up-gutenberg-options.json"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    protected function get_preset_dir() {
        $dir = trailingslashit(__DIR__ . '/' . self::PRESET_DIR);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir) && !file_exists($dir . 'index.php')) {
            file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    protected function list_presets() {
        $dir = $this->get_preset_dir();
        $files = glob($dir . '*.json');
        if (!$files) {
            return [];
        }

        $presets = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $data = json_decode($contents, true);
            if (!is_array($data)) {
                $data = null;
            }
            $presets[] = [
                'file' => basename($file),
                'path' => $file,
                'modified' => filemtime($file),
                'size' => filesize($file),
                'data' => $data,
                'raw' => $contents,
            ];
        }

        return $presets;
    }

    protected function preset_file_path($filename) {
        $filename = sanitize_file_name($filename);
        $dir = $this->get_preset_dir();
        return $dir . $filename;
    }

    protected function build_preset_filename($blocks, $panel) {
        if (!is_array($blocks)) {
            $blocks = [$blocks];
        }
        $firstBlock = isset($blocks[0]) ? $blocks[0] : 'preset';
        $blockSlug = sanitize_title($firstBlock);
        $panelSlug = sanitize_title($panel ? $panel : 'options-up');
        if (!$blockSlug) {
            $blockSlug = 'preset';
        }
        if (!$panelSlug) {
            $panelSlug = 'panel';
        }
        return strtolower($blockSlug . '-' . $panelSlug . '.json');
    }

    protected function get_preset_redirect_url($notice = '') {
        $url = add_query_arg(['page' => self::PRESET_PAGE_SLUG], admin_url('admin.php'));
        if ($notice) {
            $url = add_query_arg('up_ge_notice', $notice, $url);
        }
        return $url;
    }

    public function render_presets_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Préconfigurations Gutenberg', 'up'); ?></h1>
            <p><?php esc_html_e('Les préconfigurations enregistrées peuvent être ré-importées dans la configuration principale. Chaque import fusionne avec les contrôles existants.', 'up'); ?></p>
            <div id="up-ge-presets-list"></div>
        </div>
        <?php
    }

    public function handle_import_preset() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        check_admin_referer('up_ge_import_preset');

        $file = isset($_POST['preset_file']) ? sanitize_file_name(wp_unslash($_POST['preset_file'])) : '';
        if (!$file) {
            wp_safe_redirect($this->get_preset_redirect_url('preset_error'));
            exit;
        }

        $path = $this->preset_file_path($file);
        if (!file_exists($path)) {
            wp_safe_redirect($this->get_preset_redirect_url('preset_error'));
            exit;
        }

        $contents = file_get_contents($path);
        $data = json_decode($contents, true);
        if (!is_array($data) || empty($data['blocks']) || empty($data['controls'])) {
            wp_safe_redirect($this->get_preset_redirect_url('preset_error'));
            exit;
        }

        $blocks = is_array($data['blocks']) ? array_filter(array_map('sanitize_text_field', $data['blocks'])) : [];
        if (!$blocks) {
            wp_safe_redirect($this->get_preset_redirect_url('preset_error'));
            exit;
        }

        $panel = isset($data['panel']) ? sanitize_text_field($data['panel']) : '';
        $controls = isset($data['controls']) && is_array($data['controls']) ? $data['controls'] : [];
        if (!$controls) {
            wp_safe_redirect($this->get_preset_redirect_url('preset_error'));
            exit;
        }

        $payload = [];
        foreach ($blocks as $block) {
            foreach ($controls as $control) {
                if (!isset($payload[$block])) {
                    $payload[$block] = [];
                }
                $controlData = $control;
                if ($panel) {
                    $controlData['panel'] = $panel;
                }
                $payload[$block][] = $controlData;
            }
        }

        $normalized = $this->normalize_switches($payload);
        $existing = $this->get_option_blocks();
        $merged = $this->merge_configs($existing, $normalized);
        update_option(self::OPTION_KEY, $merged);

        wp_safe_redirect($this->get_preset_redirect_url('preset_imported'));
        exit;
    }

    public function handle_save_panel_preset() {
        check_ajax_referer('up_ge_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'up')], 403);
        }

        $block = isset($_POST['block']) ? sanitize_text_field(wp_unslash($_POST['block'])) : '';
        $panel = isset($_POST['panel']) ? sanitize_text_field(wp_unslash($_POST['panel'])) : '';
        $controls = isset($_POST['controls']) ? wp_unslash($_POST['controls']) : '';

        if (!$block || !$panel || !$controls) {
            wp_send_json_error(['message' => __('Paramètres manquants.', 'up')], 400);
        }

        $decoded = json_decode($controls, true);
        if (!is_array($decoded) || !$decoded) {
            wp_send_json_error(['message' => __('Format des contrôles invalide.', 'up')], 400);
        }

        $blocks = array_filter(array_map('trim', explode(',', $block)));
        if (!$blocks) {
            $blocks = [$block];
        }

        $filename = $this->build_preset_filename($blocks, $panel);
        $path = $this->preset_file_path($filename);

        $payload = [
            'blocks' => array_values($blocks),
            'panel' => $panel,
            'controls' => $decoded,
        ];

        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            wp_send_json_error(['message' => __('Impossible de sérialiser la préconfiguration.', 'up')], 500);
        }

        $dir = $this->get_preset_dir();
        if (!is_dir($dir) || !wp_is_writable($dir)) {
            wp_send_json_error(['message' => __('Le dossier de préconfiguration est indisponible.', 'up')], 500);
        }

        $written = file_put_contents($path, $encoded);
        if ($written === false) {
            wp_send_json_error(['message' => __('Échec de l’enregistrement du fichier.', 'up')], 500);
        }

        wp_send_json_success(['message' => __('Préconfiguration enregistrée.', 'up'), 'file' => $filename]);
    }

    // Wrapper pour contourner d'éventuels problèmes de résolution de callback
    public function render_presets_page_cb() {
        return $this->render_presets_page();
    }

    /**
     * Gère la sauvegarde d'un contrôle individuel comme préconfiguration (AJAX)
     */
    public function handle_save_single_control_as_preset() {
        check_ajax_referer('up_ge_filter_preset', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'up')], 403);
        }
        
        $block_name = isset($_POST['block']) ? sanitize_text_field(wp_unslash($_POST['block'])) : '';
        $control_index = isset($_POST['control_index']) ? intval($_POST['control_index']) : -1;
        $panel_name = isset($_POST['panel']) ? sanitize_text_field(wp_unslash($_POST['panel'])) : '';
        
        if (!$block_name || $control_index < 0 || !$panel_name) {
            wp_send_json_error(['message' => __('Paramètres manquants.', 'up')], 400);
        }
        
        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        if (!isset($normalized_configs[$block_name])) {
            wp_send_json_error(['message' => __('Configuration de bloc non trouvée.', 'up')], 404);
        }
        
        $controls = $normalized_configs[$block_name];
        if (!isset($controls[$control_index])) {
            wp_send_json_error(['message' => __('Contrôle non trouvé.', 'up')], 404);
        }
        
        $single_control = $controls[$control_index];
        
        // Créer le nom du fichier pour un contrôle unique
        $control_id = isset($single_control['id']) ? $single_control['id'] : 'control-' . $control_index;
        $filename = sanitize_file_name(strtolower($control_id . '-' . sanitize_title($panel_name) . '.json'));
        $path = $this->preset_file_path($filename);
        
        // Préparer les données de la préconfiguration avec un seul contrôle
        $preset_data = [
            'blocks' => [$block_name],
            'panel' => $panel_name,
            'controls' => [$single_control]  // Un seul contrôle dans le tableau
        ];
        
        $encoded = wp_json_encode($preset_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            wp_send_json_error(['message' => __('Impossible de sérialiser la préconfiguration.', 'up')], 500);
        }
        
        $dir = $this->get_preset_dir();
        if (!$dir) {
            wp_send_json_error(['message' => __('Le dossier de préconfiguration est indisponible.', 'up')], 500);
        }
        
        $written = file_put_contents($path, $encoded);
        if ($written === false) {
            wp_send_json_error(['message' => __("Échec de l'enregistrement du fichier.", 'up')], 500);
        }
        
        wp_send_json_success(['message' => __('Contrôle enregistré comme préconfiguration avec succès.', 'up'), 'file' => $filename]);
    }
    
    /**
     * Gère la sauvegarde d'un contrôle individuel comme préconfiguration (POST)
     */
    public function handle_save_single_control_as_preset_post() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        
        check_admin_referer('up_ge_filter_preset', 'nonce');
        
        $block_name = isset($_POST['block']) ? sanitize_text_field(wp_unslash($_POST['block'])) : '';
        $control_index = isset($_POST['control_index']) ? intval($_POST['control_index']) : -1;
        $panel_name = isset($_POST['panel']) ? sanitize_text_field(wp_unslash($_POST['panel'])) : '';
        
        if (!$block_name || $control_index < 0 || !$panel_name) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        if (!isset($normalized_configs[$block_name]) || !isset($normalized_configs[$block_name][$control_index])) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $single_control = $normalized_configs[$block_name][$control_index];
        
        // Créer le nom du fichier pour un contrôle unique
        $control_id = isset($single_control['id']) ? $single_control['id'] : 'control-' . $control_index;
        $filename = sanitize_file_name(strtolower($control_id . '-' . sanitize_title($panel_name) . '.json'));
        $path = $this->preset_file_path($filename);
        
        // Préparer les données de la préconfiguration avec un seul contrôle
        $preset_data = [
            'blocks' => [$block_name],
            'panel' => $panel_name,
            'controls' => [$single_control]
        ];
        
        $encoded = wp_json_encode($preset_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $dir = $this->get_preset_dir();
        if (!$dir) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $written = file_put_contents($path, $encoded);
        if ($written === false) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'preset_saved'], admin_url('admin.php')));
        exit;
    }

    /**
     * Gère la sauvegarde d'une configuration du filtre comme préconfiguration (AJAX)
     */
    public function handle_save_filter_as_preset() {
        check_ajax_referer('up_ge_filter_preset', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'up')], 403);
        }
        
        $block_name = isset($_POST['block']) ? sanitize_text_field(wp_unslash($_POST['block'])) : '';
        $panel_name = isset($_POST['panel']) ? sanitize_text_field(wp_unslash($_POST['panel'])) : '';
        
        if (!$block_name || !$panel_name) {
            wp_send_json_error(['message' => __('Paramètres manquants.', 'up')], 400);
        }
        
        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        if (!isset($normalized_configs[$block_name])) {
            wp_send_json_error(['message' => __('Configuration de bloc non trouvée.', 'up')], 404);
        }
        
        $controls = $normalized_configs[$block_name];
        
        // Créer le nom du fichier
        $filename = $this->build_preset_filename([$block_name], $panel_name);
        $path = $this->preset_file_path($filename);
        
        // Préparer les données de la préconfiguration
        $preset_data = [
            'blocks' => [$block_name],
            'panel' => $panel_name,
            'controls' => $controls
        ];
        
        $encoded = wp_json_encode($preset_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            wp_send_json_error(['message' => __('Impossible de sérialiser la préconfiguration.', 'up')], 500);
        }
        
        $dir = $this->get_preset_dir();
        if (!$dir) {
            wp_send_json_error(['message' => __('Le dossier de préconfiguration est indisponible.', 'up')], 500);
        }
        
        $written = file_put_contents($path, $encoded);
        if ($written === false) {
            wp_send_json_error(['message' => __("Échec de l'enregistrement du fichier.", 'up')], 500);
        }
        
        wp_send_json_success(['message' => __('Préconfiguration enregistrée avec succès.', 'up'), 'file' => $filename]);
    }
    
    /**
     * Gère la sauvegarde d'une configuration du filtre comme préconfiguration (POST)
     */
    public function handle_save_filter_as_preset_post() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        
        check_admin_referer('up_ge_filter_preset', 'nonce');
        
        $block_name = isset($_POST['block']) ? sanitize_text_field(wp_unslash($_POST['block'])) : '';
        $panel_name = isset($_POST['panel']) ? sanitize_text_field(wp_unslash($_POST['panel'])) : '';
        
        if (!$block_name || !$panel_name) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        if (!isset($normalized_configs[$block_name])) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $controls = $normalized_configs[$block_name];
        
        // Créer le nom du fichier
        $filename = $this->build_preset_filename([$block_name], $panel_name);
        $path = $this->preset_file_path($filename);
        
        // Préparer les données de la préconfiguration
        $preset_data = [
            'blocks' => [$block_name],
            'panel' => $panel_name,
            'controls' => $controls
        ];
        
        $encoded = wp_json_encode($preset_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $dir = $this->get_preset_dir();
        if (!$dir) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $written = file_put_contents($path, $encoded);
        if ($written === false) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'preset_saved'], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Gère la sauvegarde de toutes les configurations du filtre comme préconfigurations (AJAX)
     */
    public function handle_save_all_filters_as_presets() {
        check_ajax_referer('up_ge_filter_preset', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'up')], 403);
        }
        
        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        if (empty($normalized_configs)) {
            wp_send_json_error(['message' => __('Aucune configuration à sauvegarder.', 'up')], 404);
        }
        
        $saved_count = 0;
        $errors = [];
        
        foreach ($normalized_configs as $block_name => $controls) {
            $panel_name = sprintf(__('Options %s', 'up'), $block_name);
            
            // Créer le nom du fichier
            $filename = $this->build_preset_filename([$block_name], $panel_name);
            $path = $this->preset_file_path($filename);
            
            // Préparer les données de la préconfiguration
            $preset_data = [
                'blocks' => [$block_name],
                'panel' => $panel_name,
                'controls' => $controls
            ];
            
            $encoded = wp_json_encode($preset_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                $errors[] = sprintf(__('Impossible de sérialiser %s', 'up'), $block_name);
                continue;
            }
            
            $written = file_put_contents($path, $encoded);
            if ($written === false) {
                $errors[] = sprintf(__('Échec de sauvegarde pour %s', 'up'), $block_name);
                continue;
            }
            
            $saved_count++;
        }
        
        if ($saved_count === 0 && !empty($errors)) {
            wp_send_json_error(['message' => implode(', ', $errors)], 500);
        } elseif ($saved_count > 0 && !empty($errors)) {
            wp_send_json_success([
                'message' => sprintf(__('%d préconfigurations sauvegardées avec quelques erreurs: %s', 'up'), 
                    $saved_count, implode(', ', $errors))
            ]);
        } else {
            wp_send_json_success(['message' => sprintf(__('%d préconfigurations sauvegardées avec succès.', 'up'), $saved_count)]);
        }
    }
    
    /**
     * Gère la sauvegarde de toutes les configurations du filtre comme préconfigurations (POST)
     */
    public function handle_save_all_filters_as_presets_post() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }
        
        check_admin_referer('up_ge_filter_preset', 'nonce');
        
        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        if (empty($normalized_configs)) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
            exit;
        }
        
        $saved_count = 0;
        
        foreach ($normalized_configs as $block_name => $controls) {
            $panel_name = sprintf(__('Options %s', 'up'), $block_name);
            
            // Créer le nom du fichier
            $filename = $this->build_preset_filename([$block_name], $panel_name);
            $path = $this->preset_file_path($filename);
            
            // Préparer les données de la préconfiguration
            $preset_data = [
                'blocks' => [$block_name],
                'panel' => $panel_name,
                'controls' => $controls
            ];
            
            $encoded = wp_json_encode($preset_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                continue;
            }
            
            $written = file_put_contents($path, $encoded);
            if ($written !== false) {
                $saved_count++;
            }
        }
        
        if ($saved_count > 0) {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'preset_saved'], admin_url('admin.php')));
        } else {
            wp_safe_redirect(add_query_arg(['page' => self::FILTER_PAGE_SLUG, 'up_ge_notice' => 'error'], admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Affiche la page des configurations du filtre
     */
    public function render_filter_configs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Accès refusé.', 'up'));
        }

        // Récupérer les configurations du filtre
        $filter_configs = apply_filters('up_block_switches', []);
        $normalized_configs = $this->normalize_switches($filter_configs);
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Configurations du filtre up_block_switches', 'up'); ?></h1>
            <p><?php esc_html_e('Ces configurations sont définies via le filtre PHP up_block_switches dans votre thème ou dans des mu-plugins.', 'up'); ?></p>
            
            <?php if (empty($normalized_configs)): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('Aucune configuration n\'est actuellement définie via le filtre up_block_switches.', 'up'); ?></p>
                </div>
            <?php else: ?>
                <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                    <h2><?php esc_html_e('Configurations actuelles', 'up'); ?></h2>
                    
                    <?php foreach ($normalized_configs as $block_name => $controls): ?>
                        <div style="margin-bottom: 30px; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
                            <h3><?php echo esc_html($block_name); ?></h3>
                            
                            <div style="background: #f0f0f0; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                                <strong><?php esc_html_e('Nombre de contrôles:', 'up'); ?></strong> <?php echo count($controls); ?>
                            </div>
                            
                            <!-- Actions pour le bloc complet -->
                            <div style="margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">
                                <button type="button" class="button toggle-json" data-block="<?php echo esc_attr($block_name); ?>">
                                    <?php esc_html_e('Afficher/Masquer le JSON du bloc', 'up'); ?>
                                </button>
                                <button type="button" class="button button-primary save-block-as-preconfig" data-block="<?php echo esc_attr($block_name); ?>">
                                    <?php esc_html_e('Enregistrer tout le bloc', 'up'); ?>
                                </button>
                            </div>
                            
                            <!-- Liste des contrôles individuels -->
                            <div style="margin-bottom: 15px;">
                                <h4><?php esc_html_e('Contrôles individuels:', 'up'); ?></h4>
                                <?php foreach ($controls as $index => $control): ?>
                                    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin-bottom: 10px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <strong><?php echo esc_html($control['label'] ?? $control['id'] ?? 'Sans nom'); ?></strong>
                                                <span style="margin-left: 10px; color: #666;">
                                                    ID: <?php echo esc_html($control['id'] ?? ''); ?> | 
                                                    Type: <?php echo esc_html($control['type'] ?? 'toggle'); ?> | 
                                                    Panel: <?php echo esc_html($control['panel'] ?? 'Options UP'); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <button type="button" class="button button-small toggle-control-json" 
                                                        data-block="<?php echo esc_attr($block_name); ?>" 
                                                        data-control-index="<?php echo esc_attr($index); ?>">
                                                    <?php esc_html_e('JSON', 'up'); ?>
                                                </button>
                                                <button type="button" class="button button-small button-primary save-control-as-preconfig" 
                                                        data-block="<?php echo esc_attr($block_name); ?>" 
                                                        data-control-index="<?php echo esc_attr($index); ?>"
                                                        data-control-id="<?php echo esc_attr($control['id'] ?? ''); ?>"
                                                        data-control-label="<?php echo esc_attr($control['label'] ?? $control['id'] ?? 'control'); ?>">
                                                    <?php esc_html_e('Sauvegarder', 'up'); ?>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- JSON du contrôle individuel -->
                                        <pre class="control-json-display" id="control-json-<?php echo esc_attr(sanitize_key($block_name)); ?>-<?php echo esc_attr($index); ?>" 
                                             style="display: none; background: #f6f7f7; padding: 10px; border-radius: 4px; overflow-x: auto; margin-top: 10px; font-size: 12px;">
<?php echo esc_html(wp_json_encode($control, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>
                                        </pre>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <pre class="json-display" id="json-<?php echo esc_attr(sanitize_key($block_name)); ?>" 
                                 style="display: none; background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto; max-height: 400px;">
<?php echo esc_html(wp_json_encode($controls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>
                            </pre>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="card" style="max-width: 100%;">
                    <h2><?php esc_html_e('JSON complet', 'up'); ?></h2>
                    <button type="button" class="button" id="toggle-full-json">
                        <?php esc_html_e('Afficher/Masquer le JSON complet', 'up'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="save-all-as-preconfig">
                        <?php esc_html_e('Enregistrer tout comme préconfiguration', 'up'); ?>
                    </button>
                    
                    <pre id="full-json-display" style="display: none; background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto; max-height: 600px; margin-top: 10px;">
<?php echo esc_html(wp_json_encode($normalized_configs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>
                    </pre>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Toggle JSON display pour chaque bloc
            $('.toggle-json').on('click', function() {
                const blockName = $(this).data('block');
                const jsonId = '#json-' + blockName.replace(/[^a-zA-Z0-9]/g, '');
                $(jsonId).slideToggle();
            });
            
            // Toggle JSON pour un contrôle individuel
            $('.toggle-control-json').on('click', function() {
                const blockName = $(this).data('block');
                const controlIndex = $(this).data('control-index');
                const jsonId = '#control-json-' + blockName.replace(/[^a-zA-Z0-9]/g, '') + '-' + controlIndex;
                $(jsonId).slideToggle();
            });
            
            // Toggle JSON complet
            $('#toggle-full-json').on('click', function() {
                $('#full-json-display').slideToggle();
            });
            
            // Sauvegarder un contrôle individuel
            $('.save-control-as-preconfig').on('click', function() {
                const blockName = $(this).data('block');
                const controlIndex = $(this).data('control-index');
                const controlId = $(this).data('control-id');
                const controlLabel = $(this).data('control-label');
                const blockSlug = String(blockName).toLowerCase().replace(/\//g, '-').replace(/[^a-z0-9-]/g, '');
                const idSlug = String(controlId || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
                const defaultName = (blockSlug && idSlug) ? (blockSlug + '-' + idSlug) : (controlId || controlLabel || 'control');
                
                const panelName = prompt('<?php echo esc_js(__('Nom du panneau pour ce contrôle:', 'up')); ?>', defaultName);
                
                if (!panelName) return;
                
                // Créer un formulaire pour sauvegarder
                const form = $('<form>', {
                    method: 'POST',
                    action: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>'
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'up_ge_save_single_control_as_preset'
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'block',
                    value: blockName
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'control_index',
                    value: controlIndex
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'panel',
                    value: panelName
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: '<?php echo wp_create_nonce('up_ge_filter_preset'); ?>'
                }));
                
                $('body').append(form);
                form.submit();
            });
            
            // Sauvegarder comme préconfiguration (bloc complet)
            $('.save-block-as-preconfig').on('click', function() {
                const blockName = $(this).data('block');
                const panelName = prompt('<?php echo esc_js(__('Nom du panneau pour cette préconfiguration de bloc:', 'up')); ?>', 'Options ' + blockName);
                
                if (!panelName) return;
                
                // Créer un formulaire pour sauvegarder
                const form = $('<form>', {
                    method: 'POST',
                    action: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>'
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'up_ge_save_filter_as_preset'
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'block',
                    value: blockName
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'panel',
                    value: panelName
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: '<?php echo wp_create_nonce('up_ge_filter_preset'); ?>'
                }));
                
                $('body').append(form);
                form.submit();
            });
            
            // Sauvegarder tout comme préconfiguration
            $('#save-all-as-preconfig').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Voulez-vous sauvegarder toutes les configurations comme préconfigurations individuelles?', 'up')); ?>')) {
                    return;
                }
                
                const form = $('<form>', {
                    method: 'POST',
                    action: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>'
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'up_ge_save_all_filters_as_presets'
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: '<?php echo wp_create_nonce('up_ge_filter_preset'); ?>'
                }));
                
                $('body').append(form);
                form.submit();
            });
        });
        </script>
        <?php
    }

    /**
     * Structure attendue du filtre `up_block_switches`:
     * [
     *   'core/paragraph' => [
     *      // Toggle (compatibilité existante)
     *      [ 'id' => 'big', 'label' => 'Grand', 'class' => 'is-big', 'panel' => 'Apparence' ],
     *      // Select (options exclusives)
     *      [
     *        'type' => 'select',
     *        'id' => 'taille',
     *        'label' => 'Taille',
     *        'panel' => 'Apparence',
     *        'options' => [
     *           [ 'id' => 'sm', 'label' => 'Petite', 'class' => 'is-sm' ],
     *           [ 'id' => 'md', 'label' => 'Moyenne', 'class' => 'is-md' ],
     *           [ 'id' => 'lg', 'label' => 'Grande', 'class' => 'is-lg' ],
     *        ]
     *      ],
     *   ],
     *   'namespace/block' => [ ... ]
     * ]
     */
    public function get_switches(\WP_REST_Request $request) {
        $stored = $this->normalize_switches($this->get_option_blocks());
        $filtered = $this->normalize_switches(apply_filters('up_block_switches', []));
        $theme = $this->load_theme_controls();

        $mode = $this->get_source_mode();
        if ($mode === 'plugin') {
            $merged = $stored;
        } elseif ($mode === 'filter') {
            $merged = $filtered;
        } elseif ($mode === 'theme') {
            $merged = $theme;
        } else {
            $merged = $this->merge_configs($stored, $filtered, $theme);
        }

        // Enrichir les contrôles palette avec des options dérivées du theme.json
        foreach ($merged as $block => &$controls) {
            if (!is_array($controls)) { continue; }
            foreach ($controls as &$control) {
                if (!is_array($control)) { continue; }
                if (!isset($control['type']) || $control['type'] !== 'palette') { continue; }
                $classPrefix = isset($control['class']) ? $control['class'] : '';
                $source = isset($control['source']) ? $control['source'] : '';
                if (!$classPrefix || !$source) { continue; }
                $tokens = $this->get_palette_tokens($source);
                if (!$tokens) { continue; }
                $options = [];
                foreach ($tokens as $tok) {
                    $slug = isset($tok['slug']) && $tok['slug'] !== '' ? $tok['slug'] : (isset($tok['name']) ? $tok['name'] : (isset($tok['label']) ? $tok['label'] : ''));
                    if (!$slug) { continue; }
                    $label = isset($tok['name']) ? $tok['name'] : (isset($tok['label']) ? $tok['label'] : $slug);
                    $cls = sanitize_html_class($classPrefix . '-' . $slug);
                    if (!$cls) { continue; }
                    $options[] = [
                        'id' => sanitize_key($slug),
                        'label' => sanitize_text_field($label),
                        'classes' => [$cls],
                        'class' => $cls,
                    ];
                }
                if ($options) {
                    $control['options'] = $options;
                }
            }
            unset($control);
        }
        unset($controls);

        return new \WP_REST_Response($merged, 200);
    }

    protected function get_palette_tokens($source) {
        if (!function_exists('wp_get_global_settings')) {
            return [];
        }
        $settings = wp_get_global_settings();
        $src = strtolower($source);
        if ($src === 'colors' || $src === 'color') {
            // colors palette
            $cands = [];
            if (isset($settings['color']['palette']['theme'])) { $cands[] = $settings['color']['palette']['theme']; }
            if (isset($settings['color']['palette'])) { $cands[] = $settings['color']['palette']; }
            return $this->flatten_token_candidates($cands);
        }
        if ($src === 'fontsizes' || $src === 'font-sizes' || $src === 'font' || $src === 'fontsize' || $src === 'fontSizes') {
            $cands = [];
            if (isset($settings['typography']['fontSizes'])) { $cands[] = $settings['typography']['fontSizes']; }
            if (isset($settings['fontSizes'])) { $cands[] = $settings['fontSizes']; }
            return $this->flatten_token_candidates($cands);
        }
        if ($src === 'spacing' || $src === 'sizes' || $src === 'sizing') {
            $cands = [];
            if (isset($settings['spacing']['spacingSizes']['theme'])) { $cands[] = $settings['spacing']['spacingSizes']['theme']; }
            if (isset($settings['spacing']['spacingSizes'])) { $cands[] = $settings['spacing']['spacingSizes']; }
            if (isset($settings['spacing']['spacingScale']['theme'])) { $cands[] = $settings['spacing']['spacingScale']['theme']; }
            if (isset($settings['spacing']['spacingScale'])) { $cands[] = $settings['spacing']['spacingScale']; }
            if (isset($settings['spacingSizes'])) { $cands[] = $settings['spacingSizes']; }
            if (isset($settings['dimensions']['spacingSizes'])) { $cands[] = $settings['dimensions']['spacingSizes']; }
            // group format: [ { sizes: [...] }, ... ]
            $flat = $this->flatten_token_candidates($cands);
            if (!$flat && isset($settings['spacing']) && is_array($settings['spacing'])) {
                foreach ($settings['spacing'] as $maybeGroup) {
                    if (is_array($maybeGroup) && isset($maybeGroup['sizes']) && is_array($maybeGroup['sizes'])) {
                        $flat = array_merge($flat, $maybeGroup['sizes']);
                    }
                }
            }
            return $flat;
        }
        return [];
    }

    protected function flatten_token_candidates(array $cands) {
        $out = [];
        foreach ($cands as $cand) {
            if (is_array($cand)) {
                // If array of groups with sizes
                $isGroupList = isset($cand[0]) && is_array($cand[0]) && isset($cand[0]['sizes']) && is_array($cand[0]['sizes']);
                if ($isGroupList) {
                    foreach ($cand as $grp) {
                        if (isset($grp['sizes']) && is_array($grp['sizes'])) {
                            foreach ($grp['sizes'] as $t) { $out[] = $t; }
                        }
                    }
                } else {
                    foreach ($cand as $t) { $out[] = $t; }
                }
            }
        }
        return $out;
    }
}

new Up_Block_Switches();

/**
 * Exemple: ajouter un switch via filtre (à copier dans un mu-plugin ou functions.php du thème).
 *
 * add_filter('up_block_switches', function($switches){
 *   $switches['core/paragraph'][] = [
 *     'id' => 'highlight',
 *     'label' => 'Surbrillance',
 *     'class' => 'is-highlight'
 *   ];
 *   return $switches;
 * });
 */
