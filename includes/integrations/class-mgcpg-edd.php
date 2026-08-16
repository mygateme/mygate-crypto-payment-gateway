<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_EDD {
	public static function init() {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) && ! function_exists( 'EDD' ) ) {
			return;
		}

		add_filter( 'edd_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'edd_gateway_mygate', array( __CLASS__, 'process_payment' ) );
		add_action( 'edd_mygate_cc_form', '__return_false' );
	}

	public static function register_gateway( $gateways ) {
		$gateways['mygate'] = array(
			'admin_label'    => 'MyGate',
			'checkout_label' => MGCPG_Settings::payment_title(),
		);
		return $gateways;
	}

	public static function process_payment( $purchase_data ) {
		if ( function_exists( 'edd_get_errors' ) && edd_get_errors() ) {
			return;
		}

		if ( ! MGCPG_Settings::is_configured() ) {
			if ( function_exists( 'edd_set_error' ) ) {
				edd_set_error( 'mgcpg_not_configured', __( 'MyGate is not configured.', 'mygate-crypto-payment-gateway' ) );
			}
			return;
		}

		// This handler is only invoked for our registered gateway. Persist that fact
		// on the EDD payment so a later webhook/fallback cannot complete another gateway's order.
		$purchase_data['gateway'] = 'mygate';
		$payment_id               = function_exists( 'edd_insert_payment' ) ? edd_insert_payment( $purchase_data ) : 0;
		if ( ! $payment_id ) {
			if ( function_exists( 'edd_set_error' ) ) {
				edd_set_error( 'mgcpg_payment_create', __( 'Unable to create the payment record.', 'mygate-crypto-payment-gateway' ) );
			}
			return;
		}

		$amount   = isset( $purchase_data['price'] ) ? $purchase_data['price'] : 0;
		$currency = function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD';

		if ( class_exists( 'EDD_Payment' ) ) {
			$payment = new EDD_Payment( $payment_id );
			if ( ! empty( $payment->ID ) ) {
				if ( is_numeric( $payment->total ) ) {
					$amount = $payment->total;
				}
				if ( ! empty( $payment->currency ) ) {
					$currency = $payment->currency;
				}
			}
		} elseif ( function_exists( 'edd_get_payment_amount' ) ) {
			$amount = edd_get_payment_amount( $payment_id );
		}

		$redirect = function_exists( 'edd_get_success_page_uri' ) ? edd_get_success_page_uri() : home_url( '/' );
		MGCPG_Crypto::store_edd_context( $payment_id, $amount, $currency, $redirect );

		// Creating/storing this before the redirect binds the remote MyGate transaction
		// to this exact EDD payment for both webhook and polling confirmation paths.
		$reference = MGCPG_Crypto::edd_reference( $payment_id );
		if ( ! $reference ) {
			if ( function_exists( 'edd_set_error' ) ) {
				edd_set_error( 'mgcpg_reference', __( 'Unable to create the MyGate payment reference.', 'mygate-crypto-payment-gateway' ) );
			}
			return;
		}

		$payment_url = MGCPG_Crypto::build_edd_payment_url( $payment_id, $amount, $currency, $redirect );
		if ( ! $payment_url ) {
			if ( function_exists( 'edd_set_error' ) ) {
				edd_set_error( 'mgcpg_url', __( 'Unable to create the MyGate payment request.', 'mygate-crypto-payment-gateway' ) );
			}
			return;
		}

		// Webhook is the fast path; background polling is a firewall/CDN fallback.
		MGCPG_Poller::schedule_edd( $payment_id, 60 );

		MGCPG_Logger::log( 'EDD payment initialized.', array( 'payment_id' => $payment_id, 'mode' => MGCPG_Settings::get( 'checkout_experience', 'redirect' ) ) );

		$target = 'embedded' === MGCPG_Settings::get( 'checkout_experience', 'redirect' ) ? MGCPG_Embedded::edd_url( $payment_id ) : $payment_url;
		wp_redirect( esc_url_raw( $target ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- MyGate is the configured external payment service.
		exit;
	}
}
