// @ts-check
/**
 * #5080 — a PDP talk is two parts, each with its own save state and undo in
 * its header. On a phone the save state wraps under the title, part 2's
 * header stays in view while the form scrolls, an undo in part 2 leaves
 * part 1's dates alone, and a form talk asks no notes or agreed actions.
 *
 * The talk is `Mila Trainer4980`'s, from `tests/e2e/setup/pdp-preparation.php`.
 * It runs before `pdp-trainer-form.spec.js`, and leaves the first score on 3,
 * which that spec sets on every item anyway.
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

/** @param {import('@playwright/test').Page} page */
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
}

test( 'two parts, each with its own save state, and part 2\'s header stays in view', async ( { page } ) => {
	await openTalk( page );

	const details = page.locator( '.tt-pdp-part--details' );
	const form    = page.locator( '.tt-pdp-part.tt-pdp-coachform' );
	await expect( details.locator( '.tt-pdp-part__head [data-tt-save-state]' ) ).toHaveCount( 1 );
	await expect( form.locator( '.tt-pdp-part__head [data-tt-save-state]' ) ).toHaveCount( 1 );

	// A form talk: no notes and no agreed actions in part 1.
	await expect( page.locator( 'textarea[name="notes"]' ) ).toHaveCount( 0 );
	await expect( page.locator( 'textarea[name="agreed_actions"]' ) ).toHaveCount( 0 );

	// At 360px the save state wraps under the title.
	const title = await form.locator( '.tt-pdp-part__title' ).boundingBox();
	const state = await form.locator( '.tt-pdp-part__state' ).boundingBox();
	expect( title && state && state.y >= title.y + title.height - 1, 'the save state sits under the title on a phone' ).toBe( true );

	// Scroll to the end of the form: part 2's header is still on screen.
	await form.locator( '.tt-pdp-coachform__section' ).last().scrollIntoViewIfNeeded();
	const head = await form.locator( '.tt-pdp-part__head' ).boundingBox();
	expect( head && head.y >= 0 && head.y + head.height <= 800, 'part 2\'s header stays in view: ' + JSON.stringify( head ) ).toBe( true );

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );
} );

test( 'an undo in part 2 takes back a score and leaves part 1 alone', async ( { page } ) => {
	await openTalk( page );

	const details   = page.locator( '.tt-pdp-part--details' );
	const coachForm = page.locator( '[data-tt-pdp-coachform]' );
	const scheduled = await details.locator( 'input[name="scheduled_at"]' ).inputValue();

	const fold = coachForm.locator( 'details.tt-pdp-coachform__section' ).first();
	if ( ! ( await fold.evaluate( ( el ) => el.open ) ) ) await fold.locator( 'summary' ).tap();
	const row = coachForm.locator( '.tt-rating-stars--choices' ).first();

	await row.locator( 'button[data-value="3"]' ).tap();
	await expect( coachForm.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/, { timeout: 15000 } );
	await row.locator( 'button[data-value="4"]' ).tap();
	await expect( coachForm.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/, { timeout: 15000 } );

	await expect( coachForm.locator( '[data-tt-save-undo]' ), 'part 2 offers its undo' ).toBeVisible();
	await expect( details.locator( '[data-tt-save-undo]' ), 'part 1 has nothing to undo' ).toBeHidden();

	await coachForm.locator( '[data-tt-save-undo]' ).tap();
	await expect( row.locator( '[aria-checked="true"]' ) ).toHaveAttribute( 'data-value', '3', { timeout: 15000 } );
	await expect( coachForm.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/, { timeout: 15000 } );
	await expect( details.locator( 'input[name="scheduled_at"]' ), 'the date is untouched' ).toHaveValue( scheduled );
	await expect( details.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/ );
} );
