// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * The shared help link (#4710): 48px under a finger, its desktop size under
 * a mouse, and a visible focus ring for the keyboard.
 *
 * Measured on the new-player wizard, which renders the link through
 * `HelpDrawer::button()`. Skips when the Documentation module is off and
 * the link isn't rendered.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const URL = '/?tt_view=wizard&slug=new-player&restart=1';

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>} false when the help link isn't on the page
 */
async function open( page ) {
	await page.goto( URL );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return ( await page.locator( '.tt-help-button' ).count() ) > 0;
}

test.describe( 'Help link under a finger', () => {
	test.use( { viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true } );

	test( 'is at least 48px high', async ( { page } ) => {
		test.skip( ! ( await open( page ) ), 'help link not rendered on this install' );
		expect( await page.evaluate( () => matchMedia( '(pointer: coarse)' ).matches ) ).toBe( true );

		const box = await page.locator( '.tt-help-button' ).first().boundingBox();
		expect( box && box.height ).toBeGreaterThanOrEqual( 47.5 );
	} );
} );

test.describe( 'Help link under a mouse', () => {
	test.use( { viewport: { width: 1280, height: 900 }, hasTouch: false, isMobile: false } );

	test( 'keeps its desktop height', async ( { page } ) => {
		test.skip( ! ( await open( page ) ), 'help link not rendered on this install' );
		expect( await page.evaluate( () => matchMedia( '(pointer: fine)' ).matches ) ).toBe( true );

		const box = await page.locator( '.tt-help-button' ).first().boundingBox();
		expect( box && box.height ).toBeLessThan( 47.5 );
	} );

	test( 'shows a focus ring when reached with Tab', async ( { page } ) => {
		test.skip( ! ( await open( page ) ), 'help link not rendered on this install' );

		let reached = false;
		for ( let i = 0; i < 80 && ! reached; i++ ) {
			await page.keyboard.press( 'Tab' );
			reached = await page.evaluate( () => {
				const el = document.activeElement;
				return !! ( el && el.classList && el.classList.contains( 'tt-help-button' ) );
			} );
		}
		expect( reached, 'Tab reaches the help link' ).toBe( true );

		const ring = await page.evaluate( () => {
			const el = /** @type {HTMLElement} */ ( document.activeElement );
			const s = getComputedStyle( el );
			return { style: s.outlineStyle, width: parseFloat( s.outlineWidth ) };
		} );
		expect( ring.style ).not.toBe( 'none' );
		expect( ring.width ).toBeGreaterThanOrEqual( 2 );
	} );
} );
