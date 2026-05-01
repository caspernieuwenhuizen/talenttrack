// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * First smoke test (#12 v1).
 *
 * Verifies that the wp-admin login page loads, accepts the default
 * `admin / password` credentials that wp-env seeds, and lands on the
 * TalentTrack dashboard. If this passes the foundation is sound; we
 * can then add per-flow specs (player CRUD, team CRUD, evaluation,
 * activity, etc.) without re-validating the plumbing.
 */
test( 'admin can log in and reach the TalentTrack dashboard', async ( { page } ) => {
	await page.goto( '/wp-login.php' );

	await page.fill( 'input[name="log"]', 'admin' );
	await page.fill( 'input[name="pwd"]', 'password' );
	await Promise.all( [
		page.waitForURL( /\/wp-admin/ ),
		page.click( 'input#wp-submit' ),
	] );

	// Open the TalentTrack top-level menu — slug is `talenttrack` per
	// `Menu::register()` in src/Shared/Admin/Menu.php.
	await page.goto( '/wp-admin/admin.php?page=talenttrack' );

	// The dashboard renders an h1 with the localised title; we match
	// loosely to survive future label changes.
	await expect( page.locator( 'h1' ) ).toContainText( /TalentTrack/i );
} );
