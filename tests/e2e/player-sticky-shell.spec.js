// @ts-check
const { test, expect } = require( '@playwright/test' );
const { uniqueName } = require( './helpers/admin' );
const { underAppShell, rest } = require( './helpers/shell' );

/**
 * The player profile's pinned strip under the app shell (#5106).
 *
 * The shell header is sticky, and so is the profile's identity strip. Once
 * both were stuck the strip sat at the top of the viewport, under the header,
 * which covered the player's name: the one thing the strip is there to keep
 * in view. This opens a player profile under the app shell, scrolls past the
 * hero and asks the browser what is painted on the name.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const VIEWPORTS = [
	{ width: 360, height: 640 },
	{ width: 1280, height: 800 },
];

test( 'the pinned strip on a player profile stays below the app shell header', async ( { page } ) => {
	await page.goto( '/?tt_view=players' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const hasConfig = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	test.skip( ! hasConfig, 'Dashboard shell not present on this install.' );

	const player = await rest( page, 'POST', 'players', {
		first_name: 'Pinned',
		last_name: uniqueName( 'E2E' ),
		status: 'active',
	} );
	const playerId = player.json && player.json.data ? Number( player.json.data.id ) : 0;
	expect( playerId, 'a player to open' ).toBeGreaterThan( 0 );

	try {
		await underAppShell( page, async () => {
			for ( const viewport of VIEWPORTS ) {
				await page.setViewportSize( viewport );
				await page.goto( '/?tt_view=players&id=' + playerId );
				await page.waitForLoadState( 'load', { timeout: 30000 } );

				await expect( page.locator( '.tt-dashboard.tt-shell-app' ), 'the app shell renders' ).toHaveCount( 1 );
				await expect( page.locator( '.tt-player-sticky' ) ).toHaveCount( 1 );

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
		} );
	} finally {
		try {
			await page.setViewportSize( { width: 1280, height: 800 } );
			await page.goto( '/?tt_view=players' );
			await rest( page, 'DELETE', 'players/' + playerId );
		} catch ( _e ) {
			// The player is test data under a unique name; a failed archive
			// leaves a row nothing else reads.
		}
	}
} );
