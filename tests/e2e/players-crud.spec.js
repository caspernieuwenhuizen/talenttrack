// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * Players CRUD e2e spec (#0076 v1, spec #2 in the sequencing).
 *
 * Smallest CRUD-shape flow on the wp-admin Players page. Mirrors the
 * teams-crud shape: create → verify in list. Edit / archive / parent-
 * picker / photo upload are intentionally out of scope here — each
 * earns its own spec when the operator-facing flow stabilises.
 *
 * The smoke is intentionally narrow — submit the form, assert the new
 * last_name renders somewhere on the post-redirect page. That catches
 * every silent-fail regression on the save handler (#0070 row-action
 * routing fix, the v3.89.x archive-vs-status delete fix) without
 * coupling the test to specific list-table markup.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Players CRUD', () => {

    test( 'create a player through wp-admin', async ( { page } ) => {
        const firstName = 'E2E';
        const lastName  = uniqueName( 'Player' );

        await gotoAddNew( page, 'tt-players' );
        await page.fill( 'input[name="first_name"]', firstName );
        await page.fill( 'input[name="last_name"]', lastName );

        // Submit and just wait for navigation away from the add-new
        // form. The redirect target varies (saved=1 banner, edit
        // page on cap-bound flows, list page on the happy path) so
        // a generic `waitForLoadState` after click is the most
        // resilient gate.
        await page.click( 'input[type="submit"], button[type="submit"]' );
        await page.waitForLoadState( 'networkidle', { timeout: 30000 } );

        // After save the operator lands on the list view (or an
        // intermediate edit-confirmation page). Either way the
        // lastName should be present in the body — substring match
        // is enough.
        await expect( page.locator( 'body' ) ).toContainText( lastName, { timeout: 15000 } );
    } );
} );
