/**
 * comparison-slot-picker.js — hydrate `[data-tt-slot-picker]` slots.
 *
 * Each slot owns a Team <select>, a search <input>, and a Player
 * <select>. The Player options are rebuilt from the embedded JSON
 * blob whenever the team changes or the search input fires `input`.
 *
 * Form-submit contract: only `name="pN"` (the player select) is read
 * server-side. `name="team_N"` is UI state and gets stripped by the
 * server-side picker. We don't strip it client-side; harmless to send.
 */
(function () {
	'use strict';

	function init( picker ) {
		var team   = picker.querySelector( '[data-tt-slot-team]' );
		var search = picker.querySelector( '[data-tt-slot-search]' );
		var player = picker.querySelector( '[data-tt-slot-player]' );
		var clear  = picker.querySelector( '[data-tt-slot-clear]' );

		var dataEl  = picker.querySelector( 'script[data-tt-slot-data]' );
		var selEl   = picker.querySelector( 'script[data-tt-slot-selected]' );
		if ( ! team || ! search || ! player || ! dataEl ) return;

		var rows = [];
		try { rows = JSON.parse( dataEl.textContent || '[]' ); } catch ( e ) { rows = []; }

		var initialSelected = { player_id: 0, team_id: 0 };
		if ( selEl ) {
			try { initialSelected = JSON.parse( selEl.textContent || '{}' ); } catch ( e ) { /* fall through */ }
		}

		var playerPlaceholder = player.querySelector( 'option[value="0"]' );
		var placeholderText   = playerPlaceholder ? playerPlaceholder.textContent : '';

		function rebuildPlayerOptions() {
			var teamId = parseInt( team.value, 10 ) || 0;
			var query  = ( search.value || '' ).toLowerCase().trim();

			// Disable + reset when no team picked.
			if ( teamId === 0 ) {
				player.disabled = true;
				search.disabled = true;
				search.style.opacity = '0.6';
				player.innerHTML = '';
				if ( placeholderText ) {
					var ph = document.createElement( 'option' );
					ph.value = '0';
					ph.textContent = placeholderText;
					player.appendChild( ph );
				}
				updateClearButton();
				return;
			}

			player.disabled = false;
			search.disabled = false;
			search.style.opacity = '';

			var preserved = parseInt( player.value, 10 ) || 0;
			player.innerHTML = '';
			if ( placeholderText ) {
				var phOpt = document.createElement( 'option' );
				phOpt.value = '0';
				phOpt.textContent = placeholderText;
				player.appendChild( phOpt );
			}

			var matched = 0;
			for ( var i = 0; i < rows.length; i++ ) {
				var r = rows[ i ];
				if ( r.team_id !== teamId ) continue;
				if ( query !== '' && r.search.indexOf( query ) === -1 ) continue;
				var opt = document.createElement( 'option' );
				opt.value = String( r.id );
				opt.textContent = r.name;
				if ( r.id === preserved ) opt.selected = true;
				player.appendChild( opt );
				matched++;
			}

			// If preserved value is no longer in the filtered set, reset.
			if ( player.value === '' || player.value === '0' ) {
				if ( player.querySelector( 'option[value="' + preserved + '"]' ) === null ) {
					player.value = '0';
				}
			}

			updateClearButton();
		}

		function updateClearButton() {
			if ( ! clear ) return;
			var hasPick = parseInt( team.value, 10 ) > 0 || parseInt( player.value, 10 ) > 0;
			clear.style.visibility = hasPick ? '' : 'hidden';
		}

		function clearSlot() {
			team.value   = '0';
			search.value = '';
			rebuildPlayerOptions();
		}

		team.addEventListener( 'change', function () {
			search.value = '';
			rebuildPlayerOptions();
		} );
		search.addEventListener( 'input', rebuildPlayerOptions );
		player.addEventListener( 'change', updateClearButton );
		if ( clear ) clear.addEventListener( 'click', clearSlot );

		// Initial hydration: if a team was pre-selected via URL state,
		// build the option list and re-select the player.
		if ( parseInt( team.value, 10 ) > 0 ) {
			rebuildPlayerOptions();
			if ( initialSelected && initialSelected.player_id ) {
				player.value = String( initialSelected.player_id );
				updateClearButton();
			}
		} else {
			rebuildPlayerOptions();
		}
	}

	function boot() {
		var pickers = document.querySelectorAll( '[data-tt-slot-picker]' );
		for ( var i = 0; i < pickers.length; i++ ) init( pickers[ i ] );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
