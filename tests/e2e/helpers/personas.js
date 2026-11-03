// @ts-check
/**
 * Short-lived persona accounts for the mobile gate (#4812).
 *
 * The CI instance has one seeded account, `admin`. The gate also has to see
 * what a head coach and a scout see, so it makes those accounts itself over
 * WordPress's own REST users route, with the dashboard's nonce, signs each
 * in once to save its storage state, and deletes them afterwards. The same
 * pattern as the gate's record pages: nothing depends on seed data or on
 * another spec's leftovers.
 *
 * A `tt_coach` account with no team assignment resolves to the head coach
 * persona (`PersonaResolver::resolveCoachPersona()`), which is the one the
 * coach dashboard preset is built for. Each account is set to Dutch, the
 * language the gate walks in (#4770).
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { uniqueName } = require( './admin' );

const AUTH_DIR = path.join( __dirname, '..', '.auth' );

/**
 * The personas the gate walks besides the admin. `role` is the WordPress
 * role the account is made with; `key` names the persona in the baseline and
 * must equal the `data-tt-pd-persona` its dashboard home renders.
 * `minSurfaces` is the fewest phone-reachable surfaces its home may link to:
 * the walk is decided by those links, so a capability or tile regression
 * that drops them would otherwise shrink the walk and stay green.
 *
 * @type {{ key: string, role: string, minSurfaces: number }[]}
 */
const WALKED_PERSONAS = [
	{ key: 'head_coach', role: 'tt_coach', minSurfaces: 6 },
	{ key: 'scout', role: 'tt_scout', minSurfaces: 5 },
];

/**
 * Call a WordPress core REST route (`wp/v2/...`) with the dashboard's nonce.
 *
 * @param {import('@playwright/test').Page} page a page on the dashboard
 * @param {string} method
 * @param {string} route  e.g. `users` or `users/12?force=true&reassign=1`
 * @param {object|null} body
 * @returns {Promise<{status:number, json:any}>}
 */
function coreRest( page, method, route, body ) {
	return page.evaluate( async ( { method, route, body } ) => {
		const cfg = window.TT || {};
		const base = String( cfg.rest_url || '' ).replace( /talenttrack\/v1\/?$/, 'wp/v2/' );
		const res = await fetch( base + route, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, { method, route, body } );
}

/**
 * Make a persona account, sign it in once and save its storage state.
 *
 * Returns `{ id, state }` when the account can be walked with. Once the
 * account exists its id is always returned, also when signing in fails
 * (`{ id, error }`), so the caller can delete it either way. Without an
 * account the result is `{ id: 0, error }`.
 *
 * The storage state file carries the project name, so two phone projects
 * running in parallel would not walk as each other's accounts.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {{ key: string, role: string }} persona
 * @param {string} project
 * @returns {Promise<{ id: number, state?: string, error?: string }>}
 */
async function createPersona( browser, baseURL, persona, project ) {
	const admin = await browser.newContext( { storageState: 'tests/e2e/.auth/admin.json', baseURL } );
	const page = await admin.newPage();
	await page.goto( '/?tt_view=players' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	if ( ! ( await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) ) ) ) {
		await admin.close();
		return { id: 0, error: 'the dashboard shell carried no REST config' };
	}

	const login = uniqueName( 'e2e-' + persona.key ).replace( /[^a-z0-9-]/g, '' );
	const password = 'Pw-' + uniqueName( 'gate' );
	const res = await coreRest( page, 'POST', 'users', {
		username: login,
		email: login + '@example.test',
		password,
		roles: [ persona.role ],
		locale: 'nl_NL',
		name: 'E2E ' + persona.key,
	} );
	await admin.close();
	const id = res.json && res.json.id ? Number( res.json.id ) : 0;
	if ( ! id ) {
		return { id: 0, error: `${ persona.role } account refused: HTTP ${ res.status } ${ res.json && res.json.code ? res.json.code : '' }`.trim() };
	}

	// From here on the account exists: every way out carries its id.
	const context = await browser.newContext( { baseURL } );
	try {
		const login_page = await context.newPage();
		await login_page.goto( '/wp-login.php' );
		await login_page.fill( 'input[name="log"]', login );
		await login_page.fill( 'input[name="pwd"]', password );
		await Promise.all( [
			login_page.waitForLoadState( 'load', { timeout: 30000 } ),
			login_page.click( 'input#wp-submit' ),
		] );
		const signed_in = ( await context.cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in' ) );
		if ( ! signed_in ) return { id, error: `${ persona.role } account made (id ${ id }) but could not sign in` };

		if ( ! fs.existsSync( AUTH_DIR ) ) fs.mkdirSync( AUTH_DIR, { recursive: true } );
		const state = path.join( AUTH_DIR, `${ persona.key }-${ project }.json` );
		await context.storageState( { path: state } );
		return { id, state };
	} catch ( e ) {
		return { id, error: `${ persona.role } account made (id ${ id }) but signing in failed: ${ e instanceof Error ? e.message : String( e ) }` };
	} finally {
		await context.close();
	}
}

/**
 * Delete a persona account made by `createPersona()`. Its content, if any,
 * goes to the admin account (user 1 on wp-env).
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {number} id
 */
async function deletePersona( browser, baseURL, id ) {
	const admin = await browser.newContext( { storageState: 'tests/e2e/.auth/admin.json', baseURL } );
	const page = await admin.newPage();
	await page.goto( '/?tt_view=players' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const res = await coreRest( page, 'DELETE', `users/${ id }?force=true&reassign=1`, null );
	await admin.close();
	// Harmless on CI, where the container dies with the job, but against a
	// persistent install a refused delete leaves an account behind.
	if ( res.status !== 200 ) {
		console.log( `\nCould not delete persona account ${ id }: HTTP ${ res.status } ${ res.json && res.json.code ? res.json.code : '' }`.trimEnd() );
	}
}

module.exports = { WALKED_PERSONAS, createPersona, deletePersona, coreRest };
