// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The activities screens read the theme's line and soft-ink colours (#4825).
 *
 * frontend-activities-manage.css declared `--tt-line: var(--tt-line, …)` and
 * the same for `--tt-ink-soft` on its wrappers. A custom property that refers
 * to itself is invalid, so inside the activities surfaces both tokens were
 * empty on every design. The real stylesheets are applied to a minimal copy
 * of the wrappers on a routed origin, so the spec needs no wp-env data.
 */

const ORIGIN = 'http://tt-act-tokens.test';

function css( name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'css', name ), 'utf8' );
}

const SHEETS = [ 'tokens.css', 'theme-federation.css', 'theme-leon-hutten.css', 'frontend-activities-manage.css' ]
	.map( css ).join( '\n' );

const DESIGNS = [
	{ name: 'default', className: '', line: '#e3e1d8', inkSoft: '#6a6d66' },
	{ name: 'federation', className: 'tt-theme-federation', line: '#ccd4de', inkSoft: '#46566b' },
	{ name: 'leon-hutten', className: 'tt-theme-leon-hutten', line: '#dde3e8', inkSoft: '#3f4a55' },
];

for ( const design of DESIGNS ) {
	test( `activities surfaces inherit --tt-line and --tt-ink-soft on the ${ design.name } design`, async ( { page } ) => {
		// tokens.css declares the neutrals on .tt-root; a theme overrides them
		// on the .tt-dashboard inside it.
		const body = '<div class="tt-root"><div class="tt-dashboard ' + design.className + '">' +
			'<div class="tt-act-surface"><p>Surface</p></div>' +
			'<div class="tt-act-detail"><p>Detail</p></div>' +
			'<form class="tt-activity-form"><p>Form</p></form>' +
			'</div></div>';
		await page.route( ORIGIN + '/**', ( route ) => route.fulfill( {
			contentType: 'text/html',
			body: '<!doctype html><html><head><style>' + SHEETS + '</style></head><body>' + body + '</body></html>',
		} ) );
		await page.goto( ORIGIN + '/act' );

		const values = await page.evaluate( () => [ '.tt-act-surface', '.tt-act-detail', '.tt-activity-form' ].map( ( sel ) => {
			const s = getComputedStyle( /** @type {Element} */ ( document.querySelector( sel ) ) );
			return {
				sel,
				line: s.getPropertyValue( '--tt-line' ).trim().toLowerCase(),
				inkSoft: s.getPropertyValue( '--tt-ink-soft' ).trim().toLowerCase(),
			};
		} ) );

		for ( const v of values ) {
			expect( v.line, v.sel + ' --tt-line' ).toBe( design.line );
			expect( v.inkSoft, v.sel + ' --tt-ink-soft' ).toBe( design.inkSoft );
		}
	} );
}
