/**
 * Minutes grid (#2386 / epic #2381) — desktop bulk match-minutes entry.
 *
 * Players (rows) × matches (columns). Each editable cell is a numeric box;
 * edits are tracked and written in one batch to POST /minutes/bulk, which
 * routes each through the minutes-ownership arbiter server-side. Config
 * comes in via window.TTMinutesGrid (wp_localize_script).
 */
( function () {
	'use strict';

	var CFG = window.TTMinutesGrid || {};
	var I18N = CFG.i18n || {};

	var grid = document.querySelector( '.tt-agrid-card' );
	if ( ! grid ) {
		return;
	}

	var dirty = new Set();
	var statusEl = grid.querySelector( '[data-agrid-status]' );
	var saveBtn = grid.querySelector( '[data-agrid-save]' );

	function clampMinutes( raw ) {
		var n = parseInt( String( raw ).replace( /[^0-9]/g, '' ), 10 );
		if ( isNaN( n ) ) {
			return '';
		}
		return Math.max( 0, Math.min( 200, n ) );
	}

	function recomputeTotal( tr ) {
		if ( ! tr ) {
			return;
		}
		var cell = tr.querySelector( '.tt-agrid__rate' );
		if ( ! cell ) {
			return;
		}
		var total = 0;
		tr.querySelectorAll( 'input.tt-agrid-min-in' ).forEach( function ( inp ) {
			var n = parseInt( inp.value, 10 );
			if ( ! isNaN( n ) ) {
				total += n;
			}
		} );
		cell.textContent = String( total );
	}

	function refresh() {
		var n = dirty.size;
		if ( statusEl ) {
			statusEl.classList.remove( 'is-dirty', 'is-error', 'is-saved' );
			if ( n > 0 ) {
				statusEl.classList.add( 'is-dirty' );
				statusEl.textContent = ( I18N.unsaved || '%d unsaved change(s)' ).replace( '%d', String( n ) );
			} else {
				statusEl.textContent = I18N.noChanges || '';
			}
		}
		if ( saveBtn ) {
			saveBtn.disabled = n === 0;
		}
	}

	grid.querySelectorAll( 'input.tt-agrid-min-in' ).forEach( function ( inp ) {
		inp.addEventListener( 'input', function () {
			var td = inp.closest( 'td' );
			if ( td ) {
				td.classList.add( 'is-dirty' );
				dirty.add( td );
			}
			recomputeTotal( inp.closest( 'tr' ) );
			refresh();
		} );
		// Normalise + clamp when the box loses focus.
		inp.addEventListener( 'blur', function () {
			var v = clampMinutes( inp.value );
			inp.value = v === '' ? '' : String( v );
			recomputeTotal( inp.closest( 'tr' ) );
		} );
	} );

	function collectChanges() {
		var changes = [];
		dirty.forEach( function ( td ) {
			var inp = td.querySelector( 'input.tt-agrid-min-in' );
			var v = inp ? clampMinutes( inp.value ) : '';
			changes.push( {
				activity_id: parseInt( td.getAttribute( 'data-activity' ), 10 ),
				player_id: parseInt( td.getAttribute( 'data-player' ), 10 ),
				minutes: v === '' ? '' : v
			} );
		} );
		return changes;
	}

	function save() {
		if ( dirty.size === 0 || ! CFG.restBulk ) {
			return;
		}
		if ( saveBtn ) {
			saveBtn.disabled = true;
		}
		if ( statusEl ) {
			statusEl.classList.remove( 'is-dirty', 'is-error', 'is-saved' );
			statusEl.textContent = I18N.saving || '';
		}
		fetch( CFG.restBulk, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce || ''
			},
			body: JSON.stringify( { changes: collectChanges() } )
		} )
			.then( function ( r ) {
				return r.json().then( function ( body ) {
					return { ok: r.ok, body: body };
				} );
			} )
			.then( function ( res ) {
				if ( ! res.ok || ! res.body || res.body.success !== true ) {
					throw new Error( 'save_failed' );
				}
				dirty.forEach( function ( td ) {
					td.classList.remove( 'is-dirty' );
				} );
				dirty.clear();
				if ( statusEl ) {
					statusEl.classList.add( 'is-saved' );
					statusEl.textContent = I18N.saved || '';
				}
				if ( saveBtn ) {
					saveBtn.disabled = true;
				}
			} )
			.catch( function () {
				if ( statusEl ) {
					statusEl.classList.add( 'is-error' );
					statusEl.textContent = I18N.error || '';
				}
				if ( saveBtn ) {
					saveBtn.disabled = false;
				}
			} );
	}

	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', save );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty.size > 0 ) {
			e.preventDefault();
			e.returnValue = I18N.confirm || '';
			return I18N.confirm || '';
		}
	} );

	refresh();
}() );
