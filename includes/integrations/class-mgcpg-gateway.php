<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'mygate';
		$this->has_fields         = false;
		$this->method_title       = __( 'MyGate', 'mygate-crypto-payment-gateway' );
		$this->method_description = __( 'Decentralized cryptocurrency payments through MyGate.', 'mygate-crypto-payment-gateway' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->title       = MGCPG_Settings::payment_title();
		$this->description = MGCPG_Settings::payment_description();
		$this->icon        = esc_url( MGCPG_Settings::get( 'icon_url' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'mygate-crypto-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable MyGate crypto payments', 'mygate-crypto-payment-gateway' ),
				'default' => 'no',
			),
			'connection' => array(
				'title'       => __( 'MyGate connection', 'mygate-crypto-payment-gateway' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: settings page URL. */
					__( 'API keys, checkout appearance, and webhook settings are managed on the <a href="%s">MyGate settings page</a>.', 'mygate-crypto-payment-gateway' ),
					esc_url( admin_url( 'options-general.php?page=mygate-crypto-payment-gateway' ) )
				),
			),
		);
	}

	public function is_available() {
		return parent::is_available() && MGCPG_Settings::is_configured();
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Unable to initialize the MyGate payment.', 'mygate-crypto-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! MGCPG_Settings::is_configured() ) {
			wc_add_notice( __( 'MyGate is not configured. Please contact the store administrator.', 'mygate-crypto-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payment_url = MGCPG_Crypto::build_woocommerce_payment_url( $order );
		if ( ! $payment_url ) {
			wc_add_notice( __( 'Unable to create the MyGate payment request.', 'mygate-crypto-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$order->update_status( 'pending', __( 'Awaiting MyGate cryptocurrency payment.', 'mygate-crypto-payment-gateway' ) );
		}
		$order->update_meta_data( '_mgcpg_checkout_experience', MGCPG_Settings::get( 'checkout_experience', 'redirect' ) );
		$order->save();

		// Webhook is the fast path; background polling is a firewall/CDN fallback.
		MGCPG_Poller::schedule_woocommerce( $order_id, 60 );

		MGCPG_Logger::log( 'WooCommerce payment initialized.', array( 'order_id' => $order_id, 'mode' => MGCPG_Settings::get( 'checkout_experience', 'redirect' ) ) );

		$redirect = 'embedded' === MGCPG_Settings::get( 'checkout_experience', 'redirect' ) ? MGCPG_Embedded::woo_url( $order ) : $payment_url;

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}
}
