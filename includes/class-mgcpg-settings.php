<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MGCPG_Settings {
	const OPTION = 'mgcpg_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_translation_strings' ), 20 );
		self::maybe_migrate_legacy_settings();
	}

	public static function defaults() {
		return array(
			'api_key'             => '',
			'webhook_secret'      => '',
			'payment_title'       => 'Crypto',
			'payment_description' => 'Pay securely with cryptocurrency via MyGate.',
			'icon_url'            => '',
			'checkout_experience' => 'redirect',
			'debug_log'           => 'no',
		);
	}

	public static function all() {
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		return wp_parse_args( $value, self::defaults() );
	}

	public static function get( $key, $default = '' ) {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Validate a MyGate private merchant API key.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_private_api_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^sk_live_([1-9][0-9]*)_([a-f0-9]{32})_([a-f0-9]{64})$/i', trim( $key ) );
	}

	/**
	 * Return the configured private server-to-server API key.
	 *
	 * @return string
	 */
	public static function private_api_key() {
		$key = trim( (string) self::get( 'api_key' ) );
		return self::is_private_api_key( $key ) ? $key : '';
	}

	/**
	 * Derive the public checkout key from the private API key.
	 *
	 * The private key format intentionally contains the public checkout identity,
	 * allowing WordPress to keep sk_live_ server-side while exposing only pk_live_
	 * in MyGate payment URLs and browser-visible embedded checkout markup.
	 *
	 * @return string
	 */
	public static function public_checkout_key() {
		$key = self::private_api_key();
		if ( '' === $key || ! preg_match( '/^sk_live_([1-9][0-9]*)_([a-f0-9]{32})_([a-f0-9]{64})$/i', $key, $matches ) ) {
			return '';
		}

		return 'pk_live_' . $matches[1] . '_' . strtolower( $matches[2] );
	}

	public static function is_configured() {
		return '' !== self::private_api_key() && '' !== self::public_checkout_key() && '' !== trim( (string) self::get( 'webhook_secret' ) );
	}

	public static function payment_title() {
		$title = self::translate_merchant_string( (string) self::get( 'payment_title', 'Crypto' ), 'Payment method title' );
		return (string) apply_filters( 'mgcpg_payment_method_title', $title );
	}

	public static function payment_description() {
		$description = self::translate_merchant_string( (string) self::get( 'payment_description', 'Pay securely with cryptocurrency via MyGate.' ), 'Payment method description' );
		return (string) apply_filters( 'mgcpg_payment_method_description', $description );
	}

	public static function register_translation_strings() {
		$title       = (string) self::get( 'payment_title', 'Crypto' );
		$description = (string) self::get( 'payment_description', 'Pay securely with cryptocurrency via MyGate.' );

		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( 'MyGate payment method title', $title, 'MyGate Crypto Payment Gateway' );
			pll_register_string( 'MyGate payment method description', $description, 'MyGate Crypto Payment Gateway' );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public WPML integration hook.
		do_action( 'wpml_register_single_string', 'MyGate Crypto Payment Gateway', 'Payment method title', $title );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public WPML integration hook.
		do_action( 'wpml_register_single_string', 'MyGate Crypto Payment Gateway', 'Payment method description', $description );
	}

	private static function translate_merchant_string( $value, $name ) {
		if ( function_exists( 'pll__' ) ) {
			$value = pll__( $value );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public WPML integration hook.
		return (string) apply_filters( 'wpml_translate_single_string', $value, 'MyGate Crypto Payment Gateway', $name );
	}

	public static function webhook_url() {
		return rest_url( 'mygate/webhook' );
	}

	public static function admin_menu() {
		add_options_page(
			__( 'MyGate', 'mygate-crypto-payment-gateway' ),
			__( 'MyGate', 'mygate-crypto-payment-gateway' ),
			'manage_options',
			'mygate-crypto-payment-gateway',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'mgcpg_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = self::all();

		$api_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) ) : $old['api_key'];
		if ( '' !== $api_key && ! self::is_private_api_key( $api_key ) ) {
			add_settings_error(
				self::OPTION,
				'mgcpg_invalid_api_key',
				__( 'Enter the private MyGate API key that starts with sk_live_.', 'mygate-crypto-payment-gateway' ),
				'error'
			);
			$api_key = $old['api_key'];
		}

		$output = array(
			'api_key'             => $api_key,
			'webhook_secret'      => isset( $input['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $input['webhook_secret'] ) ) : $old['webhook_secret'],
			'payment_title'       => isset( $input['payment_title'] ) ? sanitize_text_field( wp_unslash( $input['payment_title'] ) ) : $old['payment_title'],
			'payment_description' => isset( $input['payment_description'] ) ? sanitize_textarea_field( wp_unslash( $input['payment_description'] ) ) : $old['payment_description'],
			'icon_url'            => isset( $input['icon_url'] ) ? esc_url_raw( wp_unslash( $input['icon_url'] ) ) : $old['icon_url'],
			'checkout_experience' => isset( $input['checkout_experience'] ) && in_array( $input['checkout_experience'], array( 'redirect', 'embedded' ), true ) ? $input['checkout_experience'] : 'redirect',
			'debug_log'           => isset( $input['debug_log'] ) && 'yes' === $input['debug_log'] ? 'yes' : 'no',
		);

		return $output;
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_mygate-crypto-payment-gateway' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'mgcpg-admin', MGCPG_URL . 'assets/css/admin.css', array(), MGCPG_VERSION );
		wp_enqueue_script( 'mgcpg-admin', MGCPG_URL . 'assets/js/admin.js', array(), MGCPG_VERSION, true );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::all();
		?>
		<div class="wrap mgcpg-admin-wrap">
			<div class="mgcpg-admin-header">
				<img src="<?php echo esc_url( MGCPG_URL . 'assets/images/logo.png' ); ?>" alt="MyGate" />
				<div>
					<h1><?php esc_html_e( 'MyGate Crypto Payment Gateway', 'mygate-crypto-payment-gateway' ); ?></h1>
					<p><?php esc_html_e( 'Connect WooCommerce or Easy Digital Downloads to your MyGate account.', 'mygate-crypto-payment-gateway' ); ?></p>
				</div>
			</div>

			<?php settings_errors(); ?>

			<?php if ( '' !== trim( (string) $settings['api_key'] ) && ! self::is_private_api_key( (string) $settings['api_key'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The saved MyGate key is a legacy/public key. Replace it with the private sk_live_ API key from MyGate → Account before taking new payments.', 'mygate-crypto-payment-gateway' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'mgcpg_settings_group' ); ?>

				<div class="mgcpg-card">
					<h2><?php esc_html_e( 'Connection', 'mygate-crypto-payment-gateway' ); ?></h2>

					<label for="mgcpg-api-key"><?php esc_html_e( 'MyGate private API key', 'mygate-crypto-payment-gateway' ); ?></label>
					<input class="regular-text" type="password" autocomplete="off" id="mgcpg-api-key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Get the sk_live_ key from MyGate → Account → API key. The plugin derives the public pk_live_ checkout key automatically and never places the private key in the payment URL.', 'mygate-crypto-payment-gateway' ); ?></p>

					<label for="mgcpg-webhook-secret"><?php esc_html_e( 'Webhook secret key', 'mygate-crypto-payment-gateway' ); ?></label>
					<input class="regular-text" type="password" autocomplete="off" id="mgcpg-webhook-secret" name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Use the same secret in MyGate → Settings → Webhook.', 'mygate-crypto-payment-gateway' ); ?></p>

					<label for="mgcpg-webhook-url"><?php esc_html_e( 'Webhook URL', 'mygate-crypto-payment-gateway' ); ?></label>
					<div class="mgcpg-copy-row">
						<input class="regular-text" type="text" readonly id="mgcpg-webhook-url" value="<?php echo esc_url( self::webhook_url() ); ?>" />
						<button type="button" class="button" data-mgcpg-copy="#mgcpg-webhook-url"><?php esc_html_e( 'Copy', 'mygate-crypto-payment-gateway' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Copy this URL into MyGate → Settings → Webhook → Webhook URL.', 'mygate-crypto-payment-gateway' ); ?></p>
				</div>

				<div class="mgcpg-card">
					<h2><?php esc_html_e( 'Checkout', 'mygate-crypto-payment-gateway' ); ?></h2>

					<label for="mgcpg-payment-title"><?php esc_html_e( 'Payment method name', 'mygate-crypto-payment-gateway' ); ?></label>
					<input class="regular-text" type="text" id="mgcpg-payment-title" name="<?php echo esc_attr( self::OPTION ); ?>[payment_title]" value="<?php echo esc_attr( $settings['payment_title'] ); ?>" />

					<label for="mgcpg-payment-description"><?php esc_html_e( 'Payment method description', 'mygate-crypto-payment-gateway' ); ?></label>
					<textarea class="large-text" rows="3" id="mgcpg-payment-description" name="<?php echo esc_attr( self::OPTION ); ?>[payment_description]"><?php echo esc_textarea( $settings['payment_description'] ); ?></textarea>

					<label for="mgcpg-icon-url"><?php esc_html_e( 'Payment method icon URL', 'mygate-crypto-payment-gateway' ); ?></label>
					<input class="regular-text" type="url" id="mgcpg-icon-url" name="<?php echo esc_attr( self::OPTION ); ?>[icon_url]" value="<?php echo esc_attr( $settings['icon_url'] ); ?>" />

					<fieldset>
						<legend><?php esc_html_e( 'Checkout experience', 'mygate-crypto-payment-gateway' ); ?></legend>
						<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[checkout_experience]" value="redirect" <?php checked( $settings['checkout_experience'], 'redirect' ); ?> /> <?php esc_html_e( 'Redirect to MyGate', 'mygate-crypto-payment-gateway' ); ?></label><br />
						<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[checkout_experience]" value="embedded" <?php checked( $settings['checkout_experience'], 'embedded' ); ?> /> <?php esc_html_e( 'Embedded modal', 'mygate-crypto-payment-gateway' ); ?></label>
						<p class="description"><?php esc_html_e( 'Embedded mode keeps the shopper on your site and opens the MyGate payment page inside a secure overlay. A direct-open fallback is always shown in case a browser blocks embedding.', 'mygate-crypto-payment-gateway' ); ?></p>
					</fieldset>
				</div>

				<div class="mgcpg-card">
					<h2><?php esc_html_e( 'Diagnostics', 'mygate-crypto-payment-gateway' ); ?></h2>
					<?php
					$last_webhook  = get_option( 'mgcpg_last_webhook', array() );
					$last_fallback = get_option( 'mgcpg_last_fallback', array() );
					?>
					<p><strong><?php esc_html_e( 'Last webhook:', 'mygate-crypto-payment-gateway' ); ?></strong> <?php
					if ( ! empty( $last_webhook['time'] ) ) {
						echo esc_html( wp_date( 'Y-m-d H:i:s', absint( $last_webhook['time'] ) ) . ' — ' . ( isset( $last_webhook['status'] ) ? strtoupper( $last_webhook['status'] ) : '' ) . ' — ' . ( isset( $last_webhook['message'] ) ? $last_webhook['message'] : '' ) );
					} else {
						esc_html_e( 'No webhook received yet.', 'mygate-crypto-payment-gateway' );
					}
					?></p>
					<p><strong><?php esc_html_e( 'Last fallback confirmation:', 'mygate-crypto-payment-gateway' ); ?></strong> <?php
					if ( ! empty( $last_fallback['time'] ) ) {
						echo esc_html( wp_date( 'Y-m-d H:i:s', absint( $last_fallback['time'] ) ) . ' — ' . ( isset( $last_fallback['status'] ) ? strtoupper( $last_fallback['status'] ) : '' ) . ' — ' . ( isset( $last_fallback['message'] ) ? $last_fallback['message'] : '' ) );
					} else {
						esc_html_e( 'No fallback confirmation used yet.', 'mygate-crypto-payment-gateway' );
					}
					?></p>
					<p class="description"><?php esc_html_e( 'MyGate webhooks are the primary confirmation path. If a firewall or CDN blocks an inbound webhook, the plugin also checks MyGate from the WordPress server and can complete the order securely.', 'mygate-crypto-payment-gateway' ); ?></p>
					<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[debug_log]" value="yes" <?php checked( $settings['debug_log'], 'yes' ); ?> /> <?php esc_html_e( 'Enable debug logging', 'mygate-crypto-payment-gateway' ); ?></label>
					<p class="description"><?php esc_html_e( 'Logs payment-flow events without storing the API key or webhook secret.', 'mygate-crypto-payment-gateway' ); ?></p>
				</div>

				<div class="mgcpg-card mgcpg-service-note">
					<h2><?php esc_html_e( 'External service', 'mygate-crypto-payment-gateway' ); ?></h2>
					<p><?php esc_html_e( 'This plugin connects your store to the MyGate payment service at app.mygate.me. Checkout amount, currency, an encrypted order reference, your MyGate account key, and the return URL are sent to MyGate so the payment page can be created and matched to your order. If a webhook is delayed or blocked, the plugin may also query the MyGate API from your WordPress server to verify the payment status. The fallback API request is server-to-server; the normal MyGate checkout URL still contains the MyGate cloud/account key required by the current checkout protocol.', 'mygate-crypto-payment-gateway' ); ?></p>
					<p><a href="https://mygate.me/privacy-policy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MyGate Privacy Policy', 'mygate-crypto-payment-gateway' ); ?></a> · <a href="https://mygate.me/support-terms/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MyGate Support Terms', 'mygate-crypto-payment-gateway' ); ?></a></p>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function maybe_migrate_legacy_settings() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$legacy = get_option( 'mgcpg-wp-settings', false );
		if ( ! $legacy ) {
			return;
		}

		if ( is_string( $legacy ) ) {
			$legacy = json_decode( $legacy, true );
		}
		if ( ! is_array( $legacy ) ) {
			return;
		}

		$migrated = self::defaults();
		$migrated['api_key']             = isset( $legacy['mygate-crypto-payment-gateway-key'] ) ? sanitize_text_field( $legacy['mygate-crypto-payment-gateway-key'] ) : '';
		$migrated['webhook_secret']      = isset( $legacy['mygate-key'] ) ? sanitize_text_field( $legacy['mygate-key'] ) : '';
		$migrated['payment_title']       = isset( $legacy['mygate-payment-option-name'] ) && '' !== $legacy['mygate-payment-option-name'] ? sanitize_text_field( $legacy['mygate-payment-option-name'] ) : $migrated['payment_title'];
		$migrated['payment_description'] = isset( $legacy['mygate-payment-option-text'] ) && '' !== $legacy['mygate-payment-option-text'] ? sanitize_textarea_field( $legacy['mygate-payment-option-text'] ) : $migrated['payment_description'];
		$migrated['icon_url']            = isset( $legacy['mygate-payment-option-icon'] ) ? esc_url_raw( $legacy['mygate-payment-option-icon'] ) : '';

		update_option( self::OPTION, $migrated, false );
	}
}
