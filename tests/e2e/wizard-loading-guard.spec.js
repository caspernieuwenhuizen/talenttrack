// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * The wizard's Next loading guard from the enqueued wizard.js (#4747).
 *
 * The guard moved out of an inline script; this checks it still behaves as
 * before in new-evaluation: a forward post the required-field check lets
 * through locks Next and the hidden default button and shows the loading
 * label; one it cancels leaves them as they were. new-player's guard is
 * covered by wizard-enter-next.spec.js; new-report isn't available on the
 * E2E install. The run is cancelled at the end, so nothing is created.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe.configure( { timeout: 90000 } );

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

test( 'new-evaluation: the guard locks both forward buttons exactly when the post goes ahead', async ( { page } ) => {
	await page.goto( '/?tt_view=wizard&slug=new-evaluation&restart=1' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	test.skip( ( await page.locator( 'button[name="mode"]' ).count() ) === 0, 'the mode step is not shown on this install' );

	expect( await page.evaluate( () => !! document.querySelector( 'script[src*="assets/js/wizard.js"]' ) ), 'wizard.js is enqueued' ).toBe( true );

	await submitWith( page, 'button[name="mode"][value="player"]' );
	await expect( page.locator( '[data-tt-wizard-next]' ) ).toBeVisible();

	// Record the buttons a little after the guard's tick, and whether the
	// post went ahead; kept across the navigation if there is one.
	await page.evaluate( () => {
		const form = /** @type {HTMLFormElement} */ ( document.querySelector( 'form.tt-wizard-form' ) );
		form.addEventListener( 'submit', ( e ) => {
			setTimeout( () => {
				const n = /** @type {HTMLButtonElement} */ ( form.querySelector( '[data-tt-wizard-next]' ) );
				const d = /** @type {HTMLButtonElement} */ ( form.querySelector( '[data-tt-wizard-default]' ) );
				sessionStorage.setItem( 'tt-e2e-guard', JSON.stringify( {
					prevented: e.defaultPrevented,
					next: n.disabled,
					fallback: d.disabled,
					label: n.textContent,
					loading: n.getAttribute( 'data-loading-label' ),
				} ) );
			}, 20 );
		} );
	} );
	await page.locator( '[data-tt-wizard-next]' ).evaluate( ( b ) => {
		const btn = /** @type {HTMLButtonElement} */ ( b );
		if ( btn.form ) btn.form.requestSubmit( btn );
	} );
	await page.waitForTimeout( 500 );
	await page.waitForLoadState( 'load', { timeout: 30000 } );

	const guard = JSON.parse( ( await page.evaluate( () => sessionStorage.getItem( 'tt-e2e-guard' ) ) ) || '{}' );
	expect( typeof guard.prevented, 'the submit was seen' ).toBe( 'boolean' );
	expect( guard.next, 'Next is locked exactly when the post goes ahead' ).toBe( ! guard.prevented );
	expect( guard.fallback, 'so is the default button' ).toBe( ! guard.prevented );
	if ( ! guard.prevented ) {
		expect( guard.label, 'Next shows its loading label' ).toBe( guard.loading );
	}

	await page.goto( '/?tt_view=wizard&slug=new-evaluation' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	if ( ( await page.locator( '.tt-wizard-actions [data-role="cancel"]' ).count() ) > 0 ) {
		await submitWith( page, '.tt-wizard-actions [data-role="cancel"]' );
	}
} );
