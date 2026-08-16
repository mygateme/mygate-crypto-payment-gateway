<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_WooCommerce {
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once MGCPG_PATH . 'includes/integrations/class-mgcpg-gateway.php';
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_confirm_return' ), 9 );
	}

	public static function add_gateway( $gateways ) {
		$gateways[] = 'MGCPG_Gateway';
		return $gateways;
	}

	/**
	 * When MyGate redirects a WooCommerce shopper back to the order-received URL,
	 * perform one authenticated server-side status check before the page renders.
	 * This makes redirect mode recover quickly when an inbound webhook was blocked.
	 */
	public static function maybe_confirm_return() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce return URL is authenticated by the order key below.
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || 'mygate' !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}
		if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return;
		}

		MGCPG_Poller::check_woocommerce( $order );
	}

	public static function register_blocks_support() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once MGCPG_PATH . 'includes/integrations/class-mgcpg-woocommerce-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( $registry ) {
				$registry->register( new MGCPG_WooCommerce_Blocks() );
			}
		);
	}
}
