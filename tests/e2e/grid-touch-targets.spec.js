// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The attendance, minutes and ratings grids on a phone (§2: 48px under a
 * coarse pointer, 8px between neighbours), and unchanged under a mouse.
 *
 * The grids' real stylesheets are applied to a minimal copy of their markup
 * on a routed origin, so the spec needs no wp-env data and never skips.
 */

const ORIGIN = 'http://tt-grid-touch.test';
const MIN = 48;

function css( name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'css', name ), 'utf8' );
}

const SHEETS = [ 'tokens.css', 'public.css', 'frontend-attendance-grid.css', 'frontend-minutes-grid.css', 'frontend-ratings-grid.css' ]
	.map( css ).join( '\n' );

const BODY =
	'<div class="tt-dashboard">' +
	'<div class="tt-agrid-card"><div class="tt-agrid-scroll"><table class="tt-agrid"><thead><tr>' +
	'<th class="tt-agrid__act"><span class="tt-agrid__act-date">1 okt</span>' +
	'<button type="button" class="tt-agrid__fill" data-activity="11">allen aanwezig</button></th></tr></thead>' +
	'<tbody><tr>' +
	'<td class="tt-agrid-cell tt-agrid-cell--empty"><select class="tt-agrid-sel" aria-label="A"><option value=""></option></select></td>' +
	'<td class="tt-agrid-cell tt-agrid-cell--empty"><select class="tt-agrid-sel" aria-label="B"><option value=""></option></select></td>' +
	'</tr></tbody></table></div></div>' +
	'<div class="tt-agrid-card"><div class="tt-agrid-statpick">' +
	'<label class="tt-agrid-statchip"><input type="checkbox" data-agrid-stattoggle value="goals" checked> Doelpunten</label>' +
	'<label class="tt-agrid-statchip"><input type="checkbox" data-agrid-stattoggle value="assists" checked> Assists</label>' +
	'</div><div class="tt-agrid-scroll"><table class="tt-agrid tt-agrid--stats"><tbody><tr>' +
	'<td class="tt-agrid-cell tt-agrid-min"><input class="tt-agrid-min-in" type="text" inputmode="numeric" value="60" aria-label="Min"></td>' +
	'<td class="tt-agrid-cell tt-agrid-stat" data-stat="goals"><input class="tt-agrid-stat-in" type="text" inputmode="numeric" aria-label="G"></td>' +
	'<td class="tt-agrid-cell tt-agrid-stat" data-stat="assists"><input class="tt-agrid-stat-in" type="text" inputmode="numeric" aria-label="A"></td>' +
	'</tr></tbody></table></div></div>' +
	'<div class="tt-rgrid" data-tt-rgrid><p class="tt-rgrid-desktop-only tt-notice">' +
	'Het beoordelingsraster heeft een breder scherm nodig. Gebruik op een telefoon de beoordelingswizard.' +
	'</p></div>' +
	'</div>';

async function mount( page ) {
	await page.route( ORIGIN + '/**', ( route ) => route.fulfill( {
		contentType: 'text/html',
		body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">' +
			'<style>' + SHEETS + '</style></head><body>' + BODY + '</body></html>',
	} ) );
	await page.goto( ORIGIN + '/grid' );
}

/** Width and height of every element matching `sel`. */
function boxes( page, sel ) {
	return page.$$eval( sel, ( els ) => els.map( ( el ) => {
		const r = el.getBoundingClientRect();
		return { w: r.width, h: r.height, left: r.left, right: r.right };
	} ) );
}

test.describe( 'Grid touch targets on a phone', () => {
	test.use( { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } );

	test( 'every grid control is at least 48 by 48', async ( { page } ) => {
		await mount( page );
		expect( await page.evaluate( () => matchMedia( '(pointer: coarse)' ).matches ) ).toBe( true );

		for ( const sel of [ '.tt-agrid__fill', 'select.tt-agrid-sel', 'input.tt-agrid-min-in', 'input.tt-agrid-stat-in', 'label.tt-agrid-statchip' ] ) {
			for ( const b of await boxes( page, sel ) ) {
				expect( b.w, sel + ' width' ).toBeGreaterThanOrEqual( MIN );
				expect( b.h, sel + ' height' ).toBeGreaterThanOrEqual( MIN );
			}
		}

		// The chip is the target: its checkbox lets the tap through to it.
		const through = await page.$$eval( '.tt-agrid-statchip input', ( els ) => els.map( ( el ) => getComputedStyle( el ).pointerEvents ) );
		expect( through ).toEqual( [ 'none', 'none' ] );
		await page.locator( 'label.tt-agrid-statchip' ).first().tap();
		expect( await page.locator( '.tt-agrid-statchip input' ).first().isChecked() ).toBe( false );
	} );

	test( 'neighbouring boxes are at least 8px apart', async ( { page } ) => {
		await mount( page );
		for ( const sel of [ 'select.tt-agrid-sel', '.tt-agrid--stats input' ] ) {
			const b = await boxes( page, sel );
			for ( let i = 1; i < b.length; i++ ) {
				expect( b[ i ].left - b[ i - 1 ].right, sel + ' gap' ).toBeGreaterThanOrEqual( 8 );
			}
		}
	} );

	test( 'no horizontal scroll at 360px', async ( { page } ) => {
		await page.setViewportSize( { width: 360, height: 780 } );
		await mount( page );
		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );

test.describe( 'Grid density under a mouse', () => {
	test.use( { viewport: { width: 1280, height: 900 }, hasTouch: false, isMobile: false } );

	test( 'the desktop sizes are unchanged', async ( { page } ) => {
		await mount( page );
		expect( await page.evaluate( () => matchMedia( '(pointer: fine)' ).matches ) ).toBe( true );

		const fill = ( await boxes( page, '.tt-agrid__fill' ) )[ 0 ];
		expect( fill.h ).toBeLessThan( MIN );
		const stat = ( await boxes( page, 'input.tt-agrid-stat-in' ) )[ 0 ];
		expect( Math.round( stat.w ) ).toBe( 34 );
		const through = await page.$eval( '.tt-agrid-statchip input', ( el ) => getComputedStyle( el ).pointerEvents );
		expect( through ).not.toBe( 'none' );
	} );
} );
