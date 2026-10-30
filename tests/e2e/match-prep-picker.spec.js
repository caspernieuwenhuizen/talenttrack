// @ts-check
const { test, expect } = require( '@playwright/test' );
const { uniqueName } = require( './helpers/admin' );

/**
 * Match prep player picker on a scrolled page (#4263).
 *
 * The picker is `position: fixed`, but `openPicker()` added the scroll
 * offset to its coordinates, so on a scrolled page it opened below the fold
 * behind a backdrop that blocked everything else. This walks the reported
 * path: scroll to the bottom, open a set-piece role, check the picker lies
 * inside the viewport, pick a player, reload, and check the role kept him.
 *
 * The wp-env baseline seeds no squad, so the spec builds one over REST
 * (team, player, match, prep with the player Present) with the dashboard's
 * own nonce. It skips rather than fails when the dashboard shell is absent
 * or the install's plan refuses match prep (402).
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

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
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{activityId:number, playerId:number}|string>} ids, or why it skipped
 */
async function seedMatch( page ) {
	await page.goto( '/?tt_view=activities' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const hasConfig = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	if ( ! hasConfig ) return 'Dashboard shell not present on this install.';

	const team = await rest( page, 'POST', 'teams', { name: uniqueName( 'E2E picker team' ) } );
	const teamId = team.json && team.json.data ? Number( team.json.data.id ) : 0;
	if ( ! teamId ) return 'Team create refused: ' + team.status;

	const player = await rest( page, 'POST', 'players', {
		first_name: 'Picker',
		last_name: uniqueName( 'E2E' ),
		team_id: teamId,
		status: 'active',
	} );
	const playerId = player.json && player.json.data ? Number( player.json.data.id ) : 0;
	if ( ! playerId ) return 'Player create refused: ' + player.status;

	const activity = await rest( page, 'POST', 'activities', {
		title: uniqueName( 'E2E picker match' ),
		session_date: '2026-12-12',
		team_id: teamId,
		activity_type_key: 'game',
	} );
	const activityId = activity.json && activity.json.data ? Number( activity.json.data.id ) : 0;
	if ( ! activityId ) return 'Activity create refused: ' + activity.status;

	const availability = {};
	availability[ playerId ] = { status: 'Present', reason: '' };
	const prep = await rest( page, 'PUT', 'match-prep/' + activityId, { availability } );
	if ( prep.status === 402 ) return 'Match prep is not on this install\'s plan.';
	if ( prep.status !== 200 ) return 'Match prep save refused: ' + prep.status;

	return { activityId, playerId };
}

test.describe( 'Match prep player picker (#4263)', () => {

	test( 'opens inside the viewport on a scrolled page and saves the role', async ( { page } ) => {
		await page.setViewportSize( { width: 1366, height: 768 } );
		const seeded = await seedMatch( page );
		if ( typeof seeded === 'string' ) {
			test.skip( true, seeded );
			return;
		}
		const { activityId, playerId } = seeded;
		const prepUrl = '/?tt_view=match-prep&activity_id=' + activityId;

		await page.goto( prepUrl );
		await page.waitForLoadState( 'load', { timeout: 30000 } );

		const role = page.locator( 'li[data-tt-mp-role]' ).first();
		if ( ( await role.count() ) === 0 ) {
			test.skip( true, 'No set-piece roles on this install.' );
			return;
		}
		const roleKey = await role.getAttribute( 'data-tt-mp-role' );

		await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
		await role.click();

		const picker = page.locator( '[data-tt-mp-picker]' );
		await expect( picker ).toBeVisible();
		const box = await picker.boundingBox();
		expect( box ).not.toBeNull();
		if ( box ) {
			expect( box.x ).toBeGreaterThanOrEqual( 0 );
			expect( box.y ).toBeGreaterThanOrEqual( 0 );
			expect( box.x + box.width ).toBeLessThanOrEqual( 1366 );
			expect( box.y + box.height ).toBeLessThanOrEqual( 768 );
		}

		const saved = page.waitForResponse(
			( res ) => res.url().includes( '/role' ) && res.request().method() === 'PUT',
			{ timeout: 15000 }
		);
		await picker.locator( '.tt-mp-picker-row[data-pid="' + playerId + '"]' ).click();
		expect( ( await saved ).status() ).toBe( 200 );
		await expect( picker ).toBeHidden();

		await page.goto( prepUrl );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		await expect( page.locator( 'li[data-tt-mp-role="' + roleKey + '"]' ) ).toHaveAttribute( 'data-pid', String( playerId ) );
	} );
} );
