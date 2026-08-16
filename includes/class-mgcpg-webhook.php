<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_Webhook {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$definition = array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
		);

		// Keep the existing MyGate URL working without any MyGate server changes.
		register_rest_route( 'mygate', '/webhook', $definition );
		// Versioned alias for future use.
		register_rest_route( 'mygate/v1', '/webhook', $definition );
		// Legacy aliases: older Boxcoin/MyGate server-side Woo routing may still call these paths.
		register_rest_route( 'boxcoin', '/webhook', $definition );
		register_rest_route( 'boxcoin/v1', '/webhook', $definition );
	}

	public static function handle( WP_REST_Request $request ) {
		self::record_status( 'webhook', 'received', __( 'Webhook request reached WordPress.', 'mygate-crypto-payment-gateway' ) );
		$payload = json_decode( $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			self::record_status( 'webhook', 'rejected', __( 'Invalid JSON.', 'mygate-crypto-payment-gateway' ) );
			MGCPG_Logger::log( 'Webhook rejected: invalid JSON.', array(), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid JSON.' ), 400 );
		}

		$provided_secret = isset( $payload['key'] ) && is_string( $payload['key'] ) ? $payload['key'] : '';
		$expected_secret = (string) MGCPG_Settings::get( 'webhook_secret' );
		if ( '' === $expected_secret || '' === $provided_secret || ! hash_equals( $expected_secret, $provided_secret ) ) {
			self::record_status( 'webhook', 'rejected', __( 'Webhook secret mismatch.', 'mygate-crypto-payment-gateway' ) );
			MGCPG_Logger::log( 'Webhook rejected: secret mismatch.', array(), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid webhook secret.' ), 401 );
		}

		$transaction = isset( $payload['transaction'] ) && is_array( $payload['transaction'] ) ? $payload['transaction'] : array();
		return self::process_transaction( $transaction, 'webhook' );
	}

	/**
	 * Validate and complete a MyGate transaction.
	 *
	 * Used by both the authenticated webhook and the server-side API fallback.
	 * The fallback already authenticates to MyGate with the merchant API key, but
	 * this method still validates the encrypted reference, completed status, amount,
	 * currency, local payment method, terminal state, and idempotency before changing the order.
	 *
	 * @param array  $transaction MyGate transaction.
	 * @param string $channel     webhook|fallback.
	 * @return WP_REST_Response
	 */
	public static function process_transaction( $transaction, $channel = 'webhook' ) {
		$channel = 'fallback' === $channel ? 'fallback' : 'webhook';
		if ( ! is_array( $transaction ) || empty( $transaction['external_reference'] ) ) {
			self::record_status( $channel, 'rejected', __( 'Transaction reference missing.', 'mygate-crypto-payment-gateway' ) );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Transaction reference missing.' ), 400 );
		}

		// Never grant access for pending, refunded, or underpaid transactions.
		$status = isset( $transaction['status'] ) ? strtoupper( sanitize_text_field( (string) $transaction['status'] ) ) : '';
		if ( 'C' !== $status ) {
			self::record_status( $channel, 'rejected', __( 'Transaction is not completed.', 'mygate-crypto-payment-gateway' ) );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Transaction is not completed.' ), 409 );
		}

		$reference = (string) $transaction['external_reference'];
		$decrypted = MGCPG_Crypto::decrypt_reference( $reference );
		if ( false === $decrypted || '' === $decrypted ) {
			self::record_status( $channel, 'rejected', __( 'Transaction reference could not be decrypted.', 'mygate-crypto-payment-gateway' ) );
			MGCPG_Logger::log( 'Payment confirmation rejected: reference could not be decrypted.', array( 'transaction_id' => isset( $transaction['id'] ) ? $transaction['id'] : '' ), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid transaction reference.' ), 400 );
		}

		$parts  = array_values( array_filter( explode( '|', $decrypted ), 'strlen' ) );
		$source = in_array( 'woo', $parts, true ) ? 'woo' : ( in_array( 'edd', $parts, true ) ? 'edd' : 'unknown' );

		MGCPG_Logger::log(
			'Payment confirmation received.',
			array(
				'transaction_id' => isset( $transaction['id'] ) ? $transaction['id'] : '',
				'source'         => $source,
				'channel'        => $channel,
			)
		);

		if ( 'woo' === $source ) {
			$response = self::complete_woocommerce( $transaction, $parts, $channel, $reference );
		} elseif ( 'edd' === $source ) {
			$response = self::complete_edd( $transaction, $parts, $channel, $reference );
		} else {
			self::record_status( $channel, 'rejected', __( 'Unknown payment source.', 'mygate-crypto-payment-gateway' ) );
			$response = new WP_REST_Response( array( 'success' => false, 'message' => 'Unknown payment source.' ), 400 );
		}

		if ( $response instanceof WP_REST_Response && $response->get_status() >= 200 && $response->get_status() < 300 ) {
			MGCPG_API::clear_reference_cache( $reference );
		}
		return $response;
	}

	private static function complete_woocommerce( $transaction, $parts, $channel, $reference ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'WooCommerce is unavailable.' ), 503 );
		}

		$order_id = self::first_numeric_part( $parts );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			self::record_status( $channel, 'rejected', __( 'WooCommerce order not found.', 'mygate-crypto-payment-gateway' ) );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order not found.' ), 404 );
		}

		if ( 'mygate' !== $order->get_payment_method() ) {
			self::record_status( $channel, 'rejected', __( 'WooCommerce payment method mismatch.', 'mygate-crypto-payment-gateway' ) );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order payment method mismatch.' ), 409 );
		}

		$stored_reference = (string) $order->get_meta( '_mgcpg_external_reference', true );
		if ( '' === $stored_reference ) {
			$stored_reference = (string) MGCPG_Crypto::woocommerce_reference( $order );
		}
		if ( ! self::references_match( $stored_reference, $reference ) ) {
			self::record_status( $channel, 'rejected', __( 'WooCommerce payment reference mismatch.', 'mygate-crypto-payment-gateway' ), $order->get_id(), isset( $transaction['id'] ) ? $transaction['id'] : '' );
			MGCPG_Logger::log( 'WooCommerce payment confirmation rejected: reference mismatch.', array( 'order_id' => $order->get_id() ), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order payment reference mismatch.' ), 409 );
		}

		$transaction_id = isset( $transaction['id'] ) ? sanitize_text_field( (string) $transaction['id'] ) : '';
		$existing_tx_id = sanitize_text_field( (string) $order->get_transaction_id() );
		if ( ! $order->is_paid() && $transaction_id && $existing_tx_id && hash_equals( $existing_tx_id, $transaction_id ) ) {
			self::record_status( $channel, 'rejected', __( 'WooCommerce transaction replay rejected.', 'mygate-crypto-payment-gateway' ), $order->get_id(), $transaction_id );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Previously completed transaction cannot be replayed.' ), 409 );
		}

		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) {
			self::record_status( $channel, 'rejected', __( 'WooCommerce order is in a terminal state.', 'mygate-crypto-payment-gateway' ), $order->get_id(), isset( $transaction['id'] ) ? $transaction['id'] : '' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Order can no longer be completed automatically.' ), 409 );
		}

		if ( ! self::woocommerce_amount_and_currency_match( $order, $transaction ) ) {
			self::record_status( $channel, 'rejected', __( 'WooCommerce amount or currency mismatch.', 'mygate-crypto-payment-gateway' ) );
			MGCPG_Logger::log( 'WooCommerce payment confirmation rejected: amount or currency mismatch.', array( 'order_id' => $order->get_id(), 'transaction_id' => isset( $transaction['id'] ) ? $transaction['id'] : '' ), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid amount or currency.' ), 409 );
		}

		// Idempotent handling: webhook and polling may race each other safely.
		if ( $order->is_paid() ) {
			if ( $transaction_id && ! $order->get_transaction_id() ) {
				$order->set_transaction_id( $transaction_id );
				$order->save();
			}
			self::record_status( $channel, 'success', __( 'WooCommerce order already paid.', 'mygate-crypto-payment-gateway' ), $order->get_id(), $transaction_id );
			return new WP_REST_Response( array( 'success' => true, 'message' => 'Order already paid.' ), 200 );
		}

		$order->update_meta_data( '_mgcpg_transaction_id', $transaction_id );
		if ( isset( $transaction['cryptocurrency'] ) ) {
			$order->update_meta_data( '_mgcpg_cryptocurrency', sanitize_text_field( (string) $transaction['cryptocurrency'] ) );
		}
		if ( isset( $transaction['hash'] ) ) {
			$order->update_meta_data( '_mgcpg_blockchain_hash', sanitize_text_field( (string) $transaction['hash'] ) );
		}
		$order->save();

		$order->payment_complete( $transaction_id );
		$order->add_order_note(
			sprintf(
				/* translators: %s: MyGate transaction ID. */
				__( 'MyGate payment confirmed. Transaction ID: %s', 'mygate-crypto-payment-gateway' ),
				$transaction_id ? $transaction_id : __( 'not provided', 'mygate-crypto-payment-gateway' )
			)
		);

		self::record_status( $channel, 'success', __( 'WooCommerce order marked paid.', 'mygate-crypto-payment-gateway' ), $order->get_id(), $transaction_id );
		MGCPG_Logger::log( 'WooCommerce order marked paid.', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'channel' => $channel ) );
		return new WP_REST_Response( array( 'success' => true, 'message' => 'Payment completed.' ), 200 );
	}

	private static function complete_edd( $transaction, $parts, $channel, $reference ) {
		if ( ! class_exists( 'EDD_Payment' ) && ! function_exists( 'edd_get_payment_status' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Easy Digital Downloads is unavailable.' ), 503 );
		}

		$payment_id = self::first_numeric_part( $parts );
		if ( ! $payment_id ) {
			self::record_status( $channel, 'rejected', __( 'EDD payment not found.', 'mygate-crypto-payment-gateway' ) );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'EDD payment not found.' ), 404 );
		}

		$context = MGCPG_Crypto::edd_context( $payment_id );
		if ( '' === $context['status'] && null === $context['amount'] ) {
			self::record_status( $channel, 'rejected', __( 'EDD payment not found.', 'mygate-crypto-payment-gateway' ) );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'EDD payment not found.' ), 404 );
		}

		if ( 'mygate' !== $context['gateway'] ) {
			self::record_status( $channel, 'rejected', __( 'EDD payment method mismatch.', 'mygate-crypto-payment-gateway' ), $payment_id, isset( $transaction['id'] ) ? $transaction['id'] : '' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'EDD payment method mismatch.' ), 409 );
		}

		$stored_reference = MGCPG_Crypto::edd_reference( $payment_id );
		if ( ! self::references_match( $stored_reference, $reference ) ) {
			self::record_status( $channel, 'rejected', __( 'EDD payment reference mismatch.', 'mygate-crypto-payment-gateway' ), $payment_id, isset( $transaction['id'] ) ? $transaction['id'] : '' );
			MGCPG_Logger::log( 'EDD payment confirmation rejected: reference mismatch.', array( 'payment_id' => $payment_id ), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'EDD payment reference mismatch.' ), 409 );
		}

		$transaction_id = isset( $transaction['id'] ) ? sanitize_text_field( (string) $transaction['id'] ) : '';
		$existing_tx_id = function_exists( 'edd_get_payment_transaction_id' ) ? sanitize_text_field( (string) edd_get_payment_transaction_id( $payment_id ) ) : '';
		$status         = sanitize_key( (string) $context['status'] );
		if ( ! in_array( $status, array( 'publish', 'complete', 'completed' ), true ) && $transaction_id && $existing_tx_id && hash_equals( $existing_tx_id, $transaction_id ) ) {
			self::record_status( $channel, 'rejected', __( 'EDD transaction replay rejected.', 'mygate-crypto-payment-gateway' ), $payment_id, $transaction_id );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Previously completed transaction cannot be replayed.' ), 409 );
		}

		if ( in_array( $status, array( 'publish', 'complete', 'completed' ), true ) ) {
			if ( $transaction_id && function_exists( 'edd_get_payment_transaction_id' ) && function_exists( 'edd_set_payment_transaction_id' ) && ! edd_get_payment_transaction_id( $payment_id ) ) {
				edd_set_payment_transaction_id( $payment_id, $transaction_id );
			}
			self::record_status( $channel, 'success', __( 'EDD payment already completed.', 'mygate-crypto-payment-gateway' ), $payment_id, $transaction_id );
			return new WP_REST_Response( array( 'success' => true, 'message' => 'Payment already completed.' ), 200 );
		}

		if ( in_array( $status, array( 'failed', 'refunded', 'revoked', 'abandoned', 'cancelled', 'canceled', 'trash' ), true ) ) {
			self::record_status( $channel, 'rejected', __( 'EDD payment is in a terminal state.', 'mygate-crypto-payment-gateway' ), $payment_id, isset( $transaction['id'] ) ? $transaction['id'] : '' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'EDD payment can no longer be completed automatically.' ), 409 );
		}

		if ( ! self::edd_amount_and_currency_match( $payment_id, $transaction, $context ) ) {
			self::record_status( $channel, 'rejected', __( 'EDD amount or currency mismatch.', 'mygate-crypto-payment-gateway' ), $payment_id, isset( $transaction['id'] ) ? $transaction['id'] : '' );
			MGCPG_Logger::log( 'EDD payment confirmation rejected: amount or currency mismatch.', array( 'payment_id' => $payment_id, 'transaction_id' => isset( $transaction['id'] ) ? $transaction['id'] : '' ), 'warning' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid amount or currency.' ), 409 );
		}

		MGCPG_Crypto::edd_update_meta( $payment_id, '_mgcpg_transaction_id', $transaction_id );
		if ( isset( $transaction['cryptocurrency'] ) ) {
			MGCPG_Crypto::edd_update_meta( $payment_id, '_mgcpg_cryptocurrency', sanitize_text_field( (string) $transaction['cryptocurrency'] ) );
		}
		if ( isset( $transaction['hash'] ) ) {
			MGCPG_Crypto::edd_update_meta( $payment_id, '_mgcpg_blockchain_hash', sanitize_text_field( (string) $transaction['hash'] ) );
		}
		if ( function_exists( 'edd_update_payment_status' ) ) {
			edd_update_payment_status( $payment_id, 'complete' );
		} elseif ( class_exists( 'EDD_Payment' ) ) {
			$payment = new EDD_Payment( $payment_id );
			if ( ! empty( $payment->ID ) && method_exists( $payment, 'update_status' ) ) {
				$payment->update_status( 'complete' );
			}
		}

		$updated_context = MGCPG_Crypto::edd_context( $payment_id );
		if ( ! in_array( sanitize_key( (string) $updated_context['status'] ), array( 'publish', 'complete', 'completed' ), true ) ) {
			self::record_status( $channel, 'error', __( 'EDD payment status could not be updated.', 'mygate-crypto-payment-gateway' ), $payment_id, $transaction_id );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'EDD payment status update failed.' ), 500 );
		}
		if ( $transaction_id && function_exists( 'edd_set_payment_transaction_id' ) ) {
			edd_set_payment_transaction_id( $payment_id, $transaction_id );
		}

		$note = sprintf(
			/* translators: %s: MyGate transaction ID. */
			__( 'MyGate payment confirmed. Transaction ID: %s', 'mygate-crypto-payment-gateway' ),
			$transaction_id ? $transaction_id : __( 'not provided', 'mygate-crypto-payment-gateway' )
		);
		if ( function_exists( 'edd_insert_payment_note' ) ) {
			edd_insert_payment_note( $payment_id, $note );
		} elseif ( class_exists( 'EDD_Payment' ) ) {
			$payment = new EDD_Payment( $payment_id );
			if ( ! empty( $payment->ID ) && method_exists( $payment, 'add_note' ) ) {
				$payment->add_note( $note );
			}
		}

		self::record_status( $channel, 'success', __( 'EDD payment marked complete.', 'mygate-crypto-payment-gateway' ), $payment_id, $transaction_id );
		MGCPG_Logger::log( 'EDD payment marked complete.', array( 'payment_id' => $payment_id, 'transaction_id' => $transaction_id, 'channel' => $channel ) );
		return new WP_REST_Response( array( 'success' => true, 'message' => 'Payment completed.' ), 200 );
	}

	private static function record_status( $channel, $status, $message, $order_id = 0, $transaction_id = '' ) {
		$option = 'fallback' === $channel ? 'mgcpg_last_fallback' : 'mgcpg_last_webhook';
		update_option(
			$option,
			array(
				'time'           => time(),
				'status'         => sanitize_key( $status ),
				'message'        => sanitize_text_field( $message ),
				'order_id'       => absint( $order_id ),
				'transaction_id' => sanitize_text_field( (string) $transaction_id ),
			),
			false
		);
	}

	private static function first_numeric_part( $parts ) {
		foreach ( $parts as $part ) {
			if ( ctype_digit( (string) $part ) ) {
				return absint( $part );
			}
		}
		return 0;
	}

	private static function references_match( $stored, $incoming ) {
		$stored   = is_string( $stored ) ? trim( $stored ) : '';
		$incoming = is_string( $incoming ) ? trim( $incoming ) : '';
		return '' !== $stored && '' !== $incoming && hash_equals( $stored, $incoming );
	}

	private static function woocommerce_amount_and_currency_match( $order, $transaction ) {
		$transaction_currency = isset( $transaction['currency'] ) ? strtoupper( sanitize_text_field( (string) $transaction['currency'] ) ) : '';
		if ( '' === $transaction_currency || strtoupper( $order->get_currency() ) !== $transaction_currency ) {
			return false;
		}

		if ( ! isset( $transaction['amount_fiat'] ) || '' === (string) $transaction['amount_fiat'] ) {
			return false;
		}

		$expected  = (float) $order->get_total();
		$received  = (float) $transaction['amount_fiat'];
		$decimals  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$tolerance = 0.5 / pow( 10, max( 0, (int) $decimals ) );

		return ( $received + $tolerance ) >= $expected;
	}

	private static function edd_amount_and_currency_match( $payment_id, $transaction, $context = null ) {
		$context = is_array( $context ) ? $context : MGCPG_Crypto::edd_context( $payment_id );
		if ( null === $context['amount'] || '' === $context['currency'] ) {
			return false;
		}

		$transaction_currency = isset( $transaction['currency'] ) ? strtoupper( sanitize_text_field( (string) $transaction['currency'] ) ) : '';
		if ( '' === $transaction_currency || strtoupper( $context['currency'] ) !== $transaction_currency ) {
			return false;
		}

		if ( ! isset( $transaction['amount_fiat'] ) || '' === (string) $transaction['amount_fiat'] ) {
			return false;
		}

		$expected  = (float) $context['amount'];
		$received  = (float) $transaction['amount_fiat'];
		$tolerance = 0.005;
		return ( $received + $tolerance ) >= $expected;
	}
}
