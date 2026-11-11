// @ts-check
/**
 * #4980 — the trainer fills in a PDP conversation form and signs it off, on
 * a phone. The talk is `Mila Trainer4980`'s, from
 * `tests/e2e/setup/pdp-preparation.php`; the admin is the trainer.
 *
 * After sign-off the player's evaluations hold one from the conversation,
 * and the goals list holds the two development points.
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
 * Open the fixture talk's conversation screen.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<{ form: import('@playwright/test').Locator, playerId: number }>}
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
	const playerId = Number( file.player_id || ( detail.json.data && detail.json.data.player_id ) );

	await page.goto( `/?tt_view=pdp&id=${ file.id }&conv=${ conv.id }` );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	await page.getByRole( 'tab', { name: 'Conversation' } ).click();

	const form = page.locator( '[data-tt-pdp-coachform]' );
	await expect( form ).toBeVisible();
	return { form, playerId };
}

/** The first item's choices, with its fold open. */
async function firstRow( form ) {
	const fold = form.locator( 'details.tt-pdp-coachform__section' ).first();
	if ( ! ( await fold.evaluate( ( el ) => el.open ) ) ) await fold.locator( 'summary' ).tap();
	return form.locator( '.tt-rating-stars--choices' ).first();
}

/** Wait until every edit is stored: the save state settles on saved. */
async function settled( form ) {
	await expect( form.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/, { timeout: 15000 } );
}

/** A REST refusal in the plugin's envelope. */
function refusal( code, message ) {
	return {
		status: 409,
		contentType: 'application/json',
		body: JSON.stringify( { success: false, data: null, errors: [ { code, message, details: {} } ] } ),
	};
}

test( 'the trainer scores every item and writes the agreements', async ( { page } ) => {
	const { form } = await openTalk( page );
	await expect( form.locator( '.tt-pdp-coachform__player' ).first(), 'the player has not prepared' ).toContainText( 'not ready yet' );

	// Open every fold, then score every item 3.
	const folds = form.locator( 'details.tt-pdp-coachform__section' );
	for ( let i = 0; i < await folds.count(); i++ ) {
		const fold = folds.nth( i );
		if ( ! ( await fold.evaluate( ( el ) => el.open ) ) ) await fold.locator( 'summary' ).tap();
	}
	const rows = form.locator( '.tt-rating-stars--choices' );
	expect( await rows.count() ).toBe( 17 );
	for ( let i = 0; i < 17; i++ ) {
		const choice = rows.nth( i ).locator( 'button[data-value="3"]' );
		const box    = await choice.boundingBox();
		expect( box && box.height >= 48 && box.width >= 48, `choice ${ i } is at least 48px` ).toBe( true );
		// #5081 — tapping the chosen score clears it, so only tap one that
		// is not chosen yet (an earlier spec may have left a 3).
		if ( ( await choice.getAttribute( 'aria-checked' ) ) !== 'true' ) await choice.tap();
		await expect( choice ).toHaveAttribute( 'aria-checked', 'true' );
	}

	await form.locator( 'textarea[name="answers[strongest_quality]"]' ).fill( 'Afgesproken: de passing met links.' );
	for ( const slot of [ 1, 2 ] ) {
		await form.locator( `textarea[name="goals[${ slot }][action]"]` ).fill( `Ontwikkelpunt ${ slot }: elke training twintig passes links.` );
		await form.locator( `textarea[name="goals[${ slot }][support]"]` ).fill( 'Extra oefenvorm na de training.' );
		await form.locator( `input[name="goals[${ slot }][due_date]"]` ).fill( '2027-01-31' );
	}

	const signoff = page.locator( '[data-tt-coach-signoff]' );
	await expect( signoff, 'the form is complete: sign-off opens' ).toBeEnabled( { timeout: 15000 } );
	await expect( page.locator( '[data-tt-coach-missing]' ) ).toBeHidden();

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );
	await settled( form );
} );

test( 'a sign-off the server refuses says why, keeps undo, and leaves the form saving', async ( { page } ) => {
	const { form } = await openTalk( page );
	const message  = 'The conversation form was changed meanwhile. Reload the form and try again.';
	const signoff  = ( url ) => decodeURIComponent( url.href ).includes( '/signoff' );
	await page.route( signoff, ( route ) => ( route.request().method() === 'POST' ? route.fulfill( refusal( 'form_changed', message ) ) : route.continue() ) );

	// A real, stored change: the one undo should take back.
	const row = await firstRow( form );
	await expect( row.locator( '[aria-checked="true"]' ) ).toHaveAttribute( 'data-value', '3' );
	await row.locator( 'button[data-value="4"]' ).tap();
	await settled( form );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '[data-tt-coach-signoff]' ).tap();
	await expect( page.locator( '[data-tt-coach-signoff-box] .tt-form-msg' ), 'the server\'s reason' ).toHaveText( message, { timeout: 15000 } );
	await expect( form.locator( '[data-tt-save-state]' ), 'a stale sign-off does not halt the form' ).toHaveClass( /is-saved/ );
	await expect( page.locator( '[data-tt-coach-signoff]' ) ).toBeEnabled();

	const undo = form.locator( '[data-tt-save-undo]' );
	await expect( undo, 'undo survives the refusal' ).toBeVisible();
	await undo.tap();
	await expect( row.locator( '[aria-checked="true"]' ), 'undo took the change back' ).toHaveAttribute( 'data-value', '3' );
	await settled( form );
	await page.unroute( signoff );
} );

test( 'a halted form gives one reason, in the line and in the save state, and sends no sign-off', async ( { page } ) => {
	const { form } = await openTalk( page );
	const message  = 'This conversation has already been signed off.';
	const coach    = ( url ) => decodeURIComponent( url.href ).includes( '/form/coach' );
	await page.route( coach, ( route ) => ( route.request().method() === 'PATCH' ? route.fulfill( refusal( 'already_signed_off', message ) ) : route.continue() ) );
	let posted = false;
	page.on( 'request', ( r ) => { if ( r.url().includes( '/signoff' ) && r.method() === 'POST' ) posted = true; } );

	const row = await firstRow( form );
	await row.locator( 'button[data-value="4"]' ).tap();
	const state = form.locator( '[data-tt-save-state]' );
	await expect( state, 'the save halts with the server\'s reason' ).toHaveText( message, { timeout: 15000 } );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '[data-tt-coach-signoff]' ).tap();
	await expect( page.locator( '[data-tt-coach-signoff-box] .tt-form-msg' ) ).toHaveText( message );
	await expect( form.locator( '[data-tt-save-undo]' ), 'nothing writes once halted' ).toBeHidden();
	expect( posted, 'no sign-off goes out from a halted form' ).toBe( false );

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'the line wraps: no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );
	await page.unroute( coach );
} );

test( 'the trainer signs off, which writes the evaluation and the two goals', async ( { page } ) => {
	const { form, playerId } = await openTalk( page );
	const signoff = page.locator( '[data-tt-coach-signoff]' );
	await expect( signoff, 'the form stored in the first test is complete' ).toBeEnabled();
	await expect( form.locator( '.tt-rating-stars--choices' ).first().locator( '[aria-checked="true"]' ), 'undo restored the score' ).toHaveAttribute( 'data-value', '3' );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await Promise.all( [
		page.waitForResponse( ( r ) => r.url().includes( '/signoff' ) && r.request().method() === 'POST' && r.status() === 201, { timeout: 30000 } ),
		signoff.tap(),
	] );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	await page.getByRole( 'tab', { name: 'Conversation' } ).click();
	await expect( page.locator( '.tt-pdp-coachform__signed' ) ).toBeVisible();
	await expect( page.locator( '.tt-pdp-coachform__made' ) ).toHaveCount( 2 );

	// The evaluation the radar and the history read, and the two goals.
	const evals = rowsOf( ( await get( page, `players/${ playerId }/evaluations` ) ).json );
	expect( evals.length, 'the player has the evaluation from the conversation' ).toBeGreaterThanOrEqual( 1 );
	const goals = rowsOf( ( await get( page, `players/${ playerId }/goals` ) ).json );
	expect( goals.filter( ( g ) => String( g.title || '' ).startsWith( 'Ontwikkelpunt' ) ).length, 'two goals from the development points' ).toBe( 2 );
} );
