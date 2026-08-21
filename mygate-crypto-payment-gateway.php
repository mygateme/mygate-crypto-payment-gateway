<?php
/**
 * Plugin Name: MyGate Crypto Payment Gateway
 * Plugin URI: https://mygate.me/docs/#wordpress
 * Description: A WordPress cryptocurrency payment gateway for WooCommerce and Easy Digital Downloads, with classic and block checkout support.
 * Version: 2.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: MyGate
 * Author URI: https://mygate.me
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mygate-crypto-payment-gateway
 * Domain Path: /languages
 * WC requires at least: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MGCPG_VERSION', '2.0.1' );
define( 'MGCPG_FILE', __FILE__ );
define( 'MGCPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'MGCPG_URL', plugin_dir_url( __FILE__ ) );

require_once MGCPG_PATH . 'includes/class-mgcpg-settings.php';
require_once MGCPG_PATH . 'includes/class-mgcpg-crypto.php';
require_once MGCPG_PATH . 'includes/class-mgcpg-api.php';
require_once MGCPG_PATH . 'includes/class-mgcpg-logger.php';
require_once MGCPG_PATH . 'includes/class-mgcpg-webhook.php';
require_once MGCPG_PATH . 'includes/class-mgcpg-poller.php';
require_once MGCPG_PATH . 'includes/class-mgcpg-embedded.php';
require_once MGCPG_PATH . 'includes/integrations/class-mgcpg-woocommerce.php';
require_once MGCPG_PATH . 'includes/integrations/class-mgcpg-edd.php';

// Register Blocks integration hook during plugin load so it cannot miss WooCommerce's blocks-loaded event.
add_action( 'woocommerce_blocks_loaded', array( 'MGCPG_WooCommerce', 'register_blocks_support' ) );

/**
 * Declare WooCommerce feature compatibility before WooCommerce initializes.
 */
function mgcpg_declare_woocommerce_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'mgcpg_declare_woocommerce_compatibility' );

/**
 * Boot plugin integrations after plugins are loaded.
 */
function mgcpg_boot() {
	MGCPG_Settings::init();
	MGCPG_Webhook::init();
	MGCPG_Poller::init();
	MGCPG_Embedded::init();
	MGCPG_WooCommerce::init();
	MGCPG_EDD::init();
}
add_action( 'plugins_loaded', 'mgcpg_boot', 20 );

/**
 * Plugin action links.
 *
 * @param array $links Existing links.
 * @return array
 */
function mgcpg_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=mygate-crypto-payment-gateway' ) ) . '">' . esc_html__( 'Settings', 'mygate-crypto-payment-gateway' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mgcpg_action_links' );
