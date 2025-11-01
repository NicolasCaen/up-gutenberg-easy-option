<?php
/**
 * Plugin Name: UP gutenberg easy option
 * Description: Ajoute des switches configurables aux blocs Gutenberg pour ajouter/retirer des classes via l’inspecteur. Configurable via un filtre et exposé via l’API REST.
 * Version: 0.2.0
 * Author: GEHIN Nicolas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Up_Block_Switches {
    const REST_NAMESPACE = 'up/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
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

    public function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, '/switches', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_switches'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);
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
        $switches = apply_filters('up_block_switches', []);

        // Normaliser: assurer que c'est un tableau associatif blockName => array of switches
        if (!is_array($switches)) {
            $switches = [];
        }

        // Filtrer les éléments invalides
        $normalized = [];
        foreach ($switches as $blockName => $defs) {
            if (!is_string($blockName) || !is_array($defs)) {
                continue;
            }
            $clean = [];
            foreach ($defs as $d) {
                if (!is_array($d)) { continue; }

                // Champs communs
                $id = isset($d['id']) ? sanitize_key($d['id']) : '';
                $label = isset($d['label']) ? sanitize_text_field($d['label']) : '';
                $panel = isset($d['panel']) ? sanitize_text_field($d['panel']) : '';
                $type = isset($d['type']) ? sanitize_key($d['type']) : 'toggle';

                if ($type === 'select') {
                    // Contrôle de type select avec options exclusives
                    $options = [];
                    if (isset($d['options']) && is_array($d['options'])) {
                        foreach ($d['options'] as $opt) {
                            if (!is_array($opt)) { continue; }
                            $opt_id = isset($opt['id']) ? sanitize_key($opt['id']) : '';
                            $opt_label = isset($opt['label']) ? sanitize_text_field($opt['label']) : '';
                            $opt_class = isset($opt['class']) ? sanitize_html_class($opt['class']) : '';
                            if ($opt_id && $opt_label && $opt_class) {
                                $options[] = [ 'id' => $opt_id, 'label' => $opt_label, 'class' => $opt_class ];
                            }
                        }
                    }
                    if ($id && $label && !empty($options)) {
                        $entry = [
                            'type' => 'select',
                            'id' => $id,
                            'label' => $label,
                            'options' => $options,
                        ];
                        if ($panel) { $entry['panel'] = $panel; }
                        $clean[] = $entry;
                    }
                } else {
                    // Par défaut: toggle (compatibilité)
                    $class = isset($d['class']) ? sanitize_html_class($d['class']) : '';
                    if ($id && $label && $class) {
                        $entry = [ 'type' => 'toggle', 'id' => $id, 'label' => $label, 'class' => $class ];
                        if ($panel) { $entry['panel'] = $panel; }
                        $clean[] = $entry;
                    }
                }
            }
            if ($clean) {
                $normalized[$blockName] = $clean;
            }
        }

        return new \WP_REST_Response($normalized, 200);
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
