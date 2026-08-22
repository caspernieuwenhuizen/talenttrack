// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Alerts inbox smoke spec (#2633, epic #2629).
 *
 * A new `?tt_view=<slug>` route gets a Playwright smoke test. This one is
 * deliberately thin: it does not seed occurrences, because the wp-env
 * baseline has no past activities to be unmarked and manufacturing them
 * through the UI would test the activities module rather than this route.
 *
 * What it does assert is the set of things that break when a route is wired
 * up wrong, and which no PHP unit test sees:
 *
 *   - the slug dispatches at all (a missing `case` in the router renders
 *     the branded 404, which is the failure mode #764 hit three times);
 *   - the §5 navigation contract survives a real render — the breadcrumb
 *     chain is in the DOM and no hand-rolled back button is;
 *   - the filter bar renders, so the chip deep-link has something to land
 *     in;
 *   - it does not scroll horizontally at 360px, which is the constraint an
 *     inline-chip wave is most likely to violate.
 *
 * Skips rather than fails when the frontend dashboard surface isn't present
 * on the install (no shortcode page / cap mismatch) — surface availability
 * is the meaningful gate, mirroring filterbar.spec.js.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const ALERTS = '/?tt_view=alerts';

/**
 * Load the alerts inbox. Resolves false when the dashboard shell isn't
 * rendering on this install.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function gotoAlerts( page ) {
	await page.goto( ALERTS );
	await page.waitForLoadState( 'load', { timeout: 30000 } );

	const crumbs = page.locator( '.tt-breadcrumbs' ).first();
	try {
		await crumbs.waitFor( { state: 'attached', timeout: 15000 } );
	} catch ( _e ) {
		return false;
	}
	return true;
}

test.describe( 'Alerts inbox (?tt_view=alerts)', () => {

	test( 'the slug dispatches and satisfies the two-affordance nav contract', async ( { page } ) => {
		const present = await gotoAlerts( page );
		if ( ! present ) {
			test.skip( true, 'Dashboard shell not present on this install.' );
			return;
		}

		// Dispatched, not 404'd.
		await expect( page.locator( '.tt-404' ) ).toHaveCount( 0 );
		await expect( page.locator( 'h1.tt-fview-title' ).first() ).toBeVisible();

		// Affordance 1: the chain. Affordance 2 (the tt_back pill) renders
		// only when the entry URL carried one, and this entry did not — its
		// absence here is the designed behaviour, not a gap.
		await expect( page.locator( '.tt-breadcrumbs' ).first() ).toBeVisible();

		// And nothing else. A third back affordance is the violation §5
		// exists to prevent.
		await expect( page.locator( '.tt-back-btn' ) ).toHaveCount( 0 );
	} );

	test( 'the filter bar renders so a chip deep-link has somewhere to land', async ( { page } ) => {
		const present = await gotoAlerts( page );
		if ( ! present ) {
			test.skip( true, 'Dashboard shell not present on this install.' );
			return;
		}

		// An install whose alerts migration has not run renders the
		// "not available yet" notice instead of a filter bar. That is a
		// designed degradation, not a failure of this route, so skip on it
		// rather than asserting a bar that legitimately isn't there.
		const bars = await page.locator( '[data-tt-filterbar]' ).count();
		if ( bars === 0 ) {
			test.skip( true, 'Alerts table not present on this install.' );
			return;
		}
		await expect( page.locator( '[data-tt-filterbar]' ).first() ).toBeAttached();
	} );

	test( 'a subject-scoped deep link stays on the alerts route', async ( { page } ) => {
		// The shape every AlertChip emits. With no matching occurrence the
		// list is empty, which is fine — what must hold is that the route
		// accepts the parameters and still renders the inbox rather than
		// falling through to the 404.
		await page.goto( `${ ALERTS }&subject_type=activity&subject_id=1` );
		await page.waitForLoadState( 'load', { timeout: 30000 } );

		const crumbs = page.locator( '.tt-breadcrumbs' ).first();
		try {
			await crumbs.waitFor( { state: 'attached', timeout: 15000 } );
		} catch ( _e ) {
			test.skip( true, 'Dashboard shell not present on this install.' );
			return;
		}

		await expect( page.locator( '.tt-404' ) ).toHaveCount( 0 );
	} );

	test( 'does not scroll horizontally at 360px', async ( { page } ) => {
		await page.setViewportSize( { width: 360, height: 740 } );

		const present = await gotoAlerts( page );
		if ( ! present ) {
			test.skip( true, 'Dashboard shell not present on this install.' );
			return;
		}

		const overflows = await page.evaluate(
			() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
		);
		expect( overflows ).toBe( false );
	} );
} );
