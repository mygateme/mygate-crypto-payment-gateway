(function () {
	'use strict';
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-mgcpg-copy]');
		if (!button) {
			return;
		}
		var target = document.querySelector(button.getAttribute('data-mgcpg-copy'));
		if (!target) {
			return;
		}
		target.select();
		target.setSelectionRange(0, target.value.length);
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(target.value);
		} else {
			document.execCommand('copy');
		}
	});
}());
