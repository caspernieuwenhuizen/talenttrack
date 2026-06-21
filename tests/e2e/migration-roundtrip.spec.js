// @ts-check
/**
 * #1464 phase 3-4 — migration export → import round-trip.
 *
 * Exercises the write engine end to end on a single install: create a
 * uniquely-named team, export a .ttmig, then re-import it as new records and
 * confirm the engine inserts with remapped ids (the team name then appears
 * twice — the original plus the imported copy). The dry-run + typed
 * confirmation steps are walked exactly as an operator would.
 *
 * Forms are submitted via requestSubmit() rather than button clicks: the
 * wp-admin pages carry dismissible notices that shift layout and defeat
 * Playwright's click-stability wait (see #1593).
 */
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/** Submit the form that owns the given selector, natively. */
async function submitFormOf( page, selector ) {
    await page.locator( selector ).first().evaluate( ( el ) => {
        const form = el.closest( 'form' ) || el.form;
        form.requestSubmit();
    } );
}

test.describe( 'Migration round-trip', () => {

    test( 'export then import re-inserts records with remapped ids', async ( { page } ) => {
        const teamName = 'E2E ' + uniqueName( 'MigTeam' );

        // ── Seed a uniquely-named team ──
        await gotoAddNew( page, 'tt-teams' );
        await page.fill( 'input[name="name"]', teamName );
        await Promise.all( [
            page.waitForURL( /page=tt-teams(?!&action=new)/ ),
            submitFormOf( page, 'input[name="name"]' ),
        ] );
        await expect( page.locator( 'body' ) ).toContainText( teamName );

        // ── Export for migration (.ttmig download) ──
        await page.goto( '/wp-admin/admin.php?page=tt-config&tab=backups' );
        const [ download ] = await Promise.all( [
            page.waitForEvent( 'download' ),
            submitFormOf( page, 'input[name="action"][value="tt_migration_export"]' ),
        ] );
        const archivePath = await download.path();
        expect( archivePath ).toBeTruthy();

        // ── Upload → preview ──
        await page.goto( '/wp-admin/admin.php?page=tt-config&tab=backups' );
        await page.setInputFiles( 'input[name="migration_file"]', archivePath );
        await Promise.all( [
            page.waitForURL( /admin-post\.php|action=tt_migration_import_preview/ ).catch( () => {} ),
            submitFormOf( page, 'input[name="migration_file"]' ),
        ] );
        await expect( page.locator( 'body' ) ).toContainText( /Configure import/i );

        // ── Dry run ──
        await submitFormOf( page, 'input[name="action"][value="tt_migration_import_dryrun"]' );
        await expect( page.locator( 'body' ) ).toContainText( /Dry run/i );

        // ── Commit with typed confirmation ──
        await page.fill( 'input[name="confirm_text"]', 'IMPORT' );
        await submitFormOf( page, 'input[name="confirm_text"]' );
        await expect( page.locator( 'body' ) ).toContainText( /Import complete/i );

        // ── Verify: the team was re-inserted as a new record ──
        await page.goto( '/wp-admin/admin.php?page=tt-teams' );
        const occurrences = await page.locator( `text=${ teamName }` ).count();
        expect( occurrences ).toBeGreaterThanOrEqual( 2 );
    } );
} );
