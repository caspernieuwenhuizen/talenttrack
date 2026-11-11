// @ts-check
/**
 * #4981 — the player and the parent read a signed PDP conversation form and
 * confirm it, on a phone. The talk is `Jip Reader4981`'s, signed off by
 * `tests/e2e/setup/pdp-preparation.php`; the player did not prepare.
 */
const { test, expect } = require( '@playwright/test' );

test.use( { viewport: { width: 360, height: 800 }, hasTouch: true, isMobile: true } );
test.describe.configure( { mode: 'serial' } );

/**
 * Sign someone in, in a context of their own, and open My PDP.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {string} login
 * @param {object} [opts] context options; a phone under a finger by default
 */
async function signedIn( browser, baseURL, login, opts = { viewport: { width: 360, height: 800 }, hasTouch: true, isMobile: true } ) {
	const context = await browser.newContext( { baseURL, ...opts } );
	const page    = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.fill( 'input[name="log"]', login );
	await page.fill( 'input[name="pwd"]', 'password' );
	await Promise.all( [
		page.waitForLoadState( 'load', { timeout: 30000 } ),
		page.click( 'input#wp-submit' ),
	] );
	const ok = ( await context.cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in' ) );
	expect( ok, `${ login } signs in: run tests/e2e/setup/pdp-preparation.php first` ).toBe( true );
	await page.goto( '/?tt_view=my-pdp' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return { context, page };
}

/** Open the talk's panel from the timeline, and return the signed form. */
async function openTalk( page, touch = true ) {
	const node = page.locator( '[aria-controls^="tt-pdp-panel-"]' ).first();
	await expect( node, 'the talk is on the timeline' ).toBeVisible();
	if ( ( await node.getAttribute( 'aria-expanded' ) ) !== 'true' ) await ( touch ? node.tap() : node.click() );
	const form = page.locator( '.tt-pdp-panel:not([hidden]) .tt-pdp-formread' );
	await expect( form, 'the signed form shows in the panel' ).toBeVisible();
	return form;
}

test( 'the signed-off message lands the player on the talk, at its signed form, with the confirm in reach', async ( { browser, baseURL } ) => {
	const { context, page } = await signedIn( browser, baseURL, 'e2e_pop_read' );
	try {
		// The notice at the top carries the same link the message does.
		const notice = page.locator( '.tt-pdp-awaiting' );
		await expect( notice, 'the notice says a signed form waits' ).toBeVisible();
		const href = String( await notice.locator( 'a' ).first().getAttribute( 'href' ) );
		expect( href ).toMatch( /[?&]conv=\d+.*#tt-pdp-read-\d+$/ );

		await page.goto( href );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		const id      = href.split( '#' )[ 1 ];
		const heading = page.locator( '#' + id );
		await expect( heading, 'the talk is open at its signed form' ).toBeVisible();
		await expect( heading, 'and the heading has focus' ).toBeFocused();

		const confirm = page.locator( '.tt-pdp-panel:not([hidden])' ).getByRole( 'button', { name: 'I have read it' } );
		await expect( confirm ).toBeVisible();
	} finally {
		await context.close();
	}
} );

// #4982 — one Print link shows: the PDF under a finger, the print page under
// a mouse. Both are `.tt-btn`, so a print rule the button rule outranks shows
// the two at once. The mouse case says `hasTouch: false` itself: the file's
// `test.use()` is the default for every context the test opens.
for ( const device of [
	{ name: 'a phone under a finger', touch: true, shows: 'tt-pdp-printact__pdf', opts: { viewport: { width: 360, height: 800 }, hasTouch: true, isMobile: true } },
	{ name: 'a computer with a mouse', touch: false, shows: 'tt-pdp-printact__page', opts: { viewport: { width: 1280, height: 900 }, hasTouch: false, isMobile: false } },
] ) {
	test( `the signed form offers exactly one Print link on ${ device.name }`, async ( { browser, baseURL } ) => {
		const { context, page } = await signedIn( browser, baseURL, 'e2e_pop_read', device.opts );
		try {
			const form    = await openTalk( page, device.touch );
			const visible = form.locator( '.tt-pdp-printact a:visible' );
			await expect( visible, 'one Print link' ).toHaveCount( 1 );
			await expect( visible ).toHaveClass( new RegExp( device.shows ) );
			const box = await visible.boundingBox();
			const min = device.touch ? 48 : 36;
			expect( box && box.height >= min, `the link is a ${ min }px target` ).toBe( true );
		} finally {
			await context.close();
		}
	} );
}

for ( const who of [ 'e2e_pop_read', 'e2e_pop_read_parent' ] ) {
	test( `${ who } reads every item with both scores, opens the folds by keyboard, and confirms`, async ( { browser, baseURL } ) => {
		const { context, page } = await signedIn( browser, baseURL, who );
		try {
			const form  = await openTalk( page );
			const folds = form.locator( 'details.tt-pdp-formread__section' );
			const count = await folds.count();
			expect( count, 'a fold per section' ).toBeGreaterThan( 1 );

			// The folds open from the keyboard: Tab to the summary, Enter.
			const second = folds.nth( 1 );
			await expect( second ).not.toHaveAttribute( 'open', '' );
			await second.locator( 'summary' ).focus();
			await page.keyboard.press( 'Enter' );
			await expect( second ).toHaveAttribute( 'open', '' );
			const sumBox = await second.locator( 'summary' ).boundingBox();
			expect( sumBox && sumBox.height >= 48, 'a fold title is a 48px target' ).toBe( true );

			for ( let i = 0; i < count; i++ ) {
				const fold = folds.nth( i );
				if ( ! ( await fold.evaluate( ( el ) => el.open ) ) ) await fold.locator( 'summary' ).tap();
			}
			const items = form.locator( '.tt-pdp-formread__item' );
			expect( await items.count(), 'every item of the outfield form' ).toBe( 17 );
			await expect( items.first().locator( '.tt-pdp-formread__player' ), 'the player did not prepare' ).toContainText( 'not filled in' );
			await expect( items.first().locator( '.tt-pdp-formread__coach' ) ).toContainText( '3' );
			await expect( form.locator( '.tt-pdp-formread__goal' ) ).toHaveCount( 2 );

			const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
			expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );

			const confirm = page.locator( '.tt-pdp-panel:not([hidden])' ).getByRole( 'button', { name: 'I have read it' } );
			const box     = await confirm.boundingBox();
			expect( box && box.height >= 48 && box.width >= 48, 'the confirm is a 48px target' ).toBe( true );
			await Promise.all( [
				page.waitForResponse( ( r ) => /pdp-conversations\/\d+$/.test( r.url().split( '?' )[ 0 ] ) && r.request().method() === 'PATCH' && r.status() === 200, { timeout: 30000 } ),
				confirm.tap(),
			] );
			// The form reloads the page after it saves.
			await expect( page.locator( '.tt-pdp-acked' ), 'recorded' ).toHaveCount( 1, { timeout: 30000 } );
			await page.waitForLoadState( 'load', { timeout: 30000 } );
			await openTalk( page );
			await expect( page.locator( '.tt-pdp-panel:not([hidden])' ), 'the date it was confirmed' ).toContainText( 'You confirmed you read it on' );
			await expect( page.locator( '.tt-pdp-awaiting' ), 'the notice is gone once confirmed' ).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );
}
