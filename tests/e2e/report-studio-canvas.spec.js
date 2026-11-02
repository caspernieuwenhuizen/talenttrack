// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The Report Studio canvas (#4571) against a stubbed REST API.
 *
 * The real `tt-autosave.js` and `frontend-report-studio.js` run on a routed
 * origin, with the canvas markup the view prints and the routes it calls
 * answered by the test. Nothing needs the wp-env data, so the spec never
 * skips. It checks what a unit test cannot: how many requests a burst of
 * edits sends, what the page does with a 409, which audience the data
 * request asks for, and how far undo reaches.
 */

const ORIGIN = 'http://tt-studio.test';
const REST = ORIGIN + '/wp-json/talenttrack/v1/';

function script( name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', name ), 'utf8' );
}

const BODY =
	'<div class="tt-rs" data-tt-rs>' +
	'<div class="tt-rs-top"><input type="text" data-tt-rs-name aria-label="Name" value="">' +
	'<span class="tt-save-state-group"><span data-tt-save-state role="status">All changes saved</span>' +
	'<button type="button" data-tt-save-undo hidden>Undo</button>' +
	'<button type="button" data-tt-save-revert hidden>Revert changes</button></span>' +
	'<button type="button" data-tt-rs-open-library>Add block</button>' +
	'<button type="button" data-tt-rs-open-versions>Versions</button>' +
	'<button type="button" data-tt-rs-open-publish>Publish</button></div>' +
	'<div class="tt-rs-context"><div data-tt-rs-subject></div>' +
	'<input type="date" data-tt-rs-from aria-label="From"><input type="date" data-tt-rs-to aria-label="To">' +
	'<fieldset data-tt-rs-audience></fieldset>' +
	'<fieldset data-tt-rs-view><label><input type="radio" name="tt-rs-view" value="screen" checked>Screen</label>' +
	'<label><input type="radio" name="tt-rs-view" value="a4">A4</label></fieldset></div>' +
	'<p data-tt-rs-notice role="status" hidden></p><p data-tt-rs-live aria-live="polite"></p>' +
	'<div class="tt-rs-body"><div class="tt-rs-main"><div data-tt-rs-meter hidden></div>' +
	'<ol data-tt-rs-canvas data-view="screen"></ol></div>' +
	'<dialog data-tt-rs-library><input type="search" data-tt-rs-search aria-label="Search"><ul data-tt-rs-library-list></ul></dialog>' +
	'<dialog data-tt-rs-settings><h2 data-tt-rs-settings-title></h2><button type="button" data-tt-rs-close>Close</button>' +
	'<div data-tt-rs-settings-body></div></dialog></div>' +
	'</div>';

const I18N = {
	loadError: 'Load failed', stale: 'Changed in another tab. Loading the newest version…',
	reloaded: 'Changed in another tab, so the newest version is loaded.', tooMany: 'At most %d blocks.',
	added: '%s added.', moved: '%1$s moved to place %2$s.', removed: '%s removed.', block: 'Block',
	dragAdd: 'Drag %s', noBlocks: 'No blocks', emptyCanvas: 'Add a block', page: 'Page %d',
	blockAt: '%1$s, block %2$s of %3$s', dragMove: 'Drag to move %s', toolbar: 'Change %s',
	up: 'Move up', down: 'Move down', makeFull: 'Make full width', makeHalf: 'Make half width',
	duplicate: 'Duplicate', settings: 'Settings', remove: 'Remove', loading: 'Loading…',
	nothing: 'Nothing between %1$s and %2$s.', truncated: 'First rows only.', value: 'Value',
	onePage: 'One page, %s%% full.', pages: '%1$s pages; last %2$s%% full.', noOptions: 'No settings.',
	choose: 'Choose…', none: 'None', whoSees: 'Who sees it', source: 'Source', player: 'Player',
	team: 'Team', subject: 'About', audience: 'Read as',
};

const CONFIG = 'window.TT_Autosave = { user: "1", i18n: {} }; window.TTReportStudio = ' + JSON.stringify( {
	id: 7, rest: REST, nonce: 'e2e', maxBlocks: 40, scopeLabel: 'One player',
	columns: { week: 'Week', attendance_pct: 'Attendance %' }, i18n: I18N,
} ) + ';';

/**
 * Serve the canvas and answer its routes. `server` holds the draft the stub
 * keeps and every request it saw.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ patchDelay?: number, conflictOn?: number }} opts
 */
async function mount( page, opts = {} ) {
	const server = {
		name: 'Pack',
		blocks: [ 'b1', 'b2', 'b3' ].map( ( id ) => ( { id, key: 'attendance_per_week', options: {}, width: 'full' } ) ),
		revision: 0,
		patches: [],
		dataQueries: [],
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
				body: '<!doctype html><html><body class="tt-dashboard">' + BODY +
					'<script>' + CONFIG + '</script><script>' + script( 'tt-autosave.js' ) + '</script>' +
					'<script>' + script( 'tt-report-blocks.js' ) + '</script>' +
					'<script>' + script( 'frontend-report-studio.js' ) + '</script></body></html>',
			} );
			return;
		}

		const p = url.pathname.replace( '/wp-json/talenttrack/v1/', '' );
		if ( p === 'reports/7' && req.method() === 'GET' ) {
			return json( {
				id: 7, name: server.name, kind: 'report', scope: 'player', binding: 'fixed', audience: 'internal',
				sharing: 'me', subject: { player_id: 5, team_id: null }, permissions: { can_edit: true, can_publish: false },
				persona_keys: [], draft: { definition: { blocks: server.blocks }, revision: server.revision },
			} );
		}
		if ( p === 'reports/7' && req.method() === 'PATCH' ) {
			const body = JSON.parse( req.postData() || '{}' );
			server.patches.push( { revision: Number( url.searchParams.get( 'revision' ) ), body } );
			if ( opts.patchDelay ) await new Promise( ( r ) => setTimeout( r, opts.patchDelay ) );
			if ( opts.conflictOn && server.patches.length === opts.conflictOn ) {
				// Another tab saved first: the stub holds a newer draft.
				server.name = 'Newer';
				server.revision += 1;
				return json( { code: 'stale_revision', message: 'Changed elsewhere.' }, 409 );
			}
			server.blocks = body.definition.blocks;
			server.name = body.name;
			server.revision += 1;
			return json( { id: 7, name: server.name, draft: { definition: { blocks: server.blocks }, revision: server.revision } } );
		}
		if ( p === 'reports/7/data' ) {
			server.dataQueries.push( Object.fromEntries( url.searchParams ) );
			return json( {
				context: { scope: 'player', player_id: 5, team_id: null, player_ids: [], from: '2026-10-01', to: '2026-10-31', audience: url.searchParams.get( 'audience' ) || 'internal' },
				blocks: server.blocks.map( ( b ) => ( { id: b.id, key: b.key, width: b.width, options: {}, data: { breakdown: 'week', rows: [ { week: '2026-W40', attendance_pct: 90 } ] } } ) ),
				page_fit: { pages: 1, fill: [ 40 ], breaks: [] },
			} );
		}
		if ( p === 'report-blocks' ) {
			return json( { blocks: [ { key: 'attendance_per_week', label: 'Attendance per week', options: [], source: 'Attendance', family: 'A family may see it.' } ], unavailable: [] } );
		}
		if ( p === 'reports/start-options' ) {
			return json( { options: { audience: [
				{ value: 'internal', label: 'Staff', ok: true, reason: null },
				{ value: 'family', label: 'The family', ok: true, reason: null },
			] } } );
		}
		if ( p === 'reports/subject-options' ) {
			return json( { teams: [ { id: 3, name: 'U12', age_group: 'U12' } ], players: [ { id: 5, name: 'Anna', team_id: 3 } ] } );
		}
		if ( p.indexOf( 'report-blocks/' ) === 0 ) {
			return json( { options: {}, data: { breakdown: 'week', rows: [] } } );
		}
		return json( { code: 'not_found', message: 'Not stubbed' }, 404 );
	} );

	await page.goto( ORIGIN + '/studio' );
	await expect( page.locator( '[data-tt-rs-block]' ) ).toHaveCount( 3 );
	return server;
}

function order( page ) {
	return page.locator( '[data-tt-rs-block]' ).evaluateAll( ( els ) => els.map( ( e ) => e.getAttribute( 'data-tt-rs-block' ) ) );
}

function card( page, id ) {
	return page.locator( '[data-tt-rs-block="' + id + '"]' );
}

test.describe( 'Report Studio canvas', () => {

	test( 'ten reorders in a burst on a slow network send at most two saves and land the final order', async ( { page } ) => {
		const server = await mount( page, { patchDelay: 1500 } );

		const moves = [ 'Move down', 'Move down', 'Move up', 'Move up', 'Move down', 'Move down', 'Move up', 'Move up', 'Move down', 'Move down' ];
		for ( const name of moves ) {
			await card( page, 'b1' ).getByRole( 'button', { name, exact: true } ).click();
		}
		expect( await order( page ) ).toEqual( [ 'b2', 'b3', 'b1' ] );

		await expect( page.locator( '[data-tt-save-state]' ) ).toHaveText( 'All changes saved', { timeout: 10000 } );
		expect( server.patches.length ).toBeGreaterThan( 0 );
		expect( server.patches.length ).toBeLessThanOrEqual( 2 );
		expect( server.blocks.map( ( b ) => b.id ) ).toEqual( [ 'b2', 'b3', 'b1' ] );
	} );

	test( 'a stale save from a second tab reloads the newer draft with a notice', async ( { page } ) => {
		const server = await mount( page, { conflictOn: 1 } );

		await card( page, 'b1' ).getByRole( 'button', { name: 'Move down', exact: true } ).click();

		await expect( page.locator( '[data-tt-rs-name]' ) ).toHaveValue( 'Newer', { timeout: 10000 } );
		await expect( page.locator( '[data-tt-rs-notice]' ) ).toHaveText( I18N.reloaded );
		expect( server.name ).toBe( 'Newer' );
		expect( server.patches ).toHaveLength( 1 );
	} );

	test( 'reading as the family asks the server for the family audience', async ( { page } ) => {
		const server = await mount( page );

		await page.getByRole( 'radio', { name: 'The family' } ).check();

		await expect.poll( () => server.dataQueries.some( ( q ) => q.audience === 'family' ) ).toBe( true );
		expect( server.dataQueries[ 0 ].audience ).toBeUndefined();
	} );

	test( 'undo takes back a remove, a width change and a reorder, one step at a time', async ( { page } ) => {
		const server = await mount( page );
		const undo = page.locator( '[data-tt-save-undo]' );
		await expect( undo ).toBeHidden();

		await card( page, 'b2' ).getByRole( 'button', { name: 'Remove', exact: true } ).click();
		await card( page, 'b1' ).getByRole( 'button', { name: 'Make half width', exact: true } ).click();
		await card( page, 'b1' ).getByRole( 'button', { name: 'Move down', exact: true } ).click();
		expect( await order( page ) ).toEqual( [ 'b3', 'b1' ] );

		await undo.click();
		expect( await order( page ) ).toEqual( [ 'b1', 'b3' ] );
		await expect( page.locator( '.tt-rs-block--half' ) ).toHaveCount( 1 );

		await undo.click();
		await expect( page.locator( '.tt-rs-block--half' ) ).toHaveCount( 0 );

		await undo.click();
		expect( await order( page ) ).toEqual( [ 'b1', 'b2', 'b3' ] );
		await expect( undo ).toBeHidden();

		await expect( page.locator( '[data-tt-save-state]' ) ).toHaveText( 'All changes saved', { timeout: 10000 } );
		expect( server.blocks.map( ( b ) => b.id ) ).toEqual( [ 'b1', 'b2', 'b3' ] );
	} );
} );
