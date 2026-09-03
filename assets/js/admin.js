/**
 * Fatal Plugin Auto Deactivator — admin UI behaviour.
 *
 * Vanilla JS, no dependencies, no build step. Everything here is progressive:
 * with JavaScript off the page still filters (GET form), saves, deletes and
 * exports — only the conveniences below disappear.
 *
 * See docs/ui.md for the markup contracts these behaviours rely on.
 */
( function () {
	'use strict';

	var app = document.getElementById( 'fpad-app' );
	if ( ! app ) {
		return;
	}

	var i18n = window.fpadUi || {};

	function text( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	/**
	 * Copy an incident report to the clipboard (data-fpad-report on the button).
	 */
	function copyReport( button ) {
		var payload = button.getAttribute( 'data-fpad-report' ) || '';
		var label = button.querySelector( 'span' );
		var original = label ? label.textContent : '';

		function done( ok ) {
			if ( ! label ) {
				return;
			}
			label.textContent = ok ? text( 'copied', 'Copied' ) : text( 'copyFailed', 'Copy failed' );
			window.setTimeout( function () {
				label.textContent = original;
			}, 1600 );
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( payload ).then(
				function () {
					done( true );
				},
				function () {
					done( fallbackCopy( payload ) );
				}
			);
			return;
		}

		done( fallbackCopy( payload ) );
	}

	/**
	 * Clipboard fallback for browsers without the async clipboard API.
	 */
	function fallbackCopy( payload ) {
		var field = document.createElement( 'textarea' );
		var ok = false;

		field.value = payload;
		field.setAttribute( 'readonly', 'readonly' );
		field.style.position = 'fixed';
		field.style.opacity = '0';
		document.body.appendChild( field );
		field.select();

		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		document.body.removeChild( field );

		return ok;
	}

	/**
	 * Live-filter a checklist (used by the protected-plugins list).
	 */
	function bindListFilters() {
		Array.prototype.forEach.call( app.querySelectorAll( '[data-fpad-filter]' ), function ( input ) {
			var list = app.querySelector( input.getAttribute( 'data-fpad-filter' ) );
			if ( ! list ) {
				return;
			}

			input.addEventListener( 'input', function () {
				var needle = input.value.toLowerCase().trim();

				Array.prototype.forEach.call( list.querySelectorAll( '[data-fpad-filterable]' ), function ( item ) {
					var haystack = item.getAttribute( 'data-fpad-filterable' ) || '';
					item.style.display = ! needle || haystack.indexOf( needle ) !== -1 ? '' : 'none';
				} );
			} );
		} );
	}

	/**
	 * Keep the switch caption in sync with its checkbox where the caption differs
	 * per state (data-fpad-switch-text holds ["off label", "on label"]).
	 */
	function bindSwitchLabels() {
		Array.prototype.forEach.call( app.querySelectorAll( '[data-fpad-switch-text]' ), function ( input ) {
			var labels;

			try {
				labels = JSON.parse( input.getAttribute( 'data-fpad-switch-text' ) );
			} catch ( e ) {
				return;
			}

			if ( ! labels || labels.length !== 2 ) {
				return;
			}

			input.addEventListener( 'change', function () {
				var caption = input.parentNode.querySelector( '.fpad-switch-text' );
				if ( caption ) {
					caption.textContent = input.checked ? labels[ 1 ] : labels[ 0 ];
				}
			} );
		} );
	}

	// Delegated clicks: copy buttons and confirm-guarded destructive actions.
	app.addEventListener( 'click', function ( event ) {
		var copy = event.target.closest ? event.target.closest( '.fpad-copy' ) : null;
		if ( copy ) {
			event.preventDefault();
			copyReport( copy );
			return;
		}

		var guarded = event.target.closest ? event.target.closest( '[data-fpad-confirm]' ) : null;
		if ( guarded && ! window.confirm( guarded.getAttribute( 'data-fpad-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	// Forms can carry the guard too (the "Clear log" submit).
	app.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form.hasAttribute && form.hasAttribute( 'data-fpad-confirm' ) ) {
			if ( ! window.confirm( form.getAttribute( 'data-fpad-confirm' ) ) ) {
				event.preventDefault();
			}
		}
	} );

	bindListFilters();
	bindSwitchLabels();
} )();
