// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The Report Studio reader's view in print (#4572) against a stubbed REST
 * API. A page break is the one block that only acts on paper, and there is
 * no PDF route for a Studio report, so the browser's print is the paper path:
 * the break must be in the page, hidden on screen, and start a new page in
 * print.
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
	'<p class="tt-rs-notice" data-tt-rv-notice role="status" hidden></p>' +
	'<ol class="tt-rs-canvas tt-rv-blocks" data-tt-rv-blocks aria-label="Report blocks"></ol></div>';

const CONFIG = 'window.TTReportViewer = ' + JSON.stringify( {
	id: 9, rest: REST, nonce: 'e2e', periods: [], columns: { week: 'Week', attendance_pct: 'Attendance %' },
	i18n: { loadError: 'Load failed', loading: 'Loading…', nothing: 'Nothing between %1$s and %2$s.', truncated: 'First rows only.',
		value: 'Value', block: 'Block', notAvailable: 'Not available to you.', empty: 'No blocks yet.', player: 'Player', team: 'Team', choose: 'Choose…' },
} ) + ';';

async function mount( page ) {
	await page.route( ORIGIN + '/**', async ( route ) => {
		const url = new URL( route.request().url() );
		const json = ( data ) => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data } ) } );

		if ( url.pathname === '/report' ) {
			await route.fulfill( {
				contentType: 'text/html',
				body: '<!doctype html><html><head><style>' + asset( 'css', 'frontend-report-studio.css' ) + '</style></head>' +
					'<body class="tt-dashboard">' + BODY +
					'<script>' + CONFIG + '</script><script>' + asset( 'js', 'tt-report-blocks.js' ) + '</script>' +
					'<script>' + asset( 'js', 'frontend-report-viewer.js' ) + '</script></body></html>',
			} );
			return;
		}

		const p = url.pathname.replace( '/wp-json/talenttrack/v1/', '' );
		if ( p === 'reports/9' ) {
			return json( { id: 9, name: 'Pack', kind: 'report', scope: 'team', binding: 'fixed', audience: 'internal' } );
		}
		if ( p === 'report-blocks' ) {
			return json( { blocks: [ { key: 'attendance_per_week', label: 'Attendance per week' }, { key: 'page_break', label: 'Page break' } ], unavailable: [] } );
		}
		if ( p === 'reports/9/subjects' ) return json( { teams: [], players: [] } );
		if ( p === 'reports/9/data' ) {
			const rows = { breakdown: 'week', rows: [ { week: '2026-W40', attendance_pct: 90 } ] };
			return json( {
				context: { scope: 'team', team_id: 3, from: '2026-10-01', to: '2026-10-31', audience: 'internal' },
				blocks: [
					{ id: 'b1', key: 'attendance_per_week', width: 'full', options: {}, data: rows },
					{ id: 'b2', key: 'page_break', width: 'full', options: {}, data: {} },
					{ id: 'b3', key: 'attendance_per_week', width: 'full', options: {}, data: rows },
				],
				page_fit: { pages: 2, fill: [ 30, 30 ], breaks: [ 'b3' ] },
			} );
		}
		return route.fulfill( { status: 404, body: '{}' } );
	} );
	await page.goto( ORIGIN + '/report' );
	await expect( page.locator( '[data-tt-rv-blocks] > li' ) ).toHaveCount( 3 );
}

test( 'a page break is in the reader\'s page, hidden on screen and a new page in print', async ( { page } ) => {
	await mount( page );

	const brk = page.locator( '[data-tt-rv-break="b2"]' );
	await expect( brk ).toHaveCount( 1 );
	await expect( brk ).toHaveClass( /tt-rs-print-break/ );
	await expect( brk ).toBeHidden();

	// The break sits between the two blocks it separates.
	const order = await page.locator( '[data-tt-rv-blocks] > li' ).evaluateAll( ( items ) => items.map( ( li ) => li.dataset.ttRvBreak ? 'break' : 'block' ) );
	expect( order ).toEqual( [ 'block', 'break', 'block' ] );

	await page.emulateMedia( { media: 'print' } );
	const printed = await brk.evaluate( ( li ) => {
		const s = getComputedStyle( li );
		return { display: s.display, breakBefore: s.breakBefore || s.getPropertyValue( 'page-break-before' ) };
	} );
	expect( printed.display ).toBe( 'block' );
	expect( [ 'page', 'always' ] ).toContain( printed.breakBefore );
} );
