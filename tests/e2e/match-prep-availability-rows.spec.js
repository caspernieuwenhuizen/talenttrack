// @ts-check
const { test, expect } = require( '@playwright/test' );
const { rest, seedMatchPrep, archiveSeeded } = require( './helpers/match-prep' );

/**
 * Match prep availability rows at every width (#4703, #4704).
 *
 * From 1024px the name used to sit beside the status chips. Inside the
 * wizard frame that left each chip about 67px, so short labels broke
 * mid-word, and the "Check availability" badge ran out of the name column
 * under the first chip. The name now stays above the chips at every width.
 *
 * Seeds a prep with two players, one of them carrying an injury whose
 * expected return has passed by the match date, which is what puts the
 * badge beside the name. Skips when the install cannot seed a prep.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/**
 * Per row: where the name, the badge and the chips sit, and how many text
 * lines each chip label takes.
 *
 * @param {import('@playwright/test').Page} page
 */
function measureRows( page ) {
	return page.evaluate( () => {
		return Array.from( document.querySelectorAll( '.tt-avail-row' ) ).map( ( row ) => {
			const name = /** @type {HTMLElement} */ ( row.querySelector( '.tt-avail-name' ) ).getBoundingClientRect();
			const segEl = /** @type {HTMLElement} */ ( row.querySelector( '.tt-avail-seg' ) );
			const seg = segEl.getBoundingClientRect();
			const badgeEl = row.querySelector( '.tt-mp-check' );
			const badge = badgeEl ? badgeEl.getBoundingClientRect() : null;
			const chips = Array.from( segEl.querySelectorAll( 'label' ) ).map( ( label ) => {
				const r = label.getBoundingClientRect();
				const range = document.createRange();
				range.selectNodeContents( label );
				const lines = new Set( Array.from( range.getClientRects() ).map( ( x ) => Math.round( x.top ) ) ).size;
				return { text: ( label.textContent || '' ).trim(), top: Math.round( r.top ), height: r.height, lines };
			} );
			return {
				nameBottom: name.bottom,
				nameRight: name.right,
				segTop: seg.top,
				gap: getComputedStyle( segEl ).columnGap,
				badge: badge ? { right: badge.right, bottom: badge.bottom } : null,
				chips,
			};
		} );
	} );
}

test.describe( 'Match prep availability rows (#4703, #4704)', () => {

	/** @type {Array<string>} */
	const created = [];
	/** @type {string} */
	let wizardUrl = '';
	/** @type {string} */
	let skipWhy = '';

	test.beforeEach( async ( { page } ) => {
		const seeded = await seedMatchPrep( page, created, 2 );
		if ( typeof seeded === 'string' ) {
			skipWhy = seeded;
			return;
		}
		// The seeded match is on 2026-12-12; a return expected before it
		// that never happened is a record the coach has to check.
		const injury = await rest( page, 'POST', 'players/' + seeded.playerIds[ 0 ] + '/injuries', {
			started_on: '2026-11-01',
			expected_return: '2026-11-20',
		} );
		const injuryId = injury.json && injury.json.data ? Number( injury.json.data.id ) : 0;
		if ( ! injuryId ) {
			skipWhy = 'Injury create refused: ' + injury.status;
			return;
		}
		created.push( 'player-injuries/' + injuryId );
		skipWhy = '';
		wizardUrl = '/?tt_view=wizard&slug=match-prep&activity_id=' + seeded.activityId;
	} );

	test.afterEach( async ( { page } ) => {
		await archiveSeeded( page, created );
	} );

	for ( const width of [ 480, 768, 1024, 1280 ] ) {
		test( `the name sits above one line of chips at ${ width }px`, async ( { page } ) => {
			test.skip( skipWhy !== '', skipWhy );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( wizardUrl );
			await page.waitForLoadState( 'load', { timeout: 30000 } );

			const rows = await measureRows( page );
			expect( rows.length, 'one row per seeded player' ).toBe( 2 );
			expect( rows.filter( ( r ) => r.badge !== null ).length, 'the injured player carries the badge' ).toBe( 1 );

			for ( const row of rows ) {
				expect( row.nameBottom, 'the name is above the chips' ).toBeLessThanOrEqual( row.segTop + 0.5 );
				expect( row.gap ).toBe( '8px' );
				expect( new Set( row.chips.map( ( c ) => c.top ) ).size, 'the chips share one line' ).toBe( 1 );
				for ( const chip of row.chips ) {
					expect( chip.height, chip.text + ' is a 48px target' ).toBeGreaterThanOrEqual( 47.5 );
					if ( width >= 768 ) {
						expect( chip.lines, chip.text + ' fits on one line' ).toBe( 1 );
					}
				}
				if ( row.badge ) {
					expect( row.badge.right, 'the badge stays inside the name' ).toBeLessThanOrEqual( row.nameRight + 0.5 );
					expect( row.badge.bottom, 'the badge stays above the chips' ).toBeLessThanOrEqual( row.segTop + 0.5 );
				}
			}
		} );
	}
} );
