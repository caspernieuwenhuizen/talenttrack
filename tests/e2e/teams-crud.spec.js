// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAdminPage, gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * Teams CRUD e2e spec (#0076 v1, spec #3 in the sequencing).
 *
 * Covers create / edit / archive on the wp-admin Teams page, plus the
 * staff section sanity check that catches the #19 regression
 * (mishandled staff render). Operationally this is the smallest team-
 * shaped flow that exercises every layer the bug touched.
 *
 * Coach assignment + per-team detail-edit flow are out of scope here
 * — they ride on top of this and earn their own coverage in the
 * follow-up activity / evaluation specs.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Teams CRUD', () => {

    test( 'create + edit + verify staff section renders', async ( { page } ) => {
        const teamName = 'E2E ' + uniqueName( 'Team' );

        // ── Create ──
        await gotoAddNew( page, 'tt-teams' );
        await page.fill( 'input[name="name"]', teamName );
        await Promise.all( [
            page.waitForURL( /page=tt-teams(?!&action=new)/ ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );

        // ── Verify list shows the new team ──
        await expect( page.locator( 'body' ) ).toContainText( teamName );

        // ── Open the edit page and verify the staff section renders.
        // #0070 / #19 regression: the staff section silently disappeared
        // because the renderer threw an unrelated warning. We just
        // assert the section's heading is present — that's enough to
        // catch the silent-fail regression.
        //
        // #1593: navigate via the edit link's href rather than clicking it.
        // After the create redirect the list page carries dismissible admin
        // notices; WordPress injects their "Dismiss" buttons after load,
        // which shifts the table (and this link) downward, so a `.click()`
        // never sees the link reach Playwright's "stable" state and times
        // out. The href is deterministic, so a direct goto is both robust
        // and still exercises exactly what this test verifies — that the
        // edit page renders the staff section.
        const editHref = await page.locator(
            `a[href*="page=tt-teams"][href*="action=edit"]`
        ).first().getAttribute( 'href' );
        expect( editHref ).toBeTruthy();
        await page.goto( editHref );
        await page.waitForURL( /page=tt-teams.*action=edit/ );

        // The staff heading text varies per locale; we match the
        // English phrase that ships in the seed since wp-env runs `en_US`.
        await expect( page.locator( 'body' ) ).toContainText( /staff/i );

        // ── Edit ──
        await page.fill( 'input[name="name"]', teamName + ' Renamed' );
        await Promise.all( [
            page.waitForURL( /page=tt-teams(?!&action=)/ ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );
        await expect( page.locator( 'body' ) ).toContainText( teamName + ' Renamed' );
    } );
} );
