<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class MGCPG_WooCommerce_Blocks extends AbstractPaymentMethodType {
	protected $name = 'mygate';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_mygate_settings', array() );
	}

	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && MGCPG_Settings::is_configured();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'mgcpg-checkout-blocks',
			MGCPG_URL . 'assets/js/checkout-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			MGCPG_VERSION,
			true
		);
		return array( 'mgcpg-checkout-blocks' );
	}


	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	public function get_payment_method_data() {
		return array(
			'title'       => MGCPG_Settings::payment_title(),
			'description' => MGCPG_Settings::payment_description(),
			'icon'        => esc_url_raw( MGCPG_Settings::get( 'icon_url' ) ),
			'supports'    => array( 'products' ),
		);
	}
}
