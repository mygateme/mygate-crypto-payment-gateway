=== MyGate Crypto Payment Gateway ===
Contributors: mygate
Tags: cryptocurrency, bitcoin, monero, usdt, payment gateway
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin, Ethereum, USDT, USDC, Monero and other cryptocurrency payments through MyGate in WooCommerce and Easy Digital Downloads.

== Description ==

MyGate Crypto Payment Gateway connects a WordPress store to the MyGate cryptocurrency payment service.

Customers can pay using Bitcoin, Ethereum, Tether USDT, USD Coin USDC, Monero and other supported cryptocurrencies and blockchain networks.

The plugin supports WooCommerce classic checkout, WooCommerce Cart and Checkout Blocks, and Easy Digital Downloads.

= Features =

* WooCommerce classic checkout support.
* WooCommerce Cart and Checkout Blocks payment-method integration.
* WooCommerce HPOS compatibility declaration.
* Easy Digital Downloads integration.
* Redirect checkout mode.
* Embedded checkout overlay with direct-open fallback.
* Secure webhook endpoint for payment confirmation.
* Server-side payment-status fallback when an inbound webhook is blocked or delayed.
* Lightweight WordPress cron retry for pending MyGate payments.
* Idempotent WooCommerce and Easy Digital Downloads payment completion.
* Exact stored-reference, amount, currency, completed-status, payment-method, and terminal-state validation before access is granted.
* Official EDD transaction-ID storage when supported by the installed EDD version.
* Configurable payment-method title, description, and icon.
* WPML/Polylang-friendly merchant payment title and description.
* Optional diagnostic logging without exposing API keys or webhook secrets.
* Private MyGate API keys remain server-side.
* Only public MyGate checkout keys are included in browser-visible payment URLs.
* No cryptocurrency private keys are stored by the WordPress plugin.

= Supported cryptocurrencies =

MyGate supports multiple cryptocurrencies and blockchain networks, including:

* Bitcoin [BTC]
* Ethereum [ETH]
* Ethereum - Arbitrum [ARB]
* Ethereum - Base [BASE]
* Ethereum - Optimism [OP]
* Tron [TRX]
* Tether USD [USDT - TRON / TRC20]
* Tether USD [USDT - Ethereum / ERC20]
* Tether USD [USDT - BNB Smart Chain / BEP20]
* USD Coin [USDC]
* BNB [BNB / BSC]
* XRP [XRP]
* Solana [SOL]
* Dogecoin [DOGE]
* Litecoin [LTC]
* Monero [XMR]
* Bitcoin Cash [BCH]
* Shiba Inu [SHIB]
* Basic Attention Token [BAT]
* Chainlink [LINK]
* Algorand [ALGO]
* Polygon [POL]
* Polkadot [DOT]
* Avalanche [AVAX]
* Tezos [XTZ]
* Stellar [XLM]

MyGate can also support custom tokens on compatible blockchain networks.

Available cryptocurrencies and networks depend on those enabled in the merchant's MyGate account.

= How it works =

1. The customer selects MyGate at checkout.
2. WordPress creates a payment request using the merchant's public MyGate checkout key.
3. The customer selects a cryptocurrency and sends the payment.
4. MyGate verifies the blockchain transaction.
5. MyGate notifies the WordPress store through the configured webhook.
6. The plugin validates the payment reference, amount, currency, transaction status, and local order state.
7. The WooCommerce order or Easy Digital Downloads payment is completed.
8. If the webhook is delayed or blocked, the plugin can verify the transaction through an authenticated server-to-server fallback request.

= Private and public API keys =

MyGate 2.0 uses separate private and public merchant credentials.

The private `sk_live_` API key remains on the WordPress server and is used only for authenticated server-to-server requests.

The plugin automatically derives the corresponding public `pk_live_` key for customer-facing checkout URLs.

The private API key is never intentionally included in browser-visible MyGate checkout URLs.

= External service =

This plugin depends on the MyGate service at https://app.mygate.me to create and display cryptocurrency payment requests and to report confirmed payments back to the store.

When a shopper chooses MyGate, the plugin sends the order/payment amount, currency, an encrypted order reference, the merchant's public MyGate checkout key (`pk_live_`), the return URL, and a short order note to MyGate.

The private MyGate API key (`sk_live_`) remains on the WordPress server and is used only for authenticated server-to-server fallback status checks.

MyGate normally sends the confirmed transaction back to the webhook URL configured by the merchant.

If a webhook is delayed or blocked by a firewall, CDN, or security product, the plugin can make a server-to-server request from WordPress to the MyGate API. It searches for the exact encrypted order reference and validates the completed transaction before updating the local WooCommerce or Easy Digital Downloads payment.

Fallback status lookups are sent server-to-server using the private `sk_live_` key. Browser-visible checkout URLs contain only the derived public `pk_live_` key.

MyGate Privacy Policy: https://mygate.me/privacy-policy/
MyGate Support Terms: https://mygate.me/support-terms/

== Installation ==

1. Install and activate the plugin.
2. Open Settings > MyGate.
3. Enter the private `sk_live_` API key from MyGate > Account > API key. The plugin derives the public `pk_live_` checkout key automatically.
4. Enter the same Webhook secret key used in MyGate > Settings > Webhook.
5. Copy the Webhook URL shown by the plugin into MyGate > Settings > Webhook > Webhook URL.
6. In WooCommerce > Settings > Payments, enable MyGate.
7. For Easy Digital Downloads, enable MyGate in Downloads > Settings > Payments.
8. Choose Redirect to MyGate or Embedded modal under Settings > MyGate.

== Frequently Asked Questions ==

= Which cryptocurrencies can customers use? =

Customers can use cryptocurrencies and blockchain networks enabled in the merchant's MyGate account, including Bitcoin, Ethereum, Tether USDT, USD Coin USDC, Monero, BNB, XRP, Solana, Dogecoin, Litecoin and others.

= Does this support WooCommerce Checkout Blocks? =

Yes. The plugin registers MyGate as a payment method for WooCommerce Cart and Checkout Blocks as well as the classic shortcode checkout.

= What happens if Cloudflare or another firewall blocks the webhook? =

The webhook remains the primary and fastest confirmation path.

The plugin also has a server-side fallback that queries the MyGate API for the exact encrypted payment reference.

A completed transaction is still validated against the local order amount, currency, payment method, stored reference, and terminal status before the order is marked paid.

= Is the private MyGate API key exposed to customers? =

No. The private `sk_live_` key remains on the WordPress server.

Customer-facing checkout URLs use only the corresponding public `pk_live_` key.

= Does the plugin store cryptocurrency private keys? =

No. Cryptocurrency wallet private keys are not stored by the WordPress plugin.

= Does the plugin custody cryptocurrency? =

No. Cryptocurrency payments use the wallet configuration of the merchant's MyGate account. The WordPress plugin does not custody merchant cryptocurrency funds.

= Does embedded mode require a different webhook? =

No. Embedded and redirect modes use the same MyGate payment flow and webhook endpoint. Embedded mode changes only the shopper-facing presentation.

= Does the plugin support Easy Digital Downloads? =

Yes. MyGate can be enabled as an Easy Digital Downloads payment gateway and supports payment confirmation, transaction validation, webhook handling, and server-side fallback verification.

== Changelog ==

= 2.0.0 =
* Finalized the 2.0 release after successful WooCommerce Blocks, classic WooCommerce, Easy Digital Downloads, embedded modal, and direct MyGate checkout testing.
* Uses private `sk_live_` keys only for server-to-server API fallback checks and derived public `pk_live_` keys for browser-visible checkout URLs.
* Includes webhook confirmation, authenticated fallback polling, idempotent payment completion, and legacy public-checkout compatibility.
* Added exact payment-reference, amount, currency, transaction-status, payment-method, and terminal-state validation.
* Added WooCommerce HPOS compatibility declaration.
* Added WooCommerce Cart and Checkout Blocks integration.
* Added Easy Digital Downloads integration with transaction-ID storage where supported.
* Added embedded checkout mode with direct-open fallback.
* Added lightweight WordPress cron retries for pending payments.
* Added optional diagnostics without exposing API keys or webhook secrets.

= 2.0.0-beta.6 =
* Updated the plugin for MyGate public/private merchant keys.
* Private `sk_live_` API keys are now kept server-side and used only for authenticated MyGate API fallback checks.
* Browser-visible WooCommerce, WooCommerce Blocks, EDD, redirect, and embedded checkout URLs now use only the derived public `pk_live_` checkout key.
* Added validation and an upgrade warning for legacy/public keys saved in older plugin versions.
* Updated external-service disclosure and setup instructions for the new key model.

= 2.0.0-beta.5 =
* Hardened WooCommerce confirmation by binding each remote transaction to the exact encrypted reference stored on the order.
* Prevented late/replayed confirmations from reopening cancelled, failed, refunded, or trashed WooCommerce orders, including completed-transaction replay checks.
* Persisted EDD encrypted references, expected amount/currency, return URL, and gateway context in EDD payment metadata.
* Added EDD gateway validation, exact reference validation, terminal-status protection, and completed-transaction replay protection.
* Added official EDD transaction-ID storage when the installed EDD version provides the helper API.
* Improved EDD polling and embedded checkout to read the payment's own amount, currency, gateway, status, and return URL instead of relying on global store state.
* Cleaned the WordPress.org package structure and readme metadata in preparation for final compatibility testing.

= 2.0.0-beta.4 =
* Added server-side MyGate API polling as a fallback when a webhook is blocked or delayed.
* Added lightweight background WordPress cron retries for pending WooCommerce and EDD payments.
* Embedded checkout now promotes the shopper to the success page only after the local order is actually confirmed paid.
* WooCommerce redirect returns perform one authenticated server-side status check before rendering the order-received page.
* Added completed-status validation before webhook/fallback completion.
* Added EDD amount and currency validation.
* Fixed the EDD redirect URL variable in payment-link generation.
* Added stable encrypted-reference storage for WooCommerce and short-lived EDD reference storage.
* Expanded diagnostics with the last fallback confirmation.
* Regenerated translation template with all current modal, settings, and diagnostic strings.
* Added Checkout Block editor script registration.

= 2.0.0-beta.3 =
* Reverted the beta.2 return-bridge URL that could trigger a MyGate 500 response.
* Embedded checkout detects MyGate returning the iframe to the store.
* Kept webhook diagnostics and legacy callback aliases from beta.2.

= 2.0.0-beta.2 =
* Rebuilt plugin architecture from scratch.
* Added WooCommerce Checkout Blocks integration.
* Added HPOS compatibility declaration.
* Reworked WooCommerce payment completion to use `payment_complete()`.
* Added idempotent webhook handling.
* Added embedded checkout mode with status polling and direct-open fallback.
* Added legacy settings migration from the previous MyGate plugin.
