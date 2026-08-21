<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php /* translators: %s: Store name. */ ?>
	<title><?php echo esc_html( sprintf( __( 'Secure payment — %s', 'mygate-crypto-payment-gateway' ), $store_name ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="mgcpg-embedded-page">
	<main class="mgcpg-embedded-shell">
		<div class="mgcpg-embedded-backdrop" aria-hidden="true"></div>
		<section class="mgcpg-embedded-modal" role="dialog" aria-modal="true" aria-labelledby="mgcpg-payment-title">
			<header class="mgcpg-embedded-header">
				<div>
					<strong id="mgcpg-payment-title">🔒 <?php esc_html_e( 'Secure crypto payment', 'mygate-crypto-payment-gateway' ); ?></strong>
					<span><?php echo esc_html( $store_name ); ?></span>
				</div>
				<a class="mgcpg-close" href="<?php echo esc_url( $return_url ); ?>" aria-label="<?php esc_attr_e( 'Return to order', 'mygate-crypto-payment-gateway' ); ?>">×</a>
			</header>

			<div class="mgcpg-frame-wrap">
				<iframe
					id="mgcpg-payment-frame"
					title="<?php esc_attr_e( 'MyGate payment', 'mygate-crypto-payment-gateway' ); ?>"
					src="<?php echo esc_url( $payment_url ); ?>"
					allow="clipboard-read; clipboard-write"
					referrerpolicy="strict-origin-when-cross-origin"
				></iframe>
			</div>

			<footer class="mgcpg-embedded-footer">
				<div id="mgcpg-payment-status" aria-live="polite"><?php esc_html_e( 'Waiting for payment confirmation…', 'mygate-crypto-payment-gateway' ); ?></div>
				<a class="mgcpg-open-direct" href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open payment page directly', 'mygate-crypto-payment-gateway' ); ?></a>
			</footer>
		</section>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
