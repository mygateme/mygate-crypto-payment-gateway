<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment confirmation fallback.
 *
 * Webhooks remain the fastest path. This class lets WordPress query MyGate from
 * the server side when an inbound webhook is blocked by Cloudflare, a firewall,
 * or another security layer. It also schedules light background checks so an
 * order can complete even if the shopper closes the browser.
 */
class MGCPG_Poller {
	const CRON_HOOK = 'mgcpg_poll_payment';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_check' ), 10, 2 );
	}

	public static function schedule_woocommerce( $order_id, $delay = 60 ) {
		self::schedule( 'woo', absint( $order_id ), $delay );
	}

	public static function schedule_edd( $payment_id, $delay = 60 ) {
		self::schedule( 'edd', absint( $payment_id ), $delay );
	}

	private static function schedule( $source, $id, $delay ) {
		if ( ! $id || ! in_array( $source, array( 'woo', 'edd' ), true ) ) {
			return;
		}
		$args = array( $source, $id );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 30, absint( $delay ) ), self::CRON_HOOK, $args );
		}
	}

	/**
	 * Browser-triggered Woo fallback check after order-key validation.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function check_woocommerce( $order ) {
		if ( ! $order || 'mygate' !== $order->get_payment_method() ) {
			return array( 'paid' => false, 'remote_status' => '', 'detected' => false );
		}
		if ( $order->is_paid() ) {
			return array( 'paid' => true, 'remote_status' => 'C', 'detected' => true );
		}
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) {
			return array( 'paid' => false, 'remote_status' => '', 'detected' => false );
		}

		$reference = MGCPG_Crypto::woocommerce_reference( $order );
		$result    = self::check_reference( $reference );
		if ( ! empty( $result['completed'] ) ) {
			// Reload after payment_complete() so the caller gets the current state.
			$order = wc_get_order( $order->get_id() );
		}
		$result['paid'] = $order ? $order->is_paid() : false;
		return $result;
	}

	public static function check_edd( $payment_id ) {
		$payment_id = absint( $payment_id );
		$context    = MGCPG_Crypto::edd_context( $payment_id );
		$status     = sanitize_key( (string) $context['status'] );
		$paid       = in_array( $status, array( 'publish', 'complete', 'completed' ), true );
		if ( $paid ) {
			return array( 'paid' => true, 'remote_status' => 'C', 'detected' => true );
		}
		if ( 'mygate' !== $context['gateway'] || in_array( $status, array( 'failed', 'refunded', 'revoked', 'abandoned', 'cancelled', 'canceled', 'trash' ), true ) ) {
			return array( 'paid' => false, 'remote_status' => '', 'detected' => false );
		}

		$reference = MGCPG_Crypto::edd_reference( $payment_id );
		$result    = self::check_reference( $reference );
		if ( ! empty( $result['completed'] ) ) {
			$context = MGCPG_Crypto::edd_context( $payment_id );
			$status  = sanitize_key( (string) $context['status'] );
			$paid    = in_array( $status, array( 'publish', 'complete', 'completed' ), true );
		}
		$result['paid'] = $paid;
		return $result;
	}

	private static function check_reference( $reference ) {
		$default = array(
			'paid'          => false,
			'completed'     => false,
			'remote_status' => '',
			'detected'      => false,
			'api_error'     => false,
		);

		if ( ! $reference ) {
			$default['api_error'] = true;
			return $default;
		}

		$transaction = MGCPG_API::find_transaction_by_reference( $reference );
		if ( is_wp_error( $transaction ) ) {
			$default['api_error'] = true;
			return $default;
		}
		if ( ! $transaction ) {
			return $default;
		}

		$status = isset( $transaction['status'] ) ? strtoupper( sanitize_text_field( (string) $transaction['status'] ) ) : '';
		$default['remote_status'] = $status;
		$default['detected']      = ! empty( $transaction['hash'] ) || in_array( $status, array( 'C', 'X', 'R' ), true );

		if ( 'C' !== $status ) {
			return $default;
		}

		$response = MGCPG_Webhook::process_transaction( $transaction, 'fallback' );
		if ( $response instanceof WP_REST_Response && $response->get_status() >= 200 && $response->get_status() < 300 ) {
			$default['completed'] = true;
		}
		return $default;
	}

	/**
	 * Background fallback. Stops after successful payment, terminal local status,
	 * or approximately 48 hours.
	 */
	public static function cron_check( $source, $id ) {
		$source = sanitize_key( $source );
		$id     = absint( $id );
		if ( ! $id || ! MGCPG_Settings::is_configured() ) {
			return;
		}

		$attempt_key = 'mgcpg_poll_attempt_' . $source . '_' . $id;
		$attempt     = absint( get_transient( $attempt_key ) );
		if ( $attempt >= 576 ) { // 48 hours at five-minute retry intervals.
			delete_transient( $attempt_key );
			return;
		}
		set_transient( $attempt_key, $attempt + 1, 2 * DAY_IN_SECONDS );

		if ( 'woo' === $source ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return;
			}
			$order = wc_get_order( $id );
			if ( ! $order || 'mygate' !== $order->get_payment_method() ) {
				delete_transient( $attempt_key );
				return;
			}
			if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) {
				delete_transient( $attempt_key );
				return;
			}
			$result = self::check_woocommerce( $order );
			if ( ! empty( $result['paid'] ) ) {
				delete_transient( $attempt_key );
				return;
			}
			self::schedule_woocommerce( $id, 300 );
			return;
		}

		if ( 'edd' === $source ) {
			$context = MGCPG_Crypto::edd_context( $id );
			$status  = sanitize_key( (string) $context['status'] );
			if ( 'mygate' !== $context['gateway'] || in_array( $status, array( 'publish', 'complete', 'completed', 'failed', 'revoked', 'refunded', 'abandoned', 'cancelled', 'canceled', 'trash' ), true ) ) {
				delete_transient( $attempt_key );
				return;
			}
			$result = self::check_edd( $id );
			if ( ! empty( $result['paid'] ) ) {
				delete_transient( $attempt_key );
				return;
			}
			self::schedule_edd( $id, 300 );
		}
	}
}
