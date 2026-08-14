/**
 * Attendance grid (#2382 / epic #2381) — desktop bulk attendance entry.
 *
 * Players (rows) × activities (columns). Each cell is a native <select>
 * whose closed text is hidden; the compact abbreviation is painted over it
 * (full words stay in the open list). Edits are tracked per cell and written
 * in one batch to POST /talenttrack/v1/attendance/bulk on Save.
 *
 * No framework: config comes in via window.TTAttendanceGrid (wp_localize_script).
 */
( function () {
	'use strict';

	var CFG = window.TTAttendanceGrid || {};
	var STATUSES = Array.isArray( CFG.statuses ) ? CFG.statuses : [];
	var I18N = CFG.i18n || {};

	var SHORT = {};
	var MOD = {};
	var MOD_CLASSES = [];
	STATUSES.forEach( function ( s ) {
		SHORT[ s.value ] = s.short;
		MOD[ s.value ] = s.mod;
		MOD_CLASSES.push( 'tt-agrid-cell--' + s.mod );
	} );

	var grid = document.querySelector( '.tt-agrid-card' );
	if ( ! grid ) {
		return;
	}

	var dirty = new Set();
	var statusEl = grid.querySelector( '[data-agrid-status]' );
	var saveBtn = grid.querySelector( '[data-agrid-save]' );

	function paintCell( td, value ) {
		MOD_CLASSES.forEach( function ( c ) {
			td.classList.remove( c );
		} );
		td.classList.add( 'tt-agrid-cell--' + ( MOD[ value ] || 'empty' ) );
		var abbr = td.querySelector( '.tt-agrid-abbr' );
		if ( abbr ) {
			abbr.textContent = value !== '' && SHORT[ value ] !== undefined ? SHORT[ value ] : ( SHORT[ '' ] || '' );
		}
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

	function markDirty( td ) {
		td.classList.add( 'is-dirty' );
		dirty.add( td );
		refresh();
	}

	// cell edits
	grid.querySelectorAll( 'select.tt-agrid-sel' ).forEach( function ( sel ) {
		sel.addEventListener( 'change', function () {
			var td = sel.closest( 'td' );
			if ( ! td ) {
				return;
			}
			paintCell( td, sel.value );
			markDirty( td );
		} );
	} );

	// "all present" fills a whole activity column
	grid.querySelectorAll( '.tt-agrid__fill' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var aid = btn.getAttribute( 'data-activity' );
			grid.querySelectorAll( 'td[data-activity="' + aid + '"]' ).forEach( function ( td ) {
				var sel = td.querySelector( 'select.tt-agrid-sel' );
				if ( sel && sel.value !== 'present' ) {
					sel.value = 'present';
					paintCell( td, 'present' );
					markDirty( td );
				}
			} );
		} );
	} );

	function collectChanges() {
		var changes = [];
		dirty.forEach( function ( td ) {
			var sel = td.querySelector( 'select.tt-agrid-sel' );
			changes.push( {
				activity_id: parseInt( td.getAttribute( 'data-activity' ), 10 ),
				player_id: parseInt( td.getAttribute( 'data-player' ), 10 ),
				status: sel ? sel.value : ''
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

	// guard against losing edits
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty.size > 0 ) {
			e.preventDefault();
			e.returnValue = I18N.confirm || '';
			return I18N.confirm || '';
		}
	} );

	refresh();
}() );
