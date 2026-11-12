// @ts-check
/**
 * #5081 — on a phone, the trainer clears a score three ways (tap the chosen
 * score again, the Clear button, Space on it), each clear is saved as null
 * and undo brings the score back; and the form's sections read as folds,
 * with open all / close all working by keyboard.
 *
 * The talk is `Mila Trainer4980`'s, from `tests/e2e/setup/pdp-preparation.php`.
 * It runs before `pdp-trainer-form.spec.js` and leaves the first score empty,
 * which that spec then fills in.
 */
const { test, expect } = require( '@playwright/test' );

test.use( {
	storageState: 'tests/e2e/.auth/admin.json',
	viewport: { width: 360, height: 800 },
	hasTouch: true,
	isMobile: true,
} );

/**
 * A REST GET with the dashboard's nonce.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 */
function get( page, path ) {
	return page.evaluate( async ( p ) => {
		const res = await fetch( window.TT.rest_url + p, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.TT.rest_nonce },
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, path );
}

/** The rows of a list answer, whichever envelope it uses. */
function rowsOf( json ) {
	const data = json && json.data !== undefined ? json.data : json;
	if ( Array.isArray( data ) ) return data;
	if ( data && Array.isArray( data.rows ) ) return data.rows;
	if ( data && Array.isArray( data.items ) ) return data.items;
	return [];
}

test.describe.configure( { mode: 'serial' } );

/**
 * @param {import('@playwright/test').Page} page
 * @return {Promise<number>} the conversation id
 */
async function openTalk( page ) {
	await page.goto( '/?tt_view=pdp' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const files = await get( page, 'pdp-files?search=Trainer4980' );
	const file  = rowsOf( files.json ).find( ( r ) => r && r.id );
	expect( file, 'the fixture file: run tests/e2e/setup/pdp-preparation.php first. ' + JSON.stringify( files.json ) ).toBeTruthy();
	const detail = await get( page, 'pdp-files/' + file.id );
	const convs  = ( detail.json && detail.json.data && detail.json.data.conversations ) || [];
	const conv   = convs.find( ( c ) => Number( c.sequence ) === 1 ) || convs[ 0 ];
	expect( conv && conv.id, 'the first talk' ).toBeTruthy();
	await page.goto( `/?tt_view=pdp&id=${ file.id }&conv=${ conv.id }` );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	await page.getByRole( 'tab', { name: 'Conversation' } ).click();
	return Number( conv.id );
}

/** @param {import('@playwright/test').Locator} form */
async function settled( form ) {
	await expect( form.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/, { timeout: 15000 } );
}

test( 'the sections read as folds, and open all / close all work by keyboard', async ( { page } ) => {
	await openTalk( page );
	const form     = page.locator( '[data-tt-pdp-coachform]' );
	const sections = form.locator( '[data-tt-pdp-section]' );
	const count    = await sections.count();
	expect( count ).toBeGreaterThan( 2 );

	const sum = sections.first().locator( 'summary' );
	const box = await sum.boundingBox();
	expect( box && box.height >= 56, 'a section row is at least 56px' ).toBe( true );
	await expect( sum.locator( '.tt-pdp-coachform__chev' ) ).toHaveCount( 1 );
	await expect( sum.locator( '.tt-pdp-coachform__count' ) ).toContainText( 'scored' );
	for ( let i = 0; i < count; i++ ) {
		await expect( sections.nth( i ).locator( ':scope > summary .tt-pdp-coachform__count' ), `section ${ i } has a count line` ).toHaveCount( 1 );
	}
	await expect( sections.last().locator( '[data-tt-pdp-goals-empty]' ), 'the SMART points say how many are still empty' ).toHaveCount( 1 );
	await expect( form.locator( '.tt-pdp-coachform__legend' ) ).toContainText( 'Tap your score again, or choose Clear, to empty it.' );

	const close = form.locator( '[data-tt-pdp-folds="close"]' );
	const open  = form.locator( '[data-tt-pdp-folds="open"]' );
	for ( const b of [ close, open ] ) {
		const bb = await b.boundingBox();
		expect( bb && bb.height >= 48, 'open all / close all are at least 48px' ).toBe( true );
	}

	await close.focus();
	await page.keyboard.press( 'Enter' );
	for ( let i = 0; i < count; i++ ) {
		expect( await sections.nth( i ).evaluate( ( el ) => el.open ), `section ${ i } closed` ).toBe( false );
	}
	await expect( sum.locator( '.tt-pdp-coachform__toggle-open' ) ).toBeVisible();

	await open.focus();
	await page.keyboard.press( 'Enter' );
	for ( let i = 0; i < count; i++ ) {
		expect( await sections.nth( i ).evaluate( ( el ) => el.open ), `section ${ i } open` ).toBe( true );
	}
	await expect( sum.locator( '.tt-pdp-coachform__toggle-close' ) ).toBeVisible();

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );
} );

test( 'a score clears by tapping it again, with Clear, and with Space, and undo brings it back', async ( { page } ) => {
	const conv = await openTalk( page );
	const form = page.locator( '[data-tt-pdp-coachform]' );
	const fold = form.locator( 'details.tt-pdp-coachform__section' ).first();
	if ( ! ( await fold.evaluate( ( el ) => el.open ) ) ) await fold.locator( 'summary' ).tap();

	const row    = form.locator( '.tt-rating-row--choices' ).first();
	const two    = row.locator( 'button[data-value="2"]' );
	const clear  = row.locator( '[data-tt-rating-clear]' );
	const name   = String( await row.locator( '[data-tt-rating-value]' ).getAttribute( 'name' ) );
	const key    = name.replace( /^scores\[/, '' ).replace( /\]$/, '' );
	const stored = async () => {
		const res = await get( page, `pdp-conversations/${ conv }/form` );
		const scores = ( res.json && res.json.data && res.json.data.coach && res.json.data.coach.scores ) || {};
		return scores[ key ] === undefined ? null : scores[ key ];
	};

	if ( ( await two.getAttribute( 'aria-checked' ) ) !== 'true' ) await two.tap();
	await settled( form );
	await expect( clear, 'Clear shows while a score is set' ).toBeVisible();

	// 1. Tap the chosen score again.
	await two.tap();
	await expect( row.locator( '[aria-checked="true"]' ) ).toHaveCount( 0 );
	await settled( form );
	expect( await stored(), 'saved as null' ).toBeNull();
	await expect( clear ).toBeHidden();

	await form.locator( '[data-tt-save-undo]' ).tap();
	await expect( two, 'undo brings it back' ).toHaveAttribute( 'aria-checked', 'true', { timeout: 15000 } );
	await settled( form );
	expect( await stored() ).toBe( 2 );

	// 2. The Clear button, the non-gesture path.
	const cb = await clear.boundingBox();
	expect( cb && cb.height >= 48, 'Clear is at least 48px' ).toBe( true );
	await clear.tap();
	await expect( row.locator( '[aria-checked="true"]' ) ).toHaveCount( 0 );
	await settled( form );
	expect( await stored() ).toBeNull();

	// 3. Space on the chosen score.
	await two.tap();
	await settled( form );
	await two.focus();
	await page.keyboard.press( 'Space' );
	await expect( row.locator( '[aria-checked="true"]' ) ).toHaveCount( 0 );
	await settled( form );
	expect( await stored() ).toBeNull();

	// The sign-off list names what is still needed, the cleared item too.
	const label = String( await form.locator( '.tt-pdp-coachform__iname > span' ).first().textContent() ).trim();
	await expect( page.locator( '[data-tt-coach-missing]' ) ).toBeVisible();
	await expect( page.locator( '[data-tt-coach-missing]' ) ).toContainText( label );
} );
