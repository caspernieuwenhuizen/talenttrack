/*
 * filter-bar.js — behaviour for the shared FilterBar component
 * (#2026, epic #2017). Progressive enhancement only: every filter
 * navigates via a real link or auto-submitting form field, so the bar
 * works with JS off. This script adds:
 *
 *   - the mobile bottom sheet: a real <dialog> opened with showModal(), so
 *     the focus trap, Escape, ::backdrop and inertness are the platform's
 *     (#3294). This script only opens it, closes it on backdrop click, and
 *     locks page scroll while it is up.
 *   - the inline period pill-dropdown popover (open/close, outside-click)
 *   - auto-submit on [data-tt-filter-submit] controls (selects, toggle)
 *   - reflecting the toggle checkbox state onto the visual switch
 *   - keeping the duplicate inline/sheet copies of each field in sync so
 *     the stale copy can't overwrite the user's edit on submit (#2327)
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

	// #2201 / #2327 — the inline row and the bottom sheet each render their own
	// copy of a control with the SAME name inside one <form>. On GET submit the
	// browser serializes both copies and PHP keeps the last (the stale sheet
	// copy), so the user's edit reverts. Mirror the control the user just
	// changed onto every same-named sibling in the form: `.checked` for
	// checkbox/radio (reflecting the `.tt-switch` UI as before, #2201) and
	// `.value` for selects / date inputs / text inputs (#2327), so both copies
	// agree before the form submits.
	function syncField( form, source ) {
		var name = source.name;
		if ( ! name ) {
			return;
		}
		var esc = window.CSS && CSS.escape ? CSS.escape( name ) : name;
		var mates = form.querySelectorAll( '[name="' + esc + '"]' );
		var isToggle = source.type === 'checkbox' || source.type === 'radio';
		Array.prototype.forEach.call( mates, function ( mate ) {
			if ( mate === source ) {
				return;
			}
			if ( isToggle ) {
				mate.checked = source.checked;
				var sw = mate.closest ? mate.closest( '[data-tt-switch]' ) : null;
				if ( sw ) {
					sw.classList.toggle( 'tt-switch--on', mate.checked );
				}
			} else {
				mate.value = source.value;
			}
		} );
	}

	// #4147 — the inline bar is one row or it is the Filters button, never
	// two lines. Whether a bar's controls fit depends on the controls, which
	// no stylesheet breakpoint knows, so it is measured: the full bar first,
	// then the tighter one (`is-tight`), then the collapsed one
	// (`is-compact`). Re-measured when the bar's width changes, and only
	// then — the classes change its height, which must not loop.
	function wraps( bar ) {
		// A bar rendered inside its host's form (`form => false`, the
		// comparison view) has no flex form of its own: its row and cluster
		// stack by design, which is not a wrap.
		if ( ! bar.querySelector( '.tt-filterbar__form' ) ) {
			return false;
		}
		var row = bar.querySelector( '.tt-filterbar__row' );
		if ( ! row || window.getComputedStyle( row ).display === 'none' ) {
			return false;   // the stylesheet already collapsed it
		}
		var items = Array.prototype.slice.call( row.children );
		Array.prototype.forEach.call(
			bar.querySelectorAll( '.tt-filterbar__utils, .tt-filterbar__trailing' ),
			function ( el ) { items.push( el ); }
		);
		var first = null;
		for ( var i = 0; i < items.length; i++ ) {
			var r = items[ i ].getBoundingClientRect();
			if ( r.width === 0 && r.height === 0 ) {
				continue;   // not rendered
			}
			if ( first === null ) {
				first = r;
				continue;
			}
			// On one line the boxes overlap vertically; a box that starts
			// below the first one's bottom edge went onto a new line.
			if ( r.top >= first.bottom ) {
				return true;
			}
		}
		return false;
	}

	function fit( bar ) {
		bar.classList.remove( 'is-tight', 'is-compact' );
		if ( ! wraps( bar ) ) {
			return;
		}
		bar.classList.add( 'is-tight' );
		if ( ! wraps( bar ) ) {
			return;
		}
		bar.classList.add( 'is-compact' );
	}

	function fitInline( bar ) {
		fit( bar );
		// Web fonts can land after this runs and widen every label.
		window.addEventListener( 'load', function () { fit( bar ); } );
		if ( typeof window.ResizeObserver !== 'function' ) {
			return;
		}
		var lastWidth = bar.getBoundingClientRect().width;
		new window.ResizeObserver( function () {
			var width = bar.getBoundingClientRect().width;
			if ( width === lastWidth ) {
				return;
			}
			lastWidth = width;
			fit( bar );
		} ).observe( bar );
	}

	ready( function () {
		var bars = document.querySelectorAll( '[data-tt-filterbar]' );
		Array.prototype.forEach.call( bars, initBar );
	} );

	function initBar( bar ) {
		var sheet = bar.querySelector( '[data-tt-filter-sheet]' );
		var openBtn = bar.querySelector( '[data-tt-filter-open]' );

		// ---- Bottom sheet (#3294 — a real <dialog>) ----
		//
		// showModal() supplies the focus trap, top-layer stacking, ::backdrop,
		// Escape-to-close and inertness of the page behind. That is why there
		// is no scrim element, no document-level Escape listener and no
		// `hidden` toggling here any more: all four were hand-rolled around a
		// <div role="dialog"> that enforced none of them, and Tab walked out
		// of the sheet into a list the user could not see.
		//
		// The one thing showModal() does not do is stop the page behind from
		// scrolling — a touch starting on the backdrop still scrolls the
		// document — so that stays explicit below.
		function openSheet() {
			if ( ! sheet ) {
				return;
			}
			if ( typeof sheet.showModal === 'function' ) {
				sheet.showModal();
			} else {
				// No <dialog> support: fall back to showing it in flow. The
				// filters remain usable; the modality does not exist, which
				// is what every browser had before this change anyway.
				sheet.setAttribute( 'open', '' );
			}
			document.documentElement.classList.add( 'tt-sheet-lock' );
			// Next frame so the slide-up transition runs from the closed state.
			window.requestAnimationFrame( function () {
				bar.classList.add( 'is-sheet-open' );
			} );
			if ( openBtn ) {
				openBtn.setAttribute( 'aria-expanded', 'true' );
			}
		}

		function closeSheet() {
			bar.classList.remove( 'is-sheet-open' );
			document.documentElement.classList.remove( 'tt-sheet-lock' );
			if ( sheet ) {
				if ( typeof sheet.close === 'function' ) {
					sheet.close();
				} else {
					sheet.removeAttribute( 'open' );
				}
			}
			if ( openBtn ) {
				openBtn.setAttribute( 'aria-expanded', 'false' );
			}
		}

		if ( openBtn ) {
			openBtn.addEventListener( 'click', openSheet );
		}
		if ( sheet ) {
			// Escape and the backdrop's own dismissal both fire `close`, so
			// this is the single place the bar's state is put back — however
			// the dialog was dismissed. `close` also fires on our own
			// close(), which is harmless: the class removal is idempotent.
			sheet.addEventListener( 'close', function () {
				bar.classList.remove( 'is-sheet-open' );
				document.documentElement.classList.remove( 'tt-sheet-lock' );
				if ( openBtn ) {
					openBtn.setAttribute( 'aria-expanded', 'false' );
					openBtn.focus();
				}
			} );
			// A click on the backdrop lands on the dialog element itself,
			// never on its contents — the contents are children of the inner
			// boxes. That is the standard way to get click-outside-to-close
			// out of <dialog>, and it replaces the scrim's click handler.
			sheet.addEventListener( 'click', function ( e ) {
				if ( e.target === sheet ) {
					closeSheet();
				}
			} );
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
					// #2201 / #2327 — the inline row and the bottom sheet each
					// render their own copy of this control with the SAME name.
					// Mirror the changed control (checkbox toggle OR select)
					// onto its same-named siblings before submitting, so both
					// copies agree and the stale copy can't overwrite the edit.
					syncField( form, ctrl );
					if ( typeof form.requestSubmit === 'function' ) {
						form.requestSubmit();
					} else {
						form.submit();
					}
				} );
			}
		);

		// ---- Sync (no submit) the non-auto-submitting inputs ----
		// The Date range From/To and free-text inputs have their own explicit
		// Apply button rather than auto-submitting. #2327 — keep each edit in
		// lockstep with its same-named sheet/inline sibling on change, so when
		// Apply finally submits, the stale copy can't overwrite the user's edit.
		//
		// #3352 — selects belong here too. The list above only reaches one
		// carrying `data-tt-filter-submit`, and a select opted out of
		// auto-submit carries none: on the comparison view, changing the
		// evaluation type inline and pressing Compare sent the sheet's stale
		// copy instead. The same gap opens on every `refresh` surface, which
		// turns auto-submit off on all of its groups.
		Array.prototype.forEach.call(
			bar.querySelectorAll( '.tt-fildate__input, .tt-filtext__input, .tt-filsel__select' ),
			function ( input ) {
				input.addEventListener( 'change', function () {
					var form = input.form || bar.querySelector( '[data-tt-filterbar-form]' );
					if ( form ) {
						syncField( form, input );
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

		// ---- #4147 — reveal Clear once something changes ----
		// The inline Clear is rendered hidden while nothing is filtered. A
		// list that filters in place never reloads to re-render it, so the
		// first change inside the form shows it.
		var clearBtn = bar.querySelector( '[data-tt-filter-clear][hidden]' );
		if ( clearBtn ) {
			var reveal = function () {
				clearBtn.hidden = false;
				bar.removeEventListener( 'change', reveal );
				bar.removeEventListener( 'input', reveal );
			};
			bar.addEventListener( 'change', reveal );
			bar.addEventListener( 'input', reveal );
		}

		fitInline( bar );

		// #3294 — the document-level Escape listener that used to live here
		// is gone. <dialog> handles Escape itself and fires `close`, which
		// the handler above already answers. Keeping this would have meant
		// one listener per bar on the document, racing the native one.
	}
} )();
