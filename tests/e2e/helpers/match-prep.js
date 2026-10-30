// @ts-check
/**
 * Seed a match with a match prep over REST, for the match-prep specs.
 *
 * The wp-env baseline has no squad, so a spec that needs the prep screen
 * builds one with the dashboard's own nonce (`window.TT`). Every record it
 * makes is pushed onto `created`, so the spec can archive them afterwards
 * and later specs (and the mobile gate walk) do not see them.
 */

const { uniqueName } = require( './admin' );

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} method
 * @param {string} path
 * @param {object} body
 * @returns {Promise<{status:number, json:any}>}
 */
function rest( page, method, path, body ) {
	return page.evaluate( async ( { method, path, body } ) => {
		const cfg = window.TT || {};
		const res = await fetch( cfg.rest_url + path, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
			body: JSON.stringify( body ),
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, { method, path, body } );
}

/**
 * A team of `size` players, a match and a prep with all of them Present.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Array<string>} created REST paths of the records made
 * @param {number} size
 * @returns {Promise<{activityId:number, playerIds:Array<number>}|string>} ids, or why to skip
 */
async function seedMatchPrep( page, created, size ) {
	await page.goto( '/?tt_view=activities' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const hasConfig = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	if ( ! hasConfig ) return 'Dashboard shell not present on this install.';

	const team = await rest( page, 'POST', 'teams', { name: uniqueName( 'E2E prep team' ) } );
	const teamId = team.json && team.json.data ? Number( team.json.data.id ) : 0;
	if ( ! teamId ) return 'Team create refused: ' + team.status;
	created.push( 'teams/' + teamId );

	const playerIds = [];
	for ( let i = 1; i <= size; i++ ) {
		const player = await rest( page, 'POST', 'players', {
			first_name: 'Speler' + i,
			last_name: uniqueName( 'E2E' ),
			team_id: teamId,
			status: 'active',
		} );
		const playerId = player.json && player.json.data ? Number( player.json.data.id ) : 0;
		if ( ! playerId ) return 'Player create refused: ' + player.status;
		created.push( 'players/' + playerId );
		playerIds.push( playerId );
	}

	const activity = await rest( page, 'POST', 'activities', {
		title: uniqueName( 'E2E prep match' ),
		session_date: '2026-12-12',
		team_id: teamId,
		activity_type_key: 'game',
	} );
	const activityId = activity.json && activity.json.data ? Number( activity.json.data.id ) : 0;
	if ( ! activityId ) return 'Activity create refused: ' + activity.status;
	created.push( 'activities/' + activityId );

	const availability = {};
	playerIds.forEach( ( id ) => { availability[ id ] = { status: 'Present', reason: '' }; } );
	const prep = await rest( page, 'PUT', 'match-prep/' + activityId, { availability } );
	if ( prep.status === 402 ) return 'Match prep is not on this install\'s plan.';
	if ( prep.status !== 200 ) return 'Match prep save refused: ' + prep.status;

	return { activityId, playerIds };
}

/**
 * Archive what `seedMatchPrep()` made, newest first.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Array<string>} created
 */
async function archiveSeeded( page, created ) {
	for ( const path of created.slice().reverse() ) {
		await rest( page, 'DELETE', path, {} );
	}
	created.length = 0;
}

module.exports = { rest, seedMatchPrep, archiveSeeded };
