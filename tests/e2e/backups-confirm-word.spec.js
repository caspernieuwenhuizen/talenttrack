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
            };
        } );

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
} );
