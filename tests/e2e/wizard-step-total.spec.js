// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * The wizard step total never grows while you move forward (#4744).
 *
 * The evaluation wizard used to count only the mode step before a branch was
 * picked, so the total went up once "Evaluate an activity" or "Evaluate 1
 * player" was chosen. Both branches now count until one is picked, and the
 * total can only go down. `new-report` is covered by PHPUnit, because it is
 * not available on the E2E install. Each run is cancelled, so nothing is
 * created.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe.configure( { timeout: 90000 } );

/**
 * "Step X of Y" from the rail toggle, as [X, Y].
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<[number, number]>}
 */
async function counter( page ) {
	// textContent, not innerText: the toggle is hidden on a desktop viewport.
	const text = ( await page.locator( '.tt-wizard-rail-toggle-meta' ).first().textContent() ) || '';
	const m = /(\d+)\D+(\d+)/.exec( text );
	expect( m, 'a step counter is rendered' ).not.toBeNull();
	return [ Number( m ? m[1] : 0 ), Number( m ? m[2] : 0 ) ];
}

/**
 * Submit through the form rather than clicking: after a step POST the page
 * arrives through a cross-document view transition, and a click() then
 * waits forever in Playwright's stability check.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function submitWith( page, selector ) {
	const button = page.locator( selector ).first();
	await Promise.all( [
		page.waitForNavigation( { timeout: 30000 } ),
		button.evaluate( ( b ) => { const btn = /** @type {HTMLButtonElement} */ ( b ); if ( btn.form ) btn.form.requestSubmit( btn ); } ),
	] );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
}

for ( const mode of [ 'activity', 'player' ] ) {
	test( `new-evaluation: picking the ${ mode } branch never raises the total`, async ( { page } ) => {
		await page.goto( '/?tt_view=wizard&slug=new-evaluation&restart=1' );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		test.skip( ( await page.locator( 'button[name="mode"]' ).count() ) === 0, 'the mode step is not shown on this install' );

		const [ , before ] = await counter( page );
		await submitWith( page, 'button[name="mode"][value="' + mode + '"]' );

		expect( page.url(), 'still in the wizard' ).toContain( 'tt_view=wizard' );
		const [ position, after ] = await counter( page );
		expect( after, 'the total did not grow' ).toBeLessThanOrEqual( before );
		expect( position ).toBeLessThanOrEqual( after );

		await submitWith( page, '.tt-wizard-actions [data-role="cancel"]' );
	} );
}
