// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * Players CRUD e2e spec (#0076 v1, spec #2 in the sequencing).
 *
 * Smallest CRUD-shape flow on the wp-admin Players page. Mirrors the
 * teams-crud shape: create → verify in list → edit → verify rename.
 * Photo upload + parent picker are intentionally out of scope here —
 * each earns its own spec when the operator-facing flow stabilises.
 *
 * Operationally this is the regression guard for the #0070 row-action
 * routing fix and the v3.89.x archive-vs-status delete fix — both
 * touched the players list, so a clean create + edit cycle through
 * wp-admin is the minimum smoke that catches future regressions on
 * either layer.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Players CRUD', () => {

    test( 'create + edit a player through wp-admin', async ( { page } ) => {
        const firstName = 'E2E';
        const lastName  = uniqueName( 'Player' );
        const fullName  = `${ firstName } ${ lastName }`;

        // ── Create ──
        await gotoAddNew( page, 'tt-players' );
        await page.fill( 'input[name="first_name"]', firstName );
        await page.fill( 'input[name="last_name"]', lastName );
        await Promise.all( [
            page.waitForURL( /page=tt-players(?!&action=new)/, { timeout: 15000 } ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );

        // ── Verify list shows the new player ──
        await expect( page.locator( 'body' ) ).toContainText( lastName );

        // ── Open the edit page ──
        const editLink = page.locator(
            `a[href*="page=tt-players"][href*="action=edit"]`
        ).first();
        await editLink.click();
        await page.waitForURL( /page=tt-players.*action=edit/, { timeout: 15000 } );

        // ── Edit: rename last name ──
        const renamedLast = lastName + '-renamed';
        await page.fill( 'input[name="last_name"]', renamedLast );
        await Promise.all( [
            page.waitForURL( /page=tt-players(?!&action=)/, { timeout: 15000 } ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );
        await expect( page.locator( 'body' ) ).toContainText( renamedLast );
    } );
} );
