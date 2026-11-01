// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Backups type-to-confirm word (#4532).
 *
 * The restore and import forms ask the user to type a word, which is now
 * translated (RESTORE / HERSTELLEN, IMPORT / IMPORTEREN). The script sends
 * the API its fixed token only when the typed text is exactly the word the
 * page shows, so the comparison is tested with both locales' words.
 *
 * Skips when the frontend backups view isn't rendered on this install,
 * like the other frontend specs.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

test.describe( 'Backups type-to-confirm', () => {

    test( 'the token is sent only for the word the page shows', async ( { page } ) => {
        await page.goto( '/?tt_view=backups' );

        const ready = await page.evaluate( () => !! ( window.TT && window.TT.backupsConfirmToken ) );
        if ( ! ready ) {
            test.skip( true, 'Frontend backups view not present on this install.' );
            return;
        }

        const results = await page.evaluate( () => {
            const confirm = window.TT.backupsConfirmToken;
            return {
                enRestore:      confirm( 'RESTORE', 'RESTORE', 'RESTORE' ),
                enImport:       confirm( ' IMPORT ', 'IMPORT', 'IMPORT' ),
                nlRestore:      confirm( 'HERSTELLEN', 'HERSTELLEN', 'RESTORE' ),
                nlImport:       confirm( 'IMPORTEREN', 'IMPORTEREN', 'IMPORT' ),
                nlTypedEnglish: confirm( 'RESTORE', 'HERSTELLEN', 'RESTORE' ),
                wrongCase:      confirm( 'herstellen', 'HERSTELLEN', 'RESTORE' ),
                empty:          confirm( '', 'HERSTELLEN', 'RESTORE' ),
                noWord:         confirm( '', undefined, 'RESTORE' ),
            };
        } );
        expect( results.noWord ).toBeNull();

        expect( results.enRestore ).toBe( 'RESTORE' );
        expect( results.enImport ).toBe( 'IMPORT' );
        expect( results.nlRestore ).toBe( 'RESTORE' );
        expect( results.nlImport ).toBe( 'IMPORT' );
        expect( results.nlTypedEnglish ).toBeNull();
        expect( results.wrongCase ).toBeNull();
        expect( results.empty ).toBeNull();
    } );

    test( 'the page offers the localized word as the placeholder and label', async ( { page } ) => {
        await page.goto( '/?tt_view=backups' );

        const words = await page.evaluate( () => {
            const bag = ( window.TT_Backups || {} ).i18n || {};
            return { restore: bag.restore_word, label: bag.restore_type };
        } );
        if ( ! words.restore ) {
            test.skip( true, 'Frontend backups view not present on this install.' );
            return;
        }

        expect( words.label ).toContain( words.restore );
    } );

    /**
     * The request itself: typing the shown word sends the API its fixed
     * English token, and a wrong word sends nothing. The restore call is
     * intercepted and answered here, so no data is replaced.
     */
    test( 'the restore request carries the English token, never the typed word', async ( { page } ) => {
        await page.goto( '/?tt_view=backups' );
        const word = await page.evaluate( () => ( ( window.TT_Backups || {} ).i18n || {} ).restore_word || '' );
        if ( ! word ) {
            test.skip( true, 'Frontend backups view not present on this install.' );
            return;
        }

        // A restore needs a backup in the list; make one if there is none.
        if ( await page.locator( '[data-tt-backups-restore]' ).count() === 0 ) {
            const ran = page.waitForResponse( ( r ) => /backups\/run/.test( r.url() ), { timeout: 60000 } );
            await page.locator( '[data-tt-backups-run]' ).click();
            const res = await ran;
            if ( ! res.ok() ) {
                test.skip( true, 'Could not create a backup on this install.' );
                return;
            }
            await page.goto( '/?tt_view=backups' );
        }
        if ( await page.locator( '[data-tt-backups-restore]' ).count() === 0 ) {
            test.skip( true, 'No backup to restore on this install.' );
            return;
        }

        const sent = [];
        await page.route( /backups\/[^/]+\/restore/, async ( route ) => {
            sent.push( route.request().postDataJSON() );
            await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: {} } ) } );
        } );

        await page.locator( '[data-tt-backups-restore]' ).first().click();
        const input = page.locator( '#tt-bk-restore-confirm' );
        await expect( input ).toBeVisible( { timeout: 30000 } );
        const submit = page.locator( '[data-tt-backups-restore-panel] button[type="submit"]' );

        await input.fill( word.toLowerCase() === word ? word.toUpperCase() : word.toLowerCase() );
        await submit.click();
        await page.waitForTimeout( 300 );
        expect( sent, 'a wrong word sends no request' ).toHaveLength( 0 );

        await input.fill( word );
        await submit.click();
        await expect.poll( () => sent.length ).toBe( 1 );
        expect( sent[0].confirm_text ).toBe( 'RESTORE' );
    } );
} );
