/**
 * frontend-strava.js (#2061, epic #2002) — per-player Strava connect panel.
 *
 * Drives the FrontendStravaView shell against the talenttrack/v1 REST API:
 * loads connection status + imported activities, mints + follows the OAuth
 * authorize URL on connect (consent-gated), and disconnects. Vanilla JS,
 * no jQuery; nonce via X-WP-Nonce.
 */
( function () {
	'use strict';

	var cfg = window.TT_Strava || {};
	var i18n = cfg.i18n || {};
	var rest = ( cfg.rest_url || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' );
	var nonce = cfg.rest_nonce || '';
	var playerId = parseInt( cfg.player_id, 10 ) || 0;

	var root = document.querySelector( '[data-tt-strava]' );
	if ( ! root || ! playerId ) {
		return;
	}

	var statusEl = root.querySelector( '[data-tt-strava-status]' );
	var connectPanel = root.querySelector( '[data-tt-strava-connect]' );
	var connectedPanel = root.querySelector( '[data-tt-strava-connected]' );
	var metaEl = root.querySelector( '[data-tt-strava-meta]' );
	var consentBox = root.querySelector( '[data-tt-strava-consent]' );
	var connectBtn = root.querySelector( '[data-tt-strava-connect-btn]' );
	var disconnectBtn = root.querySelector( '[data-tt-strava-disconnect-btn]' );
	var msgEl = root.querySelector( '[data-tt-strava-msg]' );
	var listEl = root.querySelector( '[data-tt-strava-activities]' );

	function headers() {
		var h = { Accept: 'application/json', 'Content-Type': 'application/json' };
		if ( nonce ) {
			h['X-WP-Nonce'] = nonce;
		}
		return h;
	}

	function call( path, method, body ) {
		return fetch( rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: headers(),
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { ok: res.ok, json: json };
			} );
		} );
	}

	function setMsg( text ) {
		if ( msgEl ) {
			msgEl.textContent = text || '';
		}
	}

	function show( el ) {
		if ( el ) {
			el.hidden = false;
		}
	}
	function hide( el ) {
		if ( el ) {
			el.hidden = true;
		}
	}

	function renderStatus( data ) {
		if ( statusEl ) {
			statusEl.textContent = '';
		}
		if ( data && data.connected ) {
			hide( connectPanel );
			show( connectedPanel );
			if ( metaEl ) {
				metaEl.textContent = data.last_sync_at
					? ( i18n.connected_meta || 'Connected. Last synced: %s' ).replace( '%s', data.last_sync_at )
					: ( i18n.never_synced || 'Connected. No activities synced yet.' );
			}
			loadActivities();
			return;
		}

		show( connectPanel );
		hide( connectedPanel );
		if ( data && data.configured === false && statusEl ) {
			statusEl.textContent = i18n.not_configured || 'Strava is not set up for this academy yet.';
			if ( connectBtn ) {
				connectBtn.disabled = true;
			}
		}
	}

	function loadStatus() {
		call( 'players/' + playerId + '/strava/status' ).then( function ( r ) {
			if ( r.ok && r.json && r.json.success ) {
				renderStatus( r.json.data );
			} else {
				if ( statusEl ) {
					statusEl.textContent = i18n.error || 'Something went wrong.';
				}
			}
		} ).catch( function () {
			if ( statusEl ) {
				statusEl.textContent = i18n.error || 'Something went wrong.';
			}
		} );
	}

	function fmtDistance( m ) {
		if ( m === null || m === undefined ) {
			return '';
		}
		return ( m / 1000 ).toFixed( 1 ) + ' ' + ( i18n.km || 'km' );
	}

	function fmtDuration( s ) {
		if ( s === null || s === undefined ) {
			return '';
		}
		return Math.round( s / 60 ) + ' ' + ( i18n.min || 'min' );
	}

	function renderActivities( rows ) {
		if ( ! listEl ) {
			return;
		}
		listEl.textContent = '';
		if ( ! rows || ! rows.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'tt-strava__empty';
			empty.textContent = i18n.no_activities || 'No activities imported yet.';
			listEl.appendChild( empty );
			return;
		}
		rows.forEach( function ( a ) {
			var li = document.createElement( 'li' );
			li.className = 'tt-strava__activity';

			var name = document.createElement( 'span' );
			name.className = 'tt-strava__activity-name';
			name.textContent = a.name || a.activity_type || '';

			var meta = document.createElement( 'span' );
			meta.className = 'tt-strava__activity-meta';
			var parts = [];
			if ( a.started_at ) {
				parts.push( a.started_at.substring( 0, 10 ) );
			}
			if ( a.distance_m ) {
				parts.push( fmtDistance( a.distance_m ) );
			}
			if ( a.moving_time_s ) {
				parts.push( fmtDuration( a.moving_time_s ) );
			}
			meta.textContent = parts.join( ' · ' );

			li.appendChild( name );
			li.appendChild( meta );
			listEl.appendChild( li );
		} );
	}

	function loadActivities() {
		call( 'players/' + playerId + '/strava/activities' ).then( function ( r ) {
			if ( r.ok && r.json && r.json.success ) {
				renderActivities( r.json.data.activities );
			}
		} ).catch( function () {} );
	}

	if ( consentBox && connectBtn ) {
		consentBox.addEventListener( 'change', function () {
			connectBtn.disabled = ! consentBox.checked;
		} );
	}

	if ( connectBtn ) {
		connectBtn.addEventListener( 'click', function () {
			if ( consentBox && ! consentBox.checked ) {
				return;
			}
			connectBtn.disabled = true;
			setMsg( i18n.connecting || 'Connecting…' );
			call( 'players/' + playerId + '/strava/connect', 'POST', { consent: true } ).then( function ( r ) {
				if ( r.ok && r.json && r.json.success && r.json.data.authorize_url ) {
					window.location.href = r.json.data.authorize_url;
				} else {
					setMsg( i18n.error || 'Something went wrong.' );
					connectBtn.disabled = false;
				}
			} ).catch( function () {
				setMsg( i18n.error || 'Something went wrong.' );
				connectBtn.disabled = false;
			} );
		} );
	}

	if ( disconnectBtn ) {
		disconnectBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirm_disc || 'Disconnect this Strava account?' ) ) {
				return;
			}
			disconnectBtn.disabled = true;
			setMsg( i18n.disconnecting || 'Disconnecting…' );
			call( 'players/' + playerId + '/strava/connect', 'DELETE' ).then( function ( r ) {
				disconnectBtn.disabled = false;
				if ( r.ok && r.json && r.json.success ) {
					setMsg( '' );
					renderActivities( [] );
					loadStatus();
				} else {
					setMsg( i18n.error || 'Something went wrong.' );
				}
			} ).catch( function () {
				disconnectBtn.disabled = false;
				setMsg( i18n.error || 'Something went wrong.' );
			} );
		} );
	}

	loadStatus();
} )();
