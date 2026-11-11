// @ts-check
/**
 * #5079 — the PDP talk opens on a visible hero (photo or initials, name,
 * team · shirt number · position, the talk line) in both shells, the tab
 * reads "Background", and the form in use sits in the tab row: on a phone it
 * wraps under the tabs, with a 48px switch and no sideways scroll. Under the
 * app shell the stuck spine sits below the shell's header, never under it.
 *
 * The talk is `Mila Trainer4980`'s, from `tests/e2e/setup/pdp-preparation.php`.
 * This spec only reads it; `pdp-trainer-form.spec.js` fills it in later.
 */
const { test, expect } = require( '@playwright/test' );
const { openTalk, underAppShell, onTop } = require( './helpers/pdp-talk' );

test.use( {
	storageState: 'tests/e2e/.auth/admin.json',
	viewport: { width: 360, height: 800 },
	hasTouch: true,
	isMobile: true,
} );

test.describe.configure( { mode: 'serial' } );

/** @param {import('@playwright/test').Page} page */
async function expectHero( page ) {
	const hero = page.locator( '.tt-pdp-talk-hero' );
	await expect( hero ).toBeVisible();
	await expect( page.locator( 'h1' ), 'exactly one h1' ).toHaveCount( 1 );
	await expect( hero.locator( 'h1.tt-player-hero__name' ) ).toHaveText( /Trainer4980/ );
	await expect( hero.locator( '.tt-player-hero__avatar' ) ).toBeVisible();
	await expect( hero.locator( '.tt-player-hero__sub' ).last() ).toContainText( 'conversation 1 of' );
}

test( 'the talk names the player, reads Background, and keeps the form in the tab row', async ( { page } ) => {
	await openTalk( page );

	// The page is about Mila, and about this talk: a visible hero.
	await expectHero( page );
	await expect( page.locator( 'nav.tt-breadcrumbs' ).first() ).toContainText( 'Trainer4980' );

	// The tab is called Background, and says what it holds.
	const background = page.getByRole( 'tab', { name: 'Background' } );
	await expect( background ).toBeVisible();
	await expect( page.getByRole( 'tab', { name: 'Evidence' } ) ).toHaveCount( 0 );

	// The form in use sits in the tab row, after the tabs, and at 360px it
	// wraps under them.
	const row     = page.locator( '.tt-spine__row' );
	const tablist = row.locator( '[role="tablist"]' );
	const aside   = row.locator( '.tt-spine__aside' );
	await expect( aside.locator( '.tt-pdp-form-switch__current' ) ).toContainText( 'Form:' );
	const tabsBox  = await tablist.boundingBox();
	const asideBox = await aside.boundingBox();
	expect( tabsBox && asideBox && asideBox.y >= tabsBox.y + tabsBox.height - 1, 'the form wraps under the tabs on a phone' ).toBe( true );

	const switchButton = aside.locator( 'button[type="submit"]' );
	if ( await switchButton.count() ) {
		const box = await switchButton.boundingBox();
		expect( box && box.height >= 48, 'the switch is at least 48px tall' ).toBe( true );
		await expect( switchButton ).toHaveAttribute( 'aria-describedby', /tt-pdp-form-switch-help-/ );
		const framed = await aside.locator( 'form' ).evaluate( ( f ) => getComputedStyle( f ).borderTopWidth );
		expect( framed, 'the switch is not framed as a card' ).toBe( '0px' );
	}
	await expect( page.locator( '.tt-pdp-form-switch' ), 'one form block, in the tab row only' ).toHaveCount( 1 );

	const overflow = await page.evaluate( () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth );
	expect( overflow, 'no sideways scroll at 360' ).toBeLessThanOrEqual( 0 );

	await background.tap();
	await expect( page.locator( '.tt-pdp-background-lede' ) ).toHaveText( 'What is on record about the player this season, to support the talk.' );
} );

test( 'under the app shell: the hero, and a stuck spine the shell header does not cover', async ( { page } ) => {
	await page.setViewportSize( { width: 360, height: 640 } );
	await underAppShell( page, async () => {
		await openTalk( page );
		await expectHero( page );

		const spine = page.locator( '.tt-spine' ).first();
		const name  = spine.locator( '.tt-spine__name' );
		await expect( name ).toHaveText( /Trainer4980/ );

		// Scroll well past the hero, so the spine is stuck.
		await page.evaluate( () => window.scrollTo( 0, document.scrollingElement.scrollHeight ) );
		await page.waitForTimeout( 300 );
		expect( await onTop( name, '.tt-spine' ), 'the stuck spine\'s name is not under the shell header' ).toBe( true );

		// The stuck chrome at 360x640, for the PR: the shell header and the
		// spine with its aside wrapped. The spine's own budget is 30vh.
		const sizes = await page.evaluate( () => {
			const header = document.querySelector( '.tt-dash-header' );
			const sp     = document.querySelector( '.tt-spine' );
			return {
				header: header ? Math.round( header.getBoundingClientRect().height ) : 0,
				spine:  sp ? Math.round( sp.getBoundingClientRect().height ) : 0,
				top:    sp ? Math.round( sp.getBoundingClientRect().top ) : 0,
			};
		} );
		console.log( 'stuck chrome at 360x640: ' + JSON.stringify( sizes ) );
		test.info().annotations.push( { type: 'stuck-chrome-360x640', description: JSON.stringify( sizes ) } );
		expect( sizes.top, 'the spine sticks at the header\'s bottom edge' ).toBeGreaterThanOrEqual( sizes.header - 1 );
	} );
} );
