<?php
/**
 * Plugin Name: UP gutenberg easy option
 * Description: Ajoute des switches configurables aux blocs Gutenberg pour ajouter/retirer des classes via l’inspecteur. Configurable via un filtre et exposé via l’API REST.
 * Version: 0.1.0
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
     *      [ 'id' => 'big', 'label' => 'Grand', 'class' => 'is-big' ],
     *      [ 'id' => 'highlight', 'label' => 'Surbrillance', 'class' => 'is-highlight' ],
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
                $id = isset($d['id']) ? sanitize_key($d['id']) : '';
                $label = isset($d['label']) ? sanitize_text_field($d['label']) : '';
                $class = isset($d['class']) ? sanitize_html_class($d['class']) : '';
                if ($id && $label && $class) {
                    $clean[] = [ 'id' => $id, 'label' => $label, 'class' => $class ];
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
