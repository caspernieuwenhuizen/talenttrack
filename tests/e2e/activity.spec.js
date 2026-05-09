// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * Activity e2e spec (#0076 v1, spec #6 in the sequencing).
 *
 * Smallest CRUD-shape flow on the wp-admin Activities page. Mirrors
 * the teams + goal shape: create → verify in list → edit → verify
 * rename. Attendance recording, guest-add (#26), and Spond-row
 * visibility (#13) are intentionally out of scope here — each rides
 * on more complex setup (existing players + attendance state) and
 * earns its own spec.
 *
 * Operationally this is the regression guard for the v3.110.18
 * presence-percentage clamp + the v3.110.19 dispatcher-routing fix
 * — both touched the activities admin, so a clean create + edit
 * cycle through wp-admin is the minimum smoke that catches future
 * regressions.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Activities CRUD', () => {

    test( 'create + edit an activity through wp-admin', async ( { page } ) => {
        const title = 'E2E ' + uniqueName( 'Activity' );

        // ── Create ──
        await gotoAddNew( page, 'tt-activities' );

        // The form requires `activity_type_key` — pick the first
        // non-empty option to stay decoupled from seed order /
        // operator-customised lookup rows.
        const firstTypeOption = page.locator(
            'select[name="activity_type_key"] option[value]:not([value=""])'
        ).first();
        const typeValue = await firstTypeOption.getAttribute( 'value' );
        if ( ! typeValue ) {
            test.skip( true, 'No activity_type lookup rows on this install — activity create cannot be tested.' );
            return;
        }
        await page.selectOption( 'select[name="activity_type_key"]', typeValue );

        await page.fill( 'input[name="title"]', title );
        // session_date defaults to today via current_time('Y-m-d') — no
        // need to fill it. Leaving it as-default keeps the test
        // resilient to date-format differences across locales.

        await Promise.all( [
            page.waitForURL( /page=tt-activities(?!&action=new)/, { timeout: 15000 } ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );

        // ── Verify list shows the new activity ──
        await expect( page.locator( 'body' ) ).toContainText( title );

        // ── Open the edit page ──
        const editLink = page.locator(
            `a[href*="page=tt-activities"][href*="action=edit"]`
        ).first();
        await editLink.click();
        await page.waitForURL( /page=tt-activities.*action=edit/, { timeout: 15000 } );

        // ── Edit: rename the title ──
        const renamed = title + ' Renamed';
        await page.fill( 'input[name="title"]', renamed );
        await Promise.all( [
            page.waitForURL( /page=tt-activities(?!&action=)/, { timeout: 15000 } ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );
        await expect( page.locator( 'body' ) ).toContainText( renamed );
    } );
} );
