// @ts-check
/**
 * #5079 — the PDP talk names its player and the talk, the tab reads
 * "Background", and the form in use sits in the tab row: on a phone it
 * wraps under the tabs, with a 48px switch and no sideways scroll.
 *
 * The talk is `Mila Trainer4980`'s, from `tests/e2e/setup/pdp-preparation.php`.
 * This spec only reads it; `pdp-trainer-form.spec.js` fills it in later.
 */
const { test, expect } = require( '@playwright/test' );

test.use( {
	storageState: 'tests/e2e/.auth/admin.json',
	viewport: { width: 360, height: 800 },
	hasTouch: true,
	isMobile: true,
} );

/**
 * A REST GET with the dashboard's nonce.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
function get( page, path ) {
	return page.evaluate( async ( p ) => {
		const res = await fetch( window.TT.rest_url + p, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.TT.rest_nonce },
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, path );
}

/** The rows of a list answer, whichever envelope it uses. */
function rowsOf( json ) {
	const data = json && json.data !== undefined ? json.data : json;
	if ( Array.isArray( data ) ) return data;
	if ( data && Array.isArray( data.rows ) ) return data.rows;
	if ( data && Array.isArray( data.items ) ) return data.items;
	return [];
}

test( 'the talk names the player, reads Background, and keeps the form in the tab row', async ( { page } ) => {
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

	// The page is about Mila, and about this talk.
	await expect( page.locator( 'h1.tt-pdp-conv-title' ) ).toHaveText( /Trainer4980/ );
	await expect( page.locator( '.tt-pdp-conv-talkline' ) ).toContainText( 'conversation 1 of' );
	await expect( page.locator( 'nav.tt-breadcrumbs' ).first() ).toContainText( 'Trainer4980' );

	// The tab is called Background, and says what it holds.
	const background = page.getByRole( 'tab', { name: 'Background' } );
	await expect( background ).toBeVisible();
	await expect( page.getByRole( 'tab', { name: 'Evidence' } ) ).toHaveCount( 0 );

	// The form in use sits in the tab row, after the tabs, and at 360px it
	// wraps under them.
	const row     = page.locator( '.tt-spine__row' );
	const tablist = row.locator( '[role="tablist"]' );
	const aside   = row.locator( '.tt-spine__aside' );
	await expect( aside.locator( '.tt-pdp-form-switch__current' ) ).toContainText( 'Form:' );
	const tabsBox  = await tablist.boundingBox();
	const asideBox = await aside.boundingBox();
	expect( tabsBox && asideBox && asideBox.y >= tabsBox.y + tabsBox.height - 1, 'the form wraps under the tabs on a phone' ).toBe( true );

	const switchButton = aside.locator( 'button[type="submit"]' );
	if ( await switchButton.count() ) {
		const box = await switchButton.boundingBox();
		expect( box && box.height >= 48, 'the switch is at least 48px tall' ).toBe( true );
		await expect( switchButton ).toHaveAttribute( 'aria-describedby', /tt-pdp-form-switch-help-/ );
	}
	await expect( page.locator( '.tt-pdp-form-switch' ), 'one form block, in the tab row only' ).toHaveCount( 1 );

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );

	await background.tap();
	await expect( page.locator( '.tt-pdp-background-lede' ) ).toHaveText( 'What is on record about the player this season, to support the talk.' );
} );
