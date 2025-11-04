<?php
/**
 * Plugin Name: UP gutenberg easy option
 * Description: Ajoute des switches configurables aux blocs Gutenberg pour ajouter/retirer des classes via l’inspecteur. Configurable via un filtre et exposé via l’API REST.
 * Version: 0.4.0
 * Author: GEHIN Nicolas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Up_Block_Switches {
    const REST_NAMESPACE = 'up/v1';
    const OPTION_KEY = 'up_ge_switches_config';
    const ADMIN_PAGE_SLUG = 'up-ge-config';
    const ADMIN_MENU_SLUG = 'up-gutenberg';
    const PRESET_PAGE_SLUG = 'up-ge-preconfigs';
    const PRESET_DIR = 'prefconfig';

    protected $admin_page_hook = null;
    protected $preset_page_hook = null;
    protected $config_page_hook = null;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_up_ge_save_config', [$this, 'handle_save_config']);
        add_action('admin_post_up_ge_import_config', [$this, 'handle_import_config']);
        add_action('admin_post_up_ge_export_config', [$this, 'handle_export_config']);
        add_action('admin_post_up_ge_import_preset', [$this, 'handle_import_preset']);
        add_action('wp_ajax_up_ge_save_panel_preset', [$this, 'handle_save_panel_preset']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
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
        $merged = $this->merge_configs($stored, $filtered);
        return new \WP_REST_Response($merged, 200);
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
