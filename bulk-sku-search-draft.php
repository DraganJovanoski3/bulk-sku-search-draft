<?php
/**
 * Plugin Name: Bulk SKU Search & Draft
 * Description: Search WooCommerce products by up to 500 SKUs at once and bulk-set published matches to draft.
 * Version: 1.0.0
 * Author: Dragan Jovanoski
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: bulk-sku-search-draft
 */

defined( 'ABSPATH' ) || exit;

define( 'BSSD_VERSION', '1.0.0' );
define( 'BSSD_PLUGIN_FILE', __FILE__ );
define( 'BSSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BSSD_MAX_SKUS', apply_filters( 'bssd_max_skus', 500 ) );
define( 'BSSD_BATCH_SIZE', apply_filters( 'bssd_batch_size', 50 ) );
define( 'BSSD_TRANSIENT_KEY', 'bssd_search_results' );

require_once BSSD_PLUGIN_DIR . 'includes/class-sku-parser.php';
require_once BSSD_PLUGIN_DIR . 'includes/class-sku-finder.php';
require_once BSSD_PLUGIN_DIR . 'includes/class-draft-processor.php';
require_once BSSD_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Bootstrap the plugin after plugins are loaded.
 */
function bssd_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'bssd_woocommerce_missing_notice' );
		return;
	}

	BSSD_Admin_Page::instance();
}
add_action( 'plugins_loaded', 'bssd_init' );

/**
 * Show notice when WooCommerce is not active.
 */
function bssd_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Bulk SKU Search & Draft requires WooCommerce to be installed and active.', 'bulk-sku-search-draft' )
	);
}
