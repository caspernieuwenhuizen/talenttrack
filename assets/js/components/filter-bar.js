/*
 * filter-bar.js — behaviour for the shared FilterBar component
 * (#2026, epic #2017). Progressive enhancement only: every filter
 * navigates via a real link or auto-submitting form field, so the bar
 * works with JS off. This script adds:
 *
 *   - the mobile bottom sheet (open/close, scrim, Escape, focus trap-lite)
 *   - the inline period pill-dropdown popover (open/close, outside-click)
 *   - auto-submit on [data-tt-filter-submit] controls (selects, toggle)
 *   - reflecting the toggle checkbox state onto the visual switch
 *
 * No globals beyond the TT namespace; strings come from TT_FILTER_BAR
 * (localized in PHP) merged onto TT.i18n. Mirrors the mockup script.
 */
( function () {
	'use strict';

	var TT = ( window.TT = window.TT || {} );
	TT.i18n = TT.i18n || {};
	var cfg = window.TT_FILTER_BAR || {};
	if ( cfg.i18n ) {
		for ( var k in cfg.i18n ) {
			if ( Object.prototype.hasOwnProperty.call( cfg.i18n, k ) && ! TT.i18n[ k ] ) {
				TT.i18n[ k ] = cfg.i18n[ k ];
			}
		}
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var bars = document.querySelectorAll( '[data-tt-filterbar]' );
		Array.prototype.forEach.call( bars, initBar );
	} );

	function initBar( bar ) {
		var sheet = bar.querySelector( '[data-tt-filter-sheet]' );
		var scrim = bar.querySelector( '[data-tt-filter-scrim]' );
		var openBtn = bar.querySelector( '[data-tt-filter-open]' );

		// ---- Bottom sheet ----
		function openSheet() {
			if ( ! sheet ) {
				return;
			}
			sheet.hidden = false;
			if ( scrim ) {
				scrim.hidden = false;
			}
			// next frame so the transition runs from the hidden state
			window.requestAnimationFrame( function () {
				bar.classList.add( 'is-sheet-open' );
			} );
			if ( openBtn ) {
				openBtn.setAttribute( 'aria-expanded', 'true' );
			}
			var first = sheet.querySelector(
				'button, [href], select, input, [tabindex]:not([tabindex="-1"])'
			);
			if ( first ) {
				first.focus();
			}
		}

		function closeSheet() {
			bar.classList.remove( 'is-sheet-open' );
			if ( openBtn ) {
				openBtn.setAttribute( 'aria-expanded', 'false' );
				openBtn.focus();
			}
			// hide after the transition so it can't be tabbed into
			window.setTimeout( function () {
				if ( ! bar.classList.contains( 'is-sheet-open' ) ) {
					if ( sheet ) {
						sheet.hidden = true;
					}
					if ( scrim ) {
						scrim.hidden = true;
					}
				}
			}, 220 );
		}

		if ( openBtn ) {
			openBtn.addEventListener( 'click', openSheet );
		}
		if ( scrim ) {
			scrim.addEventListener( 'click', closeSheet );
		}
		Array.prototype.forEach.call(
			bar.querySelectorAll( '[data-tt-filter-close]' ),
			function ( el ) {
				el.addEventListener( 'click', closeSheet );
			}
		);

		// ---- Inline period pill-dropdown (native <details>) ----
		// The <details> element handles open/close + keyboard natively;
		// JS only adds outside-click-to-close and Escape as enhancements.
		Array.prototype.forEach.call(
			bar.querySelectorAll( 'details[data-tt-perdrop]' ),
			function ( wrap ) {
				document.addEventListener( 'click', function ( e ) {
					if ( wrap.open && ! wrap.contains( e.target ) ) {
						wrap.open = false;
					}
				} );
				wrap.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Escape' && wrap.open ) {
						wrap.open = false;
						var summary = wrap.querySelector( 'summary' );
						if ( summary ) {
							summary.focus();
						}
					}
				} );
			}
		);

		// ---- Auto-submit controls (selects, toggle checkbox) ----
		Array.prototype.forEach.call(
			bar.querySelectorAll( '[data-tt-filter-submit]' ),
			function ( ctrl ) {
				ctrl.addEventListener( 'change', function () {
					var form = ctrl.form || bar.querySelector( '[data-tt-filterbar-form]' );
					if ( ! form ) {
						return;
					}
					if ( typeof form.requestSubmit === 'function' ) {
						form.requestSubmit();
					} else {
						form.submit();
					}
				} );
			}
		);

		// ---- Reflect toggle checkbox state onto the visual switch ----
		Array.prototype.forEach.call(
			bar.querySelectorAll( '[data-tt-switch]' ),
			function ( sw ) {
				var input = sw.querySelector( '.tt-switch__input' );
				if ( ! input ) {
					return;
				}
				input.addEventListener( 'change', function () {
					sw.classList.toggle( 'tt-switch--on', input.checked );
				} );
			}
		);

		// ---- Escape closes the sheet (document-level, once per bar) ----
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && bar.classList.contains( 'is-sheet-open' ) ) {
				closeSheet();
			}
		} );
	}
} )();
