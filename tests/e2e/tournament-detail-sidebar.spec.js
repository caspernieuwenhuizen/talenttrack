// @ts-check
const { test, expect } = require( '@playwright/test' );
const { uniqueName } = require( './helpers/admin' );

/**
 * Tournament detail header vs the desktop minutes sidebar (#4261).
 *
 * From 1024px the minutes ticker is a fixed sidebar on the right. The detail
 * wrapper reserves room for it, but the header used to render outside that
 * wrapper, so the sidebar covered "Add match" and "Edit". This seeds a
 * tournament over REST and checks, at three desktop widths, that the title
 * and every visible header action sit clear of the sidebar.
 *
 * It skips rather than fails when the dashboard shell is absent or the
 * install's plan refuses tournaments (402).
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
 * @param {{x:number, y:number, width:number, height:number}} a
 * @param {{x:number, y:number, width:number, height:number}} b
 * @returns {boolean}
 */
function intersects( a, b ) {
	return a.x < b.x + b.width && b.x < a.x + a.width
		&& a.y < b.y + b.height && b.y < a.y + a.height;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {Array<string>} created REST paths of the records made, archived afterwards
 * @returns {Promise<number|string>} the tournament id, or why it skipped
 */
async function seedTournament( page, created ) {
	await page.goto( '/?tt_view=tournaments' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const hasConfig = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	if ( ! hasConfig ) return 'Dashboard shell not present on this install.';

	const team = await rest( page, 'POST', 'teams', { name: uniqueName( 'E2E tournament team' ) } );
	const teamId = team.json && team.json.data ? Number( team.json.data.id ) : 0;
	if ( ! teamId ) return 'Team create refused: ' + team.status;
	created.push( 'teams/' + teamId );

	const tournament = await rest( page, 'POST', 'tournaments', {
		name: uniqueName( 'E2E sidebar tournament' ),
		team_id: teamId,
		start_date: '2026-12-12',
	} );
	if ( tournament.status === 402 ) return 'Tournaments are not on this install\'s plan.';
	const tournamentId = tournament.json && tournament.json.data ? Number( tournament.json.data.id ) : 0;
	if ( ! tournamentId ) return 'Tournament create refused: ' + tournament.status;
	created.push( 'tournaments/' + tournamentId );

	return tournamentId;
}

test.describe( 'Tournament detail sidebar (#4261)', () => {

	/** @type {Array<string>} */
	let created = [];

	test.afterEach( async ( { page } ) => {
		for ( const path of created.reverse() ) {
			await rest( page, 'DELETE', path, {} );
		}
		created = [];
	} );

	test( 'the header and its actions sit clear of the minutes sidebar', async ( { page } ) => {
		await page.setViewportSize( { width: 1366, height: 768 } );
		const seeded = await seedTournament( page, created );
		if ( typeof seeded === 'string' ) {
			test.skip( true, seeded );
			return;
		}

		for ( const width of [ 1024, 1366, 1920 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( '/?tt_view=tournaments&id=' + seeded );
			await page.waitForLoadState( 'load', { timeout: 30000 } );

			const ticker = page.locator( '.tt-minutes-ticker' );
			await expect( ticker ).toBeVisible();
			const tickerBox = await ticker.boundingBox();
			expect( tickerBox ).not.toBeNull();
			if ( ! tickerBox ) continue;

			const addMatch = page.locator( '.tt-page-actions a[href*="tt_view=tournament-match"]' ).first();
			await expect( addMatch ).toBeVisible();

			const targets = page.locator( '.tt-page-head .tt-fview-title, .tt-page-actions a:visible' );
			const count = await targets.count();
			expect( count ).toBeGreaterThan( 1 );
			for ( let i = 0; i < count; i++ ) {
				const box = await targets.nth( i ).boundingBox();
				if ( ! box ) continue;
				expect( intersects( box, tickerBox ), 'header element ' + i + ' overlaps the sidebar at ' + width + 'px' ).toBe( false );
			}
		}
	} );
} );
