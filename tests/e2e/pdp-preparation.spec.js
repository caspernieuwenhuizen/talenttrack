// @ts-check
/**
 * #4979 — the player prepares a PDP form conversation in My PDP, on a phone.
 *
 * Runs against the two players `tests/e2e/setup/pdp-preparation.php` writes:
 * `e2e_pop_open`, whose talk is in 5 days (inside the window), and
 * `e2e_pop_locked`, whose talk was yesterday, so the clock is fixed by the
 * data rather than moved. The specs run in order on one player: filling the
 * form, then "klaar" and "toch aanpassen".
 */
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.use( { viewport: { width: 360, height: 800 }, hasTouch: true, isMobile: true } );
test.describe.configure( { mode: 'serial' } );

/**
 * Sign a player in, in a context of its own.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string|undefined} baseURL
 * @param {string} login
 */
async function playerPage( browser, baseURL, login ) {
	const context = await browser.newContext( {
		baseURL,
		viewport: { width: 360, height: 800 },
		hasTouch: true,
		isMobile: true,
	} );
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.fill( 'input[name="log"]', login );
	await page.fill( 'input[name="pwd"]', 'password' );
	await Promise.all( [
		page.waitForLoadState( 'load', { timeout: 30000 } ),
		page.click( 'input#wp-submit' ),
	] );
	const signedIn = ( await context.cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in' ) );
	expect( signedIn, `${ login } signs in: run tests/e2e/setup/pdp-preparation.php first` ).toBe( true );
	await page.goto( '/?tt_view=my-pdp' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return { context, page };
}

/** The value chosen for the n-th item: 1 to 5, round and round. */
function scoreFor( n ) {
	return String( ( n % 5 ) + 1 );
}

/** Wait until every edit is stored: the save state settles on saved. */
async function settled( form ) {
	await expect( form.locator( '[data-tt-save-state]' ) ).toHaveClass( /is-saved/, { timeout: 15000 } );
}

test( 'the player scores every item and the strongest quality on a phone, and a reload keeps it', async ( { browser, baseURL } ) => {
	const { context, page } = await playerPage( browser, baseURL, 'e2e_pop_open' );
	try {
		const form = page.locator( '[data-tt-pdp-prep]' );
		await expect( form, 'the preparation form is shown inside the window' ).toBeVisible();

		const steps  = form.locator( '[data-tt-prep-step]' );
		const total  = await steps.count();
		const rows   = form.locator( '.tt-rating-stars--choices' );
		const items  = await rows.count();
		expect( total, 'a step per section and one for the strongest quality' ).toBeGreaterThan( 1 );
		expect( items, 'the outfield form has 17 items' ).toBe( 17 );

		let n = 0;
		for ( let s = 0; s < total; s++ ) {
			const step = form.locator( '[data-tt-prep-step]:not([hidden])' );
			await expect( step ).toHaveCount( 1 );
			const groups = step.locator( '.tt-rating-stars--choices' );
			const here   = await groups.count();
			for ( let g = 0; g < here; g++ ) {
				const choice = groups.nth( g ).locator( `button[data-value="${ scoreFor( n ) }"]` );
				const box    = await choice.boundingBox();
				expect( box && box.width >= 48 && box.height >= 48, `choice ${ n } is at least 48px: ${ JSON.stringify( box ) }` ).toBe( true );
				await choice.tap();
				await expect( choice ).toHaveAttribute( 'aria-checked', 'true' );
				n++;
			}
			if ( s < total - 1 ) {
				await form.locator( '[data-tt-prep-next]' ).tap();
			}
		}
		expect( n, 'every item was scored' ).toBe( items );

		const strongest = form.locator( 'textarea[name="answers[strongest_quality]"]' );
		await strongest.fill( 'Mijn passing over de lange lijn' );
		await expect( form.locator( '[data-tt-prep-done]' ), 'klaar is on the last step' ).toBeVisible();
		await settled( form );

		await page.reload();
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		const again = page.locator( '[data-tt-pdp-prep]' );
		await expect( again ).toBeVisible();
		const kept = again.locator( '.tt-rating-stars--choices' );
		for ( let i = 0; i < items; i++ ) {
			await expect( kept.nth( i ).locator( '[aria-checked="true"]' ), `item ${ i } kept its score` ).toHaveAttribute( 'data-value', scoreFor( i ) );
		}
		await expect( again.locator( 'textarea[name="answers[strongest_quality]"]' ) ).toHaveValue( 'Mijn passing over de lange lijn' );

		const axe = await new AxeBuilder( { page } )
			.include( '[data-tt-pdp-prep]' )
			.withTags( [ 'wcag2a', 'wcag2aa' ] )
			.analyze();
		expect( axe.violations.map( ( v ) => `${ v.id }: ${ v.help }` ) ).toEqual( [] );

		await page.setViewportSize( { width: 320, height: 800 } );
		const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
		expect( overflow, 'no sideways scroll at 320' ).toBeLessThanOrEqual( 0 );
	} finally {
		await context.close();
	}
} );

test( 'the arrow keys move the choice, as in a radio group', async ( { browser, baseURL } ) => {
	const { context, page } = await playerPage( browser, baseURL, 'e2e_pop_open' );
	try {
		const row = page.locator( '[data-tt-pdp-prep] [data-tt-prep-step]:not([hidden]) .tt-rating-stars--choices' ).first();
		await row.locator( '[aria-checked="true"]' ).focus();
		const before = Number( await row.locator( '[aria-checked="true"]' ).getAttribute( 'data-value' ) );
		await page.keyboard.press( before < 5 ? 'ArrowRight' : 'ArrowLeft' );
		await expect( row.locator( '[aria-checked="true"]' ) ).toHaveAttribute( 'data-value', String( before < 5 ? before + 1 : before - 1 ) );
		await settled( page.locator( '[data-tt-pdp-prep]' ) );
	} finally {
		await context.close();
	}
} );

test( 'klaar shows the summary, and toch aanpassen opens the form again', async ( { browser, baseURL } ) => {
	const { context, page } = await playerPage( browser, baseURL, 'e2e_pop_open' );
	try {
		const form = page.locator( '[data-tt-pdp-prep]' );
		await expect( form ).toBeVisible();
		const last = await form.locator( '[data-tt-prep-goto]' ).count();
		await form.locator( `[data-tt-prep-goto="${ last - 1 }"]` ).tap();
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( '/form/player/preparation' ) && r.request().method() === 'PUT' && r.status() === 200 ),
			form.locator( '[data-tt-prep-done]' ).tap(),
		] );
		await page.waitForLoadState( 'load', { timeout: 30000 } );

		await expect( page.locator( '.tt-pdp-prep__ready' ), 'the summary' ).toBeVisible();
		await expect( page.locator( '[data-tt-pdp-prep]' ), 'no form while marked ready' ).toHaveCount( 0 );
		await expect( page.locator( '.tt-pdp-prep__sumrow' ) ).toHaveCount( 4 );
		await expect( page.locator( '.tt-pdp-prep__answer' ) ).toContainText( 'Mijn passing over de lange lijn' );

		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( '/form/player/preparation' ) && r.request().method() === 'DELETE' && r.status() === 200 ),
			page.locator( '[data-tt-prep-reopen]' ).tap(),
		] );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		await expect( page.locator( '[data-tt-pdp-prep]' ), 'the form is back' ).toBeVisible();
	} finally {
		await context.close();
	}
} );

test( 'after the talk has started the preparation is read-only', async ( { browser, baseURL } ) => {
	const { context, page } = await playerPage( browser, baseURL, 'e2e_pop_locked' );
	try {
		const section = page.locator( '.tt-pdp-prep' );
		await expect( section ).toBeVisible();
		await expect( section.locator( '.tt-pdp-prep__guard' ), 'a line says why' ).toBeVisible();
		await expect( page.locator( '[data-tt-pdp-prep]' ), 'no form' ).toHaveCount( 0 );
		await expect( page.locator( '[data-tt-prep-reopen]' ), 'no way back in' ).toHaveCount( 0 );
		await expect( section.locator( '.tt-pdp-prep__answer' ) ).toContainText( 'Mijn kopbal bij corners' );
	} finally {
		await context.close();
	}
} );
