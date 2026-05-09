// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * Activity e2e spec (#0076 v1, spec #6 in the sequencing).
 *
 * Smallest CRUD-shape flow on the wp-admin Activities page: create →
 * verify in list. Attendance recording, guest-add (#26), Spond-row
 * visibility (#13) ride on more complex setup (existing players +
 * attendance state) and earn their own specs.
 *
 * Operationally this is the regression guard for the v3.110.18
 * presence-percentage clamp + the v3.110.19 dispatcher-routing fix —
 * both touched the activities admin, so a clean create cycle catches
 * future regressions on the save handler.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Activities CRUD', () => {

    test( 'create an activity through wp-admin', async ( { page } ) => {
        const title = 'E2E ' + uniqueName( 'Activity' );

        await gotoAddNew( page, 'tt-activities' );

        // The form requires `activity_type_key`. Defensive count check
        // up front so we skip cleanly when the install has zero rows
        // — `getAttribute` would otherwise hang for the full test
        // timeout waiting for the locator to materialise.
        const typeOptions = page.locator(
            'select[name="activity_type_key"] option[value]:not([value=""])'
        );
        const typeCount = await typeOptions.count();
        if ( typeCount === 0 ) {
            test.skip( true, 'No activity_type lookup rows seeded on this install — activity create cannot be exercised.' );
            return;
        }

        const typeValue = await typeOptions.first().getAttribute( 'value', { timeout: 5000 } );
        if ( ! typeValue ) {
            test.skip( true, 'First activity_type option has no value attribute — likely an unexpected DOM shape.' );
            return;
        }

        await page.selectOption( 'select[name="activity_type_key"]', typeValue );
        await page.fill( 'input[name="title"]', title );
        // session_date defaults to today via current_time('Y-m-d');
        // leaving it as-default keeps the test resilient to date-
        // format differences across locales.

        await page.click( 'input[type="submit"], button[type="submit"]' );
        await page.waitForLoadState( 'networkidle', { timeout: 30000 } );

        await expect( page.locator( 'body' ) ).toContainText( title, { timeout: 15000 } );
    } );
} );
