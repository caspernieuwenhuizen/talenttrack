// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The Report Studio grid (#4962) against a stubbed REST API.
 *
 * The real viewer and canvas scripts and the real stylesheets run on a routed
 * origin. Nothing needs the wp-env data, so the spec never skips. It checks
 * what only a browser can: at 1280px the reader's grid places blocks as
 * stored, at 360px it stacks them in reading order without sideways scroll,
 * a persona's home reads the dashboard in one request, and on the canvas
 * every move and resize works by keyboard and by buttons, is one undo step,
 * and is saved.
 */

const ORIGIN = 'http://tt-grid.test';
const REST = ORIGIN + '/wp-json/talenttrack/v1/';

function asset( dir, name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', dir, name ), 'utf8' );
}

function css() {
	return [ 'tokens.css', 'public.css', 'frontend-admin.css', 'components/stack-table.css', 'frontend-report-studio.css',
		'frontend-report-document.css', 'frontend-report-grid.css' ].map( ( f ) => asset( 'css', f ) ).join( '\n' );
}

// ---- the reader's grid ------------------------------------------------------

function cell( id, x, y, w, h, title ) {
	return '<div class="tt-sg-cell" style="--tt-sg-x:' + ( x + 1 ) + ';--tt-sg-y:' + ( y + 1 ) + ';--tt-sg-w:' + w + ';--tt-sg-h:' + h + '">' +
		'<div class="tt-sd-block" id="tt-sd-' + id + '"><h2 class="tt-sd-title">' + title + '</h2><p class="tt-sd-figure">12</p></div></div>';
}

// As StudioGrid prints it: reading order, top row first, left to right.
const GRID =
	'<div class="tt-sd tt-sg"><div class="tt-sg-grid">' +
	cell( 'b1', 0, 0, 6, 1, 'Left' ) + cell( 'b2', 6, 0, 6, 1, 'Right' ) + cell( 'b3', 0, 1, 12, 2, 'Below, across' ) +
	'</div><p class="tt-sd-foot">Confidential — staff only.</p></div>';

const VIEWER_BODY =
	'<section class="tt-rv tt-rv--home" data-tt-rv><h2 class="tt-rv-title">Coach home</h2>' +
	'<div class="tt-rs-context tt-rv-context"><div class="tt-field" data-tt-rv-subject hidden></div>' +
	'<fieldset class="tt-rs-radios" data-tt-rv-periods hidden><legend>Period</legend></fieldset></div>' +
	'<p class="tt-rs-notice" data-tt-rv-notice role="status" hidden></p>' +
	'<div class="tt-rv-document" data-tt-rv-document aria-live="polite"></div></section>';

// A persona's home: the page hands over the report and its subjects.
const VIEWER_CONFIG = 'window.TTReportViewer = ' + JSON.stringify( {
	id: 11, rest: REST, nonce: 'e2e', periods: [], pdf: '',
	report: { scope: 'team', period_mode: 'on_open', layout: 'grid' },
	subjects: { teams: [], players: [] },
	i18n: { loadError: 'Load failed', loading: 'Loading…', player: 'Player', team: 'Team', choose: 'Choose…' },
} ) + ';';

async function mountViewer( page ) {
	const requests = [];
	await page.route( ORIGIN + '/**', async ( route ) => {
		const url = new URL( route.request().url() );
		if ( url.pathname === '/home' ) {
			await route.fulfill( {
				contentType: 'text/html',
				body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css() +
					'</style></head><body class="tt-dashboard">' + VIEWER_BODY +
					'<script>' + VIEWER_CONFIG + '</script><script>' + asset( 'js', 'frontend-report-viewer.js' ) + '</script></body></html>',
			} );
			return;
		}
		const p = url.pathname.replace( '/wp-json/talenttrack/v1/', '' );
		requests.push( p );
		if ( p === 'reports/11/document' ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				layout: 'grid', context: { scope: 'team', team_id: 3, can_snapshot: false }, page_fit: null, html: GRID,
			} } ) } );
		}
		return route.fulfill( { status: 404, body: '{}' } );
	} );
	await page.goto( ORIGIN + '/home' );
	await expect( page.locator( '.tt-sg-cell' ) ).toHaveCount( 3 );
	return requests;
}

test.describe( 'Report Studio grid: the reader', () => {

	test( 'at 1280px the blocks sit where they were placed, and the home makes one request', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		const requests = await mountViewer( page );

		const [ left, right, below ] = await Promise.all( [ '#tt-sd-b1', '#tt-sd-b2', '#tt-sd-b3' ].map( ( s ) => page.locator( s ).boundingBox() ) );
		expect( left && right && below ).toBeTruthy();
		if ( ! left || ! right || ! below ) return;
		expect( Math.abs( left.y - right.y ) ).toBeLessThanOrEqual( 1 );
		expect( right.x ).toBeGreaterThan( left.x + left.width - 1 );
		expect( Math.abs( left.width - right.width ) ).toBeLessThanOrEqual( 2 );
		expect( below.y ).toBeGreaterThanOrEqual( left.y + left.height - 1 );
		expect( below.width ).toBeGreaterThan( left.width * 1.8 );

		expect( requests ).toEqual( [ 'reports/11/document' ] );
	} );

	test( 'at 360px the grid stacks in reading order with no sideways scroll', async ( { page } ) => {
		await page.setViewportSize( { width: 360, height: 740 } );
		await mountViewer( page );

		const tops = await page.locator( '.tt-sg-cell .tt-sd-block' ).evaluateAll( ( list ) => list.map( ( b ) => ( { id: b.id, top: b.getBoundingClientRect().top, left: b.getBoundingClientRect().left } ) ) );
		expect( tops.map( ( t ) => t.id ) ).toEqual( [ 'tt-sd-b1', 'tt-sd-b2', 'tt-sd-b3' ] );
		expect( tops[ 0 ].top ).toBeLessThan( tops[ 1 ].top );
		expect( tops[ 1 ].top ).toBeLessThan( tops[ 2 ].top );
		expect( new Set( tops.map( ( t ) => Math.round( t.left ) ) ).size ).toBe( 1 );

		const widths = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth } ) );
		expect( widths.scroll ).toBeLessThanOrEqual( widths.client );
	} );
} );

// ---- the canvas ---------------------------------------------------------------

const CANVAS_BODY =
	'<div class="tt-rs" data-tt-rs>' +
	'<div class="tt-rs-top"><input type="text" data-tt-rs-name aria-label="Name" value="">' +
	'<span class="tt-save-state-group"><span data-tt-save-state role="status">All changes saved</span>' +
	'<button type="button" data-tt-save-undo hidden>Undo</button>' +
	'<button type="button" data-tt-save-revert hidden>Revert changes</button></span>' +
	'<button type="button" data-tt-rs-open-library>Add block</button></div>' +
	'<div class="tt-rs-context"><div data-tt-rs-subject></div>' +
	'<input type="date" data-tt-rs-from aria-label="From"><input type="date" data-tt-rs-to aria-label="To">' +
	'<fieldset data-tt-rs-audience></fieldset>' +
	'<fieldset data-tt-rs-view><label><input type="radio" name="tt-rs-view" value="screen" checked>Screen</label>' +
	'<label><input type="radio" name="tt-rs-view" value="phone">Phone</label></fieldset></div>' +
	'<p data-tt-rs-notice role="status" hidden></p><p data-tt-rs-live aria-live="polite"></p>' +
	'<div class="tt-rs-body"><div class="tt-rs-main"><div data-tt-rs-meter hidden></div>' +
	'<ol class="tt-rs-canvas" data-tt-rs-canvas data-view="screen"></ol></div>' +
	'<dialog data-tt-rs-library><input type="search" data-tt-rs-search aria-label="Search"><ul data-tt-rs-library-list></ul></dialog>' +
	'<dialog data-tt-rs-settings><h2 data-tt-rs-settings-title></h2><button type="button" data-tt-rs-close>Close</button>' +
	'<div data-tt-rs-settings-body></div></dialog></div>' +
	'</div>';

const I18N = {
	loadError: 'Load failed', stale: 'Stale', reloaded: 'Reloaded', tooMany: 'At most %d blocks.', added: '%s added.',
	moved: '%1$s moved to place %2$s.', removed: '%s removed.', block: 'Block', dragAdd: 'Drag %s', noBlocks: 'No blocks',
	emptyCanvas: 'Add a block', page: 'Page %d', blockAt: '%1$s, block %2$s of %3$s', dragMove: 'Drag to move %s',
	toolbar: 'Change %s', up: 'Move up', down: 'Move down', makeFull: 'Make full width', makeHalf: 'Make half width',
	duplicate: 'Duplicate', settings: 'Settings', remove: 'Remove', loading: 'Loading…', nothing: 'Nothing.', truncated: 'First rows.',
	value: 'Value', noOptions: 'No settings.', choose: 'Choose…', none: 'None', whoSees: 'Who sees it', source: 'Source',
	player: 'Player', team: 'Team', subject: 'About', audience: 'Read as',
	left: 'Move left', right: 'Move right', narrower: 'Make narrower', wider: 'Make wider', shorter: 'Make shorter', taller: 'Make taller',
	placed: '%1$s at column %2$s, row %3$s, %4$s columns wide and %5$s rows tall.',
	noRoom: 'There is no room there.', gridFull: 'The grid is full.', gridBlockAt: '%1$s, column %2$s, row %3$s.',
};

const CANVAS_CONFIG = 'window.TT_Autosave = { user: "1", i18n: {} }; window.TTReportStudio = ' + JSON.stringify( {
	id: 8, rest: REST, nonce: 'e2e', maxBlocks: 12, scopeLabel: 'One team', setsHome: false,
	grid: { columns: 12, rows: 48, maxHeight: 4, width: 6, height: 2 },
	columns: {}, i18n: I18N,
} ) + ';';

async function mountCanvas( page ) {
	const server = {
		blocks: [
			{ id: 'b1', key: 'heading', options: {}, width: 'full', grid: { x: 0, y: 0, w: 4, h: 2 } },
			{ id: 'b2', key: 'heading', options: {}, width: 'full', grid: { x: 6, y: 0, w: 6, h: 2 } },
		],
		revision: 0,
		patches: [],
	};
	await page.route( ORIGIN + '/**', async ( route ) => {
		const req = route.request();
		const url = new URL( req.url() );
		const json = ( data, status = 200 ) => route.fulfill( {
			status,
			contentType: 'application/json',
			body: JSON.stringify( status < 400 ? { success: true, data } : { success: false, errors: [ data ] } ),
		} );
		if ( url.pathname === '/studio' ) {
			await route.fulfill( {
				contentType: 'text/html',
				body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css() +
					'</style></head><body class="tt-dashboard">' + CANVAS_BODY +
					'<script>' + CANVAS_CONFIG + '</script><script>' + asset( 'js', 'tt-autosave.js' ) + '</script>' +
					'<script>' + asset( 'js', 'tt-report-blocks.js' ) + '</script>' +
					'<script>' + asset( 'js', 'frontend-report-studio.js' ) + '</script></body></html>',
			} );
			return;
		}
		const p = url.pathname.replace( '/wp-json/talenttrack/v1/', '' );
		if ( p === 'reports/8' && req.method() === 'GET' ) {
			return json( {
				id: 8, name: 'Home', kind: 'dashboard', scope: 'team', binding: 'viewer', audience: 'internal', layout: 'grid',
				sharing: 'role', subject: { player_id: null, team_id: null }, permissions: { can_edit: true, can_publish: true },
				persona_keys: [], draft: { name: 'Home', definition: { blocks: server.blocks }, revision: server.revision },
			} );
		}
		if ( p === 'reports/8' && req.method() === 'PATCH' ) {
			const body = JSON.parse( req.postData() || '{}' );
			server.patches.push( body );
			server.blocks = body.definition.blocks;
			server.revision += 1;
			return json( { id: 8, draft: { definition: { blocks: server.blocks }, revision: server.revision } } );
		}
		if ( p === 'reports/8/data' ) {
			return json( {
				layout: 'grid',
				context: { scope: 'team', team_id: 3, from: '2026-10-01', to: '2026-10-31', audience: 'internal' },
				blocks: server.blocks.map( ( b ) => ( { id: b.id, key: b.key, width: b.width, grid: b.grid, options: {}, data: { heading: 'Heading ' + b.id } } ) ),
				page_fit: null,
			} );
		}
		if ( p === 'report-blocks' ) {
			return json( { blocks: [ { key: 'heading', label: 'Heading', options: [], source: 'You', family: 'A family may see it.' } ], unavailable: [] } );
		}
		if ( p === 'reports/start-options' ) return json( { options: { audience: [ { value: 'internal', label: 'Staff', ok: true, reason: null } ] } } );
		if ( p.indexOf( 'report-blocks/' ) === 0 ) return json( { options: {}, data: { heading: 'Preview' } } );
		return json( { code: 'not_found', message: 'Not stubbed' }, 404 );
	} );
	await page.goto( ORIGIN + '/studio' );
	await expect( page.locator( '[data-tt-rs-block]' ) ).toHaveCount( 2 );
	return server;
}

function card( page, id ) {
	return page.locator( '[data-tt-rs-block="' + id + '"]' );
}

/** Wait until the stub holds this place for the block: the edit was saved. */
async function expectSaved( server, id, place ) {
	await expect.poll( () => {
		const b = server.blocks.find( ( x ) => x.id === id );
		return b ? b.grid : null;
	}, { timeout: 10000 } ).toEqual( place );
}

test.describe( 'Report Studio grid: the canvas', () => {

	test( 'arrow keys move a block and Shift resizes it, each one undo step, and each is saved', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		const server = await mountCanvas( page );

		await card( page, 'b1' ).focus();
		await page.keyboard.press( 'ArrowDown' );
		await expect( card( page, 'b1' ) ).toBeFocused();
		await page.keyboard.press( 'Shift+ArrowRight' );
		await expectSaved( server, 'b1', { x: 0, y: 1, w: 5, h: 2 } );

		await page.locator( '[data-tt-save-undo]' ).click();
		await expectSaved( server, 'b1', { x: 0, y: 1, w: 4, h: 2 } );
		await page.locator( '[data-tt-save-undo]' ).click();
		await expectSaved( server, 'b1', { x: 0, y: 0, w: 4, h: 2 } );
		await expect( page.locator( '[data-tt-save-undo]' ) ).toBeHidden();
	} );

	test( 'the buttons move and resize too, and a place on another block is not made', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		const server = await mountCanvas( page );

		await card( page, 'b1' ).getByRole( 'button', { name: 'Move right', exact: true } ).click();
		await card( page, 'b1' ).getByRole( 'button', { name: 'Make taller', exact: true } ).click();
		await expectSaved( server, 'b1', { x: 1, y: 0, w: 4, h: 3 } );

		// Now b1 ends at column 6, where b2 starts: one more step right or
		// wider would cover b2, so those buttons are off.
		await card( page, 'b1' ).getByRole( 'button', { name: 'Move right', exact: true } ).click();
		await expectSaved( server, 'b1', { x: 2, y: 0, w: 4, h: 3 } );
		await expect( card( page, 'b1' ).getByRole( 'button', { name: 'Move right', exact: true } ) ).toBeDisabled();
		await expect( card( page, 'b1' ).getByRole( 'button', { name: 'Make wider', exact: true } ) ).toBeDisabled();
		await expect( card( page, 'b1' ).getByRole( 'button', { name: 'Move left', exact: true } ) ).toBeEnabled();

		// The keyboard is held to the same rule.
		await card( page, 'b1' ).focus();
		const before = server.patches.length;
		await page.keyboard.press( 'Shift+ArrowRight' );
		await expect( page.locator( '[data-tt-rs-live]' ) ).toHaveText( I18N.noRoom );
		expect( server.patches.length ).toBe( before );

		// On the desktop the canvas lays the blocks out as stored.
		const [ one, two ] = await Promise.all( [ card( page, 'b1' ).boundingBox(), card( page, 'b2' ).boundingBox() ] );
		expect( one && two && two.x >= one.x + one.width - 1 ).toBeTruthy();
	} );

	test( 'at 360px the canvas stacks in reading order with no sideways scroll, on 48px targets', async ( { browser } ) => {
		const context = await browser.newContext( { viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true } );
		const page = await context.newPage();
		await mountCanvas( page );

		const ids = await page.locator( '[data-tt-rs-block]' ).evaluateAll( ( list ) => list.map( ( c ) => c.getAttribute( 'data-tt-rs-block' ) ) );
		expect( ids ).toEqual( [ 'b1', 'b2' ] );
		const [ one, two ] = await Promise.all( [ card( page, 'b1' ).boundingBox(), card( page, 'b2' ).boundingBox() ] );
		expect( one && two && two.y >= one.y + one.height - 1 ).toBeTruthy();

		const widths = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth } ) );
		expect( widths.scroll ).toBeLessThanOrEqual( widths.client );

		const sizes = await card( page, 'b1' ).locator( '.tt-rs-tool' ).evaluateAll( ( list ) => list.map( ( b ) => {
			const r = b.getBoundingClientRect();
			return Math.min( r.width, r.height );
		} ) );
		expect( sizes.length ).toBeGreaterThan( 8 );
		for ( const size of sizes ) expect( size ).toBeGreaterThanOrEqual( 48 );
		await context.close();
	} );
} );
