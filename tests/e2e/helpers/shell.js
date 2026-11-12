// @ts-check
const { expect } = require( '@playwright/test' );

/**
 * Running a spec under the app shell (#5106).
 *
 * The shell is a club-wide setting (`tt_frontend_shell`, read through
 * `ShellPreference`), so a spec that needs the app shell switches it for the
 * whole install. That is only safe because specs run one at a time
 * (`workers: 1` in `playwright.config.js`). If the suite moves to parallel
 * workers, this is the one place a lock or a per-user override goes.
 *
 * The restore is what keeps the switch from reaching later specs, so it runs
 * whatever happened inside the callback, and its status is asserted.
 */

/** A dashboard page that carries the REST config. */
const LANDING = '/?tt_view=players';

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

/**
 * Does the page in front of us carry the REST config? False on a page that
 * failed to load or that is not a dashboard page.
 *
 * @param {import('@playwright/test').Page} page
 */
async function hasRestConfig( page ) {
	try {
		return await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	} catch ( _e ) {
		return false;
	}
}

/**
 * Write the club's shell setting, from the current page when it can, from
 * the landing page otherwise.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} shell
 * @returns {Promise<{status:number, json:any}>}
 */
async function writeShell( page, shell ) {
	if ( ! await hasRestConfig( page ) ) {
		try {
			await page.goto( LANDING );
			await page.waitForLoadState( 'load', { timeout: 30000 } );
		} catch ( _e ) {
			// Asserted below: without the config there is nothing to write with.
		}
	}
	if ( ! await hasRestConfig( page ) ) return { status: 0, json: null };
	return rest( page, 'POST', 'config', { config: { tt_frontend_shell: shell } } );
}

/**
 * Run `fn` with the club's shell switched to `app`, and put the previous
 * setting back afterwards, whether `fn` passed, failed or threw.
 *
 * @param {import('@playwright/test').Page} page
 * @param {() => Promise<void>} fn
 */
async function underAppShell( page, fn ) {
	if ( ! await hasRestConfig( page ) ) {
		await page.goto( LANDING );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
	}
	expect( await hasRestConfig( page ), 'a dashboard page with the REST config' ).toBe( true );

	const before = await rest( page, 'GET', 'config' );
	expect( before.status, 'the shell setting is read' ).toBe( 200 );
	const stored = before.json && before.json.data ? before.json.data.tt_frontend_shell : '';
	// An unset key resolves to `classic` (`ShellPreference::clubDefault()`).
	const was = typeof stored === 'string' && stored !== '' ? stored : 'classic';

	const set = await writeShell( page, 'app' );
	expect( set.status, 'the shell switch: ' + JSON.stringify( set.json ) ).toBe( 200 );

	try {
		await fn();
	} finally {
		const restored = await writeShell( page, was );
		expect( restored.status, 'the shell setting is put back to ' + was + ': ' + JSON.stringify( restored.json ) ).toBe( 200 );
	}
}

module.exports = { underAppShell, rest };
