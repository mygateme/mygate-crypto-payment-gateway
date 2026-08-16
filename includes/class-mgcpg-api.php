<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal server-to-server MyGate API client.
 *
 * This client sends fallback status lookups from the WordPress server rather than
 * from browser JavaScript. It is used only when a webhook is delayed or blocked
 * by a firewall/CDN. The hosted checkout receives only the derived public pk_live_ key; the private
 * sk_live_ key stays on the WordPress server and is used only for API requests.
 */
class MGCPG_API {
	const API_URL = 'https://app.mygate.me/api.php';

	/**
	 * Find the exact MyGate transaction created for an encrypted external reference.
	 *
	 * @param string $reference Encrypted MyGate external reference.
	 * @return array|false|WP_Error Exact transaction, false when no transaction exists yet, or WP_Error.
	 */
	public static function find_transaction_by_reference( $reference ) {
		$reference = is_string( $reference ) ? trim( $reference ) : '';
		if ( '' === $reference ) {
			return new WP_Error( 'mgcpg_api_reference', __( 'Payment reference is missing.', 'mygate-crypto-payment-gateway' ) );
		}

		$api_key = MGCPG_Settings::private_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'mgcpg_api_key', __( 'MyGate private API key is not configured.', 'mygate-crypto-payment-gateway' ) );
		}

		// Browser polling can run every few seconds. Share a short cache between requests
		// so the store never hammers the MyGate API.
		$cache_key = 'mgcpg_tx_' . md5( $reference );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && array_key_exists( 'found', $cached ) ) {
			return ! empty( $cached['found'] ) && isset( $cached['transaction'] ) && is_array( $cached['transaction'] )
				? $cached['transaction']
				: false;
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'Accept' => 'application/json',
				),
				'body'        => array(
					'api-key'    => $api_key,
					'function'   => 'get-transactions',
					'pagination' => 0,
					'search'     => $reference,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			MGCPG_Logger::log( 'MyGate fallback API request failed.', array( 'error' => $response->get_error_message() ), 'warning' );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			MGCPG_Logger::log( 'MyGate fallback API returned a non-success HTTP status.', array( 'http_code' => $code ), 'warning' );
			return new WP_Error( 'mgcpg_api_http', __( 'MyGate status check is temporarily unavailable.', 'mygate-crypto-payment-gateway' ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'mgcpg_api_json', __( 'MyGate returned an invalid status response.', 'mygate-crypto-payment-gateway' ) );
		}

		// Boxcoin/MyGate REST API normally wraps results in {success,response}.
		if ( array_key_exists( 'success', $decoded ) ) {
			if ( empty( $decoded['success'] ) ) {
				$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : __( 'MyGate rejected the status request.', 'mygate-crypto-payment-gateway' );
				return new WP_Error( 'mgcpg_api_response', sanitize_text_field( $message ) );
			}
			$transactions = isset( $decoded['response'] ) ? $decoded['response'] : array();
		} else {
			// Keep compatibility with installations returning the response array directly.
			$transactions = $decoded;
		}

		if ( ! is_array( $transactions ) ) {
			$transactions = array();
		}

		$exact = false;
		foreach ( $transactions as $transaction ) {
			if ( ! is_array( $transaction ) || ! isset( $transaction['external_reference'] ) ) {
				continue;
			}
			if ( hash_equals( $reference, (string) $transaction['external_reference'] ) ) {
				if ( false === $exact || (int) ( isset( $transaction['id'] ) ? $transaction['id'] : 0 ) > (int) ( isset( $exact['id'] ) ? $exact['id'] : 0 ) ) {
					$exact = $transaction;
				}
			}
		}

		set_transient(
			$cache_key,
			array(
				'found'       => (bool) $exact,
				'transaction' => $exact ? $exact : array(),
			),
			5
		);

		return $exact;
	}

	/**
	 * Clear a cached lookup after a webhook completes the payment.
	 *
	 * @param string $reference Encrypted reference.
	 */
	public static function clear_reference_cache( $reference ) {
		if ( is_string( $reference ) && '' !== $reference ) {
			delete_transient( 'mgcpg_tx_' . md5( $reference ) );
		}
	}
}
