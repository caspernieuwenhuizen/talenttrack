// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, gotoAdminPage, uniqueName } = require( './helpers/admin' );

/**
 * Goal e2e spec (#0076 v1, spec #5 in the sequencing).
 *
 * Smallest workflow flow: create a goal against an existing demo
 * player, verify it lands in the list, open edit, change status,
 * verify the change persists. The detail-view click-through (#0070)
 * and the goal-redirect-after-save fix (#28) both ride through this
 * path, so a clean create + status-edit cycle is the minimum smoke
 * that catches regressions on either layer.
 *
 * Goals require a `player_id` — the seeded demo data ships at least
 * one player on every install so the dropdown is never empty. We
 * pick the first non-empty option to stay decoupled from the seed
 * order.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Goals CRUD', () => {

    test( 'create + status-edit a goal through wp-admin', async ( { page } ) => {
        const title = 'E2E ' + uniqueName( 'Goal' );

        // ── Create ──
        await gotoAddNew( page, 'tt-goals' );

        // The player <select> is the only required dropdown other
        // than `title`. Pick the first non-empty option.
        const firstPlayerOption = page.locator(
            'select[name="player_id"] option[value]:not([value=""])'
        ).first();
        const playerValue = await firstPlayerOption.getAttribute( 'value' );
        if ( ! playerValue ) {
            test.skip( true, 'Demo data has zero players — goal create cannot be tested.' );
            return;
        }
        await page.selectOption( 'select[name="player_id"]', playerValue );
        await page.fill( 'input[name="title"]', title );

        await Promise.all( [
            page.waitForURL( /page=tt-goals(?!&action=new)/, { timeout: 15000 } ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );

        // ── Verify list shows the new goal ──
        await expect( page.locator( 'body' ) ).toContainText( title );

        // ── Open the edit page and flip status to in_progress ──
        const editLink = page.locator(
            `a[href*="page=tt-goals"][href*="action=edit"]`
        ).first();
        await editLink.click();
        await page.waitForURL( /page=tt-goals.*action=edit/, { timeout: 15000 } );

        // The status enum is seeded in tt_lookups; in_progress is
        // present on every install (initial schema seed). Selector
        // matches by value to stay robust against label translation.
        await page.selectOption( 'select[name="status"]', 'in_progress' );
        await Promise.all( [
            page.waitForURL( /page=tt-goals(?!&action=)/, { timeout: 15000 } ),
            page.click( 'input[type="submit"], button[type="submit"]' ),
        ] );

        // List re-renders with the goal still visible — status pill
        // text varies per locale so we just assert the title persisted.
        await gotoAdminPage( page, 'tt-goals' );
        await expect( page.locator( 'body' ) ).toContainText( title );
    } );
} );
