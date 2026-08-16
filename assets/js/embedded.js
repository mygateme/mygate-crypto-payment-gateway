(function () {
	'use strict';

	if (typeof MGCPG_EMBED === 'undefined') {
		return;
	}

	var statusNode = document.getElementById('mgcpg-payment-status');
	var stopped = false;
	var polling = false;

	function setStatus(text) {
		if (statusNode) {
			statusNode.textContent = text;
		}
	}

	function poll() {
		if (stopped || polling) {
			return;
		}
		polling = true;

		var body = new URLSearchParams();
		body.set('action', 'mgcpg_payment_status');
		body.set('nonce', MGCPG_EMBED.nonce);
		body.set('source', MGCPG_EMBED.source);
		body.set('id', String(MGCPG_EMBED.id));
		body.set('auth', MGCPG_EMBED.auth);

		fetch(MGCPG_EMBED.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (!response || !response.success || !response.data) {
					return;
				}

				if (response.data.paid) {
					stopped = true;
					setStatus(MGCPG_EMBED.i18n.paid);
					window.setTimeout(function () {
						window.location.href = response.data.redirect || MGCPG_EMBED.returnUrl;
					}, 700);
					return;
				}

				if (response.data.terminal) {
					stopped = true;
					setStatus(MGCPG_EMBED.i18n.terminal);
					return;
				}

				if (response.data.remoteStatus === 'X') {
					setStatus(MGCPG_EMBED.i18n.underpaid);
					return;
				}

				if (response.data.detected) {
					setStatus(MGCPG_EMBED.i18n.received);
					return;
				}

				setStatus(MGCPG_EMBED.i18n.checking);
			})
			.catch(function () {
				// Keep polling. The direct-open fallback remains available to the shopper.
			})
			.finally(function () {
				polling = false;
				if (!stopped) {
					window.setTimeout(poll, 3000);
				}
			});
	}

	/*
	 * MyGate normally redirects the iframe to the store return URL after payment.
	 * While the frame is on app.mygate.me the browser correctly blocks us from
	 * reading its URL. As soon as it returns to this store it becomes same-origin,
	 * so hide the returned store page and trigger an immediate verified status check.
	 * The top window is redirected only after the local order is actually paid.
	 */
	var paymentFrame = document.getElementById('mgcpg-payment-frame');
	if (paymentFrame) {
		paymentFrame.addEventListener('load', function () {
			try {
				var frameUrl = paymentFrame.contentWindow.location.href;
				if (!frameUrl || frameUrl === 'about:blank') {
					return;
				}
				var frameLocation = new URL(frameUrl, window.location.href);
				if (frameLocation.origin === window.location.origin && frameUrl !== window.location.href) {
					var frameWrap = document.querySelector('.mgcpg-frame-wrap');
					if (frameWrap) {
						frameWrap.classList.add('mgcpg-awaiting');
					}
					setStatus(MGCPG_EMBED.i18n.received);
					// Do not trust the redirect alone as proof of payment. Ask the local
					// order endpoint, which can securely verify MyGate via webhook or API.
					poll();
				}
			} catch (error) {
				// Expected while the iframe is still on app.mygate.me (cross-origin).
			}
		});
	}

	window.setTimeout(poll, 1500);
}());
