// @ts-check
const { test, expect } = require( '@playwright/test' );
const { uniqueName } = require( './helpers/admin' );

/**
 * The player profile's pinned strip under the app shell (#5106).
 *
 * The shell header is sticky, and so is the profile's identity strip. Once
 * both were stuck the strip sat at the top of the viewport, under the header,
 * which covered the player's name: the one thing the strip is there to keep
 * in view. This turns the app shell on for the run, opens a player profile,
 * scrolls past the hero and asks the browser what is painted on the name.
 *
 * Specs run one at a time (`workers: 1`), so switching the club's shell here
 * cannot reach another spec; the previous value is put back afterwards.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} method
 * @param {string} path
 * @param {object} [body]
 * @returns {Promise<{status:number, json:any}>}
 */
function rest( page, method, path, body ) {
	return page.evaluate( async ( { method, path, body } ) => {
		const cfg = window.TT || {};
		const res = await fetch( cfg.rest_url + path, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, { method, path, body } );
}

const VIEWPORTS = [
	{ width: 360, height: 640 },
	{ width: 1280, height: 800 },
];

test( 'the pinned strip on a player profile stays below the app shell header', async ( { page } ) => {
	await page.goto( '/?tt_view=players' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const hasConfig = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	test.skip( ! hasConfig, 'Dashboard shell not present on this install.' );

	const before = await rest( page, 'GET', 'config' );
	const config = before.json && before.json.data ? before.json.data : ( before.json || {} );
	const previousShell = typeof config.tt_frontend_shell === 'string' ? config.tt_frontend_shell : '';

	let playerId = 0;
	try {
		const set = await rest( page, 'POST', 'config', { config: { tt_frontend_shell: 'app' } } );
		expect( set.status, 'the shell setting is written' ).toBeLessThan( 300 );

		const player = await rest( page, 'POST', 'players', {
			first_name: 'Pinned',
			last_name: uniqueName( 'E2E' ),
			status: 'active',
		} );
		playerId = player.json && player.json.data ? Number( player.json.data.id ) : 0;
		expect( playerId, 'a player to open' ).toBeGreaterThan( 0 );

		for ( const viewport of VIEWPORTS ) {
			await page.setViewportSize( viewport );
			await page.goto( '/?tt_view=players&id=' + playerId );
			await page.waitForLoadState( 'load', { timeout: 30000 } );

			await expect( page.locator( '.tt-dashboard.tt-shell-app' ), 'the app shell renders' ).toHaveCount( 1 );
			const strip = page.locator( '.tt-player-sticky' );
			await expect( strip ).toHaveCount( 1 );

			// Room to scroll whatever the profile holds, then past the hero,
			// so both the header and the strip are stuck.
			await page.evaluate( () => {
				const dash = /** @type {HTMLElement} */ ( document.querySelector( '.tt-dashboard' ) );
				dash.style.paddingBottom = '3000px';
				const pin = /** @type {HTMLElement} */ ( document.querySelector( '.tt-player-sticky' ) );
				window.scrollTo( 0, pin.getBoundingClientRect().top + window.scrollY + 600 );
			} );
			await page.waitForTimeout( 150 );

			const seen = await page.evaluate( () => {
				const name = /** @type {HTMLElement} */ ( document.querySelector( '.tt-player-sticky__name' ) );
				const header = document.querySelector( '.tt-dashboard.tt-shell-app .tt-dash-header' );
				const bar = document.getElementById( 'wpadminbar' );
				const r = name.getBoundingClientRect();
				const x = r.left + Math.min( 8, r.width / 2 );
				const points = [ r.top + 1, r.top + r.height / 2, r.bottom - 1 ];
				return {
					nameTop: r.top,
					headerBottom: header ? header.getBoundingClientRect().bottom : 0,
					barBottom: bar ? bar.getBoundingClientRect().bottom : 0,
					stuck: Math.round( r.top ) < 600,
					clear: points.every( ( y ) => {
						const hit = document.elementFromPoint( x, y );
						return !! ( hit && hit.closest( '.tt-player-sticky__name' ) );
					} ),
				};
			} );

			const at = viewport.width + 'x' + viewport.height;
			expect( seen.stuck, at + ': the strip is pinned at the top after the scroll' ).toBe( true );
			expect( seen.clear, at + ': the name row is painted on top, not covered' ).toBe( true );
			expect( seen.nameTop, at + ': below the shell header' ).toBeGreaterThanOrEqual( seen.headerBottom - 0.5 );
			expect( seen.nameTop, at + ': below the admin bar' ).toBeGreaterThanOrEqual( seen.barBottom - 0.5 );
		}
	} finally {
		await page.setViewportSize( { width: 1280, height: 800 } );
		await page.goto( '/?tt_view=players' );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		await rest( page, 'POST', 'config', { config: { tt_frontend_shell: previousShell !== '' ? previousShell : 'classic' } } );
		if ( playerId > 0 ) await rest( page, 'DELETE', 'players/' + playerId );
	}
} );
