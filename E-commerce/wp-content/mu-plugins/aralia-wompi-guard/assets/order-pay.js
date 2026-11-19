/**
 * Order-pay de Aralía: pide al servidor un intento de pago y abre el widget de Wompi con lo que el servidor firmó.
 *
 * La página (GET) no tiene efectos: aquí se hace el POST que crea el intento (referencia, monto y firma de integridad los fija el
 * servidor) justo antes de abrir el widget, y luego se avisa del resultado a /aralia/v1/payment-attempt (una pista: quien paga un
 * pedido es el servidor, tras verificar con Wompi). Sin JavaScript inline (CSP estricta).
 */
( function () {
	'use strict';

	const WIDGET_WAIT_MS = 8000;
	const POLL_MS = 200;
	const MESSAGES = {
		expired:
			'Tu reserva venció. Vuelve a tu carrito para intentarlo de nuevo.',
		paid: 'Tu pedido ya está pagado. ¡Gracias!',
		blocked:
			'Por seguridad, el pago de este pedido está en pausa. Inténtalo de nuevo en una hora o escríbenos.',
		processing:
			'Tienes un pago en proceso. Te avisaremos apenas se confirme.',
		unavailable:
			'El pago no está disponible en este momento. Inténtalo de nuevo en un momento.',
		other: 'Este pedido no admite pago en este momento.',
	};

	function text( root, role, value ) {
		const el = root.querySelector( '[data-role="' + role + '"]' );
		if ( el ) {
			el.textContent = value;
		}
	}

	function pad( n ) {
		return n < 10 ? '0' + n : String( n );
	}

	function countdown( root, expiresIn ) {
		// Segundos que quedan según el SERVIDOR (no una hora absoluta: el reloj del teléfono puede estar desfasado).
		const end = Date.now() + expiresIn * 1000;
		const timeEl = root.querySelector( '[data-role="time"]' );
		if ( ! timeEl || isNaN( end ) ) {
			return;
		}
		function tick() {
			const left = Math.max(
				0,
				Math.floor( ( end - Date.now() ) / 1000 )
			);
			if ( left === 0 ) {
				timeEl.textContent =
					'Tu reserva está por vencer. Si no se completa el pago, tendrás que volver a tu carrito.';
				return;
			}
			timeEl.textContent =
				'Tu reserva dura ' +
				pad( Math.floor( left / 60 ) ) +
				':' +
				pad( left % 60 ) +
				' más.';
			window.setTimeout( tick, 1000 );
		}
		tick();
	}

	// Muestra un mensaje y, si se pide, el botón que recarga la página (recargar crea un intento NUEVO en el servidor).
	function show( root, message, withRetry ) {
		text( root, 'msg', message );
		const retry = root.querySelector( '[data-role="retry"]' );
		if ( retry && withRetry && retry.dataset.bound !== '1' ) {
			retry.dataset.bound = '1';
			retry.hidden = false;
			retry.addEventListener( 'click', function () {
				window.location.reload();
			} );
		}
	}

	function sendHint( root, reference, transactionId ) {
		if ( ! transactionId || typeof window.fetch !== 'function' ) {
			return;
		}
		try {
			window
				.fetch( root.dataset.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						order_id: parseInt( root.dataset.order, 10 ),
						order_key: root.dataset.key,
						reference,
						transaction_id: String( transactionId ),
					} ),
				} )
				.catch( function () {} );
		} catch {
			// Es solo una pista: el evento de Wompi y la conciliación hacen el trabajo.
		}
	}

	function openWidget( root, config ) {
		const checkout = new window.WidgetCheckout( {
			currency: config.currency,
			amountInCents: parseInt( config.cents, 10 ),
			reference: config.reference,
			publicKey: config.public,
			signature: { integrity: config.signature },
			redirectUrl: config.redirect,
		} );
		show( root, 'Completa el pago en la ventana de Wompi.', false );
		checkout.open( function ( result ) {
			const tx = result && result.transaction ? result.transaction : null;
			sendHint( root, config.reference, tx ? tx.id : '' );
			if ( tx && tx.status === 'APPROVED' ) {
				show( root, 'Estamos confirmando tu pago…', false );
				window.location.href = config.redirect;
				return;
			}
			if ( tx && tx.status === 'PENDING' ) {
				// PSE, Nequi...: el pago sigue en proceso. NO se ofrece otro intento: se va a «Gracias», que muestra el estado real.
				show(
					root,
					'Tu pago está en proceso. Te avisaremos apenas se confirme.',
					false
				);
				window.location.href = config.redirect;
				return;
			}
			show(
				root,
				'El pago no se completó. Puedes intentarlo de nuevo con otro medio.',
				true
			);
		} );
	}

	function whenWidgetReady( root, config ) {
		let waited = 0;
		( function wait() {
			if ( typeof window.WidgetCheckout === 'function' ) {
				try {
					openWidget( root, config );
				} catch {
					show(
						root,
						'No pudimos abrir el pago. Inténtalo de nuevo en un momento.',
						true
					);
				}
				return;
			}
			waited += POLL_MS;
			if ( waited >= WIDGET_WAIT_MS ) {
				show(
					root,
					'No pudimos abrir el pago. Inténtalo de nuevo en un momento.',
					true
				);
				return;
			}
			window.setTimeout( wait, POLL_MS );
		} )();
	}

	function init() {
		const root = document.getElementById( 'aralia-wompi-pay' );
		if ( ! root || typeof window.fetch !== 'function' ) {
			return;
		}
		window
			.fetch( root.dataset.open, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					order_id: parseInt( root.dataset.order, 10 ),
					order_key: root.dataset.key,
				} ),
			} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( config ) {
				if ( ! config || ! config.state ) {
					show(
						root,
						'No pudimos preparar el pago. Inténtalo de nuevo en un momento.',
						true
					);
					return;
				}
				if ( config.state !== 'open' ) {
					// «unavailable» es transitorio (candado ocupado, API de Wompi lenta): se ofrece reintentar.
					show(
						root,
						MESSAGES[ config.state ] || MESSAGES.other,
						config.state === 'unavailable'
					);
					return;
				}
				countdown( root, config.expires_in );
				whenWidgetReady( root, config );
			} )
			.catch( function () {
				show(
					root,
					'No pudimos preparar el pago. Inténtalo de nuevo en un momento.',
					true
				);
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
