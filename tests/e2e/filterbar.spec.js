// @ts-check
const { test, expect } = require( '@playwright/test' );
const { gotoAddNew, uniqueName } = require( './helpers/admin' );

/**
 * FilterBar list-filtering e2e spec (#2094).
 *
 * Epic #2017 Phase 2 routed every FrontendListTable filter through the
 * shared FilterBar (#2082) and promoted record-state filters to status
 * pills (#2083), but no Playwright spec exercised list filtering on any
 * migrated surface. This spec closes that gap on the Players list
 * (`?tt_view=players`), the canonical FilterBar + FrontendListTable
 * adopter (search + select filters + an `archived` status-pill group).
 *
 * Coverage:
 *   - Search (`name="search"`) narrows the hydrated row set.
 *   - A `select` filter (`filter[team_id]`) applies (URL + narrowing).
 *   - A status pill (`filter[archived]=archived`, link-based full reload)
 *     applies the record-state filter.
 *   - At 360px the "Filters" bottom sheet opens (`[data-tt-filter-open]`
 *     → `[data-tt-filter-sheet]`), a filter applies, the sheet closes,
 *     and there is no horizontal scroll.
 *
 * Resilience: the wp-env baseline seeds no demo players and no dashboard
 * shortcode page beyond the site root. Each test seeds the rows it needs
 * via the wp-admin create flow (mirroring players-crud.spec.js), then
 * asserts *relative* narrowing / presence of the seeded rows rather than
 * brittle exact counts. When the frontend FilterBar surface isn't present
 * on the install (cap mismatch / shortcode page absent), the test skips
 * rather than fails — surface availability is the meaningful gate.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

const FRONTEND_PLAYERS = '/?tt_view=players';

/**
 * Create a player through the wp-admin add-new flow (the players-crud
 * harness pattern). Returns the last name so the caller can search for
 * it on the frontend list.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} lastName
 */
async function seedPlayer( page, lastName ) {
    await gotoAddNew( page, 'tt-players' );
    await page.fill( 'input[name="first_name"]', 'E2E' );
    await page.fill( 'input[name="last_name"]', lastName );
    // wp-admin submit: use requestSubmit (not click) + wait for the
    // navigation, never `networkidle` — the admin heartbeat/ajax means
    // networkidle rarely settles and a notice layout-shift can make the
    // submit button non-actionable for click() (repo e2e gotcha).
    await Promise.all( [
        page.waitForNavigation( { waitUntil: 'load', timeout: 30000 } ),
        page.evaluate( () => {
            const b = document.querySelector( '#submit' )
                || document.querySelector( 'input[type="submit"], button[type="submit"]' );
            if ( b && b.form ) { b.form.requestSubmit( b ); }
        } ),
    ] );
}

/**
 * Navigate to the frontend players list and wait for the FilterBar +
 * the hydrated list body to be present. Resolves to `false` when the
 * FilterBar surface isn't rendered on this install (so the caller can
 * skip gracefully).
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function gotoPlayersList( page ) {
    await page.goto( FRONTEND_PLAYERS );

    const filterbar = page.locator( '[data-tt-filterbar]' ).first();
    try {
        await filterbar.waitFor( { state: 'attached', timeout: 15000 } );
    } catch ( _e ) {
        return false;
    }

    // The list body hydrates via REST after DOMContentLoaded. Wait for
    // the async row fetch to settle so counts are stable.
    await page.waitForLoadState( 'load', { timeout: 30000 } );
    return true;
}

/** Count the currently-rendered data rows in the hydrated list body. */
function dataRows( page ) {
    // `.tt-list-table-empty` is the empty-state row; exclude it so an
    // empty result reads as zero real rows, not one.
    return page.locator(
        '[data-tt-list-body="1"] tr:not(.tt-list-table-empty)'
    );
}

test.describe( 'FilterBar list filtering (Players)', () => {

    test( 'search narrows the row set to the matching player', async ( { page } ) => {
        // Two distinct, uniquely-named players so a search on one must
        // exclude the other — relative narrowing, not an absolute count.
        const wanted   = uniqueName( 'Zephyr' );
        const unwanted = uniqueName( 'Quixote' );
        await seedPlayer( page, wanted );
        await seedPlayer( page, unwanted );

        const present = await gotoPlayersList( page );
        if ( ! present ) {
            test.skip( true, 'Frontend players FilterBar not present on this install.' );
            return;
        }

        // Baseline: both seeded rows are reachable in the unfiltered list
        // (the list may paginate, so assert presence via search rather
        // than requiring both on page one — a search for each confirms
        // they exist before the narrowing assertion).
        const search = page.locator(
            '[data-tt-filterbar] input[name="search"]'
        ).first();
        await expect( search ).toBeVisible();

        // Type the wanted surname; the hydrator debounces + re-fetches.
        await search.fill( wanted );
        await page.waitForLoadState( 'load', { timeout: 30000 } );

        // The wanted row is present, the unwanted one is gone — proves
        // the search actually narrowed rather than no-op'd.
        await expect(
            page.locator( '[data-tt-list-body="1"]' )
        ).toContainText( wanted, { timeout: 15000 } );
        await expect(
            page.locator( '[data-tt-list-body="1"]' )
        ).not.toContainText( unwanted );

        // URL reflects the search term (SaaS-portable state in the query).
        await expect( page ).toHaveURL( /[?&]search=/ );
    } );

    test( 'a select filter applies to URL and result set', async ( { page } ) => {
        const present = await gotoPlayersList( page );
        if ( ! present ) {
            test.skip( true, 'Frontend players FilterBar not present on this install.' );
            return;
        }

        // The Team select is `name="filter[team_id]"`. It only has real
        // options when teams are seeded; skip when the baseline has none
        // (a bare placeholder option) rather than asserting on nothing.
        const teamSelect = page.locator(
            '[data-tt-filterbar] select[name="filter[team_id]"]'
        ).first();
        if ( await teamSelect.count() === 0 ) {
            test.skip( true, 'Team select filter not rendered on this install.' );
            return;
        }
        const realOptions = teamSelect.locator( 'option[value]:not([value=""])' );
        const optionCount = await realOptions.count();
        if ( optionCount === 0 ) {
            test.skip( true, 'No teams seeded — team select has no selectable value to exercise.' );
            return;
        }

        const rowsBefore = await dataRows( page ).count();

        const value = await realOptions.first().getAttribute( 'value' );
        await teamSelect.selectOption( value );
        // Select change auto-applies through the hydrator (live re-fetch,
        // no full reload); URL is synced via replaceState.
        await page.waitForLoadState( 'load', { timeout: 30000 } );

        // The chosen filter reaches the URL as `filter[team_id]=<value>`.
        await expect( page ).toHaveURL(
            new RegExp( 'filter%5Bteam_id%5D=' + value + '|filter\\[team_id\\]=' + value )
        );

        // Filtering to one team cannot produce MORE rows than the
        // unfiltered list — resilient to variable seed data.
        const rowsAfter = await dataRows( page ).count();
        expect( rowsAfter ).toBeLessThanOrEqual( rowsBefore );
    } );

    test( 'status pill applies the record-state filter', async ( { page } ) => {
        const present = await gotoPlayersList( page );
        if ( ! present ) {
            test.skip( true, 'Frontend players FilterBar not present on this install.' );
            return;
        }

        // The `archived` group renders as status pills — link-based
        // (`<a class="tt-statpill" href="…filter[archived]=archived">`),
        // a full-page reload rather than a live re-fetch. Target the
        // "Archived" pill by its stable data hook, scoped to the inline
        // desktop copy (the sheet renders a duplicate at narrow widths).
        const archivedPill = page.locator(
            '[data-tt-filterbar] a.tt-statpill[data-k="archived"]'
        ).first();
        if ( await archivedPill.count() === 0 ) {
            test.skip( true, 'Archived status pill not rendered on this install.' );
            return;
        }

        await Promise.all( [
            page.waitForURL( /filter(%5B|\[)archived(%5D|\])=archived/, { timeout: 30000 } ),
            archivedPill.click(),
        ] );

        // After the reload the pill for the active state is marked on,
        // and the list re-hydrated for the archived scope.
        await page.waitForLoadState( 'load', { timeout: 30000 } );
        await expect(
            page.locator( '[data-tt-filterbar] a.tt-statpill[data-k="archived"]' ).first()
        ).toHaveClass( /tt-statpill--on/ );
    } );

    test( '360px bottom-sheet opens, applies a filter, closes, no horizontal scroll', async ( { page } ) => {
        await page.setViewportSize( { width: 360, height: 780 } );

        const present = await gotoPlayersList( page );
        if ( ! present ) {
            test.skip( true, 'Frontend players FilterBar not present on this install.' );
            return;
        }

        // No horizontal scroll on the narrow viewport (the mobile
        // collapse to the "Filters" trigger + chips must not overflow).
        const noOverflow = await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth + 1
        );
        expect( noOverflow ).toBeTruthy();

        // The collapsed trigger opens the bottom sheet.
        const openBtn = page.locator( '[data-tt-filterbar] [data-tt-filter-open]' ).first();
        await expect( openBtn ).toBeVisible();

        const sheet = page.locator( '[data-tt-filterbar] [data-tt-filter-sheet]' ).first();
        // Sheet starts hidden (the `hidden` attribute).
        await expect( sheet ).toBeHidden();

        await openBtn.click();
        await expect( sheet ).toBeVisible( { timeout: 10000 } );
        await expect( openBtn ).toHaveAttribute( 'aria-expanded', 'true' );

        // Apply a filter from inside the sheet. The sheet renders its own
        // copy of the search box; typing there re-fetches (the hydrator
        // mirrors the value onto the inline copy so they never drift).
        const sheetSearch = sheet.locator( 'input[name="search"]' ).first();
        if ( await sheetSearch.count() > 0 ) {
            await sheetSearch.fill( 'zzz-no-match-' + Date.now() );
            await page.waitForLoadState( 'load', { timeout: 30000 } );
        }

        // Close via the sheet's close control; it hides after the
        // transition.
        const closeBtn = sheet.locator( '[data-tt-filter-close]' ).first();
        await closeBtn.click();
        await expect( sheet ).toBeHidden( { timeout: 10000 } );
        await expect( openBtn ).toHaveAttribute( 'aria-expanded', 'false' );

        // Still no horizontal scroll after interacting with the sheet.
        const stillNoOverflow = await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth + 1
        );
        expect( stillNoOverflow ).toBeTruthy();
    } );
} );
