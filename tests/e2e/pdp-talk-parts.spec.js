// @ts-check
/**
 * #5080 — a PDP talk is two parts, each with its own save state and undo in
 * its header. On a phone the save state wraps under the title, part 2's
 * header stays in view while the form scrolls, below the stuck spine and
 * never under it or over it (under both shells), an undo in part 2 leaves
 * part 1's dates alone, and a form talk asks no notes or agreed actions.
 *
 * The talk is `Mila Trainer4980`'s, from `tests/e2e/setup/pdp-preparation.php`.
 * It runs before `pdp-trainer-form.spec.js`, and leaves the first score on 3,
 * which that spec sets on every item anyway.
 */
const { test, expect } = require( '@playwright/test' );
const { openTalk: open, underAppShell, onTop } = require( './helpers/pdp-talk' );

test.use( {
	storageState: 'tests/e2e/.auth/admin.json',
	viewport: { width: 360, height: 800 },
	hasTouch: true,
	isMobile: true,
} );

test.describe.configure( { mode: 'serial' } );

/** @param {import('@playwright/test').Page} page */
async function openTalk( page ) {
	await open( page );
	await page.getByRole( 'tab', { name: 'Conversation' } ).click();
}

/**
 * Scroll to the end of the form and check what is stuck: part 2's header
 * shows its own save state on top, the spine above it is not covered, and
 * the row just under the header is the form, not a piece of the spine
 * poking out. Returns the stuck heights for the PR.
 *
 * @param {import('@playwright/test').Page} page
 */
async function checkStuckStack( page ) {
	const form = page.locator( '.tt-pdp-part.tt-pdp-coachform' );
	// Well into the form, with plenty of it still below: the header is stuck.
	await form.evaluate( ( el ) => {
		window.scrollTo( 0, el.getBoundingClientRect().top + window.scrollY + 400 );
	} );
	await page.waitForTimeout( 300 );
	const formBox = await form.boundingBox();
	expect( formBox && formBox.y < 0, 'the form\'s top has scrolled past' ).toBe( true );

	const head = form.locator( '.tt-pdp-part__head' );
	expect( await onTop( head.locator( '[data-tt-save-state]' ), '.tt-pdp-part__head' ), 'part 2\'s save state is on top' ).toBe( true );
	expect( await onTop( page.locator( '.tt-spine__tabs' ).first(), '.tt-spine' ), 'the spine\'s tabs are not covered' ).toBe( true );

	const below = await head.evaluate( ( el ) => {
		const r   = el.getBoundingClientRect();
		const hit = document.elementFromPoint( r.left + r.width / 2, r.bottom + 12 );
		return { inSpine: !! ( hit && hit.closest( '.tt-spine' ) ), inForm: !! ( hit && hit.closest( '.tt-pdp-coachform' ) ) };
	} );
	expect( below.inSpine, 'no piece of the spine pokes out under part 2\'s header' ).toBe( false );
	expect( below.inForm, 'under the header is the form' ).toBe( true );

	const sizes = await page.evaluate( () => {
		const h = ( sel ) => {
			const el = document.querySelector( sel );
			return el ? Math.round( el.getBoundingClientRect().height ) : 0;
		};
		const shell = document.querySelector( '.tt-dashboard.tt-shell-app .tt-dash-header' ) ? h( '.tt-dash-header' ) : 0;
		const spine = h( '.tt-spine' );
		const part2 = h( '.tt-pdp-coachform .tt-pdp-part__head' );
		return { shell, spine, part2, total: shell + spine + part2, viewport: window.innerHeight };
	} );
	// The budget (#5080): the stuck chrome stays within 45% of 360x640.
	expect( sizes.total, 'the stuck chrome stays within 288px: ' + JSON.stringify( sizes ) ).toBeLessThanOrEqual( 288 );
	return sizes;
}

/**
 * At the top of the talk on a phone, the form and its switch are on the
 * page once, under the spine and not in it; focusable when there is a switch.
 *
 * @param {import('@playwright/test').Page} page
 */
async function checkAsideOutOfStrip( page ) {
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await expect( page.locator( '.tt-spine__aside' ) ).toHaveCount( 1 );
	await expect( page.locator( '.tt-spine .tt-spine__aside' ) ).toHaveCount( 0 );
	const aside = page.locator( '.tt-spine-aside-slot > .tt-spine__aside' );
	await expect( aside ).toBeVisible();
	const button = aside.locator( 'button[type="submit"]' );
	if ( await button.count() ) {
		await button.focus();
		await expect( button ).toBeFocused();
	}
}

test( 'two parts, each with its own save state, and part 2\'s header stays in view', async ( { page } ) => {
	await page.setViewportSize( { width: 360, height: 640 } );
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

	await checkAsideOutOfStrip( page );
	const sizes = await checkStuckStack( page );
	console.log( 'classic stuck chrome at 360x640: ' + JSON.stringify( sizes ) );
	test.info().annotations.push( { type: 'stuck-chrome-classic-360x640', description: JSON.stringify( sizes ) } );

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );
} );

test( 'under the app shell, part 2\'s header sits below the stuck spine', async ( { page } ) => {
	await page.setViewportSize( { width: 360, height: 640 } );
	await underAppShell( page, async () => {
		await openTalk( page );
		await checkAsideOutOfStrip( page );
		const sizes = await checkStuckStack( page );
		console.log( 'app-shell stuck chrome at 360x640: ' + JSON.stringify( sizes ) );
		test.info().annotations.push( { type: 'stuck-chrome-app-360x640', description: JSON.stringify( sizes ) } );
		expect( await onTop( page.locator( '.tt-spine__name' ).first(), '.tt-spine' ), 'the spine\'s name is not under the shell header' ).toBe( true );

		// From 480px up nothing changes: at 1280 the aside is back in the
		// tab row, on the right, and the slot is empty.
		await page.setViewportSize( { width: 1280, height: 800 } );
		await expect( page.locator( '.tt-spine__row > .tt-spine__aside' ) ).toHaveCount( 1 );
		await expect( page.locator( '.tt-spine-aside-slot' ) ).toBeHidden();
		const row   = await page.locator( '.tt-spine__row' ).boundingBox();
		const aside = await page.locator( '.tt-spine__row > .tt-spine__aside' ).boundingBox();
		expect( row && aside && aside.x + aside.width >= row.x + row.width - 20, 'on the right of the tab row' ).toBe( true );
	} );
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
