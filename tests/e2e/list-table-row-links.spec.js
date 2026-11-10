// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * List-table row links stay table rows (#4884).
 *
 * A row that opens its record on a tap is still `role="row"` with no
 * tabindex: a `role="link"` row inside a `role="table"` drops out of the
 * table for a screen reader. Its keyboard path is an anchor to its own
 * record inside the row — the one the preset prints, or one the table adds
 * when the preset prints none.
 *
 * The Players list fetch is answered with fixed rows: one whose name cell
 * links the record, one whose name is plain text. Skips when the frontend
 * list is not rendered.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const FRONTEND_PLAYERS = '/?tt_view=players';

/** @param {URL} url */
function isPlayersListFetch( url ) {
    const href = decodeURIComponent( url.href );
    return /talenttrack\/v1\/players(\?|&|$)/.test( href );
}

test( 'every row link is a table row with an anchor to its own record', async ( { page, baseURL } ) => {
    const origin  = new URL( baseURL || 'http://localhost' ).origin;
    const linked  = origin + '/?tt_view=players&id=9301';
    const plain   = origin + '/?tt_view=players&id=9302';

    await page.route( isPlayersListFetch, async ( route ) => {
        await route.fulfill( {
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify( {
                success: true,
                errors: [],
                data: {
                    total: 2,
                    page: 1,
                    per_page: 25,
                    rows: [
                        {
                            id: 9301,
                            detail_url: linked,
                            name_link_html: '<a class="tt-record-link" href="' + linked + '">Row Linkx</a>',
                        },
                        {
                            id: 9302,
                            detail_url: plain,
                            name_link_html: 'Plain Rowx',
                        },
                    ],
                },
            } ),
        } );
    } );

    await page.goto( FRONTEND_PLAYERS );
    const list = page.locator( '[data-tt-list-table="1"]' ).first();
    try {
        await list.waitFor( { state: 'attached', timeout: 15000 } );
    } catch ( _e ) {
        test.skip( true, 'Frontend players list not present on this install.' );
        return;
    }
    const body = list.locator( '[data-tt-list-body="1"]' );
    await expect( body.getByText( 'Row Linkx' ) ).toBeVisible();

    // The record's address without the back hint, so a cell link and the
    // row's address compare as the same record.
    const recordKey = ( /** @type {string} */ href ) => {
        const u = new URL( href, origin );
        u.searchParams.delete( 'tt_back' );
        u.searchParams.sort();
        return u.origin + u.pathname + '?' + u.searchParams.toString();
    };

    const rows = body.locator( 'tr.is-row-link' );
    await expect( rows ).toHaveCount( 2 );
    for ( let i = 0; i < 2; i++ ) {
        const row = rows.nth( i );
        await expect( row ).toHaveAttribute( 'role', 'row' );
        expect( await row.getAttribute( 'tabindex' ) ).toBeNull();

        const rowKey  = recordKey( String( await row.getAttribute( 'data-row-href' ) ) );
        const anchors = await row.locator( 'td a[href]' ).evaluateAll( ( els ) => els.map( ( a ) => a.getAttribute( 'href' ) ) );
        expect( anchors.map( ( h ) => recordKey( String( h ) ) ) ).toContain( rowKey );
    }
} );
