(function () {
	'use strict';

	// Escapa para posición de atributo (también seguro en texto). Si en el futuro
	// se construye HTML con innerHTML, usar SIEMPRE esta función para atributos.
	function escAttr( text ) {
		if ( text === null || typeof text === 'undefined' ) {
			return '';
		}
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	var startBtn    = document.getElementById( 'wpwebp-bulk-start' );
	var htaccessBtn = document.getElementById( 'wpwebp-bulk-htaccess' );
	var progressBox = document.getElementById( 'wpwebp-bulk-progress' );
	var fill        = document.getElementById( 'wpwebp-bulk-fill' );
	var statusEl    = document.getElementById( 'wpwebp-bulk-status' );
	var pollTimer   = null;

	function post( action, payload ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', wpwebpAdmin.nonce );
		if ( payload ) {
			Object.keys( payload ).forEach( function ( key ) {
				body.set( key, payload[ key ] );
			} );
		}

		return fetch( wpwebpAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function setProgress( offset, total ) {
		var pct = total > 0 ? Math.round( ( offset / total ) * 100 ) : 0;
		fill.style.width = pct + '%';
		// textContent es seguro (no interpreta HTML).
		statusEl.textContent = 'Procesadas ' + offset + ' de ' + total + ' (' + pct + '%)';
	}

	function stopPolling( msg ) {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
		if ( msg ) {
			statusEl.textContent = msg;
		}
		startBtn.disabled = false;
	}

	function poll() {
		post( 'wpwebp_bulk_status' ).then( function ( res ) {
			if ( ! res.success ) {
				stopPolling( res.data && res.data.message ? res.data.message : 'Error.' );
				return;
			}

			var d = res.data;
			setProgress( d.offset, d.total );

			if ( d.done && d.offset >= d.total ) {
				stopPolling( 'Conversión completada.' );
			} else {
				pollTimer = setTimeout( poll, 4000 );
			}
		} ).catch( function () {
			stopPolling( 'Error al consultar el estado.' );
		} );
	}

	if ( startBtn ) {
		startBtn.addEventListener( 'click', function () {
			startBtn.disabled = true;
			progressBox.style.display = 'block';
			fill.style.width = '0';
			statusEl.textContent = 'Iniciando…';

			post( 'wpwebp_bulk_start' ).then( function ( res ) {
				if ( ! res.success ) {
					stopPolling( res.data && res.data.message ? res.data.message : 'Error.' );
					return;
				}
				setProgress( res.data.offset, res.data.total );
				pollTimer = setTimeout( poll, 2000 );
			} ).catch( function () {
				stopPolling( 'Error al iniciar la conversión.' );
			} );
		} );
	}

	if ( htaccessBtn ) {
		htaccessBtn.addEventListener( 'click', function () {
			htaccessBtn.disabled = true;
			post( 'wpwebp_bulk_htaccess' ).then( function ( res ) {
				var msg = res.data && res.data.message ? res.data.message : 'Error.';
				window.alert( msg );
				htaccessBtn.disabled = false;
			} ).catch( function () {
				window.alert( 'Error al regenerar .htaccess.' );
				htaccessBtn.disabled = false;
			} );
		} );
	}
})();
