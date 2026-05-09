// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * Goal e2e spec (#0076 v1, spec #5 in the sequencing).
 *
 * Smallest workflow flow: create a goal against the first available
 * player, verify it lands in the list. Status-edit and detail click-
 * through ride on top of this and earn their own coverage in follow-
 * up specs.
 *
 * Goals require a `player_id`. The wp-env baseline does NOT seed
 * demo players (globalSetup only logs in), so this test gracefully
 * skips when the dropdown is empty rather than failing. A separate
 * `demo-data` fixture lands when the spec sequencing reaches the
 * specs that need it.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Goals CRUD', () => {

    test( 'create a goal through wp-admin', async ( { page } ) => {
        const title = 'E2E ' + uniqueName( 'Goal' );

        await gotoAddNew( page, 'tt-goals' );

        // The player <select> is required. Defensive count check up
        // front so we skip when the wp-env baseline has zero players
        // — `getAttribute` would otherwise hang for the full test
        // timeout waiting for the locator to materialise.
        const playerOptions = page.locator(
            'select[name="player_id"] option[value]:not([value=""])'
        );
        const optionCount = await playerOptions.count();
        if ( optionCount === 0 ) {
            test.skip( true, 'No players seeded on this install — goal create cannot be exercised. Lands with the demo-data fixture in a follow-up.' );
            return;
        }

        const playerValue = await playerOptions.first().getAttribute( 'value', { timeout: 5000 } );
        if ( ! playerValue ) {
            test.skip( true, 'First player option has no value attribute — likely an unexpected DOM shape.' );
            return;
        }

        await page.selectOption( 'select[name="player_id"]', playerValue );
        await page.fill( 'input[name="title"]', title );

        await page.click( 'input[type="submit"], button[type="submit"]' );
        await page.waitForLoadState( 'networkidle', { timeout: 30000 } );

        await expect( page.locator( 'body' ) ).toContainText( title, { timeout: 15000 } );
    } );
} );
