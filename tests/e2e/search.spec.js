// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { uniqueName } = require( './helpers/admin' );

/**
 * #4672 — the header search, the phone sheet and the results page.
 *
 * One team and one player with a unique surname are made over REST before
 * the run and removed after it, so nothing depends on seed data. The
 * selectors are structural (ids, data attributes and roles) because the
 * suite may run in English or Dutch.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/** @type {{ teamId: number, playerId: number, last: string }} */
const made = { teamId: 0, playerId: 0, last: '' };

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} method
 * @param {string} path
 * @param {object|null} body
 */
function rest( page, method, path, body ) {
	return page.evaluate( async ( { method, path, body } ) => {
		const cfg = window.TT || {};
		const res = await fetch( cfg.rest_url + path, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
			body: body === null ? undefined : JSON.stringify( body ),
		} );
		let json = null;
		try { json = await res.json(); } catch ( _e ) { json = null; }
		return { status: res.status, json };
	}, { method, path, body } );
}

/** @param {{ json: any }} res */
function dataId( res ) {
	return res.json && res.json.data && res.json.data.id ? Number( res.json.data.id ) : 0;
}

/** @param {import('@playwright/test').Browser} browser */
async function adminPage( browser, baseURL ) {
	const context = await browser.newContext( { storageState: 'tests/e2e/.auth/admin.json', baseURL } );
	const page = await context.newPage();
	await page.goto( '/' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return { context, page };
}

test.beforeAll( async ( { browser, baseURL } ) => {
	const { context, page } = await adminPage( browser, baseURL );
	try {
		const team = await rest( page, 'POST', 'teams', { name: uniqueName( 'e2e-search-team' ) } );
		made.teamId = dataId( team );
		made.last = uniqueName( 'Zoekspeler' ).replace( /-/g, '' );
		const player = await rest( page, 'POST', 'players', {
			first_name: 'Search',
			last_name: made.last,
			team_id: made.teamId,
			status: 'active',
		} );
		made.playerId = dataId( player );
	} finally {
		await context.close();
	}
} );

test.afterAll( async ( { browser, baseURL } ) => {
	const { context, page } = await adminPage( browser, baseURL );
	try {
		if ( made.playerId ) {
			await rest( page, 'DELETE', `players/${ made.playerId }`, null );
			await rest( page, 'POST', `players/${ made.playerId }/trash`, null );
			await rest( page, 'DELETE', `recycle-bin/player/${ made.playerId }`, null );
		}
		if ( made.teamId ) {
			await rest( page, 'DELETE', `teams/${ made.teamId }`, null );
			await rest( page, 'DELETE', `teams/${ made.teamId }/permanent`, null );
		}
	} finally {
		await context.close();
	}
} );

/** The player's option in the quick results. */
function playerOption( page ) {
	return page.locator( '#tt-spotlight-results [role="option"].tt-spotlight__result', { hasText: made.last } ).first();
}

test.describe( 'Header search on a desktop', () => {
	test.use( { viewport: { width: 1280, height: 900 }, hasTouch: false, isMobile: false } );

	test( 'Ctrl+K focuses the box, typing finds the player, arrows and Enter open it', async ( { page } ) => {
		test.skip( ! made.playerId, 'the player could not be made' );
		await page.goto( '/' );
		await page.waitForLoadState( 'load', { timeout: 30000 } );

		const input = page.locator( '[data-tt-spotlight-input]' );
		await expect( input ).toBeVisible();
		await page.keyboard.press( 'Control+k' );
		await expect( input ).toBeFocused();

		await input.fill( made.last );
		await expect( playerOption( page ) ).toBeVisible( { timeout: 15000 } );
		await expect( page.locator( '#tt-spotlight-group-player' ) ).toBeVisible();
		await expect( input ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( playerOption( page ).locator( 'mark' ) ).toHaveCount( 1 );

		// Nothing is selected until an arrow moves.
		await expect( input ).not.toHaveAttribute( 'aria-activedescendant', /.+/ );
		await page.keyboard.press( 'ArrowDown' );
		const active = await input.getAttribute( 'aria-activedescendant' );
		expect( active ).toBeTruthy();
		await expect( page.locator( '#' + active ) ).toHaveAttribute( 'aria-selected', 'true' );

		await Promise.all( [
			page.waitForURL( new RegExp( `tt_view=players.*id=${ made.playerId }` ), { timeout: 15000 } ),
			page.keyboard.press( 'Enter' ),
		] );
	} );

	test( 'Escape closes the list, then clears the box', async ( { page } ) => {
		test.skip( ! made.playerId, 'the player could not be made' );
		await page.goto( '/' );
		const input = page.locator( '[data-tt-spotlight-input]' );
		await input.fill( made.last );
		await expect( playerOption( page ) ).toBeVisible( { timeout: 15000 } );

		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '[data-tt-spotlight-panel]' ) ).toBeHidden();
		await expect( input ).toHaveValue( made.last );
		await page.keyboard.press( 'Escape' );
		await expect( input ).toHaveValue( '' );
	} );

	test( 'an opened player shows in the empty box', async ( { page } ) => {
		test.skip( ! made.playerId, 'the player could not be made' );
		await page.goto( `/?tt_view=players&id=${ made.playerId }` );
		await page.waitForLoadState( 'load', { timeout: 30000 } );
		await page.goto( '/' );

		await page.locator( '[data-tt-spotlight-input]' ).focus();
		const recent = page.locator( '#tt-spotlight-group-recent' );
		await expect( recent ).toBeVisible( { timeout: 15000 } );
		await expect( page.locator( '#tt-spotlight-results [role="option"]', { hasText: made.last } ).first() ).toBeVisible();
	} );

	test( 'the box has no accessibility violations with its results open', async ( { page } ) => {
		test.skip( ! made.playerId, 'the player could not be made' );
		await page.goto( '/' );
		const input = page.locator( '[data-tt-spotlight-input]' );
		await input.fill( made.last );
		await expect( playerOption( page ) ).toBeVisible( { timeout: 15000 } );
		await page.keyboard.press( 'ArrowDown' );

		await expect( page.getByRole( 'combobox' ).first() ).toHaveAttribute( 'aria-controls', 'tt-spotlight-results' );
		await expect( page.getByRole( 'listbox' ).first() ).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.include( '[data-tt-spotlight-form]' )
			.withTags( [ 'wcag2a', 'wcag2aa' ] )
			.analyze();
		expect( results.violations.map( ( v ) => `${ v.id }: ${ v.help }` ) ).toEqual( [] );
	} );

	test( 'Enter with nothing selected opens the results page, which filters by kind', async ( { page } ) => {
		test.skip( ! made.playerId, 'the player could not be made' );
		await page.goto( '/' );
		const input = page.locator( '[data-tt-spotlight-input]' );
		await input.fill( made.last );
		await expect( playerOption( page ) ).toBeVisible( { timeout: 15000 } );

		await Promise.all( [
			page.waitForURL( /tt_view=search/, { timeout: 15000 } ),
			page.keyboard.press( 'Enter' ),
		] );
		expect( new URL( page.url() ).searchParams.get( 'q' ) ).toBe( made.last );
		await expect( page.locator( '.tt-breadcrumbs' ) ).toBeVisible();
		await expect( page.locator( '.tt-search-page__result', { hasText: made.last } ) ).toHaveCount( 1 );

		// Deep-linkable: the kind chip is a URL of its own.
		const chip = page.locator( '.tt-search-page__kind[href*="type=player"]' );
		await Promise.all( [
			page.waitForURL( /type=player/, { timeout: 15000 } ),
			chip.click(),
		] );
		await expect( page.locator( '.tt-search-page__kind.is-current' ) ).toHaveAttribute( 'aria-current', 'true' );
		await expect( page.locator( '.tt-search-page__result', { hasText: made.last } ) ).toHaveCount( 1 );

		// The team filter narrows to the player's own team.
		await page.goto( `/?tt_view=search&q=${ encodeURIComponent( made.last ) }&team_id=${ made.teamId }` );
		await expect( page.locator( '.tt-search-page__result', { hasText: made.last } ) ).toHaveCount( 1 );

		const results = await new AxeBuilder( { page } )
			.include( '.tt-search-page__form' )
			.include( '.tt-search-page__kinds' )
			.include( '.tt-search-page__results' )
			.withTags( [ 'wcag2a', 'wcag2aa' ] )
			.analyze();
		expect( results.violations.map( ( v ) => `${ v.id }: ${ v.help }` ) ).toEqual( [] );
	} );

	test( 'a search with no match suggests what to try', async ( { page } ) => {
		await page.goto( '/?tt_view=search&q=' + encodeURIComponent( uniqueName( 'nomatch' ).replace( /-/g, '' ) ) );
		await expect( page.locator( '.tt-search-page__empty' ) ).toBeVisible();
		await expect( page.locator( '.tt-search-page__tips li' ) ).toHaveCount( 4 );
	} );
} );

test.describe( 'Header search on a phone', () => {
	test.use( { viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true } );

	test( 'the search button opens the sheet; Escape and Back close it', async ( { page } ) => {
		await page.goto( '/' );
		await page.waitForLoadState( 'load', { timeout: 30000 } );

		const open = page.locator( '[data-tt-spotlight-open]' );
		const form = page.locator( '[data-tt-spotlight-form]' );
		await expect( open ).toBeVisible();
		await expect( form ).toBeHidden();
		const box = await open.boundingBox();
		expect( box && box.width ).toBeGreaterThanOrEqual( 47.5 );
		expect( box && box.height ).toBeGreaterThanOrEqual( 47.5 );

		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		expect( overflow ).toBeLessThanOrEqual( 0 );

		await open.tap();
		await expect( form ).toHaveClass( /is-sheet/ );
		await expect( page.locator( '[data-tt-spotlight-input]' ) ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( form ).not.toHaveClass( /is-sheet/ );

		const before = page.url();
		await open.tap();
		await expect( form ).toHaveClass( /is-sheet/ );
		await page.evaluate( () => history.back() );
		await expect( form ).not.toHaveClass( /is-sheet/ );
		expect( page.url() ).toBe( before );
	} );

	test( 'the sheet finds the player', async ( { page } ) => {
		test.skip( ! made.playerId, 'the player could not be made' );
		await page.goto( '/' );
		await page.locator( '[data-tt-spotlight-open]' ).tap();
		await page.locator( '[data-tt-spotlight-input]' ).fill( made.last );
		const opt = playerOption( page );
		await expect( opt ).toBeVisible( { timeout: 15000 } );
		const box = await opt.boundingBox();
		expect( box && box.height ).toBeGreaterThanOrEqual( 47.5 );
	} );
} );
