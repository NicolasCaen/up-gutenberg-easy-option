<?php
/**
 * Plugin Name: UP gutenberg easy option
 * Description: Ajoute des switches configurables aux blocs Gutenberg pour ajouter/retirer des classes via l’inspecteur. Configurable via un filtre et exposé via l’API REST.
 * Version: 0.3.0
 * Author: GEHIN Nicolas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Up_Block_Switches {
    const REST_NAMESPACE = 'up/v1';
    const OPTION_KEY = 'up_ge_switches_config';
    const ADMIN_PAGE_SLUG = 'up-ge-config';

    protected $admin_page_hook = null;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_up_ge_save_config', [$this, 'handle_save_config']);
        add_action('admin_post_up_ge_import_config', [$this, 'handle_import_config']);
        add_action('admin_post_up_ge_export_config', [$this, 'handle_export_config']);
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
                $normalized[$blockKey] = $clean;
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
        $this->admin_page_hook = add_options_page(
            __('UP Gutenberg Options', 'up'),
            __('UP Gutenberg Options', 'up'),
            'manage_options',
            self::ADMIN_PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_assets($hook) {
        if (!$this->admin_page_hook || $hook !== $this->admin_page_hook) {
            return;
        }

        $handle = 'up-ge-admin';
        $src = plugins_url('assets/admin.js', __FILE__);
        $deps = ['wp-element', 'wp-components', 'wp-i18n'];
        wp_enqueue_script($handle, $src, $deps, file_exists(__DIR__ . '/assets/admin.js') ? filemtime(__DIR__ . '/assets/admin.js') : false, true);

        wp_localize_script($handle, 'UPGEAdminData', [
            'config' => $this->get_option_blocks(),
            'strings' => [
                'title' => __('Configuration des options Gutenberg', 'up'),
                'blocks' => __('Blocs', 'up'),
                'addBlock' => __('Ajouter un bloc', 'up'),
                'blockNamePlaceholder' => __('core/paragraph', 'up'),
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
                'presetTitle' => __('Presets / Bundles', 'up'),
            ],
        ]);
    }

    protected function get_save_url($notice = '') {
        $url = add_query_arg(['page' => self::ADMIN_PAGE_SLUG], admin_url('options-general.php'));
        if ($notice) {
            $url = add_query_arg('up_ge_notice', $notice, $url);
        }
        return $url;
    }

    public function render_admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== self::ADMIN_PAGE_SLUG) {
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
        ];

        if (isset($messages[$notice])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$notice]));
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Vous n’avez pas les permissions nécessaires.', 'up'));
        }

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
