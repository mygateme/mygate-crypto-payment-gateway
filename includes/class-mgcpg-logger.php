<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_Logger {
	public static function enabled() {
		return 'yes' === MGCPG_Settings::get( 'debug_log', 'no' );
	}

	public static function log( $message, $context = array(), $level = 'info' ) {
		if ( ! self::enabled() ) {
			return;
		}

		$context = self::sanitize_context( $context );

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log(
				$level,
				$message . ( $context ? ' ' . wp_json_encode( $context ) : '' ),
				array( 'source' => 'mygate-crypto-payments' )
			);
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[MyGate] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		foreach ( array( 'key', 'api_key', 'webhook_secret', 'cloud' ) as $sensitive ) {
			if ( array_key_exists( $sensitive, $context ) ) {
				$context[ $sensitive ] = '[redacted]';
			}
		}
		return $context;
	}
}
