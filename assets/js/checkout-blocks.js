(function () {
	'use strict';

	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var settings = window.wc.wcSettings.getSetting('mygate_data', {});
	var createElement = window.wp.element.createElement;
	var decodeEntities = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ? window.wp.htmlEntities.decodeEntities : function (value) { return value; };
	var title = decodeEntities(settings.title || 'Crypto');
	var description = decodeEntities(settings.description || '');

	var Label = function (props) {
		if (props.components && props.components.PaymentMethodLabel) {
			return createElement(props.components.PaymentMethodLabel, { text: title });
		}
		return createElement('span', null, title);
	};

	var Content = function () {
		var children = [];
		if (settings.icon) {
			children.push(createElement('img', {
				key: 'icon',
				src: settings.icon,
				alt: '',
				style: { maxHeight: '28px', width: 'auto', marginRight: '8px', verticalAlign: 'middle' }
			}));
		}
		children.push(createElement('span', { key: 'text' }, description));
		return createElement('div', { className: 'mgcpg-block-description' }, children);
	};

	registerPaymentMethod({
		name: 'mygate',
		gatewayId: 'mygate',
		paymentMethodId: 'mygate',
		label: createElement(Label, null),
		content: createElement(Content, null),
		edit: createElement(Content, null),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: {
			features: settings.supports || ['products'],
			showSavedCards: false,
			showSaveOption: false
		}
	});
}());
