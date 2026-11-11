// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * TT.Autosave.flush() (#5088) against a stubbed endpoint.
 *
 * The real component runs on a routed origin; nothing needs the wp-env data,
 * so the spec never skips. flush() resolves at once when nothing is pending,
 * after a pending debounced save has landed, and after a failed save. commit()
 * flushes through it and behaves as it did: the edit goes out before the
 * commit, and a failed flush refuses the commit without sending it.
 */

const ORIGIN = 'http://tt-autosave.test';

const SCRIPT = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'tt-autosave.js' ), 'utf8' );

async function mount( page, { failSave = false } = {} ) {
	const server = { saves: [], commits: [] };
	await page.route( ORIGIN + '/**', async ( route ) => {
		const req = route.request();
		const url = new URL( req.url() );
		if ( url.pathname === '/page' ) {
			await route.fulfill( {
				contentType: 'text/html',
				body: '<!doctype html><html><head></head><body>' +
					'<script>window.TT_Autosave = { user: "1", i18n: {} };</script>' +
					'<script>' + SCRIPT + '</script></body></html>',
			} );
			return;
		}
		if ( url.pathname === '/save' ) {
			server.saves.push( { at: server.saves.length + server.commits.length, body: JSON.parse( req.postData() || '{}' ) } );
			// A short delay so a save is genuinely in flight when flush() is called.
			await new Promise( ( r ) => setTimeout( r, 150 ) );
			await route.fulfill( {
				status: failSave ? 500 : 200,
				contentType: 'application/json',
				body: JSON.stringify( failSave ? { success: false, errors: [ { code: 'boom', message: 'Boom' } ] } : { success: true, data: {} } ),
			} );
			return;
		}
		if ( url.pathname === '/commit' ) {
			server.commits.push( { at: server.saves.length + server.commits.length } );
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: { done: true } } ) } );
			return;
		}
		await route.fulfill( { status: 404, body: '' } );
	} );
	await page.goto( ORIGIN + '/page' );
	await page.evaluate( () => {
		window.__value = 0;
		window.__saver = window.TT.Autosave.create( {
			delay: 250,
			serialise: function () { return { method: 'PUT', url: '/save', body: { value: window.__value } }; },
		} );
	} );
	return server;
}

test.describe( 'TT.Autosave.flush()', () => {
	test( 'resolves at once when nothing is pending', async ( { page } ) => {
		const server = await mount( page );
		const result = await page.evaluate( async () => {
			const t0 = performance.now();
			await window.__saver.flush();
			return { ms: performance.now() - t0, pending: window.__saver.isPending() };
		} );
		expect( result.pending ).toBe( false );
		expect( result.ms ).toBeLessThan( 100 );
		expect( server.saves ).toHaveLength( 0 );
	} );

	test( 'resolves after a pending debounced save has landed', async ( { page } ) => {
		const server = await mount( page );
		const state = await page.evaluate( async () => {
			window.__value = 7;
			window.__saver.change();
			await window.__saver.flush();
			return { pending: window.__saver.isPending(), failed: window.__saver.failed };
		} );
		expect( state ).toEqual( { pending: false, failed: false } );
		expect( server.saves ).toHaveLength( 1 );
		expect( server.saves[ 0 ].body ).toEqual( { value: 7 } );
	} );

	test( 'waits for a save already in flight', async ( { page } ) => {
		const server = await mount( page );
		const state = await page.evaluate( async () => {
			window.__value = 3;
			window.__saver.saveNow();
			const inFlight = window.__saver.inFlight;
			await window.__saver.flush();
			return { inFlight, after: window.__saver.inFlight, pending: window.__saver.isPending() };
		} );
		expect( state ).toEqual( { inFlight: true, after: false, pending: false } );
		expect( server.saves ).toHaveLength( 1 );
	} );

	test( 'resolves after a failed save, with the failure readable', async ( { page } ) => {
		await mount( page, { failSave: true } );
		const state = await page.evaluate( async () => {
			window.__value = 5;
			window.__saver.change();
			const out = await window.__saver.flush().then( () => 'resolved', () => 'rejected' );
			return { out, failed: window.__saver.failed, dirty: window.__saver.dirty };
		} );
		expect( state ).toEqual( { out: 'resolved', failed: true, dirty: true } );
	} );
} );

test.describe( 'TT.Autosave.commit() flushes through flush()', () => {
	test( 'sends the pending edit before the commit', async ( { page } ) => {
		const server = await mount( page );
		const res = await page.evaluate( async () => {
			window.__value = 9;
			window.__saver.change();
			return window.__saver.commit( { method: 'POST', url: '/commit' } );
		} );
		expect( res.ok ).toBe( true );
		expect( res.data ).toEqual( { done: true } );
		expect( server.saves ).toHaveLength( 1 );
		expect( server.commits ).toHaveLength( 1 );
		expect( server.saves[ 0 ].at ).toBeLessThan( server.commits[ 0 ].at );
	} );

	test( 'refuses without sending when the flush did not store', async ( { page } ) => {
		const server = await mount( page, { failSave: true } );
		const res = await page.evaluate( async () => {
			window.__value = 1;
			window.__saver.change();
			return window.__saver.commit( { method: 'POST', url: '/commit' } );
		} );
		expect( res.ok ).toBe( false );
		expect( res.code ).toBe( 'not_saved' );
		expect( server.commits ).toHaveLength( 0 );
	} );

	test( 'calls flush() rather than a wait of its own', async ( { page } ) => {
		await mount( page );
		const calls = await page.evaluate( async () => {
			let n = 0;
			const original = window.__saver.flush;
			window.__saver.flush = function () { n++; return original.call( this ); };
			await window.__saver.commit( { method: 'POST', url: '/commit' } );
			return n;
		} );
		expect( calls ).toBe( 1 );
	} );
} );
