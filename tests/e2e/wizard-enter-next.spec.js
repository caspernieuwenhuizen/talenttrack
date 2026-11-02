// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Enter in a wizard goes to the next step (#4709).
 *
 * Implicit submission (Enter in a text field or on a radio) clicks the
 * form's first submit button. That was Cancel, so Enter threw the run away
 * and landed on the dashboard. A visually hidden copy of Next now comes
 * first in every wizard form; the visible order stays Cancel, then Next.
 *
 * Each test cancels its run at the end, so nothing is created.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} slug
 * @returns {Promise<boolean>} false when the wizard isn't available here
 */
async function openWizard( page, slug ) {
	await page.goto( '/?tt_view=wizard&slug=' + slug + '&restart=1' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return ( await page.locator( 'form.tt-wizard-form' ).count() ) > 0;
}

/** @param {import('@playwright/test').Page} page */
function stepTitle( page ) {
	return page.locator( '.tt-wizard-step-title' ).innerText();
}

/**
 * Press Enter on `field` and wait for the step POST to come back.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} field
 */
async function enterOn( page, field ) {
	await field.focus();
	await Promise.all( [
		page.waitForNavigation( { timeout: 30000 } ),
		page.keyboard.press( 'Enter' ),
	] );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
}

/** @param {import('@playwright/test').Page} page */
async function cancelRun( page ) {
	const cancel = page.locator( '.tt-wizard-actions [data-role="cancel"]' );
	if ( ( await cancel.count() ) === 0 ) return;
	await Promise.all( [ page.waitForNavigation( { timeout: 30000 } ), cancel.click() ] );
}

test.describe( 'Enter in a wizard goes to the next step (#4709)', () => {

	// Each step is a POST and a redirect; three of them plus Cancel run past
	// the default 30s on the CI install.
	test.describe.configure( { timeout: 120000 } );

	test( 'the first submit in the form is a hidden Next; Cancel still comes first of the visible buttons', async ( { page } ) => {
		test.skip( ! ( await openWizard( page, 'new-player' ) ), 'new-player wizard not available' );

		const first = page.locator( 'form.tt-wizard-form button[type="submit"]' ).first();
		await expect( first ).toHaveAttribute( 'value', 'next' );
		await expect( first ).toHaveAttribute( 'tabindex', '-1' );
		await expect( first ).toHaveAttribute( 'aria-hidden', 'true' );
		const box = await first.boundingBox();
		expect( box && box.width * box.height, 'the default button takes no visible space' ).toBeLessThanOrEqual( 1 );

		const visible = page.locator( '.tt-wizard-actions button[type="submit"]' );
		await expect( visible.first() ).toHaveAttribute( 'data-role', 'cancel' );
		await expect( visible.first() ).toHaveAttribute( 'formnovalidate', '' );

		await cancelRun( page );
	} );

	test( 'new-player: Enter on a radio, then in a text field, moves forward', async ( { page } ) => {
		test.skip( ! ( await openWizard( page, 'new-player' ) ), 'new-player wizard not available' );

		const startTitle = await stepTitle( page );
		await page.locator( 'input[name="path"][value="roster"]' ).check();
		await enterOn( page, page.locator( 'input[name="path"][value="roster"]' ) );

		expect( page.url(), 'still in the wizard' ).toContain( 'tt_view=wizard' );
		await expect( page.locator( 'input[name="first_name"]' ) ).toBeVisible();
		expect( await stepTitle( page ) ).not.toBe( startTitle );

		const detailsTitle = await stepTitle( page );
		await page.locator( 'input[name="first_name"]' ).fill( 'Enter' );
		await page.locator( 'input[name="last_name"]' ).fill( 'Next' );
		await enterOn( page, page.locator( 'input[name="last_name"]' ) );

		expect( page.url(), 'still in the wizard' ).toContain( 'tt_view=wizard' );
		await expect( page.locator( 'input[name="first_name"]' ) ).toHaveCount( 0 );
		expect( await stepTitle( page ) ).not.toBe( detailsTitle );

		await cancelRun( page );
	} );

	test( 'new-report: Enter on a radio moves forward', async ( { page } ) => {
		test.skip( ! ( await openWizard( page, 'new-report' ) ), 'new-report wizard not available on this install' );

		const startTitle = await stepTitle( page );
		await page.locator( 'input[type="radio"][name="kind"]:not([disabled])' ).first().check();
		const radio = page.locator( 'input[type="radio"][name="scope"]:not([disabled])' ).first();
		await radio.check();
		await enterOn( page, radio );

		expect( page.url(), 'still in the wizard' ).toContain( 'tt_view=wizard' );
		expect( await stepTitle( page ) ).not.toBe( startTitle );

		await cancelRun( page );
	} );
} );
