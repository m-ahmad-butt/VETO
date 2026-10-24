( function () {
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement, useEffect, useRef } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const PAYMENT_METHOD_NAME = 'suap_wordpresspayplugin';
	const settings = getSetting( PAYMENT_METHOD_NAME + '_data', {} );
	const labelText = decodeEntities( settings.title || 'Cleffo' );
	const IFRAME_ID = 'cleffo-blocks-card-frame';

	window.CleffoPaymentHandler = window.CleffoPaymentHandler || {
		stripeToken: '',
		callCount: 0,
		usedClientKey: '',
		pendingResolve: null,
		resetPaymentState: function () {
			this.stripeToken = '';
			this.callCount = 0;
			this.usedClientKey = '';
			this.pendingResolve = null;
		},
	};

	function formatCartTotal( billing ) {
		if ( ! billing || ! billing.cartTotal ) {
			return '';
		}
		if ( typeof billing.cartTotal === 'string' ) {
			return billing.cartTotal;
		}
		if ( billing.cartTotal.value && billing.currency ) {
			const amount = parseInt( billing.cartTotal.value, 10 ) / Math.pow( 10, billing.currency.minorUnit || 2 );
			return amount.toFixed( billing.currency.minorUnit || 2 );
		}
		return '';
	}

	function buildBillingData( billing ) {
		const address = ( billing && billing.billingAddress ) || {};
		return {
			billing_first_name: address.first_name || '',
			billing_last_name: address.last_name || '',
			billing_company: address.company || '',
			billing_address_1: address.address_1 || '',
			billing_address_2: address.address_2 || '',
			billing_city: address.city || '',
			billing_state: address.state || '',
			billing_postcode: address.postcode || '',
			billing_country: address.country || '',
			billing_phone: address.phone || '',
			billing_email: address.email || '',
			order_total: formatCartTotal( billing ),
			order_currency: ( billing && billing.currency && billing.currency.code ) || '',
			test_mode: settings.testMode || 'test',
		};
	}

	function buildPaymentMethodData( handler ) {
		return {
			suap_stripe_token: handler.stripeToken,
			suap_used_client_key: handler.usedClientKey || '',
			suap_mode: settings.testMode || 'test',
		};
	}

	function CleffoContent( props ) {
		const { eventRegistration, emitResponse, billing } = props;
		const { onPaymentSetup, onCheckoutFail } = eventRegistration;
		const iframeRef = useRef( null );

		useEffect( function () {
			const handler = window.CleffoPaymentHandler;

			function onMessage( event ) {
				if ( event.origin !== settings.apiBaseUrl ) {
					return;
				}

				if ( event.data && typeof event.data === 'string' ) {
					try {
						const tokenData = JSON.parse( event.data );
						handler.stripeToken = tokenData.token || '';
						handler.usedClientKey = tokenData.used_client_key || '';

						if ( handler.pendingResolve ) {
							const resolve = handler.pendingResolve;
							handler.pendingResolve = null;
							resolve( {
								type: emitResponse.responseTypes.SUCCESS,
								meta: {
									paymentMethodData: buildPaymentMethodData( handler ),
								},
							} );
						}
					} catch ( e ) {
						// Ignore malformed iframe messages.
					}
				} else if ( event.data === '' && handler.callCount > 0 ) {
					handler.callCount--;
				}
			}

			window.addEventListener( 'message', onMessage );
			return function () {
				window.removeEventListener( 'message', onMessage );
			};
		}, [ emitResponse ] );

		useEffect( function () {
			const handler = window.CleffoPaymentHandler;

			const unsubscribe = onPaymentSetup( function () {
				if ( handler.stripeToken ) {
					const paymentMethodData = buildPaymentMethodData( handler );
					handler.stripeToken = '';
					handler.callCount = 0;
					handler.usedClientKey = '';
					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: { paymentMethodData: paymentMethodData },
					};
				}

				if ( handler.callCount >= 2 ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: 'Unable to process card details. Please try again.',
						messageContext: emitResponse.noticeContexts.PAYMENTS,
					};
				}

				const billingData = buildBillingData( billing );
				if ( ! billingData.billing_email ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: 'Please enter a valid email address.',
						messageContext: emitResponse.noticeContexts.PAYMENTS,
					};
				}

				handler.callCount++;

				return new Promise( function ( resolve ) {
					handler.pendingResolve = resolve;

					const frame = document.getElementById( IFRAME_ID );
					if ( ! frame || ! frame.contentWindow ) {
						handler.pendingResolve = null;
						handler.callCount = 0;
						resolve( {
							type: emitResponse.responseTypes.ERROR,
							message: 'Payment form is not ready. Please try again.',
							messageContext: emitResponse.noticeContexts.PAYMENTS,
						} );
						return;
					}

					frame.contentWindow.postMessage( JSON.stringify( billingData ), settings.apiBaseUrl );

					window.setTimeout( function () {
						if ( ! handler.pendingResolve ) {
							return;
						}
						handler.pendingResolve = null;
						handler.callCount = 0;
						resolve( {
							type: emitResponse.responseTypes.ERROR,
							message: 'Card token request timed out. Please try again.',
							messageContext: emitResponse.noticeContexts.PAYMENTS,
						} );
					}, 60000 );
				} );
			} );

			return unsubscribe;
		}, [ onPaymentSetup, emitResponse, billing ] );

		useEffect( function () {
			const unsubscribe = onCheckoutFail( function () {
				window.CleffoPaymentHandler.resetPaymentState();
			} );
			return unsubscribe;
		}, [ onCheckoutFail ] );

		return createElement(
			'div',
			{ className: 'cleffo-blocks-payment-form' },
			settings.description
				? createElement( 'p', { style: { margin: '0 0 8px' } }, decodeEntities( settings.description ) )
				: null,
			createElement( 'iframe', {
				id: IFRAME_ID,
				ref: iframeRef,
				src: settings.iframeUrl,
				title: 'Cleffo card payment',
				style: { width: '100%', border: 'none', height: '115px' },
				allow: 'publickey-credentials-get; payment; camera; microphone; geolocation',
				sandbox: 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
			} )
		);
	}

	function CleffoEdit() {
		return createElement(
			'div',
			{ className: 'cleffo-blocks-payment-form-edit' },
			settings.description
				? createElement( 'p', null, decodeEntities( settings.description ) )
				: createElement( 'p', null, labelText )
		);
	}

	function CleffoLabel( props ) {
		const { PaymentMethodLabel } = props.components;
		return createElement( PaymentMethodLabel, { text: labelText } );
	}

	registerPaymentMethod( {
		name: PAYMENT_METHOD_NAME,
		label: createElement( CleffoLabel ),
		content: createElement( CleffoContent ),
		edit: createElement( CleffoEdit ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: labelText,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
