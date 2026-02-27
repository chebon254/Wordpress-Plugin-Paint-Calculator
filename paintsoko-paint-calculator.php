<?php
/**
 * Plugin Name: PaintSoko Paint Calculator
 * Plugin URI:  https://paintsoko.com
 * Description: A smart paint calculator for WooCommerce that helps customers estimate exactly how much Duracoat and Plascon paint they need.
 * Version:     3.0.3
 * Author:      PaintSoko
 * Author URI:  https://paintsoko.com
 * License:     GPL v2 or later
 * Text Domain: paintsoko-calc
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC tested up to:   9.9 .pspc-input-label
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PSPC_VERSION',     '2.0.0' );
define( 'PSPC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PSPC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'PSPC_OPTIONS_KEY', 'pspc_settings' );

/* Declare WooCommerce HPOS compatibility */
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/* ═══════════════════════════════════════════════════════════════════
   MAIN CLASS
═══════════════════════════════════════════════════════════════════ */
final class PaintSoko_Paint_Calculator {

    private static ?self $instance = null;
    private array $settings;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = $this->get_settings();
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'admin_menu',            [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue' ] );
        add_action( 'wp_enqueue_scripts',    [ $this, 'frontend_enqueue' ] );
        add_shortcode( 'paint_calculator',   [ $this, 'render_calculator' ] );
        register_activation_hook( __FILE__,  [ $this, 'activate' ] );

        /* WooCommerce product meta: spread rate field */
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'product_spread_rate_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_product_spread_rate' ] );

        /* AJAX: load products by category */
        add_action( 'wp_ajax_pspc_get_products',        [ $this, 'ajax_get_products' ] );
        add_action( 'wp_ajax_nopriv_pspc_get_products', [ $this, 'ajax_get_products' ] );

        /* AJAX: load packages (variations) for a product */
        add_action( 'wp_ajax_pspc_get_packages',        [ $this, 'ajax_get_packages' ] );
        add_action( 'wp_ajax_nopriv_pspc_get_packages', [ $this, 'ajax_get_packages' ] );
    }

    /* ── Activation ─────────────────────────────────────────────── */
    public function activate(): void {
        if ( false === get_option( PSPC_OPTIONS_KEY ) ) {
            update_option( PSPC_OPTIONS_KEY, $this->default_settings() );
        }
    }

    /* ── Default Settings ───────────────────────────────────────── */
    private function default_settings(): array {
        return [
            // ── Calculator
            'coverage_rate'           => 13.0,  // sq m per litre (1 coat) — global fallback
            'coats_fresh'             => 2,
            'coats_repainting'        => 1,
            // ── Default dimension inputs
            'default_length'          => 10.0,  // metres
            'default_height'          => 2.4,   // metres
            'default_coats'           => 2,
            'default_sides'           => 4,
            // ── Tin sizes (shown in greedy recommendation)
            'tin_sizes'               => [ 20, 4, 1 ],
            'show_tin_recommendation' => true,
            // ── WooCommerce
            'wc_enabled'              => true,
            'wc_show_brands'          => true,
            'wc_duracoat_url'         => '',
            'wc_plascom_url'          => '',
            'wc_shop_button_text'     => 'Shop Paint Now',
            // ── Display
            'primary_color'           => '#c0392b',
            'text_color'              => '#ffffff',
            'show_title'              => true,
            'calculator_title'        => 'Paint Calculator',
            'calculator_subtitle'     => 'Find out exactly how much paint you need',
            'button_text'             => 'Calculate',
        ];
    }

    public function get_settings(): array {
        $saved = get_option( PSPC_OPTIONS_KEY, [] );
        return is_array( $saved )
            ? wp_parse_args( $saved, $this->default_settings() )
            : $this->default_settings();
    }

    /* ═══════════════════════════════════════════════════════════════
       WOOCOMMERCE — SPREAD RATE PRODUCT FIELD
    ═══════════════════════════════════════════════════════════════ */
    public function product_spread_rate_field(): void {
        woocommerce_wp_text_input( [
            'id'          => '_pspc_spread_rate',
            'label'       => __( 'Spread Rate (sq m/L)', 'paintsoko-calc' ),
            'description' => __( 'How many square metres one litre covers per coat. Used by the Paint Calculator. Leave empty to use the global default.', 'paintsoko-calc' ),
            'desc_tip'    => true,
            'type'        => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min'  => '0.01',
            ],
        ] );
    }

    public function save_product_spread_rate( int $product_id ): void {
        $rate = isset( $_POST['_pspc_spread_rate'] ) ? wc_clean( wp_unslash( $_POST['_pspc_spread_rate'] ) ) : '';
        if ( '' !== $rate && floatval( $rate ) > 0 ) {
            update_post_meta( $product_id, '_pspc_spread_rate', wc_format_decimal( $rate ) );
        } else {
            delete_post_meta( $product_id, '_pspc_spread_rate' );
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       AJAX HANDLERS
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Returns JSON array of products for a given WC category ID.
     * Each item: { id, name, spread, url }
     */
    public function ajax_get_products(): void {
        // Verify nonce if user is logged in, but don't fail for unauthenticated users
        if ( is_user_logged_in() ) {
            check_ajax_referer( 'pspc_nonce', 'nonce' );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( 'WooCommerce not active.' );
        }

        $cat_id = absint( $_POST['cat_id'] ?? 0 );
        if ( ! $cat_id ) {
            wp_send_json_error( 'Invalid category.' );
        }

        $term = get_term( $cat_id, 'product_cat' );
        if ( is_wp_error( $term ) || ! $term ) {
            wp_send_json_error( 'Category not found.' );
        }

        $query    = new WC_Product_Query( [
            'status'   => 'publish',
            'category' => [ $term->slug ],
            'limit'    => 200,
            'orderby'  => 'title',
            'order'    => 'ASC',
        ] );
        $products = $query->get_products();
        $data     = [];

        foreach ( $products as $product ) {
            $spread = (float) get_post_meta( $product->get_id(), '_pspc_spread_rate', true );
            if ( $spread <= 0 ) {
                $spread = (float) $this->settings['coverage_rate'];
            }
            $data[] = [
                'id'     => $product->get_id(),
                'name'   => $product->get_name(),
                'spread' => $spread,
                'url'    => get_permalink( $product->get_id() ),
            ];
        }

        wp_send_json_success( $data );
    }

    /**
     * Returns JSON array of package options for a given product ID.
     * Each item: { id, label, size, price }
     */
    public function ajax_get_packages(): void {
        // Verify nonce if user is logged in, but don't fail for unauthenticated users
        if ( is_user_logged_in() ) {
            check_ajax_referer( 'pspc_nonce', 'nonce' );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( 'WooCommerce not active.' );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( 'Invalid product.' );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Product not found.' );
        }

        $packages = [];

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_available_variations() as $var_data ) {
                $var = wc_get_product( $var_data['variation_id'] );
                if ( ! $var || ! $var->is_purchasable() ) {
                    continue;
                }

                /* Grab the first non-empty attribute value as the label */
                $label = '';
                foreach ( $var_data['attributes'] as $attr_val ) {
                    $attr_val = sanitize_text_field( (string) $attr_val );
                    if ( '' !== $attr_val ) {
                        $label = $attr_val;
                        break;
                    }
                }

                if ( '' === $label ) {
                    $label = implode( ', ', array_filter( array_map( 'sanitize_text_field', $var_data['attributes'] ) ) );
                }

                /* Extract numeric litres from label, e.g. "20 Ltrs" → 20 */
                preg_match( '/[\d.]+/', $label, $m );
                $size = isset( $m[0] ) ? (float) $m[0] : 0.0;

                $packages[] = [
                    'id'    => $var_data['variation_id'],
                    'label' => $label !== '' ? $label : "{$size} L",
                    'size'  => $size,
                    'price' => (float) $var->get_price(),
                ];
            }
            /* Sort smallest → largest */
            usort( $packages, static fn( $a, $b ) => $a['size'] <=> $b['size'] );

        } else {
            /* Simple product — single entry */
            $packages[] = [
                'id'    => $product_id,
                'label' => '1 Unit',
                'size'  => 0.0,
                'price' => (float) $product->get_price(),
            ];
        }

        wp_send_json_success( $packages );
    }

    /* ═══════════════════════════════════════════════════════════════
       ADMIN MENU
    ═══════════════════════════════════════════════════════════════ */
    public function add_admin_menu(): void {
        add_menu_page(
            __( 'Paint Calculator', 'paintsoko-calc' ),
            __( 'Paint Calculator', 'paintsoko-calc' ),
            'manage_options',
            'pspc-settings',
            [ $this, 'render_admin_page' ],
            'dashicons-art',
            58
        );
    }

    public function register_settings(): void {
        register_setting(
            'pspc_options_group',
            PSPC_OPTIONS_KEY,
            [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
        );
    }

    public function sanitize_settings( mixed $input ): array {
        if ( ! is_array( $input ) ) {
            return $this->default_settings();
        }

        $defaults = $this->default_settings();
        $out      = [];

        /* Floats */
        foreach ( [ 'coverage_rate', 'default_length', 'default_height' ] as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? max( 0.01, (float) $input[ $k ] ) : $defaults[ $k ];
        }

        /* Ints */
        foreach ( [ 'coats_fresh', 'coats_repainting', 'default_coats', 'default_sides' ] as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? max( 1, (int) $input[ $k ] ) : $defaults[ $k ];
        }

        /* Text strings */
        foreach ( [ 'wc_shop_button_text', 'calculator_title', 'calculator_subtitle', 'button_text' ] as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : $defaults[ $k ];
        }

        /* Colors */
        foreach ( [ 'primary_color', 'text_color' ] as $k ) {
            $val        = isset( $input[ $k ] ) ? sanitize_hex_color( $input[ $k ] ) : '';
            $out[ $k ]  = $val ?: $defaults[ $k ];
        }

        /* URLs */
        foreach ( [ 'wc_duracoat_url', 'wc_plascom_url' ] as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? esc_url_raw( $input[ $k ] ) : '';
        }

        /* Booleans */
        foreach ( [ 'show_tin_recommendation', 'wc_enabled', 'wc_show_brands', 'show_title' ] as $k ) {
            $out[ $k ] = ! empty( $input[ $k ] );
        }

        /* Tin sizes */
        if ( ! empty( $input['tin_sizes'] ) ) {
            // Handle both string and array inputs
            $tin_input = $input['tin_sizes'];
            if ( is_array( $tin_input ) ) {
                $sizes = array_map( 'floatval', $tin_input );
            } else {
                $sizes = array_map( 'floatval', explode( ',', $tin_input ) );
            }
            $sizes = array_values( array_filter( $sizes, static fn( $s ) => $s > 0 ) );
            rsort( $sizes );
            $out['tin_sizes'] = ! empty( $sizes ) ? $sizes : $defaults['tin_sizes'];
        } else {
            $out['tin_sizes'] = $defaults['tin_sizes'];
        }

        return $out;
    }

    /* ── Admin Scripts ──────────────────────────────────────────── */
    public function admin_enqueue( string $hook ): void {
        if ( 'toplevel_page_pspc-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style(
            'pspc-admin',
            PSPC_PLUGIN_URL . 'assets/css/admin.css',
            [ 'wp-color-picker' ],
            PSPC_VERSION
        );
        wp_enqueue_script(
            'pspc-admin',
            PSPC_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery', 'wp-color-picker' ],
            PSPC_VERSION,
            true
        );
        wp_localize_script( 'pspc-admin', 'pspcAdmin', [
            'optionsKey' => PSPC_OPTIONS_KEY,
        ] );
    }

    /* ── Admin Page ─────────────────────────────────────────────── */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s          = $this->settings;
        $active_tab = sanitize_key( $_GET['tab'] ?? 'calculator' );
        $tabs       = [
            'calculator'  => [ 'icon' => 'dashicons-calculator',      'label' => 'Calculator' ],
            'tin_sizes'   => [ 'icon' => 'dashicons-clipboard',        'label' => 'Tin Sizes' ],
            'woocommerce' => [ 'icon' => 'dashicons-cart',             'label' => 'WooCommerce' ],
            'display'     => [ 'icon' => 'dashicons-admin-appearance', 'label' => 'Display' ],
        ];
        ?>
        <div class="wrap pspc-admin-wrap">

            <!-- Header -->
            <div class="pspc-admin-header">
                <div class="pspc-admin-brand">
                    <div class="pspc-admin-icon">
                        <span class="dashicons dashicons-art"></span>
                    </div>
                    <div>
                        <h1>PaintSoko Paint Calculator</h1>
                        <p>Configure your smart paint calculator</p>
                    </div>
                </div>
                <div class="pspc-shortcode-badge">
                    <span>Shortcode</span>
                    <code>[paint_calculator]</code>
                </div>
            </div>

            <!-- Tabs -->
            <nav class="pspc-nav-tabs">
                <?php foreach ( $tabs as $slug => $tab ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'pspc-settings', 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
                   class="pspc-nav-tab <?php echo $active_tab === $slug ? 'is-active' : ''; ?>">
                    <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                    <?php echo esc_html( $tab['label'] ); ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <!-- Settings Form -->
            <form method="post" action="options.php" class="pspc-settings-form" id="pspc-settings-form">
                <?php settings_fields( 'pspc_options_group' ); ?>

                <?php /* ── TAB: Calculator ── */ ?>
                <?php if ( 'calculator' === $active_tab ) : ?>
                <div class="pspc-tab-content">

                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-performance"></span>
                            <div>
                                <h2>Coverage &amp; Coats</h2>
                                <p>Global spread rate fallback and default coat counts.</p>
                            </div>
                        </div>
                        <table class="form-table pspc-table">
                            <tr>
                                <th><label for="pspc_coverage_rate">Coverage Rate (fallback)</label></th>
                                <td>
                                    <div class="pspc-input-with-unit">
                                        <input type="number" id="pspc_coverage_rate"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[coverage_rate]"
                                               value="<?php echo esc_attr( $s['coverage_rate'] ); ?>"
                                               step="0.1" min="0.1" class="small-text">
                                        <span class="pspc-unit-label">sq m / L</span>
                                    </div>
                                    <p class="description">Square metres one litre covers per coat. Used when a product has no spread rate set. Typical emulsion: 12–14 sq m/L.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_coats_fresh">Fresh Painting — Coats</label></th>
                                <td>
                                    <input type="number" id="pspc_coats_fresh"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[coats_fresh]"
                                           value="<?php echo esc_attr( $s['coats_fresh'] ); ?>"
                                           min="1" max="5" class="small-text">
                                    <p class="description">Typical coats for brand-new surfaces (usually 2).</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_coats_repainting">Re-Painting — Coats</label></th>
                                <td>
                                    <input type="number" id="pspc_coats_repainting"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[coats_repainting]"
                                           value="<?php echo esc_attr( $s['coats_repainting'] ); ?>"
                                           min="1" max="5" class="small-text">
                                    <p class="description">Typical coats for re-painting (usually 1).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-text-page"></span>
                            <div>
                                <h2>Default Dimensions</h2>
                                <p>Pre-filled values shown in the calculator on page load.</p>
                            </div>
                        </div>
                        <table class="form-table pspc-table">
                            <tr>
                                <th><label for="pspc_default_length">Default Length</label></th>
                                <td>
                                    <div class="pspc-input-with-unit">
                                        <input type="number" id="pspc_default_length"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[default_length]"
                                               value="<?php echo esc_attr( $s['default_length'] ); ?>"
                                               step="0.1" min="0.1" class="small-text">
                                        <span class="pspc-unit-label">metres</span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_default_height">Default Height</label></th>
                                <td>
                                    <div class="pspc-input-with-unit">
                                        <input type="number" id="pspc_default_height"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[default_height]"
                                               value="<?php echo esc_attr( $s['default_height'] ); ?>"
                                               step="0.1" min="0.1" class="small-text">
                                        <span class="pspc-unit-label">metres</span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_default_coats">Default No. of Coats</label></th>
                                <td>
                                    <input type="number" id="pspc_default_coats"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[default_coats]"
                                           value="<?php echo esc_attr( $s['default_coats'] ); ?>"
                                           min="1" max="10" class="small-text">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_default_sides">Default No. of Walls</label></th>
                                <td>
                                    <input type="number" id="pspc_default_sides"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[default_sides]"
                                           value="<?php echo esc_attr( $s['default_sides'] ); ?>"
                                           min="1" max="20" class="small-text">
                                    <p class="description">Number of walls (e.g. 4 for a full room, 1 for a single wall).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-info-outline"></span>
                            <div>
                                <h2>Per-Product Spread Rate</h2>
                                <p>Override the global spread rate on individual products.</p>
                            </div>
                        </div>
                        <div style="padding:16px 20px 20px; font-size:.875rem; color:#444; line-height:1.7;">
                            <p>Go to <strong>Products → Edit Product</strong>, scroll to <em>Product Data → General</em> and fill in the <strong>Spread Rate (sq m/L)</strong> field. If left empty, the global fallback above is used.</p>
                            <p class="description">Custom meta key: <code>_pspc_spread_rate</code></p>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endif; ?>

                <?php /* ── TAB: Tin Sizes ── */ ?>
                <?php if ( 'tin_sizes' === $active_tab ) : ?>
                <div class="pspc-tab-content">
                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-clipboard"></span>
                            <div>
                                <h2>Available Tin Sizes</h2>
                                <p>Used to recommend the optimal combination of tins when no specific package is selected.</p>
                            </div>
                        </div>
                        <table class="form-table pspc-table">
                            <tr>
                                <th><label for="pspc_tin_sizes">Tin Sizes (Litres)</label></th>
                                <td>
                                    <input type="text" id="pspc_tin_sizes"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[tin_sizes]"
                                           value="<?php echo esc_attr( implode( ', ', $s['tin_sizes'] ) ); ?>"
                                           class="regular-text"
                                           placeholder="20, 4, 1">
                                    <p class="description">Comma-separated values in litres. E.g. <code>20, 4, 1</code>. Sorted largest first automatically.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Show Recommendation</th>
                                <td>
                                    <label class="pspc-toggle-switch">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[show_tin_recommendation]"
                                               value="1"
                                               <?php checked( $s['show_tin_recommendation'] ); ?>>
                                        <span class="pspc-toggle-track"><span class="pspc-toggle-thumb"></span></span>
                                        <span class="pspc-toggle-label">Show recommended tin combination in results</span>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <?php
                        $preview_litres = 52;
                        $sizes_sorted   = $s['tin_sizes'];
                        rsort( $sizes_sorted );
                        $combo     = [];
                        $remaining = $preview_litres;
                        foreach ( $sizes_sorted as $size ) {
                            if ( $remaining <= 0 ) break;
                            $count = (int) floor( $remaining / $size );
                            if ( $count > 0 ) {
                                $combo[]   = "{$count}× {$size}L";
                                $remaining -= $count * $size;
                            }
                        }
                        if ( $remaining > 0 && ! empty( $sizes_sorted ) ) {
                            $smallest_cover = null;
                            foreach ( array_reverse( $sizes_sorted ) as $sz ) {
                                if ( $sz >= $remaining ) {
                                    $smallest_cover = $sz;
                                }
                            }
                            $combo[] = '1× ' . ( $smallest_cover ?? end( $sizes_sorted ) ) . 'L';
                        }
                        ?>
                        <div class="pspc-tin-preview" id="pspc-tin-preview">
                            <p class="pspc-tin-preview-label">Preview — for <strong><?php echo esc_html( $preview_litres ); ?> litres</strong>:</p>
                            <div class="pspc-tin-combo" id="pspc-tin-combo-preview">
                                <?php echo esc_html( implode( ' + ', $combo ) ); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* ── TAB: WooCommerce ── */ ?>
                <?php if ( 'woocommerce' === $active_tab ) : ?>
                <div class="pspc-tab-content">
                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-cart"></span>
                            <div>
                                <h2>WooCommerce Integration</h2>
                                <p>Connect calculator results to your paint product pages.</p>
                            </div>
                        </div>
                        <table class="form-table pspc-table">
                            <tr>
                                <th>Enable Shop Buttons</th>
                                <td>
                                    <label class="pspc-toggle-switch">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[wc_enabled]"
                                               value="1"
                                               <?php checked( $s['wc_enabled'] ); ?>>
                                        <span class="pspc-toggle-track"><span class="pspc-toggle-thumb"></span></span>
                                        <span class="pspc-toggle-label">Show shop buttons after calculation results</span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Show Brand Buttons</th>
                                <td>
                                    <label class="pspc-toggle-switch">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[wc_show_brands]"
                                               value="1"
                                               <?php checked( $s['wc_show_brands'] ); ?>>
                                        <span class="pspc-toggle-track"><span class="pspc-toggle-thumb"></span></span>
                                        <span class="pspc-toggle-label">Show separate buttons for Duracoat and Plascon</span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_wc_duracoat_url">Duracoat Shop URL</label></th>
                                <td>
                                    <input type="url" id="pspc_wc_duracoat_url"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[wc_duracoat_url]"
                                           value="<?php echo esc_attr( $s['wc_duracoat_url'] ); ?>"
                                           class="regular-text"
                                           placeholder="https://paintsoko.com/product-category/duracoat">
                                    <p class="description">URL to your Duracoat category or product page.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_wc_plascom_url">Plascon Shop URL</label></th>
                                <td>
                                    <input type="url" id="pspc_wc_plascom_url"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[wc_plascom_url]"
                                           value="<?php echo esc_attr( $s['wc_plascom_url'] ); ?>"
                                           class="regular-text"
                                           placeholder="https://paintsoko.com/product-category/plascon">
                                    <p class="description">URL to your Plascon category or product page.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_wc_shop_button_text">Generic Button Text</label></th>
                                <td>
                                    <input type="text" id="pspc_wc_shop_button_text"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[wc_shop_button_text]"
                                           value="<?php echo esc_attr( $s['wc_shop_button_text'] ); ?>"
                                           class="regular-text">
                                    <p class="description">Used when brand buttons are disabled.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* ── TAB: Display ── */ ?>
                <?php if ( 'display' === $active_tab ) : ?>
                <div class="pspc-tab-content">
                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-admin-customizer"></span>
                            <div>
                                <h2>Colors</h2>
                                <p>Brand colors for the submit button and active states.</p>
                            </div>
                        </div>
                        <table class="form-table pspc-table">
                            <tr>
                                <th><label for="pspc_primary_color">Primary / Accent Color</label></th>
                                <td>
                                    <input type="text" id="pspc_primary_color"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[primary_color]"
                                           value="<?php echo esc_attr( $s['primary_color'] ); ?>"
                                           class="pspc-color-picker"
                                           data-default-color="#c0392b">
                                    <p class="description">Used for the submit button and highlight accents.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_text_color">Button Text Color</label></th>
                                <td>
                                    <input type="text" id="pspc_text_color"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[text_color]"
                                           value="<?php echo esc_attr( $s['text_color'] ); ?>"
                                           class="pspc-color-picker"
                                           data-default-color="#ffffff">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="pspc-card">
                        <div class="pspc-card-header">
                            <span class="dashicons dashicons-editor-textcolor"></span>
                            <div>
                                <h2>Text &amp; Labels</h2>
                            </div>
                        </div>
                        <table class="form-table pspc-table">
                            <tr>
                                <th>Show Title Bar</th>
                                <td>
                                    <label class="pspc-toggle-switch">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[show_title]"
                                               value="1"
                                               <?php checked( $s['show_title'] ); ?>>
                                        <span class="pspc-toggle-track"><span class="pspc-toggle-thumb"></span></span>
                                        <span class="pspc-toggle-label">Show the calculator title and subtitle</span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_calculator_title">Calculator Title</label></th>
                                <td>
                                    <input type="text" id="pspc_calculator_title"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[calculator_title]"
                                           value="<?php echo esc_attr( $s['calculator_title'] ); ?>"
                                           class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_calculator_subtitle">Subtitle</label></th>
                                <td>
                                    <input type="text" id="pspc_calculator_subtitle"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[calculator_subtitle]"
                                           value="<?php echo esc_attr( $s['calculator_subtitle'] ); ?>"
                                           class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pspc_button_text">Calculate Button Text</label></th>
                                <td>
                                    <input type="text" id="pspc_button_text"
                                           name="<?php echo esc_attr( PSPC_OPTIONS_KEY ); ?>[button_text]"
                                           value="<?php echo esc_attr( $s['button_text'] ); ?>"
                                           class="regular-text">
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="pspc-form-footer">
                    <?php submit_button( 'Save Settings', 'primary large', 'submit', false ); ?>
                    <span class="pspc-shortcode-inline">
                        Embed with: <code>[paint_calculator]</code>
                    </span>
                </div>

            </form>
        </div>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════
       FRONTEND
    ═══════════════════════════════════════════════════════════════ */
    public function frontend_enqueue(): void {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'paint_calculator' ) ) {
            return;
        }
        wp_enqueue_style(
            'pspc-calculator',
            PSPC_PLUGIN_URL . 'assets/css/calculator.css',
            [],
            PSPC_VERSION
        );
        wp_enqueue_script(
            'pspc-calculator',
            PSPC_PLUGIN_URL . 'assets/js/calculator.js',
            [],
            PSPC_VERSION,
            true
        );
        wp_localize_script( 'pspc-calculator', 'pspcSettings', $this->get_js_settings() );
    }

    private function get_js_settings(): array {
        $s = $this->settings;
        return [
            'coverageRate'          => (float) $s['coverage_rate'],
            'defaultLength'         => (float) $s['default_length'],
            'defaultHeight'         => (float) $s['default_height'],
            'defaultCoats'          => (int)   $s['default_coats'],
            'defaultSides'          => (int)   $s['default_sides'],
            'tinSizes'              => $s['tin_sizes'],
            'showTinRecommendation' => (bool)  $s['show_tin_recommendation'],
            'wcEnabled'             => (bool)  $s['wc_enabled'],
            'wcShowBrands'          => (bool)  $s['wc_show_brands'],
            'wcDuracoatUrl'         => $s['wc_duracoat_url'],
            'wcPlascomUrl'          => $s['wc_plascom_url'],
            'wcShopButtonText'      => $s['wc_shop_button_text'],
            'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
            'nonce'                 => wp_create_nonce( 'pspc_nonce' ),
            'wcActive'              => class_exists( 'WooCommerce' ),
        ];
    }

    /* ── Shortcode ──────────────────────────────────────────────── */
    public function render_calculator( array $atts ): string {
        $s    = $this->settings;
        $atts = shortcode_atts( [ 'title' => '', 'subtitle' => '' ], $atts, 'paint_calculator' );

        $title    = ! empty( $atts['title'] )    ? $atts['title']    : $s['calculator_title'];
        $subtitle = ! empty( $atts['subtitle'] ) ? $atts['subtitle'] : $s['calculator_subtitle'];
        $primary  = sanitize_hex_color( $s['primary_color'] ) ?: '#c0392b';
        $txtcol   = sanitize_hex_color( $s['text_color'] )    ?: '#ffffff';

        /* WooCommerce product categories */
        $wc_categories = [];
        if ( class_exists( 'WooCommerce' ) ) {
            $cats = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ] );
            if ( ! is_wp_error( $cats ) && is_array( $cats ) ) {
                $wc_categories = $cats;
            }
        }

        ob_start();
        ?>
        <div class="pspc-calculator"
             id="pspc-calculator"
             style="--pspc-primary:<?php echo esc_attr( $primary ); ?>;--pspc-btn-text:<?php echo esc_attr( $txtcol ); ?>;">

            <?php if ( $s['show_title'] ) : ?>
            <div class="pspc-title-bar">
                <div class="pspc-title-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                        <path d="M20 14c-.092.064-2 2.083-2 3.5 0 1.494 1.044 2.5 2 2.5s2-1.006 2-2.5c0-1.417-1.908-3.436-2-3.5zM9.586 20l-1.415-1.414 8.392-8.393-1.413-1.414L6.758 17 4 14.243l6.171-6.172-1.413-1.413L2 13.414 7.758 19H9.586zm1.414 0L16 15l1 1-4 5H11zM15 3l-1 1 3 3 1-1-3-3zM4 6L3 7l3 3 1-1-3-3zm14 0l-5 5 1 1 5-5-1-1z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="pspc-title"><?php echo esc_html( $title ); ?></h2>
                    <?php if ( $subtitle ) : ?>
                    <p class="pspc-subtitle"><?php echo esc_html( $subtitle ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="pspc-layout">

                <!-- ── LEFT: Input Form ─────────────────────── -->
                <div class="pspc-form-panel">
                    <table class="pspc-input-table">
                        <tbody>

                            <?php if ( ! empty( $wc_categories ) ) : ?>
                            <tr>
                                <td class="pspc-input-label"><label for="pspc-category">Paint Category</label></td>
                                <td class="pspc-input-field">
                                    <select id="pspc-category" class="pspc-select">
                                        <option value="">— Select Category —</option>
                                        <?php foreach ( $wc_categories as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat->term_id ); ?>">
                                            <?php echo esc_html( $cat->name ); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td class="pspc-input-label"><label for="pspc-paint">Paint</label></td>
                                <td class="pspc-input-field">
                                    <select id="pspc-paint" class="pspc-select" disabled>
                                        <option value="">— Select Paint —</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <td class="pspc-input-label"><label for="pspc-package">Package</label></td>
                                <td class="pspc-input-field">
                                    <select id="pspc-package" class="pspc-select" disabled>
                                        <option value="">— Select Package —</option>
                                    </select>
                                </td>
                            </tr>
                            <?php else : ?>
                            <tr>
                                <td colspan="2" class="pspc-notice">
                                    <em><?php esc_html_e( 'WooCommerce is not active. Install &amp; activate it to enable paint selection.', 'paintsoko-calc' ); ?></em>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <tr>
                                <td class="pspc-input-label"><label for="pspc-length">Length (metres)</label></td>
                                <td class="pspc-input-field">
                                    <input type="number"
                                           id="pspc-length"
                                           class="pspc-number-input"
                                           value="<?php echo esc_attr( $s['default_length'] ); ?>"
                                           min="0.1" step="0.1"
                                           placeholder="e.g. 10">
                                </td>
                            </tr>
                            <tr>
                                <td class="pspc-input-label"><label for="pspc-height">Height (metres)</label></td>
                                <td class="pspc-input-field">
                                    <input type="number"
                                           id="pspc-height"
                                           class="pspc-number-input"
                                           value="<?php echo esc_attr( $s['default_height'] ); ?>"
                                           min="0.1" step="0.1"
                                           placeholder="e.g. 2.4">
                                </td>
                            </tr>
                            <tr>
                                <td class="pspc-input-label"><label for="pspc-coats">No. of Coats</label></td>
                                <td class="pspc-input-field">
                                    <input type="number"
                                           id="pspc-coats"
                                           class="pspc-number-input"
                                           value="<?php echo esc_attr( $s['default_coats'] ); ?>"
                                           min="1" max="10">
                                </td>
                            </tr>
                            <tr>
                                <td class="pspc-input-label"><label for="pspc-sides">No. of Walls</label></td>
                                <td class="pspc-input-field">
                                    <input type="number"
                                           id="pspc-sides"
                                           class="pspc-number-input"
                                           value="<?php echo esc_attr( $s['default_sides'] ); ?>"
                                           min="1" max="20">
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" class="pspc-submit-cell">
                                    <button type="button" id="pspc-calc-btn" class="pspc-submit-btn">
                                        <?php echo esc_html( strtoupper( $s['button_text'] ) ); ?>
                                    </button>
                                </td>
                            </tr>

                        </tbody>
                    </table>
                </div><!-- /.pspc-form-panel -->

                <!-- ── RIGHT: Results Panel ─────────────────── -->
                <div class="pspc-results-panel" id="pspc-results" aria-live="polite">

                    <h3 class="pspc-results-heading">RESULTS</h3>

                    <!-- Surface Details -->
                    <div class="pspc-result-block">
                        <div class="pspc-section-hdr">Surface Details</div>
                        <table class="pspc-result-table">
                            <tbody>
                                <tr>
                                    <td class="pspc-rd-label">Dimensions (length, height, walls) :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-dimensions">—</td>
                                </tr>
                                <tr>
                                    <td class="pspc-rd-label">Selected Package :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-package">—</td>
                                </tr>
                                <tr>
                                    <td class="pspc-rd-label">No. of Coats :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-coats">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paint Details -->
                    <div class="pspc-result-block">
                        <div class="pspc-section-hdr">Paint Details</div>
                        <table class="pspc-result-table">
                            <tbody>
                                <tr>
                                    <td class="pspc-rd-label">Paint :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-paint">—</td>
                                </tr>
                                <tr>
                                    <td class="pspc-rd-label">Paint Spread rate :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-spread">—</td>
                                </tr>
                                <tr>
                                    <td class="pspc-rd-label">Total No. of litres :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-litres">—</td>
                                </tr>
                                <tr id="pspc-rd-pkg-row">
                                    <td class="pspc-rd-label" id="pspc-rd-pkg-label">Package :</td>
                                    <td class="pspc-rd-value" id="pspc-rd-pkg-qty">—</td>
                                </tr>
                                <tr class="pspc-cost-row">
                                    <td class="pspc-rd-label">Estimated Cost :</td>
                                    <td class="pspc-rd-value pspc-rd-cost" id="pspc-rd-cost">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Shop Buttons -->
                    <div class="pspc-shop-area" id="pspc-shop-area" hidden></div>

                    <!-- Error message -->
                    <p class="pspc-error" id="pspc-error" hidden></p>

                </div><!-- /.pspc-results-panel -->

            </div><!-- /.pspc-layout -->
        </div><!-- /.pspc-calculator -->
        <?php
        return ob_get_clean();
    }
}

/* ── Bootstrap ──────────────────────────────────────────────────── */
function pspc_init(): PaintSoko_Paint_Calculator {
    return PaintSoko_Paint_Calculator::instance();
}
add_action( 'plugins_loaded', 'pspc_init' );
