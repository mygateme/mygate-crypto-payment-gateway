=== MyGate Crypto Payment Gateway ===
Contributors: mygate
Tags: cryptocurrency, bitcoin, payments, payment gateway, crypto payments
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

##Accept cryptocurrency payments through MyGate in WooCommerce and Easy Digital Downloads, including WooCommerce Checkout Blocks.

== Description ==

###MyGate Crypto Payment Gateway connects a WordPress store to the MyGate payment service.

Features:

* WooCommerce classic checkout support.
* WooCommerce Cart and Checkout Blocks payment-method integration.
* WooCommerce HPOS compatibility declaration.
* Easy Digital Downloads integration.
* Redirect checkout mode.
* Embedded checkout overlay with direct-open fallback.
* Secure webhook endpoint for order completion.
* Server-side payment-status fallback when an inbound webhook is blocked or delayed.
* Lightweight WordPress cron retry for pending MyGate payments.
* Idempotent WooCommerce and Easy Digital Downloads payment completion.
* Exact stored-reference, amount, currency, completed-status, payment-method, and terminal-state validation before access is granted.
* Official EDD transaction-ID storage when supported by the installed EDD version.
* Configurable payment-method title, description, and icon.
* WPML/Polylang-friendly merchant payment title and description.
* Optional diagnostic logging without API keys or webhook secrets.

= External service =

This plugin depends on the MyGate service at https://app.mygate.me to create and display cryptocurrency payment requests and to report confirmed payments back to the store.

When a shopper chooses MyGate, the plugin sends the order/payment amount, currency, an encrypted order reference, the merchant's public MyGate checkout key (pk_live_), the return URL, and a short order note to MyGate. The private MyGate API key (sk_live_) remains on the WordPress server and is used only for authenticated server-to-server fallback status checks. MyGate normally sends the confirmed transaction back to the webhook URL configured by the merchant.

If a webhook is delayed or blocked by a firewall, CDN, or security product, the plugin can also make a server-to-server request from WordPress to the MyGate API. It searches for the exact encrypted order reference and validates the completed transaction before updating the local WooCommerce/EDD payment. Fallback status lookups are sent server-to-server with the private sk_live_ key. Browser-visible checkout URLs contain only the derived public pk_live_ key.

MyGate Privacy Policy: https://mygate.me/privacy-policy/
MyGate Support Terms: https://mygate.me/support-terms/

== Installation ==

1. Install and activate the plugin.
2. Open Settings > MyGate.
3. Enter the private sk_live_ API key from MyGate > Account > API key. The plugin derives the public pk_live_ checkout key automatically.
4. Enter the same Webhook secret key used in MyGate > Settings > Webhook.
5. Copy the Webhook URL shown by the plugin into MyGate > Settings > Webhook > Webhook URL.
6. In WooCommerce > Settings > Payments, enable MyGate. For Easy Digital Downloads, enable MyGate in Downloads > Settings > Payments.
7. Choose Redirect to MyGate or Embedded modal under Settings > MyGate.

== Frequently Asked Questions ==

= Does this support WooCommerce Checkout Blocks? =

Yes. The plugin registers MyGate as a payment method for WooCommerce Cart and Checkout Blocks as well as the classic shortcode checkout.

= What happens if Cloudflare or another firewall blocks the webhook? =

The webhook remains the primary and fastest confirmation path. The plugin also has a server-side fallback that queries the MyGate API for the exact encrypted payment reference. A completed transaction is still validated against the local order amount, currency, payment method, stored reference, and terminal status before the order is marked paid.

= Does the plugin custody cryptocurrency? =

No. MyGate is designed around merchant-controlled wallet addresses. Blockchain processing and wallet configuration are handled by the MyGate service.

= Does embedded mode require a different webhook? =

No. Embedded and redirect modes use the same MyGate payment flow and webhook endpoint. Embedded mode changes only the shopper-facing presentation.

== Changelog ==

= 2.0.0 =
* Finalized the 2.0 release after successful WooCommerce Blocks, classic WooCommerce, Easy Digital Downloads, embedded modal, and direct MyGate checkout testing.
* Uses private sk_live_ keys only for server-to-server API fallback checks and derived public pk_live_ keys for browser-visible checkout URLs.
* Includes webhook confirmation, authenticated fallback polling, idempotent payment completion, and legacy public-checkout compatibility.

= 2.0.0-beta.6 =
* Updated the plugin for MyGate public/private merchant keys.
* Private sk_live_ API keys are now kept server-side and used only for authenticated MyGate API fallback checks.
* Browser-visible WooCommerce, WooCommerce Blocks, EDD, redirect, and embedded checkout URLs now use only the derived public pk_live_ checkout key.
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
* Reworked WooCommerce payment completion to use payment_complete().
* Added idempotent webhook handling.
* Added embedded checkout mode with status polling and direct-open fallback.
* Added legacy settings migration from the previous MyGate plugin.
