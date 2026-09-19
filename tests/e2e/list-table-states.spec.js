// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * FrontendListTable body states (#3669).
 *
 * The shared list table used to paint its empty state ("nothing recorded
 * yet", or the guided card) as the server-rendered body, and the hydrator
 * only replaced it when the REST fetch succeeded. A slow or failed fetch
 * therefore told a player with a full history that they had none.
 *
 * Coverage, on the Players list (`?tt_view=players`, the canonical
 * FrontendListTable adopter):
 *   - First paint (the HTML before any JS runs) carries the loading row
 *     and no empty-state row.
 *   - A failed list fetch replaces the body with an error row + Retry,
 *     never the empty state.
 *   - Retry calls the endpoint again and the error row goes away once
 *     the call succeeds.
 *
 * When the frontend list isn't rendered on this install, the test skips
 * rather than fails, like filterbar.spec.js.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const FRONTEND_PLAYERS = '/?tt_view=players';

/**
 * Matches the list fetch for the players collection, in both REST URL
 * shapes (`/wp-json/talenttrack/v1/players?…` and `?rest_route=…`), but
 * not `players/<id>` sub-resources.
 *
 * @param {URL} url
 */
function isPlayersListFetch( url ) {
    const href = decodeURIComponent( url.href );
    return /talenttrack\/v1\/players(\?|&|$)/.test( href );
}

test.describe( 'List table body states (Players)', () => {

    test( 'first paint shows the loading row, not the empty state', async ( { page } ) => {
        const res  = await page.request.get( FRONTEND_PLAYERS );
        const html = await res.text();
        if ( html.indexOf( 'data-tt-list-table="1"' ) === -1 ) {
            test.skip( true, 'Frontend players list not present on this install.' );
            return;
        }
        expect( html ).toContain( 'data-tt-list-loading="1"' );
        expect( html ).not.toContain( 'data-tt-list-empty="1"' );
        expect( html ).not.toContain( 'tt-list-table-empty' );
    } );

    test( 'a failed fetch shows an error row with Retry, and Retry recovers', async ( { page } ) => {
        let failing = true;
        let calls   = 0;
        await page.route( isPlayersListFetch, async ( route ) => {
            calls++;
            if ( failing ) {
                await route.fulfill( {
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify( { success: false, errors: [ { code: 'e2e', message: 'Simulated failure' } ] } ),
                } );
                return;
            }
            await route.continue();
        } );

        await page.goto( FRONTEND_PLAYERS );
        const list = page.locator( '[data-tt-list-table="1"]' ).first();
        try {
            await list.waitFor( { state: 'attached', timeout: 15000 } );
        } catch ( _e ) {
            test.skip( true, 'Frontend players list not present on this install.' );
            return;
        }

        const body     = list.locator( '[data-tt-list-body="1"]' );
        const errorRow = body.locator( '[data-tt-list-error="1"]' );
        await expect( errorRow ).toBeVisible( { timeout: 15000 } );

        // A failed load never reads as "empty" and never keeps the
        // loading placeholder.
        await expect( body.locator( '.tt-list-table-empty' ) ).toHaveCount( 0 );
        await expect( body.locator( '[data-tt-list-loading="1"]' ) ).toHaveCount( 0 );

        const retry = errorRow.locator( 'button[data-tt-list-retry]' );
        await expect( retry ).toBeVisible();

        const callsBefore = calls;
        failing = false;
        await retry.click();

        await expect( errorRow ).toHaveCount( 0, { timeout: 15000 } );
        await expect( body.locator( '[data-tt-list-loading="1"]' ) ).toHaveCount( 0 );
        expect( calls ).toBeGreaterThan( callsBefore );
    } );
} );
