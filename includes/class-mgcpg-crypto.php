<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_Crypto {
	const PAY_URL = 'https://app.mygate.me/pay.php';

	public static function encrypt_reference( $value ) {
		$secret = (string) MGCPG_Settings::get( 'webhook_secret' );
		$api    = (string) MGCPG_Settings::get( 'api_key' );

		if ( '' === $secret || '' === $api || ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}

		$key       = hash( 'sha256', $secret );
		$iv        = substr( hash( 'sha256', $api ), 0, 16 );
		$plaintext = is_string( $value ) ? $value : wp_json_encode( $value );
		$encrypted = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );

		if ( false === $encrypted ) {
			return false;
		}

		$output = base64_encode( $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Protocol compatibility with MyGate.
		return rtrim( $output, '=' );
	}

	public static function decrypt_reference( $value ) {
		$secret = (string) MGCPG_Settings::get( 'webhook_secret' );
		$api    = (string) MGCPG_Settings::get( 'api_key' );

		if ( '' === $secret || '' === $api || ! is_string( $value ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$padded = $value . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );
		$raw    = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol compatibility with MyGate.
		if ( false === $raw ) {
			return false;
		}

		$key = hash( 'sha256', $secret );
		$iv  = substr( hash( 'sha256', $api ), 0, 16 );
		return openssl_decrypt( $raw, 'AES-256-CBC', $key, 0, $iv );
	}

	/**
	 * Return the stable encrypted reference for a WooCommerce order.
	 *
	 * Saving it on the order prevents a later settings change from creating a
	 * different lookup key while the payment is still pending.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|false
	 */
	public static function woocommerce_reference( $order ) {
		$stored = (string) $order->get_meta( '_mgcpg_external_reference', true );
		if ( '' !== $stored ) {
			return $stored;
		}

		$reference = self::encrypt_reference( $order->get_id() . '|' . $order->get_checkout_order_received_url() . '|woo' );
		if ( false !== $reference ) {
			$order->update_meta_data( '_mgcpg_external_reference', $reference );
			$order->save();
		}
		return $reference;
	}

	/**
	 * Return the stable encrypted reference for an EDD payment.
	 *
	 * The reference is persisted in EDD payment meta so polling keeps working
	 * after cache/transient expiry and across normal EDD storage migrations.
	 * A transient is retained as a compatibility fallback for beta-created orders.
	 *
	 * @param int $payment_id EDD payment ID.
	 * @return string|false
	 */
	public static function edd_reference( $payment_id ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return false;
		}

		$stored = self::edd_get_meta( $payment_id, '_mgcpg_external_reference', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$cache_key = 'mgcpg_edd_ref_' . $payment_id;
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			self::edd_update_meta( $payment_id, '_mgcpg_external_reference', $cached );
			return $cached;
		}

		$reference = self::encrypt_reference( $payment_id . '|edd' );
		if ( false !== $reference ) {
			self::edd_update_meta( $payment_id, '_mgcpg_external_reference', $reference );
			set_transient( $cache_key, $reference, 2 * DAY_IN_SECONDS );
		}
		return $reference;
	}

	/**
	 * Persist the amount, currency and return URL that were used to create an EDD payment request.
	 *
	 * @param int    $payment_id EDD payment ID.
	 * @param mixed  $amount     Expected fiat amount.
	 * @param string $currency   Expected fiat currency.
	 * @param string $return_url Store return URL.
	 */
	public static function store_edd_context( $payment_id, $amount, $currency, $return_url ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return;
		}

		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		self::edd_update_meta( $payment_id, '_mgcpg_expected_amount', (string) $amount );
		self::edd_update_meta( $payment_id, '_mgcpg_expected_currency', $currency );
		self::edd_update_meta( $payment_id, '_mgcpg_return_url', esc_url_raw( $return_url ) );
		self::edd_update_meta( $payment_id, '_mgcpg_gateway', 'mygate' );

		// Keep beta.4 compatibility while existing scheduled checks are still alive.
		set_transient(
			'mgcpg_edd_expected_' . $payment_id,
			array(
				'amount'   => (float) $amount,
				'currency' => $currency,
			),
			2 * DAY_IN_SECONDS
		);
	}

	/**
	 * Return the locally stored EDD payment context.
	 *
	 * @param int $payment_id EDD payment ID.
	 * @return array
	 */
	public static function edd_context( $payment_id ) {
		$payment_id = absint( $payment_id );
		$context    = array(
			'amount'     => null,
			'currency'   => '',
			'return_url' => '',
			'gateway'    => '',
			'status'     => '',
		);

		if ( ! $payment_id ) {
			return $context;
		}

		if ( class_exists( 'EDD_Payment' ) ) {
			$payment = new EDD_Payment( $payment_id );
			if ( ! empty( $payment->ID ) ) {
				$context['amount']   = is_numeric( $payment->total ) ? (float) $payment->total : null;
				$context['currency'] = strtoupper( sanitize_text_field( (string) $payment->currency ) );
				$context['gateway']  = sanitize_key( (string) $payment->gateway );
				$context['status']   = sanitize_key( (string) $payment->status );
			}
		}

		$stored_amount   = self::edd_get_meta( $payment_id, '_mgcpg_expected_amount', '' );
		$stored_currency = self::edd_get_meta( $payment_id, '_mgcpg_expected_currency', '' );
		$stored_return   = self::edd_get_meta( $payment_id, '_mgcpg_return_url', '' );
		$stored_gateway  = self::edd_get_meta( $payment_id, '_mgcpg_gateway', '' );

		if ( null === $context['amount'] && '' !== (string) $stored_amount && is_numeric( $stored_amount ) ) {
			$context['amount'] = (float) $stored_amount;
		}
		if ( '' === $context['currency'] && '' !== (string) $stored_currency ) {
			$context['currency'] = strtoupper( sanitize_text_field( (string) $stored_currency ) );
		}
		if ( is_string( $stored_return ) && '' !== $stored_return ) {
			$context['return_url'] = esc_url_raw( $stored_return );
		}
		if ( '' === $context['gateway'] && is_string( $stored_gateway ) && '' !== $stored_gateway ) {
			$context['gateway'] = sanitize_key( $stored_gateway );
		}

		$legacy = get_transient( 'mgcpg_edd_expected_' . $payment_id );
		if ( is_array( $legacy ) ) {
			if ( null === $context['amount'] && isset( $legacy['amount'] ) && is_numeric( $legacy['amount'] ) ) {
				$context['amount'] = (float) $legacy['amount'];
			}
			if ( '' === $context['currency'] && ! empty( $legacy['currency'] ) ) {
				$context['currency'] = strtoupper( sanitize_text_field( (string) $legacy['currency'] ) );
			}
		}

		if ( null === $context['amount'] && function_exists( 'edd_get_payment_amount' ) ) {
			$context['amount'] = (float) edd_get_payment_amount( $payment_id );
		}
		if ( '' === $context['currency'] && function_exists( 'edd_get_currency' ) ) {
			$context['currency'] = strtoupper( sanitize_text_field( (string) edd_get_currency() ) );
		}
		if ( '' === $context['status'] && function_exists( 'edd_get_payment_status' ) ) {
			$context['status'] = sanitize_key( (string) edd_get_payment_status( $payment_id ) );
		}
		if ( '' === $context['gateway'] && function_exists( 'edd_get_payment_gateway' ) ) {
			$context['gateway'] = sanitize_key( (string) edd_get_payment_gateway( $payment_id ) );
		}
		if ( '' === $context['return_url'] ) {
			$context['return_url'] = function_exists( 'edd_get_success_page_uri' ) ? esc_url_raw( edd_get_success_page_uri() ) : home_url( '/' );
		}

		return $context;
	}

	/**
	 * Read EDD payment metadata through EDD's compatibility API.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $key        Meta key.
	 * @param mixed  $default    Default value.
	 * @return mixed
	 */
	public static function edd_get_meta( $payment_id, $key, $default = '' ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return $default;
		}

		if ( function_exists( 'edd_get_payment_meta' ) ) {
			$value = edd_get_payment_meta( $payment_id, $key, true );
			return '' === $value || null === $value ? $default : $value;
		}

		if ( class_exists( 'EDD_Payment' ) ) {
			$payment = new EDD_Payment( $payment_id );
			if ( ! empty( $payment->ID ) && method_exists( $payment, 'get_meta' ) ) {
				$value = $payment->get_meta( $key, true );
				return '' === $value || null === $value ? $default : $value;
			}
		}

		return $default;
	}

	/**
	 * Update EDD payment metadata through EDD's compatibility API.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $key        Meta key.
	 * @param mixed  $value      Value.
	 * @return bool
	 */
	public static function edd_update_meta( $payment_id, $key, $value ) {
		$payment_id = absint( $payment_id );
		if ( ! $payment_id ) {
			return false;
		}

		if ( function_exists( 'edd_update_payment_meta' ) ) {
			edd_update_payment_meta( $payment_id, $key, $value );
			return true;
		}

		if ( class_exists( 'EDD_Payment' ) ) {
			$payment = new EDD_Payment( $payment_id );
			if ( ! empty( $payment->ID ) && method_exists( $payment, 'update_meta' ) ) {
				$payment->update_meta( $key, $value );
				return true;
			}
		}

		return false;
	}

	public static function build_woocommerce_payment_url( $order ) {
		$return_url = $order->get_checkout_order_received_url();
		$reference  = self::woocommerce_reference( $order );
		if ( false === $reference ) {
			return false;
		}

		$args = array(
			'checkout_id'        => 'custom-wc-' . $order->get_id(),
			'price'              => $order->get_total(),
			'currency'           => strtolower( $order->get_currency() ),
			'external_reference' => $reference,
			'plugin'             => 'woocommerce',
			'redirect'           => $return_url,
			'cloud'              => MGCPG_Settings::public_checkout_key(),
			'note'               => sprintf( 'WooCommerce order ID %d', $order->get_id() ),
		);

		return add_query_arg( $args, self::PAY_URL );
	}

	public static function build_edd_payment_url( $payment_id, $amount, $currency, $redirect_url ) {
		$reference = self::edd_reference( $payment_id );
		if ( false === $reference ) {
			return false;
		}

		$args = array(
			'checkout_id'        => 'custom-edd-' . absint( $payment_id ),
			'price'              => $amount,
			'currency'           => strtolower( $currency ),
			'external_reference' => $reference,
			'plugin'             => 'edd',
			'redirect'           => $redirect_url,
			'cloud'              => MGCPG_Settings::public_checkout_key(),
			'note'               => sprintf( 'Easy Digital Downloads payment ID %d', absint( $payment_id ) ),
		);

		return add_query_arg( $args, self::PAY_URL );
	}
}
