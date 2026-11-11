// @ts-check
/**
 * Helpers for the PDP talk specs (#5079, #5080, #5081): open the fixture
 * talk of `Mila Trainer4980` (`tests/e2e/setup/pdp-preparation.php`), and run
 * a block under the app shell, putting the club's shell back afterwards.
 */

const { expect } = require( '@playwright/test' );

/**
 * A REST call with the dashboard's nonce.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @param {string} [method]
 * @param {object} [body]
 */
function rest( page, path, method = 'GET', body = undefined ) {
	return page.evaluate( async ( { p, m, b } ) => {
		const res = await fetch( window.TT.rest_url + p, {
			method: m,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.TT.rest_nonce, 'Content-Type': 'application/json' },
			body: b === undefined ? undefined : JSON.stringify( b ),
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, { p: path, m: method, b: body } );
}

/** @param {import('@playwright/test').Page} page @param {string} path */
function get( page, path ) {
	return rest( page, path );
}

/** The rows of a list answer, whichever envelope it uses. */
function rowsOf( json ) {
	const data = json && json.data !== undefined ? json.data : json;
	if ( Array.isArray( data ) ) return data;
	if ( data && Array.isArray( data.rows ) ) return data.rows;
	if ( data && Array.isArray( data.items ) ) return data.items;
	return [];
}

/**
 * Open the fixture talk's conversation screen.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<{ fileId: number, convId: number }>}
 */
async function openTalk( page ) {
	await page.goto( '/?tt_view=pdp' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const files = await get( page, 'pdp-files?search=Trainer4980' );
	const file  = rowsOf( files.json ).find( ( r ) => r && r.id );
	expect( file, 'the fixture file: run tests/e2e/setup/pdp-preparation.php first. ' + JSON.stringify( files.json ) ).toBeTruthy();
	const detail = await get( page, 'pdp-files/' + file.id );
	const convs  = ( detail.json && detail.json.data && detail.json.data.conversations ) || [];
	const conv   = convs.find( ( c ) => Number( c.sequence ) === 1 ) || convs[ 0 ];
	expect( conv && conv.id, 'the first talk' ).toBeTruthy();
	await page.goto( `/?tt_view=pdp&id=${ file.id }&conv=${ conv.id }` );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return { fileId: Number( file.id ), convId: Number( conv.id ) };
}

/**
 * Run `fn` with the club's shell set to `app`, then put the earlier value
 * back, also when `fn` fails, so later specs see the shell they expect.
 *
 * @param {import('@playwright/test').Page} page
 * @param {() => Promise<void>} fn
 */
async function underAppShell( page, fn ) {
	await page.goto( '/?tt_view=pdp' );
	const before = await get( page, 'config' );
	const was    = ( before.json && before.json.data && before.json.data.tt_frontend_shell ) || 'classic';
	const set    = await rest( page, 'config', 'POST', { config: { tt_frontend_shell: 'app' } } );
	expect( set.status, 'the shell switch: ' + JSON.stringify( set.json ) ).toBe( 200 );
	try {
		await fn();
	} finally {
		await page.goto( '/?tt_view=pdp' );
		await rest( page, 'config', 'POST', { config: { tt_frontend_shell: was } } );
	}
}

/**
 * True when the topmost element at the centre of `locator` is inside
 * `selector`: nothing stuck covers it.
 *
 * @param {import('@playwright/test').Locator} locator
 * @param {string} selector
 */
function onTop( locator, selector ) {
	return locator.evaluate( ( el, sel ) => {
		const r   = el.getBoundingClientRect();
		const hit = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
		return !! ( hit && hit.closest( sel ) && ( hit === el || el.contains( hit ) || hit.contains( el ) ) );
	}, selector );
}

module.exports = { rest, get, rowsOf, openTalk, underAppShell, onTop };
