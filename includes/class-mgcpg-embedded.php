<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_Embedded {
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'render_if_requested' ), 1 );
		add_action( 'wp_ajax_mgcpg_payment_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_mgcpg_payment_status', array( __CLASS__, 'ajax_status' ) );
	}

	public static function woo_url( $order ) {
		return add_query_arg(
			array(
				'mgcpg_pay' => '1',
				'source'    => 'woo',
				'id'        => $order->get_id(),
				'key'       => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	public static function edd_url( $payment_id ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( 'mgcpg_edd_' . $token, absint( $payment_id ), 2 * DAY_IN_SECONDS );
		return add_query_arg(
			array(
				'mgcpg_pay' => '1',
				'source'    => 'edd',
				'id'        => absint( $payment_id ),
				'token'     => $token,
			),
			home_url( '/' )
		);
	}

	public static function render_if_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag; payment authorization is validated by order key or EDD token.
		if ( ! isset( $_GET['mgcpg_pay'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['mgcpg_pay'] ) ) ) {
			return;
		}

		$context = self::context_from_request();
		if ( is_wp_error( $context ) ) {
			wp_die( esc_html( $context->get_error_message() ), esc_html__( 'MyGate payment', 'mygate-crypto-payment-gateway' ), array( 'response' => 403 ) );
		}

		if ( ! empty( $context['paid'] ) ) {
			wp_safe_redirect( $context['return_url'] );
			exit;
		}

		nocache_headers();
		status_header( 200 );

		wp_enqueue_style( 'mgcpg-embedded', MGCPG_URL . 'assets/css/embedded.css', array(), MGCPG_VERSION );
		wp_enqueue_script( 'mgcpg-embedded', MGCPG_URL . 'assets/js/embedded.js', array(), MGCPG_VERSION, true );
		wp_localize_script(
			'mgcpg-embedded',
			'MGCPG_EMBED',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'mgcpg_payment_status' ),
				'source'    => $context['source'],
				'id'        => $context['id'],
				'auth'      => $context['auth'],
				'returnUrl' => $context['return_url'],
				'i18n'      => array(
					'checking'  => __( 'Checking payment…', 'mygate-crypto-payment-gateway' ),
					'paid'      => __( 'Payment confirmed. Returning to the store…', 'mygate-crypto-payment-gateway' ),
					'received'  => __( 'Payment received. Waiting for store confirmation…', 'mygate-crypto-payment-gateway' ),
					'underpaid' => __( 'Payment detected, but the amount is insufficient.', 'mygate-crypto-payment-gateway' ),
					'terminal'  => __( 'This payment can no longer be completed automatically.', 'mygate-crypto-payment-gateway' ),
				),
			)
		);

		$payment_url = $context['payment_url'];
		$return_url  = $context['return_url'];
		$store_name  = get_bloginfo( 'name' );
		include MGCPG_PATH . 'templates/embedded-checkout.php';
		exit;
	}

	public static function ajax_status() {
		check_ajax_referer( 'mgcpg_payment_status', 'nonce' );

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$auth   = isset( $_POST['auth'] ) ? sanitize_text_field( wp_unslash( $_POST['auth'] ) ) : '';

		if ( 'woo' === $source ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				wp_send_json_error( array( 'message' => __( 'WooCommerce is unavailable.', 'mygate-crypto-payment-gateway' ) ), 503 );
			}
			$order = wc_get_order( $id );
			if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $auth ) || 'mygate' !== $order->get_payment_method() ) {
				wp_send_json_error( array( 'message' => __( 'Invalid order.', 'mygate-crypto-payment-gateway' ) ), 403 );
			}

			$result = $order->is_paid() ? array( 'paid' => true, 'remote_status' => 'C', 'detected' => true, 'api_error' => false ) : MGCPG_Poller::check_woocommerce( $order );
			$order  = wc_get_order( $id );
			wp_send_json_success(
				array(
					'paid'         => $order ? $order->is_paid() : false,
					'status'       => $order ? $order->get_status() : '',
					'remoteStatus' => isset( $result['remote_status'] ) ? $result['remote_status'] : '',
					'detected'     => ! empty( $result['detected'] ),
					'apiError'     => ! empty( $result['api_error'] ),
					'redirect'     => $order ? $order->get_checkout_order_received_url() : home_url( '/' ),
					'terminal'     => $order ? ( ! $order->is_paid() && $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) : true,
				)
			);
		}

		if ( 'edd' === $source ) {
			$stored_id = get_transient( 'mgcpg_edd_' . $auth );
			if ( ! $stored_id || absint( $stored_id ) !== $id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid payment.', 'mygate-crypto-payment-gateway' ) ), 403 );
			}

			$context = MGCPG_Crypto::edd_context( $id );
			if ( 'mygate' !== $context['gateway'] ) {
				wp_send_json_error( array( 'message' => __( 'Invalid payment.', 'mygate-crypto-payment-gateway' ) ), 403 );
			}
			$status = sanitize_key( (string) $context['status'] );
			$paid   = in_array( $status, array( 'publish', 'complete', 'completed' ), true );
			$result = $paid ? array( 'paid' => true, 'remote_status' => 'C', 'detected' => true, 'api_error' => false ) : MGCPG_Poller::check_edd( $id );
			$context = MGCPG_Crypto::edd_context( $id );
			$status  = sanitize_key( (string) $context['status'] );
			$paid    = in_array( $status, array( 'publish', 'complete', 'completed' ), true );

			wp_send_json_success(
				array(
					'paid'         => $paid,
					'status'       => $status,
					'remoteStatus' => isset( $result['remote_status'] ) ? $result['remote_status'] : '',
					'detected'     => ! empty( $result['detected'] ),
					'apiError'     => ! empty( $result['api_error'] ),
					'redirect'     => $context['return_url'] ? $context['return_url'] : home_url( '/' ),
					'terminal'     => ! $paid && in_array( $status, array( 'failed', 'refunded', 'revoked', 'abandoned', 'cancelled', 'canceled', 'trash' ), true ),
				)
			);
		}

		wp_send_json_error( array( 'message' => __( 'Unknown payment source.', 'mygate-crypto-payment-gateway' ) ), 400 );
	}

	private static function context_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request context; authorization uses the WooCommerce order key or short-lived EDD token below.
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( 'woo' === $source ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return new WP_Error( 'mgcpg_no_woo', __( 'WooCommerce is unavailable.', 'mygate-crypto-payment-gateway' ) );
			}
			$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			$order = wc_get_order( $id );
			if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) || 'mygate' !== $order->get_payment_method() ) {
				return new WP_Error( 'mgcpg_invalid_order', __( 'Invalid MyGate order.', 'mygate-crypto-payment-gateway' ) );
			}
			if ( ! $order->is_paid() && $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) {
				return new WP_Error( 'mgcpg_terminal_order', __( 'This order can no longer be paid automatically.', 'mygate-crypto-payment-gateway' ) );
			}
			$payment_url = MGCPG_Crypto::build_woocommerce_payment_url( $order );
			if ( ! $payment_url ) {
				return new WP_Error( 'mgcpg_config', __( 'MyGate is not configured correctly.', 'mygate-crypto-payment-gateway' ) );
			}
			return array(
				'source'      => 'woo',
				'id'          => $id,
				'auth'        => $key,
				'paid'        => $order->is_paid(),
				'payment_url' => $payment_url,
				'return_url'  => $order->get_checkout_order_received_url(),
			);
		}

		if ( 'edd' === $source ) {
			$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			$stored_id = get_transient( 'mgcpg_edd_' . $token );
			if ( ! $stored_id || absint( $stored_id ) !== $id ) {
				return new WP_Error( 'mgcpg_invalid_edd', __( 'Invalid MyGate payment.', 'mygate-crypto-payment-gateway' ) );
			}

			$context = MGCPG_Crypto::edd_context( $id );
			if ( 'mygate' !== $context['gateway'] || null === $context['amount'] || '' === $context['currency'] ) {
				return new WP_Error( 'mgcpg_no_edd', __( 'Easy Digital Downloads payment data is unavailable.', 'mygate-crypto-payment-gateway' ) );
			}
			$status      = sanitize_key( (string) $context['status'] );
			$paid        = in_array( $status, array( 'publish', 'complete', 'completed' ), true );
			if ( ! $paid && in_array( $status, array( 'failed', 'refunded', 'revoked', 'abandoned', 'cancelled', 'canceled', 'trash' ), true ) ) {
				return new WP_Error( 'mgcpg_terminal_edd', __( 'This payment can no longer be completed automatically.', 'mygate-crypto-payment-gateway' ) );
			}
			$return_url  = $context['return_url'] ? $context['return_url'] : home_url( '/' );
			$payment_url = MGCPG_Crypto::build_edd_payment_url( $id, $context['amount'], $context['currency'], $return_url );
			if ( ! $payment_url ) {
				return new WP_Error( 'mgcpg_config', __( 'MyGate is not configured correctly.', 'mygate-crypto-payment-gateway' ) );
			}
			return array(
				'source'      => 'edd',
				'id'          => $id,
				'auth'        => $token,
				'paid'        => $paid,
				'payment_url' => $payment_url,
				'return_url'  => $return_url,
			);
		}

		$unknown_source = new WP_Error( 'mgcpg_source', __( 'Unknown MyGate payment source.', 'mygate-crypto-payment-gateway' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $unknown_source;
	}
}
