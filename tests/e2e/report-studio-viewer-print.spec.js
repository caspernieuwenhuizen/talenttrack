// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The Report Studio reader's view against a stubbed REST API (#4572, #5021).
 * The view shows the document the PDF prints, drawn on the server: the script
 * asks `GET /reports/{id}/document` and places its HTML. Two things only a
 * browser can check: a page break starts a new page when the browser prints,
 * and at 360px the document, its widest table included, has no sideways
 * scroll.
 */

const ORIGIN = 'http://tt-viewer.test';
const REST = ORIGIN + '/wp-json/talenttrack/v1/';

function asset( dir, name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', dir, name ), 'utf8' );
}

const BODY =
	'<div class="tt-rv" data-tt-rv><h1>Pack</h1>' +
	'<div class="tt-rs-context tt-rv-context"><div class="tt-field" data-tt-rv-subject hidden></div>' +
	'<fieldset class="tt-rs-radios" data-tt-rv-periods hidden><legend>Period</legend></fieldset></div>' +
	'<div class="tt-rv-actions"><a class="tt-btn tt-btn-secondary" data-tt-rv-pdf href="#" hidden>Download PDF</a>' +
	'<button type="button" class="tt-btn tt-btn-secondary" data-tt-rv-open-snapshot hidden>Take snapshot</button></div>' +
	'<p class="tt-rs-notice" data-tt-rv-notice role="status" hidden></p>' +
	'<div class="tt-rv-document" data-tt-rv-document aria-live="polite"></div>' +
	'<dialog class="tt-rs-dialog" data-tt-rv-snapshot><form method="dialog" class="tt-rs-publish-form"><h2>Take snapshot</h2>' +
	'<div class="tt-field"><label class="tt-field-label" for="tt-rv-snapshot-name">Name</label><input type="text" id="tt-rv-snapshot-name" class="tt-input" data-tt-rv-snapshot-name></div>' +
	'<p class="tt-rs-error" data-tt-rv-snapshot-error role="alert"></p>' +
	'<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-secondary" value="cancel" formnovalidate>Cancel</button>' +
	'<button type="submit" class="tt-btn tt-btn-primary" value="confirm">Take snapshot</button></div></form></dialog></div>';

const CONFIG = 'window.TTReportViewer = ' + JSON.stringify( {
	id: 9, rest: REST, nonce: 'e2e', periods: [],
	pdf: ORIGIN + '/wp-json/talenttrack/v1/exports/report_studio_pdf?format=pdf&report_id=9&source=published',
	snapshotUrl: ORIGIN + '/report?snapshot=__uuid__',
	i18n: { loadError: 'Load failed', loading: 'Loading…', player: 'Player', team: 'Team', choose: 'Choose…', snapshotTaken: 'Saved.' },
} ) + ';';

/** A stack-table row of ten columns, as StudioDocument prints a wide block. */
function wideTable() {
	const cols = [ 'Player', 'Activities', 'Present', 'Late', 'Absent', 'Excused', 'Injured', 'Suspended', 'Attendance %', 'Team' ];
	const head = cols.map( ( c ) => '<th role="columnheader">' + c + '</th>' ).join( '' );
	const row = cols.map( ( c ) => '<td role="cell" data-label="' + c + '">' + ( c === 'Player' ? 'A player with a rather long name' : '12' ) + '</td>' ).join( '' );
	return '<table class="tt-sd-table tt-stack-table tt-stack-table--to-768" role="table"><thead role="rowgroup"><tr role="row">' + head +
		'</tr></thead><tbody role="rowgroup"><tr role="row">' + row + '</tr><tr role="row">' + row + '</tr></tbody></table>';
}

const DOCUMENT =
	'<div class="tt-sd"><div class="tt-sd-page">' +
	'<div class="tt-sd-block" id="tt-sd-b1"><!--tt-sd-block:b1--><h2 class="tt-sd-title">Attendance per player</h2>' + wideTable() + '</div>' +
	'<table class="tt-sd-pair"><tr><td class="tt-sd-half"><div class="tt-sd-block"><h2 class="tt-sd-title">Left</h2></div></td>' +
	'<td class="tt-sd-half"><div class="tt-sd-block"><h2 class="tt-sd-title">Right</h2></div></td></tr></table>' +
	'</div><div class="tt-sd-page tt-sd-break">' +
	'<div class="tt-sd-block" id="tt-sd-b3"><!--tt-sd-block:b3--><h2 class="tt-sd-title">Attendance per week</h2></div>' +
	'</div><p class="tt-sd-foot">Confidential — staff only.</p></div>';

async function mount( page ) {
	await page.route( ORIGIN + '/**', async ( route ) => {
		const url = new URL( route.request().url() );
		const json = ( data ) => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data } ) } );

		if ( url.pathname === '/report' ) {
			await route.fulfill( {
				contentType: 'text/html',
				body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' +
					asset( 'css', 'tokens.css' ) + asset( 'css', 'public.css' ) + asset( 'css', 'frontend-admin.css' ) +
					asset( 'css', 'components/stack-table.css' ) + asset( 'css', 'frontend-report-studio.css' ) + asset( 'css', 'frontend-report-document.css' ) +
					'</style></head><body class="tt-dashboard">' + BODY +
					'<script>' + CONFIG + '</script><script>' + asset( 'js', 'frontend-report-viewer.js' ) + '</script></body></html>',
			} );
			return;
		}

		const p = url.pathname.replace( '/wp-json/talenttrack/v1/', '' );
		if ( p === 'reports/9' ) {
			return json( { id: 9, name: 'Pack', kind: 'report', scope: 'team', binding: 'fixed', audience: 'internal', period_mode: 'fixed' } );
		}
		if ( p === 'reports/9/subjects' ) return json( { teams: [], players: [] } );
		if ( p === 'reports/9/document' ) {
			expect( url.searchParams.get( 'source' ) ).toBe( 'published' );
			return json( {
				context: { scope: 'team', team_id: 3, from: '2026-10-01', to: '2026-10-31', audience: 'internal', can_snapshot: true },
				page_fit: { pages: 2, fill: [ 30, 30 ], breaks: [ 'b3' ] },
				html: DOCUMENT,
			} );
		}
		return route.fulfill( { status: 404, body: '{}' } );
	} );
	await page.goto( ORIGIN + '/report' );
	await expect( page.locator( '[data-tt-rv-document] .tt-sd-page' ) ).toHaveCount( 2 );
}

test( 'the reader\'s view shows the server\'s document, and its page break starts a new page in print', async ( { page } ) => {
	await mount( page );

	await expect( page.locator( '#tt-sd-b1' ) ).toBeVisible();
	await expect( page.locator( '[data-tt-rv-pdf]' ) ).toBeVisible();
	await expect( page.locator( '[data-tt-rv-pdf]' ) ).toHaveAttribute( 'href', /report_studio_pdf/ );

	const second = page.locator( '.tt-sd-page.tt-sd-break' );
	await page.emulateMedia( { media: 'print' } );
	const breakBefore = await second.evaluate( ( el ) => {
		const s = getComputedStyle( el );
		return s.breakBefore || s.getPropertyValue( 'page-break-before' );
	} );
	expect( [ 'page', 'always' ] ).toContain( breakBefore );
} );

test( 'at 360px the document has no sideways scroll', async ( { page } ) => {
	await page.setViewportSize( { width: 360, height: 740 } );
	await mount( page );

	const widths = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth } ) );
	expect( widths.scroll ).toBeLessThanOrEqual( widths.client );

	// The two half blocks stack, one under the other.
	const halves = page.locator( '.tt-sd-half' );
	const first = await halves.nth( 0 ).boundingBox();
	const next = await halves.nth( 1 ).boundingBox();
	expect( first && next && next.y >= first.y + first.height - 1 ).toBeTruthy();
} );

test( 'on a touch screen the snapshot dialog\'s buttons are 48px targets', async ( { browser } ) => {
	const context = await browser.newContext( { viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true } );
	const page = await context.newPage();
	await mount( page );
	expect( await page.evaluate( () => matchMedia( '(pointer: coarse)' ).matches ) ).toBe( true );

	const open = page.locator( '[data-tt-rv-open-snapshot]' );
	await expect( open ).toBeVisible();
	await open.click();
	const buttons = page.locator( '[data-tt-rv-snapshot] .tt-form-actions .tt-btn' );
	await expect( buttons ).toHaveCount( 2 );
	for ( const height of await buttons.evaluateAll( ( list ) => list.map( ( b ) => b.getBoundingClientRect().height ) ) ) {
		expect( height ).toBeGreaterThanOrEqual( 48 );
	}
	await context.close();
} );
