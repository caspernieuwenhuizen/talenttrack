// @ts-check
const { test, expect } = require( '@playwright/test' );
const { seedMatchPrep, archiveSeeded } = require( './helpers/match-prep' );

/**
 * Match prep pitch at phone width (#4352).
 *
 * Side by side at 360px each pitch was ~145px wide and a 64px slot covered
 * its neighbour's centre, so a thumb aimed at slot 5 opened slot 4. Below
 * 480px the halves now stack and slots are 48px; from 480px nothing
 * changes. The picker's Clear is a real button.
 *
 * Desktop Chrome with a narrow viewport and touch on: the phone user agent
 * would be sent to the desktop-only prompt, and this checks the layout, not
 * the interception. Skips when the install cannot seed a prep.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json', hasTouch: true } );

const SHAPES = [ '4-3-3', '4-4-2', '3-5-2', '3-4-3' ];

/**
 * Pick the first formation template of `shape` in the prep's select.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} shape
 * @returns {Promise<boolean>} false when no template of that shape exists
 */
async function pickShape( page, shape ) {
	const option = page.locator( '[data-tt-mp-formation] option[data-shape="' + shape + '"]' ).first();
	if ( ( await option.count() ) === 0 ) return false;
	const value = await option.getAttribute( 'value' );
	await page.selectOption( '[data-tt-mp-formation]', String( value ) );
	return true;
}

/**
 * For every slot on both pitches: is its own centre hit by itself, how tall
 * is it, and does it overlap the next slot on the same line.
 *
 * @param {import('@playwright/test').Page} page
 */
function measureSlots( page ) {
	return page.evaluate( () => {
		const out = { covered: [], short: [], overlaps: [], count: 0 };
		document.querySelectorAll( '.tt-mp-pitch' ).forEach( ( pitch ) => {
			pitch.scrollIntoView( { block: 'center' } );
			const half = pitch.getAttribute( 'data-half' );
			const boxes = [];
			pitch.querySelectorAll( '.tt-mp-slot' ).forEach( ( slot, i ) => {
				const r = slot.getBoundingClientRect();
				const hit = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
				const owner = hit && hit.closest ? hit.closest( '.tt-mp-slot' ) : null;
				if ( owner !== slot ) out.covered.push( half + ':' + i );
				if ( r.height < 47.5 ) out.short.push( half + ':' + i + '=' + Math.round( r.height ) );
				boxes.push( { i, left: r.left, right: r.right, top: r.top, bottom: r.bottom } );
				out.count++;
			} );
			boxes.forEach( ( a ) => {
				boxes.forEach( ( b ) => {
					if ( a.i >= b.i ) return;
					const xOverlap = Math.min( a.right, b.right ) - Math.max( a.left, b.left );
					const yOverlap = Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top );
					// Touching edges (the 14% lines) are allowed; overlap is not.
					if ( xOverlap > 0.5 && yOverlap > 0.5 ) out.overlaps.push( half + ':' + a.i + '/' + b.i );
				} );
			} );
		} );
		return out;
	} );
}

test.describe( 'Match prep pitch at phone width (#4352)', () => {

	/** @type {Array<string>} */
	const created = [];
	/** @type {string} */
	let prepUrl = '';
	/** @type {string} */
	let skipWhy = '';

	test.beforeEach( async ( { page } ) => {
		const seeded = await seedMatchPrep( page, created, 11 );
		if ( typeof seeded === 'string' ) {
			skipWhy = seeded;
			return;
		}
		skipWhy = '';
		prepUrl = '/?tt_view=match-prep&activity_id=' + seeded.activityId;
	} );

	test.afterEach( async ( { page } ) => {
		await archiveSeeded( page, created );
	} );

	for ( const width of [ 360, 414 ] ) {
		test( `every slot takes a tap at its own centre at ${ width }px`, async ( { page } ) => {
			test.skip( skipWhy !== '', skipWhy );
			await page.setViewportSize( { width, height: 800 } );
			await page.goto( prepUrl );
			await page.waitForLoadState( 'load', { timeout: 30000 } );

			const columns = await page.locator( '[data-tt-mp-pitches]' ).evaluate( ( el ) => getComputedStyle( el ).gridTemplateColumns.split( ' ' ).length );
			expect( columns, 'the halves stack below 480px' ).toBe( 1 );

			for ( const shape of SHAPES ) {
				if ( ! ( await pickShape( page, shape ) ) ) continue;
				const m = await measureSlots( page );
				expect( m.count, shape ).toBeGreaterThan( 0 );
				expect( m.covered, shape + ': a neighbour covers these slot centres' ).toEqual( [] );
				expect( m.short, shape + ': slots under 48px tall' ).toEqual( [] );
				expect( m.overlaps, shape + ': overlapping slots' ).toEqual( [] );
			}

			// A real tap on a slot opens that slot's picker.
			const slot = page.locator( '.tt-mp-pitch[data-half="1"] .tt-mp-slot' ).nth( 4 );
			await slot.scrollIntoViewIfNeeded();
			await slot.tap();
			await expect( page.locator( '[data-tt-mp-picker]' ) ).toBeVisible();
		} );
	}

	test( 'Clear is a keyboard-reachable button and the picker rows meet 48px under a finger', async ( { page } ) => {
		test.skip( skipWhy !== '', skipWhy );
		await page.setViewportSize( { width: 360, height: 800 } );
		await page.goto( prepUrl );
		await page.waitForLoadState( 'load', { timeout: 30000 } );

		const slot = page.locator( '.tt-mp-pitch[data-half="1"] .tt-mp-slot' ).first();
		await slot.scrollIntoViewIfNeeded();
		await slot.tap();
		const picker = page.locator( '[data-tt-mp-picker]' );
		// Clicked by event, not by position: where the picker opens on a
		// scrolled page is #4263's subject, not this spec's.
		await picker.locator( '.tt-mp-picker-row' ).first().dispatchEvent( 'click' );
		await expect( picker ).toBeHidden();
		await expect( slot ).not.toHaveClass( /tt-mp-slot-empty/ );

		await slot.tap();
		const clear = picker.locator( '[data-tt-mp-picker-clear]' );
		await expect( clear ).toBeVisible();
		expect( await clear.evaluate( ( el ) => el.tagName ) ).toBe( 'BUTTON' );

		const coarse = await page.evaluate( () => window.matchMedia( '(pointer: coarse)' ).matches );
		if ( coarse ) {
			for ( const el of [ clear, picker.locator( '.tt-mp-picker-row' ).first() ] ) {
				const box = await el.boundingBox();
				expect( box && box.height ).toBeGreaterThanOrEqual( 47.5 );
			}
		}

		// Tab from the last row lands on Clear; Space presses it.
		await picker.locator( '.tt-mp-picker-row' ).last().focus();
		await page.keyboard.press( 'Tab' );
		await expect( clear ).toBeFocused();
		await page.keyboard.press( 'Space' );
		await expect( picker ).toBeHidden();
		await expect( slot ).toHaveClass( /tt-mp-slot-empty/ );
	} );

	for ( const width of [ 768, 1280 ] ) {
		test( `the halves stay side by side with 4rem slots at ${ width }px`, async ( { page } ) => {
			test.skip( skipWhy !== '', skipWhy );
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( prepUrl );
			await page.waitForLoadState( 'load', { timeout: 30000 } );

			const columns = await page.locator( '[data-tt-mp-pitches]' ).evaluate( ( el ) => getComputedStyle( el ).gridTemplateColumns.split( ' ' ).length );
			expect( columns ).toBe( 3 );
			const box = await page.locator( '.tt-mp-pitch[data-half="1"] .tt-mp-slot' ).first().boundingBox();
			expect( box && Math.round( box.width ) ).toBe( 64 );
		} );
	}
} );
