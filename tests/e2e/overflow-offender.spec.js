// @ts-check
/**
 * The mobile gate names the element that widens the page (#4767).
 *
 * Fixture pages, no WordPress: `widestOffender()` is the function the gate
 * runs in each surface, and what it should name is decided by layout alone.
 */
const { test, expect } = require( '@playwright/test' );
const { widestOffender } = require( './helpers/overflow' );

const STRIP =
	'<nav class="tt-fixture-tabs" style="display:flex;overflow-x:auto;width:100%">' +
	'<a class="tt-fixture-tab" style="flex:0 0 auto;width:900px">A long tab strip</a>' +
	'</nav>';

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} body
 */
async function mount( page, body ) {
	await page.setViewportSize( { width: 320, height: 844 } );
	await page.setContent(
		'<!doctype html><html><head><meta name="viewport" content="width=device-width">' +
		'<style>body{margin:0}</style></head><body><div class="tt-dashboard">' + body + '</div></body></html>'
	);
}

/** @param {import('@playwright/test').Page} page */
function pageOverflow( page ) {
	return page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
}

test.describe( 'Mobile gate overflow offender', () => {

	test( 'names the real offender, not a wider box inside a scroll strip', async ( { page } ) => {
		await mount( page, STRIP + '<div class="tt-fixture-wide" style="width:360px">Too wide</div>' );

		expect( await pageOverflow( page ) ).toBe( 40 );
		const offender = await page.evaluate( widestOffender );
		expect( offender ).toEqual( { where: 'div.tt-fixture-wide', right: 360 } );
	} );

	test( 'a page whose only wide box sits inside a scroll strip does not overflow', async ( { page } ) => {
		await mount( page, STRIP );

		expect( await pageOverflow( page ) ).toBe( 0 );
		expect( await page.evaluate( widestOffender ) ).toBeNull();
	} );

	test( 'a box in an overflow-x: scroll strip is skipped too', async ( { page } ) => {
		await mount( page,
			'<div style="overflow-x:scroll"><span class="tt-fixture-inner" style="display:block;width:700px">x</span></div>' +
			'<p class="tt-fixture-para" style="width:333px">Too wide</p>'
		);

		expect( await page.evaluate( widestOffender ) ).toEqual( { where: 'p.tt-fixture-para', right: 333 } );
	} );
} );
