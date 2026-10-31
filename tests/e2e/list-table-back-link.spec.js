// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * List-table back links (#4505).
 *
 * The list REST endpoints return plain target URLs. The list table adds
 * `tt_back` (the page showing the list) when it renders a row: to the
 * row-click URL and to the links inside the row's cells, once, and never
 * to a link that already carries one.
 *
 * The Players list fetch is answered with a fixed row, so the test checks
 * what the table does with known plain URLs rather than what a given
 * install happens to hold. Skips when the frontend list is not rendered.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const FRONTEND_PLAYERS = '/?tt_view=players';

/** @param {URL} url */
function isPlayersListFetch( url ) {
    const href = decodeURIComponent( url.href );
    return /talenttrack\/v1\/players(\?|&|$)/.test( href );
}

test( 'rows and cell links get this page as their one back target', async ( { page, baseURL } ) => {
    const origin   = new URL( baseURL || 'http://localhost' ).origin;
    const detail   = origin + '/?tt_view=players&id=9101';
    const team     = origin + '/?tt_view=teams&id=9201';
    const already  = origin + '/?tt_view=teams&id=9202&tt_back=' + encodeURIComponent( origin + '/?tt_view=dashboard' );

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
                            id: 9101,
                            detail_url: detail,
                            name_link_html: '<a class="tt-record-link" href="' + detail + '">Back Linkx</a>',
                            team_link_html: '<a class="tt-record-link" href="' + team + '">Team Linkx</a>',
                        },
                        {
                            id: 9102,
                            detail_url: detail,
                            name_link_html: '<a class="tt-record-link" href="' + detail + '">Second Linkx</a>',
                            team_link_html: '<a class="tt-record-link" href="' + already + '">Already Linkx</a>',
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
    await expect( body.getByText( 'Back Linkx' ) ).toBeVisible();

    const backOf = ( /** @type {string} */ href ) => new URL( href, origin ).searchParams.getAll( 'tt_back' );
    // One back target, and it is this list (the table may add its own
    // filter state to the page URL, so match the route, not the string).
    const expectOneBackToThisList = ( /** @type {string|null} */ href ) => {
        const backs = backOf( String( href ) );
        expect( backs ).toHaveLength( 1 );
        const back = new URL( backs[ 0 ] );
        expect( back.origin ).toBe( origin );
        expect( back.searchParams.get( 'tt_view' ) ).toBe( 'players' );
    };

    // The row click target.
    expectOneBackToThisList( await body.locator( 'tr.is-row-link' ).first().getAttribute( 'data-row-href' ) );

    // Links inside the cells.
    expectOneBackToThisList( await body.getByText( 'Back Linkx' ).getAttribute( 'href' ) );
    expectOneBackToThisList( await body.getByText( 'Team Linkx' ).getAttribute( 'href' ) );

    // A link that already carries a back target keeps exactly that one.
    const alreadyHref = await body.getByText( 'Already Linkx' ).getAttribute( 'href' );
    expect( backOf( String( alreadyHref ) ) ).toEqual( [ origin + '/?tt_view=dashboard' ] );
} );
